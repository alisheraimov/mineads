<?php

namespace Tests\Feature;

use App\Models\AdCreative;
use App\Models\AdDelivery;
use App\Models\AdvertiserAccount;
use App\Models\AdvertiserLedgerEntry;
use App\Models\Campaign;
use App\Models\CampaignTarget;
use App\Models\PlayerSession;
use App\Models\PublisherAccount;
use App\Models\RateTier;
use App\Models\RateTierCountry;
use App\Models\RawImpressionEvent;
use App\Models\Server;
use App\Models\ServerHeartbeat;
use App\Models\User;
use App\Services\ImpressionValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PluginApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_plugin_can_activate_heartbeat_request_ads_and_report_impressions(): void
    {
        $publisherUser = User::factory()->create(['role' => 'publisher']);
        $publisher = PublisherAccount::factory()->create(['user_id' => $publisherUser->id]);

        $setupToken = 'setup-token-for-test';
        $server = Server::factory()->create([
            'publisher_account_id' => $publisher->id,
            'status' => 'pending',
            'category' => 'survival',
            'setup_token_hash' => Hash::make($setupToken),
            'setup_token_expires_at' => now()->addHour(),
            'activated_at' => null,
        ]);

        $advertiserUser = User::factory()->create(['role' => 'advertiser']);
        $advertiser = AdvertiserAccount::factory()->create(['user_id' => $advertiserUser->id]);
        $campaign = Campaign::factory()->create([
            'advertiser_account_id' => $advertiser->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);
        AdvertiserLedgerEntry::factory()->create([
            'advertiser_account_id' => $advertiser->id,
            'campaign_id' => null,
            'type' => 'deposit',
            'amount_micros' => 10000000,
        ]);
        $creative = AdCreative::factory()->create([
            'campaign_id' => $campaign->id,
            'language' => 'en',
            'status' => 'approved',
            'message_text' => '[Sponsored] Test campaign',
        ]);
        CampaignTarget::factory()->create([
            'campaign_id' => $campaign->id,
            'country_code' => 'US',
            'language' => 'en',
            'server_category' => 'survival',
        ]);
        $rateTier = RateTier::factory()->create([
            'name' => 'Tier 1',
            'cpm_micros' => 3000000,
            'is_default' => false,
            'sort_order' => 10,
        ]);
        RateTierCountry::factory()->create([
            'rate_tier_id' => $rateTier->id,
            'country_code' => 'US',
        ]);
        RateTier::factory()->create([
            'name' => 'Rest of World',
            'cpm_micros' => 750000,
            'is_default' => true,
            'sort_order' => 99,
        ]);

        $activation = $this->postJson('/api/plugin/v1/activate', [
            'setup_token' => $setupToken,
        ]);

        $activation->assertCreated()
            ->assertJsonPath('server_id', $server->id)
            ->assertJsonPath('config.enabled', true);

        $apiToken = $activation->json('api_token');

        $this->postJson('/api/plugin/v1/heartbeat', [
            'plugin_version' => '0.1.0',
            'minecraft_version' => '1.21.1',
            'platform' => 'paper',
            'online_players' => 12,
            'max_players' => 100,
            'tps' => 19.9,
        ], [
            'Authorization' => 'Bearer '.$apiToken,
        ])->assertOk()
            ->assertJsonPath('server_status', 'active');

        $adRequest = $this->postJson('/api/plugin/v1/ads/request', [
            'placement' => 'chat',
            'request_id' => 'req_test',
            'audiences' => [
                [
                    'audience_id' => 'aud_us_en',
                    'country' => 'US',
                    'language' => 'en',
                    'eligible_player_count' => 2,
                    'players' => [
                        ['player_session_id' => 'ps_one', 'player_hash' => hash('sha256', 'player-one')],
                        ['player_session_id' => 'ps_two', 'player_hash' => hash('sha256', 'player-two')],
                    ],
                ],
            ],
        ], [
            'Authorization' => 'Bearer '.$apiToken,
        ]);

        $adRequest->assertOk()
            ->assertJsonPath('decisions.0.decision', 'serve')
            ->assertJsonPath('decisions.0.campaign_id', $campaign->id)
            ->assertJsonPath('decisions.0.creative_id', $creative->id);

        $deliveryId = $adRequest->json('decisions.0.ad_delivery_id');
        $impressionToken = $adRequest->json('decisions.0.tracking.impression_token');

        $this->postJson('/api/plugin/v1/impressions', [
            'ad_delivery_id' => $deliveryId,
            'impression_token' => $impressionToken,
            'displayed_at' => now()->toISOString(),
            'players' => [
                ['player_session_id' => 'ps_one', 'player_hash' => hash('sha256', 'player-one')],
                ['player_session_id' => 'ps_two', 'player_hash' => hash('sha256', 'player-two')],
            ],
        ], [
            'Authorization' => 'Bearer '.$apiToken,
        ])->assertOk()
            ->assertJsonPath('accepted', 2)
            ->assertJsonPath('duplicates', 0)
            ->assertJsonPath('status', 'queued');

        $this->validatePendingImpressions();

        $this->assertDatabaseCount('raw_impression_events', 2);
        $this->assertDatabaseCount('validated_impressions', 2);
        $this->assertSame(2, AdvertiserLedgerEntry::query()->where('type', 'spend')->count());
        $this->assertDatabaseCount('publisher_ledger_entries', 2);
        $this->assertDatabaseHas('raw_impression_events', [
            'validation_status' => 'valid',
            'invalid_reason' => null,
            'risk_score' => 0,
        ]);
        $this->assertDatabaseHas('ad_deliveries', [
            'id' => $deliveryId,
            'rate_tier_id' => $rateTier->id,
            'cpm_micros' => 3000000,
        ]);
        $this->assertDatabaseHas('validated_impressions', [
            'billable_micros' => 3000,
            'publisher_earning_micros' => 2100,
            'platform_fee_micros' => 900,
        ]);
    }

    public function test_impressions_without_recent_heartbeat_are_not_billed(): void
    {
        $publisherUser = User::factory()->create(['role' => 'publisher']);
        $publisher = PublisherAccount::factory()->create(['user_id' => $publisherUser->id]);

        $setupToken = 'setup-token-without-heartbeat';
        $server = Server::factory()->create([
            'publisher_account_id' => $publisher->id,
            'status' => 'pending',
            'category' => 'survival',
            'setup_token_hash' => Hash::make($setupToken),
            'setup_token_expires_at' => now()->addHour(),
            'activated_at' => null,
        ]);

        $advertiserUser = User::factory()->create(['role' => 'advertiser']);
        $advertiser = AdvertiserAccount::factory()->create(['user_id' => $advertiserUser->id]);
        $campaign = Campaign::factory()->create([
            'advertiser_account_id' => $advertiser->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);
        AdvertiserLedgerEntry::factory()->create([
            'advertiser_account_id' => $advertiser->id,
            'campaign_id' => null,
            'type' => 'deposit',
            'amount_micros' => 10000000,
        ]);
        AdCreative::factory()->create([
            'campaign_id' => $campaign->id,
            'language' => 'en',
            'status' => 'approved',
        ]);
        CampaignTarget::factory()->create([
            'campaign_id' => $campaign->id,
            'country_code' => 'US',
            'language' => 'en',
            'server_category' => 'survival',
        ]);
        $rateTier = RateTier::factory()->create([
            'name' => 'Tier 1',
            'cpm_micros' => 3000000,
            'is_default' => true,
        ]);
        RateTierCountry::factory()->create([
            'rate_tier_id' => $rateTier->id,
            'country_code' => 'US',
        ]);

        $activation = $this->postJson('/api/plugin/v1/activate', [
            'setup_token' => $setupToken,
        ]);

        $apiToken = $activation->json('api_token');

        $adRequest = $this->postJson('/api/plugin/v1/ads/request', [
            'placement' => 'chat',
            'audiences' => [
                [
                    'audience_id' => 'aud_us_en',
                    'country' => 'US',
                    'language' => 'en',
                    'eligible_player_count' => 1,
                ],
            ],
        ], [
            'Authorization' => 'Bearer '.$apiToken,
        ]);

        $deliveryId = $adRequest->json('decisions.0.ad_delivery_id');
        $impressionToken = $adRequest->json('decisions.0.tracking.impression_token');

        $this->postJson('/api/plugin/v1/impressions', [
            'ad_delivery_id' => $deliveryId,
            'impression_token' => $impressionToken,
            'displayed_at' => now()->toISOString(),
            'players' => [
                ['player_hash' => hash('sha256', 'player-without-heartbeat')],
            ],
        ], [
            'Authorization' => 'Bearer '.$apiToken,
        ])->assertOk()
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('status', 'queued');

        $this->validatePendingImpressions();

        $this->assertDatabaseHas('raw_impression_events', [
            'server_id' => $server->id,
            'validation_status' => 'invalid',
            'invalid_reason' => 'missing_recent_heartbeat',
            'risk_score' => 100,
        ]);
        $this->assertDatabaseCount('validated_impressions', 0);
        $this->assertSame(0, AdvertiserLedgerEntry::query()->where('type', 'spend')->count());
        $this->assertDatabaseCount('publisher_ledger_entries', 0);
    }

    public function test_ad_request_returns_no_fill_when_advertiser_has_no_balance(): void
    {
        [$apiToken] = $this->activatedServer();
        $advertiser = AdvertiserAccount::factory()->create(['user_id' => User::factory()->create(['role' => 'advertiser'])->id]);
        $campaign = $this->targetedCampaign($advertiser);
        $this->approvedCreative($campaign);
        $this->usRateTier();

        $this->postJson('/api/plugin/v1/ads/request', [
            'placement' => 'chat',
            'audiences' => [
                [
                    'audience_id' => 'aud_us_en',
                    'country' => 'US',
                    'language' => 'en',
                    'eligible_player_count' => 1,
                ],
            ],
        ], [
            'Authorization' => 'Bearer '.$apiToken,
        ])->assertOk()
            ->assertJsonPath('decisions.0.decision', 'no_fill');

        $this->assertDatabaseHas('ad_deliveries', [
            'campaign_id' => null,
            'decision' => 'no_fill',
        ]);
    }

    public function test_ad_request_returns_no_fill_when_daily_budget_is_too_low(): void
    {
        [$apiToken] = $this->activatedServer();
        $advertiser = AdvertiserAccount::factory()->create(['user_id' => User::factory()->create(['role' => 'advertiser'])->id]);
        $campaign = $this->targetedCampaign($advertiser, [
            'daily_budget_micros' => 2999,
            'total_budget_micros' => 10000000,
        ]);
        $this->approvedCreative($campaign);
        $this->usRateTier();
        AdvertiserLedgerEntry::factory()->create([
            'advertiser_account_id' => $advertiser->id,
            'campaign_id' => null,
            'type' => 'deposit',
            'amount_micros' => 10000000,
        ]);

        $this->postJson('/api/plugin/v1/ads/request', [
            'placement' => 'chat',
            'audiences' => [
                [
                    'audience_id' => 'aud_us_en',
                    'country' => 'US',
                    'language' => 'en',
                    'eligible_player_count' => 1,
                ],
            ],
        ], [
            'Authorization' => 'Bearer '.$apiToken,
        ])->assertOk()
            ->assertJsonPath('decisions.0.decision', 'no_fill');
    }

    public function test_validation_rejects_impression_when_budget_is_spent_after_delivery(): void
    {
        [$apiToken, $server] = $this->activatedServer(sendHeartbeat: true);
        $advertiser = AdvertiserAccount::factory()->create(['user_id' => User::factory()->create(['role' => 'advertiser'])->id]);
        $campaign = $this->targetedCampaign($advertiser, [
            'daily_budget_micros' => 3000,
            'total_budget_micros' => 3000,
        ]);
        $this->approvedCreative($campaign);
        $this->usRateTier();
        AdvertiserLedgerEntry::factory()->create([
            'advertiser_account_id' => $advertiser->id,
            'campaign_id' => null,
            'type' => 'deposit',
            'amount_micros' => 3000,
        ]);

        $adRequest = $this->postJson('/api/plugin/v1/ads/request', [
            'placement' => 'chat',
            'audiences' => [
                [
                    'audience_id' => 'aud_us_en',
                    'country' => 'US',
                    'language' => 'en',
                    'eligible_player_count' => 1,
                ],
            ],
        ], [
            'Authorization' => 'Bearer '.$apiToken,
        ]);

        $adRequest->assertOk()
            ->assertJsonPath('decisions.0.decision', 'serve');

        AdvertiserLedgerEntry::factory()->create([
            'advertiser_account_id' => $advertiser->id,
            'campaign_id' => $campaign->id,
            'type' => 'spend',
            'amount_micros' => -3000,
        ]);

        $this->postJson('/api/plugin/v1/impressions', [
            'ad_delivery_id' => $adRequest->json('decisions.0.ad_delivery_id'),
            'impression_token' => $adRequest->json('decisions.0.tracking.impression_token'),
            'displayed_at' => now()->toISOString(),
            'players' => [
                ['player_hash' => hash('sha256', 'player-after-budget-spent')],
            ],
        ], [
            'Authorization' => 'Bearer '.$apiToken,
        ])->assertOk()
            ->assertJsonPath('accepted', 1);

        $this->validatePendingImpressions();

        $this->assertDatabaseHas('raw_impression_events', [
            'server_id' => $server->id,
            'validation_status' => 'invalid',
            'invalid_reason' => 'insufficient_campaign_budget_or_balance',
        ]);
        $this->assertDatabaseCount('validated_impressions', 0);
        $this->assertDatabaseCount('publisher_ledger_entries', 0);
    }

    public function test_plugin_endpoints_reject_missing_bearer_token(): void
    {
        $this->postJson('/api/plugin/v1/heartbeat')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_stale_player_session_is_invalid_with_its_original_risk_score(): void
    {
        $raw = $this->pendingValidationEvent();
        $session = PlayerSession::factory()->create([
            'server_id' => $raw->server_id,
            'player_hash' => $raw->player_hash,
            'last_seen_at' => $raw->received_at->copy()->subMinutes(16),
        ]);
        $raw->update(['player_session_id' => $session->id]);

        app(ImpressionValidationService::class)->validate($raw->id);

        $this->assertInvalidUnbilledImpression($raw, 'stale_player_session', 50);
    }

    public function test_oversized_delivery_remains_invalid_as_each_event_is_processed(): void
    {
        $raw = $this->pendingValidationEvent();
        RawImpressionEvent::factory()->count(3)->create([
            'ad_delivery_id' => $raw->ad_delivery_id,
            'server_id' => $raw->server_id,
            'player_session_id' => null,
            'displayed_at' => $raw->displayed_at,
            'received_at' => $raw->received_at,
        ]);

        $this->validatePendingImpressions();

        foreach (RawImpressionEvent::all() as $event) {
            $this->assertInvalidUnbilledImpression($event, 'impressions_exceed_recent_online_players', 70);
        }
    }

    public function test_player_frequency_cap_is_invalid_with_its_original_risk_score(): void
    {
        $raw = $this->pendingValidationEvent();
        RawImpressionEvent::factory()->count(4)->create([
            'player_hash' => $raw->player_hash,
            'player_session_id' => null,
            'displayed_at' => $raw->displayed_at->copy()->subMinutes(10),
            'validation_status' => 'valid',
        ]);

        app(ImpressionValidationService::class)->validate($raw->id);

        $this->assertInvalidUnbilledImpression($raw, 'player_frequency_cap_exceeded', 60);
    }

    private function pendingValidationEvent(): RawImpressionEvent
    {
        $this->freezeTime();
        $delivery = AdDelivery::factory()->create(['requested_at' => now()]);
        ServerHeartbeat::factory()->create([
            'server_id' => $delivery->server_id,
            'online_players' => 1,
            'received_at' => now(),
        ]);

        return RawImpressionEvent::factory()->create([
            'ad_delivery_id' => $delivery->id,
            'server_id' => $delivery->server_id,
            'player_session_id' => null,
            'displayed_at' => now(),
            'received_at' => now(),
        ]);
    }

    private function assertInvalidUnbilledImpression(RawImpressionEvent $raw, string $reason, int $riskScore): void
    {
        $this->assertDatabaseHas('raw_impression_events', [
            'id' => $raw->id,
            'validation_status' => 'invalid',
            'invalid_reason' => $reason,
            'risk_score' => $riskScore,
        ]);
        $this->assertNotNull($raw->fresh()->validated_at);
        $this->assertDatabaseCount('validated_impressions', 0);
        $this->assertSame(0, AdvertiserLedgerEntry::query()->where('type', 'spend')->count());
        $this->assertDatabaseCount('publisher_ledger_entries', 0);
    }

    private function activatedServer(bool $sendHeartbeat = false): array
    {
        $publisher = PublisherAccount::factory()->create(['user_id' => User::factory()->create(['role' => 'publisher'])->id]);
        $setupToken = 'setup-token-'.str()->random(12);
        $server = Server::factory()->create([
            'publisher_account_id' => $publisher->id,
            'status' => 'pending',
            'category' => 'survival',
            'setup_token_hash' => Hash::make($setupToken),
            'setup_token_expires_at' => now()->addHour(),
            'activated_at' => null,
        ]);

        $activation = $this->postJson('/api/plugin/v1/activate', [
            'setup_token' => $setupToken,
        ]);

        $apiToken = $activation->json('api_token');

        if ($sendHeartbeat) {
            $this->postJson('/api/plugin/v1/heartbeat', [
                'online_players' => 5,
            ], [
                'Authorization' => 'Bearer '.$apiToken,
            ])->assertOk();
        }

        return [$apiToken, $server];
    }

    private function targetedCampaign(AdvertiserAccount $advertiser, array $overrides = []): Campaign
    {
        $campaign = Campaign::factory()->create(array_merge([
            'advertiser_account_id' => $advertiser->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ], $overrides));

        CampaignTarget::factory()->create([
            'campaign_id' => $campaign->id,
            'country_code' => 'US',
            'language' => 'en',
            'server_category' => 'survival',
        ]);

        return $campaign;
    }

    private function approvedCreative(Campaign $campaign): AdCreative
    {
        return AdCreative::factory()->create([
            'campaign_id' => $campaign->id,
            'language' => 'en',
            'status' => 'approved',
        ]);
    }

    private function usRateTier(): RateTier
    {
        $rateTier = RateTier::factory()->create([
            'name' => 'Tier 1',
            'cpm_micros' => 3000000,
            'is_default' => true,
        ]);

        RateTierCountry::factory()->create([
            'rate_tier_id' => $rateTier->id,
            'country_code' => 'US',
        ]);

        return $rateTier;
    }

    private function validatePendingImpressions(): void
    {
        $validator = app(ImpressionValidationService::class);

        RawImpressionEvent::query()
            ->where('validation_status', 'pending')
            ->pluck('id')
            ->each(fn (int $id) => $validator->validate($id));
    }
}
