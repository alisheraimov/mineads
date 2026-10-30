<?php

namespace Tests\Feature;

use App\Models\AdCreative;
use App\Models\AdDelivery;
use App\Models\AdvertiserAccount;
use App\Models\AdvertiserLedgerEntry;
use App\Models\Campaign;
use App\Models\CampaignTarget;
use App\Models\RawImpressionEvent;
use App\Models\User;
use App\Models\ValidatedImpression;
use App\Services\CampaignBudgetService;
use Carbon\CarbonImmutable;
use Database\Seeders\AdminPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCampaignsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00', 'UTC'));
    }

    public static function endpoints(): array
    {
        return [['GET', '/api/admin/campaigns'], ['GET', '/api/admin/campaigns/advertisers'], ['GET', '/api/admin/campaigns/1'], ['GET', '/api/admin/campaigns/1/creatives'], ['DELETE', '/api/admin/campaigns/1']];
    }

    #[DataProvider('endpoints')]
    public function test_guests_cannot_access_campaign_endpoints(string $method, string $url): void
    {
        $this->json($method, $url)->assertUnauthorized();
    }

    public static function forbiddenRoles(): array
    {
        return [['advertiser'], ['publisher'], ['finance'], ['unassigned']];
    }

    #[DataProvider('forbiddenRoles')]
    public function test_unprivileged_roles_cannot_view_campaigns(string $role): void
    {
        $user = in_array($role, ['advertiser', 'publisher']) ? User::factory()->create(['role' => $role]) : $this->admin($role);
        $campaign = $this->campaign();

        $this->actingAs($user, 'sanctum')->getJson('/api/admin/campaigns')->assertForbidden();
        $this->getJson('/api/admin/campaigns/'.$campaign->id)->assertForbidden();
        $this->getJson('/api/admin/campaigns/'.$campaign->id.'/creatives')->assertForbidden();
        $this->deleteJson('/api/admin/campaigns/'.$campaign->id, ['name' => $campaign->name])->assertForbidden();

        $this->assertNotSoftDeleted($campaign);
    }

    public function test_support_can_view_configuration_and_counts_without_any_monetary_fields(): void
    {
        $support = $this->admin('support');
        $campaign = $this->campaign();

        $this->actingAs($support, 'sanctum')->getJson('/api/admin/campaigns')->assertOk()->assertJsonPath('can_view_money', false)
            ->assertJsonMissingPath('data.0.daily_budget_micros')->assertJsonMissingPath('data.0.total_budget_micros')->assertJsonMissingPath('data.0.spend_micros');
        $this->getJson('/api/admin/campaigns/'.$campaign->id)->assertOk()->assertJsonPath('data.can_delete', false)
            ->assertJsonMissingPath('data.budget')->assertJsonMissingPath('data.daily_budget_micros')->assertJsonMissingPath('data.metrics.spend_micros')
            ->assertJsonMissingPath('data.metrics.effective_cpm_micros')->assertJsonMissingPath('data.performance.points.0.spend_micros')->assertJsonMissingPath('data.performance.points.0.effective_cpm_micros');
        $this->deleteJson('/api/admin/campaigns/'.$campaign->id, ['name' => $campaign->name])->assertForbidden();

        $this->assertNotSoftDeleted($campaign);
        $this->assertDatabaseCount('admin_campaign_events', 0);
    }

    public function test_ad_operations_can_see_operational_finances_without_user_details_access(): void
    {
        $admin = $this->admin('ad-ops');
        $campaign = $this->campaign();

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/campaigns/'.$campaign->id)->assertOk()
            ->assertJsonPath('data.can_view_money', true)->assertJsonPath('data.can_delete', true)->assertJsonPath('data.can_view_advertiser', false)
            ->assertJsonPath('data.daily_budget_micros', 10000000)->assertJsonPath('data.budget.available_balance_micros', 100000000);
    }

    public function test_search_filters_and_pagination_include_deleted_campaigns_without_conflating_user_and_account_ids(): void
    {
        $owner = $this->admin();
        $first = $this->campaign();
        $second = $this->campaign(['name' => 'Other campaign', 'status' => 'paused']);
        $second->delete();

        $this->actingAs($owner, 'sanctum')->getJson('/api/admin/campaigns?search=CMP-'.$second->id.'&status=deleted&advertiser_id='.$second->advertiserAccount->user_id.'&per_page=25')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $second->id)->assertJsonPath('data.0.status', 'deleted');
        $this->getJson('/api/admin/campaigns?advertiser_id='.$first->advertiserAccount->user_id)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $first->id);
        $this->getJson('/api/admin/campaigns?status=paused')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_invalid_filters_are_rejected_and_literal_search_characters_are_not_wildcards(): void
    {
        $owner = $this->admin();
        $this->campaign();

        $this->actingAs($owner, 'sanctum')->getJson('/api/admin/campaigns?status=invalid&advertiser_id=bad&per_page=10000&page=-1')->assertUnprocessable()->assertJsonValidationErrors(['status', 'advertiser_id', 'per_page', 'page']);
        $this->getJson('/api/admin/campaigns?search=%25')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_advertiser_lookup_uses_user_identity_and_retains_deleted_advertisers(): void
    {
        $owner = $this->admin();
        $campaign = $this->campaign();
        $user = $campaign->advertiserAccount->user;
        $user->delete();

        $this->actingAs($owner, 'sanctum')->getJson('/api/admin/campaigns/advertisers?search=U-'.$user->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', $user->email);
        $this->getJson('/api/admin/campaigns/'.$campaign->id)->assertOk()->assertJsonPath('data.advertiser.status', 'deleted')->assertJsonPath('data.delivery_eligible', false);
    }

    public function test_lifetime_metrics_zero_fill_utc_chart_and_preserve_targeting_combinations(): void
    {
        $owner = $this->admin();
        $campaign = $this->campaign();
        $creative = $campaign->creatives()->firstOrFail();
        $delivery = AdDelivery::factory()->create(['campaign_id' => $campaign->id, 'creative_id' => $creative->id, 'requested_at' => '2026-10-25 11:55:00']);
        foreach ([['2026-10-23 23:59:00', 3000], ['2026-10-25 00:05:00', 5000]] as [$time, $amount]) {
            $raw = RawImpressionEvent::factory()->create(['ad_delivery_id' => $delivery->id, 'server_id' => $delivery->server_id, 'validation_status' => 'valid', 'validated_at' => $time]);
            ValidatedImpression::factory()->create(['campaign_id' => $campaign->id, 'advertiser_account_id' => $campaign->advertiser_account_id, 'creative_id' => $creative->id, 'raw_impression_event_id' => $raw->id, 'billable_micros' => $amount, 'validated_at' => $time]);
            AdvertiserLedgerEntry::factory()->create(['advertiser_account_id' => $campaign->advertiser_account_id, 'campaign_id' => $campaign->id, 'type' => 'spend', 'amount_micros' => -$amount, 'created_at' => $time]);
        }
        RawImpressionEvent::factory()->create(['ad_delivery_id' => $delivery->id, 'validation_status' => 'invalid', 'validated_at' => now()->subMinute()]);
        RawImpressionEvent::factory()->create(['ad_delivery_id' => $delivery->id, 'validation_status' => 'pending']);
        CampaignTarget::factory()->create(['campaign_id' => $campaign->id, 'country_code' => 'CA', 'language' => 'de', 'server_category' => 'skyblock']);

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/admin/campaigns/'.$campaign->id)->assertOk()
            ->assertJsonPath('data.metrics.reported_impressions', 4)->assertJsonPath('data.metrics.validated_impressions', 2)->assertJsonPath('data.metrics.invalid_impressions', 1)
            ->assertJsonPath('data.metrics.pending_impressions', 1)->assertJsonPath('data.metrics.spend_micros', 8000)->assertJsonPath('data.metrics.effective_cpm_micros', 4000000)
            ->assertJsonPath('data.metrics.invalid_rate', 33.33)->assertJsonPath('data.metrics.served_deliveries', 1)
            ->assertJsonPath('data.budget.spent_today_micros', 5000)->assertJsonPath('data.budget.lifetime_spend_micros', 8000)
            ->assertJsonCount(2, 'data.targeting_rules');
        $rules = collect($response->json('data.targeting_rules'))->keyBy('language');
        $this->assertSame(['US'], $rules['en']['countries']);
        $this->assertSame(['CA'], $rules['de']['countries']);
        $points = collect($response->json('data.performance.points'))->keyBy('date');
        $this->assertSame(0, $points['2026-10-24']['impressions']);
        $this->assertSame(1, $points['2026-10-25']['impressions']);
        $this->assertSame(8000, $points->sum('spend_micros'));
    }

    public function test_unlimited_budgets_and_no_delivery_return_zero_counts_with_null_ecpm(): void
    {
        $owner = $this->admin();
        $campaign = $this->campaign(['daily_budget_micros' => null, 'total_budget_micros' => null, 'starts_at' => null, 'ends_at' => null]);

        $this->actingAs($owner, 'sanctum')->getJson('/api/admin/campaigns/'.$campaign->id)->assertOk()->assertJsonPath('data.daily_budget_micros', null)
            ->assertJsonPath('data.budget.daily_remaining_micros', null)->assertJsonPath('data.budget.total_remaining_micros', null)->assertJsonPath('data.metrics.served_deliveries', 0)
            ->assertJsonPath('data.metrics.effective_cpm_micros', null)->assertJsonPath('data.delivery_eligible', true);
    }

    public function test_campaign_eligibility_explains_budget_schedule_and_account_restrictions(): void
    {
        $owner = $this->admin();
        $campaign = $this->campaign(['starts_at' => now()->addDay(), 'daily_budget_micros' => 0]);
        $campaign->advertiserAccount->update(['status' => 'suspended']);
        $campaign->creatives()->update(['status' => 'pending_review']);

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/admin/campaigns/'.$campaign->id)->assertOk()->assertJsonPath('data.delivery_eligible', false);

        $this->assertContains('Scheduled start has not been reached', $response->json('data.delivery_issues'));
        $this->assertContains('Daily budget exhausted', $response->json('data.delivery_issues'));
        $this->assertContains('No approved, unpaused creative', $response->json('data.delivery_issues'));
        $this->assertContains('Advertiser account suspended or unavailable', $response->json('data.delivery_issues'));
    }

    public function test_creative_inspection_is_paginated_and_retains_deleted_creative_content(): void
    {
        $support = $this->admin('support');
        $campaign = $this->campaign();
        $creative = AdCreative::factory()->create(['campaign_id' => $campaign->id, 'status' => 'rejected', 'rejection_reason' => 'Broken destination', 'message_text' => '<script>example</script>']);
        $creative->delete();

        $this->actingAs($support, 'sanctum')->getJson('/api/admin/campaigns/'.$campaign->id.'/creatives')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $creative->id)->assertJsonPath('data.0.status', 'deleted')
            ->assertJsonPath('data.0.rejection_reason', 'Broken destination')->assertJsonPath('data.0.message_text', '<script>example</script>');
    }

    public function test_soft_deletion_requires_confirmation_retains_history_and_invalidates_advertiser_caches(): void
    {
        $owner = $this->admin();
        $campaign = $this->campaign();
        $advertiser = $campaign->advertiserAccount->user;
        $creative = $campaign->creatives()->firstOrFail();
        $entry = AdvertiserLedgerEntry::factory()->create(['advertiser_account_id' => $campaign->advertiser_account_id, 'campaign_id' => $campaign->id, 'type' => 'spend', 'amount_micros' => -3000]);
        $this->actingAs($advertiser, 'sanctum')->getJson('/api/advertiser/campaigns')->assertOk()->assertJsonCount(1, 'data.campaigns');
        $this->getJson('/api/advertiser/creatives')->assertOk()->assertJsonCount(1, 'data.creatives');

        $this->actingAs($owner, 'sanctum')->deleteJson('/api/admin/campaigns/'.$campaign->id, ['name' => 'wrong name'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->deleteJson('/api/admin/campaigns/'.$campaign->id, ['name' => $campaign->name])->assertOk()->assertJsonPath('data.status', 'deleted')->assertJsonPath('data.can_delete', false);

        $this->assertSoftDeleted($campaign);
        $this->assertModelExists($creative);
        $this->assertModelExists($entry);
        $this->assertDatabaseHas('admin_campaign_events', ['campaign_id' => $campaign->id, 'actor_id' => $owner->id, 'action' => 'deleted']);
        $this->deleteJson('/api/admin/campaigns/'.$campaign->id, ['name' => $campaign->name])->assertConflict();
        $this->assertDatabaseCount('admin_campaign_events', 1);
        $this->actingAs($advertiser, 'sanctum')->getJson('/api/advertiser/campaigns')->assertOk()->assertJsonCount(0, 'data.campaigns');
        $this->getJson('/api/advertiser/creatives')->assertOk()->assertJsonCount(0, 'data.creatives');
        $this->getJson('/api/advertiser/campaigns/'.$campaign->id)->assertNotFound();
        $this->getJson('/api/advertiser/creatives/'.$creative->id)->assertNotFound();
    }

    public function test_ad_operations_can_delete_and_deleted_campaigns_keep_their_original_advertiser(): void
    {
        $admin = $this->admin('ad-ops');
        $campaign = $this->campaign();
        $originalUserId = $campaign->advertiserAccount->user_id;

        $this->actingAs($admin, 'sanctum')->deleteJson('/api/admin/campaigns/'.$campaign->id, ['name' => $campaign->name])->assertOk()
            ->assertJsonPath('data.advertiser.id', $originalUserId)->assertJsonPath('data.deleted_by.id', $admin->id)->assertJsonPath('data.history.0.actor_name', $admin->name);
    }

    public function test_historical_delivery_keeps_its_campaign_but_post_deletion_impressions_cannot_be_billed(): void
    {
        $campaign = $this->campaign();
        $delivery = AdDelivery::factory()->create(['campaign_id' => $campaign->id]);
        $campaign->delete();

        $this->assertSame($campaign->id, $delivery->fresh()->campaign->id);
        $budgets = app(CampaignBudgetService::class);
        $this->assertTrue($budgets->canBill($campaign, 2000, now()->subMinute()));
        $this->assertFalse($budgets->canBill($campaign, 2000, now()));
    }

    public function test_admin_has_no_campaign_creation_editing_or_status_change_routes(): void
    {
        $owner = $this->admin();
        $campaign = $this->campaign();

        $this->actingAs($owner, 'sanctum')->postJson('/api/admin/campaigns', ['name' => 'New campaign'])->assertMethodNotAllowed();
        $this->patchJson('/api/admin/campaigns/'.$campaign->id, ['name' => 'Changed'])->assertMethodNotAllowed();
        $this->postJson('/api/admin/campaigns/'.$campaign->id.'/status', ['status' => 'paused'])->assertNotFound();

        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'name' => $campaign->name, 'status' => 'active']);
    }

    private function admin(string $role = 'admin-owner'): User
    {
        $this->seed(AdminPermissionSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        if ($role !== 'unassigned') {
            $admin->assignRole($role);
        }

        return $admin;
    }

    private function campaign(array $overrides = []): Campaign
    {
        $account = AdvertiserAccount::factory()->create(['user_id' => User::factory()->create(['role' => 'advertiser'])->id]);
        $campaign = Campaign::factory()->create([...[
            'advertiser_account_id' => $account->id, 'name' => 'RealmCraft campaign', 'daily_budget_micros' => 10000000, 'total_budget_micros' => 100000000,
            'starts_at' => '2026-10-20 00:00:00', 'ends_at' => '2026-11-30 00:00:00', 'created_at' => '2026-10-20 00:00:00',
        ], ...$overrides]);
        AdCreative::factory()->create(['campaign_id' => $campaign->id, 'status' => 'approved', 'is_paused' => false, 'language' => 'en']);
        CampaignTarget::factory()->create(['campaign_id' => $campaign->id, 'country_code' => 'US', 'language' => 'en', 'server_category' => 'survival']);
        AdvertiserLedgerEntry::factory()->create(['advertiser_account_id' => $account->id, 'campaign_id' => null, 'type' => 'deposit', 'amount_micros' => 100000000]);

        return $campaign;
    }
}
