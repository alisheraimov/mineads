<?php

namespace Tests\Feature;

use App\Models\AdCreative;
use App\Models\AdvertiserAccount;
use App\Models\AdvertiserLedgerEntry;
use App\Models\Campaign;
use App\Models\CampaignTarget;
use App\Models\RateTier;
use App\Models\Server;
use App\Models\ServerApiToken;
use App\Models\User;
use App\Models\ValidatedImpression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdvertiserCreativeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_creative_is_pending_review_and_ignores_review_overrides(): void
    {
        $campaign = $this->campaign();
        $response = $this->postJson('/api/advertiser/creatives', [...$this->payload(), 'campaign_id' => $campaign->id, 'status' => 'approved', 'is_paused' => true, 'rejection_reason' => 'Fake']);
        $response->assertCreated()->assertJsonPath('data.status', 'pending_review')->assertJsonPath('data.is_paused', false);
        $this->assertDatabaseHas('ad_creatives', ['id' => $response->json('data.id'), 'campaign_id' => $campaign->id, 'type' => 'chat', 'rejection_reason' => null]);
    }

    public function test_rename_preserves_approval_but_content_edits_require_review_and_clear_rejection(): void
    {
        $creative = AdCreative::factory()->create(['campaign_id' => $this->campaign()->id, ...$this->payload(), 'status' => 'approved']);
        $this->putJson('/api/advertiser/creatives/'.$creative->id, [...$this->payload(), 'name' => 'New label'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('ad_creatives', ['id' => $creative->id, 'name' => 'New label', 'status' => 'approved']);
        $creative->update(['status' => 'rejected', 'rejection_reason' => 'Bad destination']);
        $this->putJson('/api/advertiser/creatives/'.$creative->id, [...$this->payload(), 'click_url' => 'https://example.com/new'])->assertOk()->assertJsonPath('data.status', 'pending_review')->assertJsonPath('data.rejection_reason', null);
        $this->assertDatabaseHas('ad_creatives', ['id' => $creative->id, 'status' => 'pending_review', 'rejection_reason' => null]);
    }

    #[TestWith(['message_text', 'Updated message'])]
    #[TestWith(['language', 'pt'])]
    public function test_message_and_language_edits_reset_approval(string $field, string $value): void
    {
        $creative = AdCreative::factory()->create(['campaign_id' => $this->campaign()->id, ...$this->payload(), 'status' => 'approved']);
        $this->putJson('/api/advertiser/creatives/'.$creative->id, [...$this->payload(), $field => $value])->assertOk()->assertJsonPath('data.status', 'pending_review');
        $this->assertDatabaseHas('ad_creatives', ['id' => $creative->id, $field => $value, 'status' => 'pending_review']);
    }

    public function test_pause_preserves_review_status_and_invalidates_portal_caches(): void
    {
        $campaign = $this->campaign();
        $creative = AdCreative::factory()->create(['campaign_id' => $campaign->id, 'status' => 'approved']);
        $this->getJson('/api/advertiser/creatives')->assertOk()->assertJsonPath('data.counts.approved', 1);
        $this->getJson('/api/advertiser/campaigns')->assertOk()->assertJsonPath('data.campaigns.0.active_creatives_count', 1);
        $this->getJson('/api/advertiser/creatives/'.$creative->id)->assertOk();
        $this->patchJson('/api/advertiser/creatives/'.$creative->id.'/pause', ['is_paused' => true])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.is_paused', true);
        $this->getJson('/api/advertiser/creatives/'.$creative->id)->assertOk()->assertJsonPath('data.is_paused', true);
        $this->getJson('/api/advertiser/creatives?status=paused')->assertOk()->assertJsonPath('data.counts.paused', 1)->assertJsonCount(1, 'data.creatives');
        $this->getJson('/api/advertiser/campaigns')->assertOk()->assertJsonPath('data.campaigns.0.active_creatives_count', 0);
        $this->getJson('/api/advertiser/dashboard')->assertOk()->assertJsonPath('data.metrics.active_creatives', 0);
        $this->patchJson('/api/advertiser/creatives/'.$creative->id.'/pause', ['is_paused' => false])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.is_paused', false);
        $this->assertDatabaseHas('ad_creatives', ['id' => $creative->id, 'is_paused' => false, 'status' => 'approved']);
    }

    public function test_soft_delete_preserves_impressions_and_removes_creative_from_portal(): void
    {
        $campaign = $this->campaign();
        $creative = AdCreative::factory()->create(['campaign_id' => $campaign->id]);
        $impression = ValidatedImpression::factory()->create(['advertiser_account_id' => $campaign->advertiser_account_id, 'campaign_id' => $campaign->id, 'creative_id' => $creative->id]);
        $this->getJson('/api/advertiser/creatives')->assertOk();
        $this->deleteJson('/api/advertiser/creatives/'.$creative->id)->assertOk();
        $this->assertSoftDeleted('ad_creatives', ['id' => $creative->id]);
        $this->assertDatabaseHas('validated_impressions', ['id' => $impression->id]);
        $this->getJson('/api/advertiser/creatives/'.$creative->id)->assertNotFound();
        $this->getJson('/api/advertiser/creatives')->assertOk()->assertJsonPath('data.counts.all', 0);
    }

    public function test_all_record_endpoints_and_campaign_selection_are_tenant_scoped(): void
    {
        $this->campaign();
        $foreign = AdCreative::factory()->create();
        $path = '/api/advertiser/creatives/'.$foreign->id;
        $this->getJson($path)->assertNotFound();
        $this->putJson($path, $this->payload())->assertNotFound();
        $this->patchJson($path.'/pause', ['is_paused' => true])->assertNotFound();
        $this->deleteJson($path)->assertNotFound();
        $this->postJson('/api/advertiser/creatives', [...$this->payload(), 'campaign_id' => $foreign->campaign_id])->assertNotFound();
        $this->getJson('/api/advertiser/creatives')->assertOk()->assertJsonCount(0, 'data.creatives');
        $this->getJson('/api/advertiser/creatives/options')->assertOk()->assertJsonCount(1, 'data.campaigns');
    }

    public function test_campaign_is_immutable_and_ended_campaign_rejects_creation(): void
    {
        $campaign = $this->campaign();
        $creative = AdCreative::factory()->create(['campaign_id' => $campaign->id]);
        $this->putJson('/api/advertiser/creatives/'.$creative->id, [...$this->payload(), 'campaign_id' => $campaign->id])->assertUnprocessable()->assertJsonValidationErrors('campaign_id');
        $campaign->update(['status' => 'ended']);
        $this->postJson('/api/advertiser/creatives', [...$this->payload(), 'campaign_id' => $campaign->id])->assertConflict();
        $this->getJson('/api/advertiser/creatives/options')->assertOk()->assertJsonCount(0, 'data.campaigns');
    }

    #[TestWith(['message_text', ''])]
    #[TestWith(['name', ''])]
    #[TestWith(['language', 'unknown'])]
    #[TestWith(['click_url', 'javascript:alert(1)'])]
    public function test_invalid_fields_are_rejected(string $field, string $value): void
    {
        $campaign = $this->campaign();
        $this->postJson('/api/advertiser/creatives', [...$this->payload(), 'campaign_id' => $campaign->id, $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_message_limit_accepts_512_unicode_characters_and_rejects_513(): void
    {
        $campaign = $this->campaign();
        $this->postJson('/api/advertiser/creatives', [...$this->payload(), 'campaign_id' => $campaign->id, 'message_text' => str_repeat('é', 512)])->assertCreated();
        $this->postJson('/api/advertiser/creatives', [...$this->payload(), 'campaign_id' => $campaign->id, 'message_text' => str_repeat('é', 513)])->assertUnprocessable()->assertJsonValidationErrors('message_text');
    }

    public function test_metrics_are_date_scoped_zero_filled_weighted_and_not_multiplied_by_joins(): void
    {
        $campaign = $this->campaign();
        $creative = AdCreative::factory()->create(['campaign_id' => $campaign->id, 'name' => 'Winter invitation']);
        foreach ([['2026-10-14 12:00:00', 1000], ['2026-10-15 12:00:00', 4000], ['2026-10-15 13:00:00', 4000], ['2026-10-01 12:00:00', 9000]] as [$date, $spend]) {
            ValidatedImpression::factory()->create(['advertiser_account_id' => $campaign->advertiser_account_id, 'campaign_id' => $campaign->id, 'creative_id' => $creative->id, 'validated_at' => $date, 'billable_micros' => $spend]);
        }
        $this->getJson('/api/advertiser/creatives/'.$creative->id.'?from=2026-10-13&to=2026-10-15')->assertOk()
            ->assertJsonPath('data.metrics.impressions', 3)->assertJsonPath('data.metrics.spend_micros', 9000)->assertJsonPath('data.metrics.effective_cpm_micros', 3000000)
            ->assertJsonPath('data.spend_chart.0.impressions', 0)->assertJsonPath('data.spend_chart.2.effective_cpm_micros', 4000000);
        $this->getJson('/api/advertiser/creatives?search=WINTER')->assertOk()->assertJsonCount(1, 'data.creatives')->assertJsonPath('data.creatives.0.spend_micros', 18000);
        $this->getJson('/api/advertiser/creatives?search=%25')->assertOk()->assertJsonCount(0, 'data.creatives');
        $this->getJson('/api/advertiser/creatives/'.$creative->id.'?from=2025-01-01&to=2026-10-15')->assertUnprocessable();
        $this->getJson('/api/advertiser/creatives/'.$creative->id.'?from=2026-10-15&to=2026-10-01')->assertUnprocessable();
    }

    public function test_breakdowns_remain_arrays_when_cached_classes_are_blocked(): void
    {
        $campaign = $this->campaign();
        $creative = AdCreative::factory()->create(['campaign_id' => $campaign->id]);
        ValidatedImpression::factory()->create(['advertiser_account_id' => $campaign->advertiser_account_id, 'campaign_id' => $campaign->id, 'creative_id' => $creative->id, 'validated_at' => '2026-10-15 12:00:00', 'billable_micros' => 2000]);
        Cache::shouldReceive('get')->andReturn('initial');
        Cache::shouldReceive('remember')->once()->andReturnUsing(function (string $key, int $ttl, callable $callback): array {
            return unserialize(serialize($callback()), ['allowed_classes' => false]);
        });

        $response = $this->getJson('/api/advertiser/creatives/'.$creative->id.'?from=2026-10-15&to=2026-10-15')->assertOk();

        foreach (['countries', 'server_categories'] as $field) {
            $rows = $response->json('data.'.$field);
            $this->assertIsArray($rows);
            $this->assertTrue(array_is_list($rows));
            $this->assertCount(1, $rows);
            $this->assertSame(1, $rows[0]['impressions']);
            $this->assertSame(2000, $rows[0]['spend_micros']);
        }
    }

    public function test_unauthenticated_and_non_advertiser_users_are_denied(): void
    {
        $this->getJson('/api/advertiser/creatives')->assertUnauthorized();
        foreach (['publisher', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');
            $this->getJson('/api/advertiser/creatives')->assertForbidden();
            $this->postJson('/api/advertiser/creatives', [])->assertForbidden();
        }
    }

    public function test_suspended_account_can_read_but_cannot_mutate(): void
    {
        $campaign = $this->campaign();
        $creative = AdCreative::factory()->create(['campaign_id' => $campaign->id]);
        $campaign->advertiserAccount->update(['status' => 'suspended']);
        $this->getJson('/api/advertiser/creatives')->assertOk();
        $this->postJson('/api/advertiser/creatives', [...$this->payload(), 'campaign_id' => $campaign->id])->assertForbidden();
        $this->putJson('/api/advertiser/creatives/'.$creative->id, $this->payload())->assertForbidden();
        $this->patchJson('/api/advertiser/creatives/'.$creative->id.'/pause', ['is_paused' => true])->assertForbidden();
        $this->deleteJson('/api/advertiser/creatives/'.$creative->id)->assertForbidden();
    }

    public function test_serving_skips_paused_and_deleted_creatives_and_resumes_approved_creative(): void
    {
        $campaign = $this->campaign();
        $campaign->update(['starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        AdvertiserLedgerEntry::factory()->create(['advertiser_account_id' => $campaign->advertiser_account_id, 'campaign_id' => null, 'type' => 'deposit', 'amount_micros' => 100000000]);
        CampaignTarget::factory()->create(['campaign_id' => $campaign->id, 'country_code' => 'US', 'language' => 'en', 'server_category' => 'survival']);
        RateTier::factory()->create(['is_default' => true]);
        $server = Server::factory()->create(['category' => 'survival', 'status' => 'active']);
        ServerApiToken::factory()->create(['server_id' => $server->id, 'token_hash' => hash('sha256', 'creative-serving-test')]);
        $paused = AdCreative::factory()->create(['campaign_id' => $campaign->id, 'language' => 'en', 'status' => 'approved', 'is_paused' => true]);
        $available = AdCreative::factory()->create(['campaign_id' => $campaign->id, 'language' => 'en', 'status' => 'approved']);
        $payload = ['placement' => 'chat', 'audiences' => [['audience_id' => 'test', 'country' => 'US', 'language' => 'en', 'eligible_player_count' => 1]]];
        $headers = ['Authorization' => 'Bearer creative-serving-test'];
        $this->postJson('/api/plugin/v1/ads/request', $payload, $headers)->assertOk()->assertJsonPath('decisions.0.creative_id', $available->id);
        $this->deleteJson('/api/advertiser/creatives/'.$available->id)->assertOk();
        $this->postJson('/api/plugin/v1/ads/request', $payload, $headers)->assertOk()->assertJsonPath('decisions.0.decision', 'no_fill');
        $this->patchJson('/api/advertiser/creatives/'.$paused->id.'/pause', ['is_paused' => false])->assertOk();
        $this->postJson('/api/plugin/v1/ads/request', $payload, $headers)->assertOk()->assertJsonPath('decisions.0.creative_id', $paused->id);
    }

    private function campaign(): Campaign
    {
        Cache::flush();
        $user = User::factory()->create(['role' => 'advertiser']);
        $account = AdvertiserAccount::factory()->create(['user_id' => $user->id, 'status' => 'active']);
        $this->actingAs($user, 'sanctum');

        return Campaign::factory()->create(['advertiser_account_id' => $account->id, 'status' => 'active']);
    }

    private function payload(): array
    {
        return ['name' => 'Survival invitation', 'language' => 'en', 'message_text' => 'Join our survival world!', 'click_url' => 'https://example.com/join'];
    }
}
