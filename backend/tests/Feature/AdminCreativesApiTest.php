<?php

namespace Tests\Feature;

use App\Models\AdCreative;
use App\Models\AdDelivery;
use App\Models\AdvertiserAccount;
use App\Models\AdvertiserLedgerEntry;
use App\Models\Campaign;
use App\Models\RawImpressionEvent;
use App\Models\User;
use App\Models\ValidatedImpression;
use Carbon\CarbonImmutable;
use Database\Seeders\AdminPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCreativesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00:00', 'UTC'));
    }

    public static function endpoints(): array
    {
        return [['GET', '/api/admin/creatives'], ['GET', '/api/admin/creatives/options?kind=campaigns'], ['GET', '/api/admin/creatives/1'], ['POST', '/api/admin/creatives/1/moderate'], ['DELETE', '/api/admin/creatives/1']];
    }

    #[DataProvider('endpoints')]
    public function test_guest_creative_access_returns_401(string $method, string $url): void
    {
        $this->json($method, $url)->assertUnauthorized();
    }

    public static function deniedRoles(): array
    {
        return [['advertiser'], ['publisher'], ['finance'], ['unassigned']];
    }

    #[DataProvider('deniedRoles')]
    public function test_unprivileged_roles_receive_403(string $role): void
    {
        $user = in_array($role, ['advertiser', 'publisher']) ? User::factory()->create(['role' => $role]) : $this->admin($role);
        $creative = $this->creative();
        $this->actingAs($user, 'sanctum')->getJson('/api/admin/creatives')->assertForbidden();
        $this->getJson('/api/admin/creatives/'.$creative->id)->assertForbidden();
        $this->postJson('/api/admin/creatives/'.$creative->id.'/moderate')->assertForbidden();
        $this->deleteJson('/api/admin/creatives/'.$creative->id)->assertForbidden();
    }

    public function test_support_can_inspect_content_but_not_money_or_moderate(): void
    {
        $creative = $this->creative();
        $this->actingAs($this->admin('support'), 'sanctum')->getJson('/api/admin/creatives')->assertOk()->assertJsonPath('can_review', false);
        $response = $this->getJson('/api/admin/creatives/'.$creative->id)->assertOk()->assertJsonPath('data.can_moderate', false)->assertJsonPath('data.can_view_money', false)
            ->assertJsonMissingPath('data.metrics.spend_micros')->assertJsonMissingPath('data.metrics.effective_cpm_micros')->assertJsonMissingPath('data.points.0.spend_micros');
        $this->assertSame($creative->message_text, $response->json('data.message_text'));
        $this->postJson('/api/admin/creatives/'.$creative->id.'/moderate', ['action' => 'approve', 'version' => $response->json('data.version')])->assertForbidden();
        $this->deleteJson('/api/admin/creatives/'.$creative->id, ['confirmation' => 'CR-'.$creative->id, 'version' => $response->json('data.version')])->assertForbidden();
    }

    public function test_pending_queue_excludes_deleted_hierarchy_and_sorts_oldest_submission_first(): void
    {
        $recent = $this->creative(['review_submitted_at' => now()->subHour()]);
        $old = $this->creative(['review_submitted_at' => now()->subDays(2)]);
        $deleted = $this->creative();
        $deleted->delete();
        $parentDeleted = $this->creative();
        $parentDeleted->campaign->delete();
        $userDeleted = $this->creative();
        $userDeleted->campaign->advertiserAccount->user->delete();
        $approved = $this->creative(['status' => 'approved']);

        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/creatives')->assertOk()->assertJsonPath('pending_count', 2)
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $old->id)->assertJsonPath('data.1.id', $recent->id);
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.pending_creatives', 2)->assertJsonPath('data.oldest_pending_creative_at', '2026-10-26T12:00:00.000000Z');
        $this->getJson('/api/admin/creatives?status=all')->assertOk()->assertJsonPath('meta.total', 6);
        $this->getJson('/api/admin/creatives/'.$parentDeleted->id)->assertOk()->assertJsonPath('data.can_moderate', false)->assertJsonPath('data.has_deleted_parent', true);
        $this->getJson('/api/admin/creatives?status=approved')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $approved->id);
    }

    public function test_search_scope_language_and_options_follow_selected_advertiser(): void
    {
        $first = $this->creative(['message_text' => 'Sky island offer', 'language' => 'en']);
        $second = $this->creative(['message_text' => 'Different message', 'language' => null]);
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/creatives?search=CR-'.$first->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
        $this->getJson('/api/admin/creatives?search=Sky')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/creatives?search=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/creatives?language=none')->assertOk()->assertJsonPath('data.0.id', $second->id)->assertJsonCount(1, 'data');
        $uid = $first->campaign->advertiserAccount->user_id;
        $this->getJson('/api/admin/creatives?advertiser_id='.$uid.'&campaign_id='.$first->campaign_id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/creatives/options?kind=campaigns&advertiser_id='.$uid)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->campaign_id);
        $this->getJson('/api/admin/creatives?per_page=999')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/api/admin/creatives?status=bogus')->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_approval_is_audited_and_does_not_edit_content_unpause_or_send_email(): void
    {
        Mail::fake();
        $creative = $this->creative(['is_paused' => true]);
        $actor = $this->admin('ad-ops');
        $version = $this->version($creative, $actor);
        $this->postJson('/api/admin/creatives/'.$creative->id.'/moderate', ['action' => 'approve', 'version' => $version, 'message_text' => 'Injected change', 'is_paused' => false])->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.is_paused', true)->assertJsonPath('data.can_view_money', true)
            ->assertJsonPath('data.can_view_advertiser', false)->assertJsonPath('data.history.0.actor_name', $actor->name);
        $this->assertDatabaseHas('ad_creatives', ['id' => $creative->id, 'status' => 'approved', 'message_text' => $creative->message_text, 'is_paused' => true]);
        $this->assertDatabaseHas('admin_creative_events', ['creative_id' => $creative->id, 'actor_id' => $actor->id, 'action' => 'approved']);
        Mail::assertNothingSent();
        $this->postJson('/api/admin/creatives/'.$creative->id.'/moderate', ['action' => 'approve', 'version' => $version])->assertConflict();
        $this->assertDatabaseCount('admin_creative_events', 1);
    }

    public function test_rejection_validation_confirmation_and_advertiser_visibility(): void
    {
        $creative = $this->creative(['status' => 'approved']);
        $actor = $this->admin();
        $version = $this->version($creative, $actor);
        $url = '/api/admin/creatives/'.$creative->id.'/moderate';
        $this->postJson($url, ['action' => 'reject', 'version' => $version])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($url, ['action' => 'reject', 'version' => $version, 'reason' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('explanation');
        $this->postJson($url, ['action' => 'reject', 'version' => $version, 'reason' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($url, ['action' => 'reject', 'version' => $version, 'reason' => 'suspicious_url'])->assertUnprocessable();
        $this->postJson($url, ['action' => 'reject', 'version' => $version, 'reason' => 'suspicious_url', 'explanation' => 'Fix the redirect.', 'confirm_withdrawal' => true])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Broken or suspicious URL: Fix the redirect.');
        $this->actingAs($creative->campaign->advertiserAccount->user, 'sanctum')->getJson('/api/advertiser/creatives/'.$creative->id)->assertOk()
            ->assertJsonPath('data.rejection_reason', 'Broken or suspicious URL: Fix the redirect.');
    }

    public function test_return_to_review_preserves_history_and_resets_queue_time(): void
    {
        $creative = $this->creative(['status' => 'approved', 'review_submitted_at' => now()->subDay()]);
        $version = $this->version($creative);
        $url = '/api/admin/creatives/'.$creative->id.'/moderate';
        $this->postJson($url, ['action' => 'return_to_review', 'version' => $version])->assertUnprocessable();
        $this->postJson($url, ['action' => 'return_to_review', 'version' => $version, 'confirm_withdrawal' => true])->assertOk()
            ->assertJsonPath('data.status', 'pending_review')->assertJsonPath('data.history.0.action', 'returned_to_review');
        $this->assertTrue($creative->fresh()->review_submitted_at->equalTo(now()));
    }

    public function test_advertiser_resubmission_invalidates_an_open_review_even_in_the_same_second(): void
    {
        $creative = $this->creative(['status' => 'rejected', 'rejection_reason' => 'Old rejection', 'rejection_code' => 'other']);
        $actor = $this->admin();
        $version = $this->version($creative, $actor);
        $advertiser = $creative->campaign->advertiserAccount->user;
        $this->actingAs($advertiser, 'sanctum')->putJson('/api/advertiser/creatives/'.$creative->id, [
            'name' => 'Updated creative', 'language' => 'en', 'message_text' => 'Updated message', 'click_url' => 'https://example.com/new',
        ])->assertOk()->assertJsonPath('data.status', 'pending_review')->assertJsonPath('data.rejection_reason', null);
        $this->assertDatabaseHas('admin_creative_events', ['creative_id' => $creative->id, 'action' => 'resubmitted']);
        $this->actingAs($actor, 'sanctum')->postJson('/api/admin/creatives/'.$creative->id.'/moderate', ['action' => 'approve', 'version' => $version])->assertConflict();
        $this->assertSame('pending_review', $creative->fresh()->status);
    }

    public function test_deleted_records_are_read_only_and_deletion_preserves_financial_data_and_invalidates_advertiser_cache(): void
    {
        $creative = $this->creative(['status' => 'approved']);
        $advertiser = $creative->campaign->advertiserAccount->user;
        $entry = AdvertiserLedgerEntry::factory()->create(['advertiser_account_id' => $creative->campaign->advertiser_account_id, 'campaign_id' => $creative->campaign_id, 'type' => 'spend', 'amount_micros' => -2000]);
        $this->actingAs($advertiser, 'sanctum')->getJson('/api/advertiser/creatives')->assertOk()->assertJsonCount(1, 'data.creatives');
        $version = $this->version($creative);
        $url = '/api/admin/creatives/'.$creative->id;
        $this->deleteJson($url, ['version' => $version, 'confirmation' => 'wrong'])->assertUnprocessable();
        $result = $this->deleteJson($url, ['version' => $version, 'confirmation' => 'CR-'.$creative->id])->assertOk()
            ->assertJsonPath('data.status', 'deleted')->assertJsonPath('data.can_moderate', false)->assertJsonPath('data.history.0.action', 'deleted');
        $this->assertSoftDeleted($creative);
        $this->assertDatabaseHas('advertiser_ledger_entries', ['id' => $entry->id, 'amount_micros' => -2000]);
        $this->deleteJson($url, ['version' => $result->json('data.version'), 'confirmation' => 'CR-'.$creative->id])->assertConflict();
        $this->actingAs($advertiser, 'sanctum')->getJson('/api/advertiser/creatives')->assertOk()->assertJsonCount(0, 'data.creatives');
        $this->getJson('/api/advertiser/creatives/'.$creative->id)->assertNotFound();
    }

    public function test_deleted_parent_blocks_review_with_a_fresh_content_version(): void
    {
        $creative = $this->creative();
        $creative->campaign->advertiserAccount->user->delete();
        $version = $this->version($creative);
        $this->postJson('/api/admin/creatives/'.$creative->id.'/moderate', ['action' => 'approve', 'version' => $version])->assertConflict();
        $this->deleteJson('/api/admin/creatives/'.$creative->id, ['version' => $version, 'confirmation' => 'CR-'.$creative->id])->assertConflict();
    }

    public function test_period_reporting_uses_utc_validation_time_zero_fills_and_hides_future_activity(): void
    {
        $creative = $this->creative(['status' => 'approved']);
        $delivery = AdDelivery::factory()->create(['creative_id' => $creative->id, 'campaign_id' => $creative->campaign_id, 'requested_at' => '2026-10-28 10:00:00']);
        foreach ([['2026-10-27 23:59:59', 3000], ['2026-10-28 00:00:00', 5000], ['2026-10-28 13:00:00', 9000]] as [$at, $spend]) {
            ValidatedImpression::factory()->create(['creative_id' => $creative->id, 'campaign_id' => $creative->campaign_id, 'validated_at' => $at, 'billable_micros' => $spend]);
        }
        RawImpressionEvent::factory()->create(['ad_delivery_id' => $delivery->id, 'validation_status' => 'invalid', 'validated_at' => '2026-10-28 01:00:00']);
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/creatives/'.$creative->id.'?period=today')->assertOk()
            ->assertJsonPath('data.metrics.validated_impressions', 1)->assertJsonPath('data.metrics.spend_micros', 5000)->assertJsonPath('data.metrics.invalid_rate', 50)
            ->assertJsonPath('data.metrics.effective_cpm_micros', 5000000)->assertJsonCount(1, 'data.points');
        $this->getJson('/api/admin/creatives/'.$creative->id.'?period=custom&from=2026-10-26&to=2026-10-28')->assertOk()
            ->assertJsonCount(3, 'data.points')->assertJsonPath('data.points.0.impressions', 0)->assertJsonPath('data.metrics.spend_micros', 8000);
        $this->getJson('/api/admin/creatives/'.$creative->id.'?period=custom&from=2026-10-28&to=2026-10-27')->assertUnprocessable();
        $this->getJson('/api/admin/creatives/'.$creative->id.'?period=custom')->assertUnprocessable();
        $this->getJson('/api/admin/creatives/'.$creative->id.'?period=custom&from=2025-01-01&to=2026-10-28')->assertUnprocessable();
        $this->getJson('/api/admin/creatives/'.$creative->id.'?period=custom&from=2026-10-28&to=2026-10-29')->assertUnprocessable();
    }

    public function test_no_activity_has_unavailable_rates_and_admin_has_no_content_edit_route(): void
    {
        $creative = $this->creative();
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/creatives/'.$creative->id)->assertOk()
            ->assertJsonPath('data.metrics.invalid_rate', null)->assertJsonPath('data.metrics.effective_cpm_micros', null)->assertJsonCount(30, 'data.points');
        $this->putJson('/api/admin/creatives/'.$creative->id, ['message_text' => 'Edited'])->assertMethodNotAllowed();
        $this->assertSame($creative->message_text, $creative->fresh()->message_text);
    }

    private function admin(string $role = 'admin-owner'): User
    {
        $this->seed(AdminPermissionSeeder::class);
        $user = User::factory()->create(['role' => 'admin']);
        if ($role !== 'unassigned') {
            $user->assignRole($role);
        }

        return $user;
    }

    private function creative(array $overrides = []): AdCreative
    {
        $user = User::factory()->create(['role' => 'advertiser']);
        $account = AdvertiserAccount::factory()->create(['user_id' => $user->id]);
        $campaign = Campaign::factory()->create(['advertiser_account_id' => $account->id, 'status' => 'active']);

        return AdCreative::factory()->create([...['campaign_id' => $campaign->id, 'status' => 'pending_review', 'review_submitted_at' => now()->subDay(), 'created_at' => now()->subDays(3)], ...$overrides]);
    }

    private function version(AdCreative $creative, ?User $actor = null): string
    {
        return $this->actingAs($actor ?? $this->admin(), 'sanctum')->getJson('/api/admin/creatives/'.$creative->id)->assertOk()->json('data.version');
    }
}
