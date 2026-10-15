<?php

namespace Tests\Feature;

use App\Models\AdCreative;
use App\Models\AdvertiserAccount;
use App\Models\AdvertiserLedgerEntry;
use App\Models\Campaign;
use App\Models\CampaignTarget;
use App\Models\User;
use App\Models\ValidatedImpression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdvertiserCampaignApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_creates_active_campaign_with_all_targeting_combinations_and_ignores_owner_override(): void
    {
        $account = $this->signInAdvertiser();
        $other = AdvertiserAccount::factory()->create();

        $response = $this->postJson('/api/advertiser/campaigns', [...$this->payload(), 'advertiser_account_id' => $other->id, 'status' => 'approved']);

        $response->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.daily_budget_micros', 80000000);
        $id = $response->json('data.id');
        $this->assertDatabaseHas('campaigns', ['id' => $id, 'advertiser_account_id' => $account->id, 'name' => 'Winter realms', 'status' => 'active', 'total_budget_micros' => 1200000000]);
        $this->assertDatabaseCount('campaign_targets', 8);
        $this->assertDatabaseHas('campaign_targets', ['campaign_id' => $id, 'country_code' => 'BR', 'language' => 'en', 'server_category' => 'skyblock']);
        $this->getJson('/api/advertiser/campaigns/'.$id)->assertOk()->assertJsonPath('data.delivery_eligible', false)->assertJsonFragment(['No approved creative']);
    }

    public function test_list_is_scoped_searchable_paginated_and_totals_are_not_multiplied_by_creatives(): void
    {
        $account = $this->signInAdvertiser();
        $campaign = Campaign::factory()->create(['advertiser_account_id' => $account->id, 'name' => 'Winter realms', 'status' => 'active']);
        Campaign::factory()->create(['advertiser_account_id' => $account->id, 'status' => 'paused']);
        Campaign::factory()->create(['name' => 'Winter foreign', 'status' => 'active']);
        AdCreative::factory()->count(2)->create(['campaign_id' => $campaign->id, 'status' => 'approved']);
        AdvertiserLedgerEntry::factory()->create(['advertiser_account_id' => $account->id, 'campaign_id' => $campaign->id, 'type' => 'spend', 'amount_micros' => -6000000]);
        ValidatedImpression::factory()->count(2)->create(['advertiser_account_id' => $account->id, 'campaign_id' => $campaign->id]);

        $this->getJson('/api/advertiser/campaigns?search=WINTER&status=active')->assertOk()
            ->assertJsonCount(1, 'data.campaigns')->assertJsonPath('data.campaigns.0.id', $campaign->id)
            ->assertJsonPath('data.campaigns.0.spend_micros', 6000000)->assertJsonPath('data.campaigns.0.impressions', 2)
            ->assertJsonPath('data.campaigns.0.effective_cpm_micros', 3000000000)
            ->assertJsonPath('data.campaigns.0.active_creatives_count', 2)->assertJsonPath('data.counts.all', 2);
        $this->getJson('/api/advertiser/campaigns?search=CMP-'.$campaign->id)->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/advertiser/campaigns?page=2')->assertOk()->assertJsonCount(0, 'data.campaigns');
    }

    public function test_details_use_ledger_spend_and_zero_fill_daily_chart_for_requested_period(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
        $account = $this->signInAdvertiser();
        $campaign = Campaign::factory()->create(['advertiser_account_id' => $account->id, 'status' => 'active', 'starts_at' => '2026-10-01', 'ends_at' => '2026-11-01', 'daily_budget_micros' => 10000000, 'total_budget_micros' => 100000000]);
        $creative = AdCreative::factory()->create(['campaign_id' => $campaign->id, 'status' => 'approved', 'language' => 'en']);
        CampaignTarget::factory()->create(['campaign_id' => $campaign->id, 'country_code' => 'US', 'language' => 'en', 'server_category' => 'survival']);
        AdvertiserLedgerEntry::factory()->create(['advertiser_account_id' => $account->id, 'campaign_id' => null, 'type' => 'deposit', 'amount_micros' => 150000000]);
        foreach (['2026-10-13 12:00:00', '2026-10-15 00:00:00'] as $date) {
            AdvertiserLedgerEntry::factory()->create(['advertiser_account_id' => $account->id, 'campaign_id' => $campaign->id, 'type' => 'spend', 'amount_micros' => -2000000, 'created_at' => $date]);
        }
        ValidatedImpression::factory()->create(['advertiser_account_id' => $account->id, 'campaign_id' => $campaign->id, 'creative_id' => $creative->id, 'validated_at' => '2026-10-15 00:00:00', 'billable_micros' => 2000000]);

        $this->getJson('/api/advertiser/campaigns/'.$campaign->id.'?from=2026-10-14&to=2026-10-15')->assertOk()
            ->assertJsonPath('data.metrics.spend_micros', 2000000)->assertJsonPath('data.metrics.spend_total_micros', 4000000)
            ->assertJsonPath('data.metrics.spend_today_micros', 2000000)->assertJsonPath('data.metrics.impressions', 1)
            ->assertJsonPath('data.spend_chart.0.spend_micros', 0)->assertJsonPath('data.spend_chart.1.spend_micros', 2000000)
            ->assertJsonPath('data.creatives.0.spend_micros', 2000000)->assertJsonPath('data.delivery_eligible', true);
    }

    public function test_changes_invalidate_cached_lists_and_details_and_ended_campaigns_cannot_resume(): void
    {
        $account = $this->signInAdvertiser();
        $campaign = Campaign::factory()->create(['advertiser_account_id' => $account->id, 'status' => 'active']);
        $this->getJson('/api/advertiser/campaigns')->assertOk()->assertJsonPath('data.campaigns.0.status', 'active');
        $this->getJson('/api/advertiser/campaigns/'.$campaign->id)->assertOk();

        $this->patchJson('/api/advertiser/campaigns/'.$campaign->id.'/status', ['status' => 'paused'])->assertOk()->assertJsonPath('data.status', 'paused');

        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'status' => 'paused']);
        $this->getJson('/api/advertiser/campaigns')->assertOk()->assertJsonPath('data.campaigns.0.status', 'paused');
        $this->getJson('/api/advertiser/campaigns/'.$campaign->id)->assertOk()->assertJsonPath('data.status', 'paused');
        $this->patchJson('/api/advertiser/campaigns/'.$campaign->id.'/status', ['status' => 'active'])->assertOk();
        $this->patchJson('/api/advertiser/campaigns/'.$campaign->id.'/status', ['status' => 'ended'])->assertOk();
        $this->patchJson('/api/advertiser/campaigns/'.$campaign->id.'/status', ['status' => 'active'])->assertConflict();
        $this->putJson('/api/advertiser/campaigns/'.$campaign->id, $this->payload())->assertConflict();
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'status' => 'ended']);
    }

    public function test_edit_replaces_targeting_and_keeps_campaign_status(): void
    {
        $account = $this->signInAdvertiser();
        $campaign = Campaign::factory()->create(['advertiser_account_id' => $account->id, 'status' => 'paused']);
        CampaignTarget::factory()->create(['campaign_id' => $campaign->id, 'country_code' => 'DE']);

        $this->putJson('/api/advertiser/campaigns/'.$campaign->id, $this->payload())->assertOk()->assertJsonPath('data.status', 'paused');

        $this->assertDatabaseMissing('campaign_targets', ['campaign_id' => $campaign->id, 'country_code' => 'DE']);
        $this->assertDatabaseCount('campaign_targets', 8);
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'name' => 'Winter realms']);
    }

    public function test_other_advertisers_cannot_read_edit_or_change_campaign_status(): void
    {
        $this->signInAdvertiser();
        $campaign = Campaign::factory()->create(['name' => 'Private campaign', 'status' => 'active']);

        $this->getJson('/api/advertiser/campaigns/'.$campaign->id)->assertNotFound();
        $this->putJson('/api/advertiser/campaigns/'.$campaign->id, $this->payload())->assertNotFound();
        $this->patchJson('/api/advertiser/campaigns/'.$campaign->id.'/status', ['status' => 'ended'])->assertNotFound();

        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'name' => 'Private campaign', 'status' => 'active']);
    }

    #[TestWith(['daily_budget_micros', -1, 'daily_budget_micros'])]
    #[TestWith(['total_budget_micros', 10000, 'total_budget_micros'])]
    #[TestWith(['countries', ['XX'], 'countries.0'])]
    #[TestWith(['languages', [], 'languages'])]
    #[TestWith(['server_categories', ['unknown'], 'server_categories.0'])]
    #[TestWith(['ends_at', '2026-01-01T00:00:00Z', 'ends_at'])]
    public function test_invalid_fields_return_422_without_creating_records(string $field, mixed $value, string $error): void
    {
        $this->signInAdvertiser();

        $this->postJson('/api/advertiser/campaigns', [...$this->payload(), $field => $value])->assertUnprocessable()->assertJsonValidationErrors($error);

        $this->assertDatabaseCount('campaigns', 0);
        $this->assertDatabaseCount('campaign_targets', 0);
    }

    public function test_guests_and_publishers_cannot_access_campaigns(): void
    {
        $this->getJson('/api/advertiser/campaigns')->assertUnauthorized();
        $this->postJson('/api/advertiser/campaigns', $this->payload())->assertUnauthorized();
        $publisher = User::factory()->create(['role' => 'publisher']);

        $this->actingAs($publisher, 'sanctum')->getJson('/api/advertiser/campaigns')->assertForbidden();
        $this->postJson('/api/advertiser/campaigns', $this->payload())->assertForbidden();
    }

    public function test_suspended_accounts_can_inspect_but_cannot_create_campaigns(): void
    {
        $account = $this->signInAdvertiser();
        $account->update(['status' => 'suspended']);

        $this->getJson('/api/advertiser/campaigns')->assertOk();
        $this->postJson('/api/advertiser/campaigns', $this->payload())->assertForbidden();

        $this->assertDatabaseCount('campaigns', 0);
    }

    public function test_rejects_reversed_and_unbounded_reporting_ranges(): void
    {
        $account = $this->signInAdvertiser();
        $campaign = Campaign::factory()->create(['advertiser_account_id' => $account->id]);

        $this->getJson('/api/advertiser/campaigns/'.$campaign->id.'?from=2026-10-15&to=2026-10-01')->assertUnprocessable();
        $this->getJson('/api/advertiser/campaigns/'.$campaign->id.'?from=2020-01-01&to=2026-10-01')->assertUnprocessable();
    }

    public function test_budget_only_edits_preserve_existing_correlated_targeting(): void
    {
        $account = $this->signInAdvertiser();
        $campaign = Campaign::factory()->create(['advertiser_account_id' => $account->id]);
        $first = CampaignTarget::factory()->create(['campaign_id' => $campaign->id, 'country_code' => 'US', 'language' => 'en', 'server_category' => 'survival']);
        $second = CampaignTarget::factory()->create(['campaign_id' => $campaign->id, 'country_code' => 'BR', 'language' => 'pt', 'server_category' => 'skyblock']);

        $this->putJson('/api/advertiser/campaigns/'.$campaign->id, $this->payload())->assertOk();

        $this->assertDatabaseCount('campaign_targets', 2);
        $this->assertModelExists($first);
        $this->assertModelExists($second);
        $this->assertDatabaseMissing('campaign_targets', ['campaign_id' => $campaign->id, 'country_code' => 'US', 'language' => 'pt']);
    }

    public function test_chart_returns_daily_impressions_and_effective_cpm_without_averaging_daily_rates(): void
    {
        $account = $this->signInAdvertiser();
        $campaign = Campaign::factory()->create(['advertiser_account_id' => $account->id]);
        foreach ([['2026-10-14 12:00:00', 3000, 3], ['2026-10-15 12:00:00', 8000, 2]] as [$date, $spend, $count]) {
            AdvertiserLedgerEntry::factory()->create(['advertiser_account_id' => $account->id, 'campaign_id' => $campaign->id, 'type' => 'spend', 'amount_micros' => -$spend, 'created_at' => $date]);
            ValidatedImpression::factory()->count($count)->create(['advertiser_account_id' => $account->id, 'campaign_id' => $campaign->id, 'validated_at' => $date]);
        }

        $this->getJson('/api/advertiser/campaigns/'.$campaign->id.'?from=2026-10-13&to=2026-10-15')->assertOk()
            ->assertJsonPath('data.spend_chart.0.impressions', 0)
            ->assertJsonPath('data.spend_chart.0.effective_cpm_micros', 0)
            ->assertJsonPath('data.spend_chart.1.impressions', 3)
            ->assertJsonPath('data.spend_chart.1.effective_cpm_micros', 1000000)
            ->assertJsonPath('data.spend_chart.2.impressions', 2)
            ->assertJsonPath('data.spend_chart.2.effective_cpm_micros', 4000000)
            ->assertJsonPath('data.metrics.impressions', 5)
            ->assertJsonPath('data.metrics.effective_cpm_micros', 2200000);
    }

    private function signInAdvertiser(): AdvertiserAccount
    {
        $user = User::factory()->create(['role' => 'advertiser']);
        $account = AdvertiserAccount::factory()->create(['user_id' => $user->id, 'status' => 'active']);
        $this->actingAs($user, 'sanctum');

        return $account;
    }

    private function payload(): array
    {
        return ['name' => 'Winter realms', 'starts_at' => '2026-11-01T00:00:00Z', 'ends_at' => '2026-11-30T23:59:00Z',
            'daily_budget_micros' => 80000000, 'total_budget_micros' => 1200000000,
            'countries' => ['US', 'BR'], 'languages' => ['en', 'pt'], 'server_categories' => ['survival', 'skyblock']];
    }
}
