<?php

namespace Tests\Feature;

use App\Models\AdCreative;
use App\Models\AdvertiserAccount;
use App\Models\AdvertiserLedgerEntry;
use App\Models\Campaign;
use App\Models\PublisherAccount;
use App\Models\Server;
use App\Models\User;
use App\Models\ValidatedImpression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AdvertiserDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_advertiser_can_view_dashboard_aggregates(): void
    {
        $user = User::factory()->create(['role' => 'advertiser']);
        $advertiser = AdvertiserAccount::factory()->create([
            'user_id' => $user->id,
            'name' => 'RealmCraft',
        ]);
        $campaign = Campaign::factory()->create([
            'advertiser_account_id' => $advertiser->id,
            'name' => 'RealmCraft Launch',
            'status' => 'active',
            'daily_budget_micros' => 100000000,
        ]);
        $otherCampaign = Campaign::factory()->create([
            'advertiser_account_id' => $advertiser->id,
            'status' => 'paused',
        ]);
        $creative = AdCreative::factory()->create([
            'campaign_id' => $campaign->id,
            'status' => 'approved',
        ]);
        AdCreative::factory()->create([
            'campaign_id' => $campaign->id,
            'status' => 'pending_review',
        ]);
        AdCreative::factory()->create([
            'campaign_id' => $otherCampaign->id,
            'status' => 'approved',
        ]);

        AdvertiserLedgerEntry::factory()->create([
            'advertiser_account_id' => $advertiser->id,
            'campaign_id' => null,
            'type' => 'deposit',
            'amount_micros' => 100000000,
            'created_at' => now()->subDays(10),
        ]);
        AdvertiserLedgerEntry::factory()->create([
            'advertiser_account_id' => $advertiser->id,
            'campaign_id' => $campaign->id,
            'type' => 'spend',
            'amount_micros' => -5000000,
            'created_at' => now()->subHour(),
        ]);
        AdvertiserLedgerEntry::factory()->create([
            'advertiser_account_id' => $advertiser->id,
            'campaign_id' => $campaign->id,
            'type' => 'spend',
            'amount_micros' => -7000000,
            'created_at' => now()->subDays(2),
        ]);

        $publisher = PublisherAccount::factory()->create();
        $server = Server::factory()->create(['publisher_account_id' => $publisher->id]);

        ValidatedImpression::factory()->count(2)->create([
            'advertiser_account_id' => $advertiser->id,
            'publisher_account_id' => $publisher->id,
            'campaign_id' => $campaign->id,
            'creative_id' => $creative->id,
            'server_id' => $server->id,
            'validated_at' => now()->subMinutes(30),
        ]);
        ValidatedImpression::factory()->create([
            'advertiser_account_id' => $advertiser->id,
            'publisher_account_id' => $publisher->id,
            'campaign_id' => $campaign->id,
            'creative_id' => $creative->id,
            'server_id' => $server->id,
            'validated_at' => now()->subDays(2),
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/advertiser/dashboard')
            ->assertOk()
            ->assertJsonPath('data.account.name', 'RealmCraft')
            ->assertJsonPath('data.metrics.available_balance_micros', 88000000)
            ->assertJsonPath('data.metrics.active_campaigns', 1)
            ->assertJsonPath('data.metrics.active_creatives', 1)
            ->assertJsonPath('data.metrics.pending_creatives', 1)
            ->assertJsonPath('data.metrics.spend_today_micros', 5000000)
            ->assertJsonPath('data.metrics.spend_month_micros', 12000000)
            ->assertJsonPath('data.metrics.impressions_today', 2)
            ->assertJsonPath('data.metrics.impressions_month', 3)
            ->assertJsonPath('data.running_campaigns.0.name', 'RealmCraft Launch')
            ->assertJsonPath('data.running_campaigns.0.spend_micros', 12000000)
            ->assertJsonPath('data.running_campaigns.0.impressions', 3);
    }

    public function test_publisher_cannot_view_advertiser_dashboard(): void
    {
        $user = User::factory()->create(['role' => 'publisher']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/advertiser/dashboard')
            ->assertForbidden()
            ->assertJsonPath('message', 'This endpoint is available to advertisers only.');
    }

    public function test_guest_cannot_view_advertiser_dashboard(): void
    {
        $this->getJson('/api/advertiser/dashboard')
            ->assertUnauthorized();
    }
}
