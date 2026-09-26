<?php

namespace Database\Seeders;

use App\Models\AdCreative;
use App\Models\AdDelivery;
use App\Models\AdvertiserAccount;
use App\Models\AdvertiserLedgerEntry;
use App\Models\Campaign;
use App\Models\CampaignTarget;
use App\Models\Payout;
use App\Models\PlayerSession;
use App\Models\PublisherAccount;
use App\Models\PublisherLedgerEntry;
use App\Models\RateTier;
use App\Models\RateTierCountry;
use App\Models\RawImpressionEvent;
use App\Models\Server;
use App\Models\ServerApiToken;
use App\Models\ServerHeartbeat;
use App\Models\User;
use App\Models\ValidatedImpression;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    /**
     * Seed a small but complete MineAds demo dataset.
     */
    public function run(): void
    {
        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@mineads.test'],
            ['name' => 'MineAds Admin', 'password' => 'password', 'role' => 'admin'],
        );

        $advertisers = collect([
            ['name' => 'BlockBoost', 'email' => 'advertiser1@mineads.test'],
            ['name' => 'CraftList', 'email' => 'advertiser2@mineads.test'],
        ])->map(function (array $advertiser) {
            $user = User::query()->firstOrCreate(
                ['email' => $advertiser['email']],
                ['name' => $advertiser['name'], 'password' => 'password', 'role' => 'advertiser'],
            );

            return AdvertiserAccount::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['name' => $advertiser['name'], 'status' => 'active', 'currency' => 'USD'],
            );
        });

        $publishers = collect([
            ['name' => 'Emerald Network', 'email' => 'publisher1@mineads.test'],
            ['name' => 'PixelRealms', 'email' => 'publisher2@mineads.test'],
        ])->map(function (array $publisher) {
            $user = User::query()->firstOrCreate(
                ['email' => $publisher['email']],
                ['name' => $publisher['name'], 'password' => 'password', 'role' => 'publisher'],
            );

            return PublisherAccount::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['name' => $publisher['name'], 'status' => 'active', 'currency' => 'USD'],
            );
        });

        $tierOne = RateTier::query()->firstOrCreate(
            ['name' => 'Tier 1'],
            ['cpm_micros' => 3000000, 'is_default' => false, 'sort_order' => 10],
        );
        $tierTwo = RateTier::query()->firstOrCreate(
            ['name' => 'Tier 2'],
            ['cpm_micros' => 2000000, 'is_default' => false, 'sort_order' => 20],
        );
        $tierThree = RateTier::query()->firstOrCreate(
            ['name' => 'Tier 3'],
            ['cpm_micros' => 1250000, 'is_default' => false, 'sort_order' => 30],
        );
        $defaultTier = RateTier::query()->firstOrCreate(
            ['name' => 'Rest of World'],
            ['cpm_micros' => 750000, 'is_default' => true, 'sort_order' => 99],
        );

        foreach (['US', 'CA', 'GB', 'AU'] as $countryCode) {
            RateTierCountry::query()->firstOrCreate([
                'country_code' => $countryCode,
            ], [
                'rate_tier_id' => $tierOne->id,
            ]);
        }

        foreach (['DE', 'FR', 'NL', 'SE'] as $countryCode) {
            RateTierCountry::query()->firstOrCreate([
                'country_code' => $countryCode,
            ], [
                'rate_tier_id' => $tierTwo->id,
            ]);
        }

        foreach (['BR', 'MX', 'TR'] as $countryCode) {
            RateTierCountry::query()->firstOrCreate([
                'country_code' => $countryCode,
            ], [
                'rate_tier_id' => $tierThree->id,
            ]);
        }

        $servers = collect([
            [$publishers[0], 'Emerald Survival', 'play.emerald.test', 'survival'],
            [$publishers[0], 'Emerald Skyblock', 'sky.emerald.test', 'skyblock'],
            [$publishers[1], 'Pixel Minigames', 'play.pixel.test', 'minigames'],
            [$publishers[1], 'Pixel Factions', 'factions.pixel.test', 'factions'],
        ])->map(function (array $serverData) {
            [$publisher, $name, $host, $category] = $serverData;

            $server = Server::query()->firstOrCreate(
                ['publisher_account_id' => $publisher->id, 'name' => $name],
                [
                    'host' => $host,
                    'category' => $category,
                    'status' => 'active',
                    'setup_token_hash' => Hash::make('setup-'.$host),
                    'setup_token_expires_at' => now()->addDay(),
                    'activated_at' => now()->subDays(14),
                    'last_seen_at' => now()->subMinutes(2),
                ],
            );

            ServerApiToken::factory()->create(['server_id' => $server->id]);
            ServerHeartbeat::factory()->count(3)->create(['server_id' => $server->id]);

            return $server;
        });

        $campaigns = collect();

        foreach ($advertisers as $index => $advertiser) {
            $campaign = Campaign::factory()->create([
                'advertiser_account_id' => $advertiser->id,
                'name' => $index === 0 ? 'Promote BlockBoost Survival' : 'CraftList Server Discovery',
                'status' => 'active',
                'daily_budget_micros' => 50000000,
                'total_budget_micros' => 500000000,
            ]);

            $creativeEn = AdCreative::factory()->create([
                'campaign_id' => $campaign->id,
                'language' => 'en',
                'message_text' => $index === 0
                    ? '[Sponsored] Grow your Minecraft server with BlockBoost campaigns.'
                    : '[Sponsored] Find fresh Minecraft communities on CraftList.',
            ]);

            AdCreative::factory()->create([
                'campaign_id' => $campaign->id,
                'language' => 'pt',
                'message_text' => $index === 0
                    ? '[Patrocinado] Divulgue seu servidor Minecraft com BlockBoost.'
                    : '[Patrocinado] Descubra novas comunidades Minecraft no CraftList.',
            ]);

            foreach ([['US', 'en', 'survival'], ['GB', 'en', null], ['BR', 'pt', 'skyblock']] as $target) {
                CampaignTarget::factory()->create([
                    'campaign_id' => $campaign->id,
                    'country_code' => $target[0],
                    'language' => $target[1],
                    'server_category' => $target[2],
                ]);
            }

            AdvertiserLedgerEntry::factory()->create([
                'advertiser_account_id' => $advertiser->id,
                'campaign_id' => null,
                'type' => 'deposit',
                'amount_micros' => 100000000,
                'reference_type' => 'demo_seed',
                'reference_id' => $campaign->id,
            ]);

            $campaigns->push([$campaign, $creativeEn]);
        }

        foreach ($servers as $server) {
            $players = PlayerSession::factory()->count(24)->create(['server_id' => $server->id]);

            foreach ($campaigns as [$campaign, $creative]) {
                $samplePlayers = $players->shuffle()->take(12)->values();

                $delivery = AdDelivery::factory()->create([
                    'server_id' => $server->id,
                    'campaign_id' => $campaign->id,
                    'creative_id' => $creative->id,
                    'rate_tier_id' => $tierOne->id,
                    'cpm_micros' => $tierOne->cpm_micros,
                    'audience_country' => 'US',
                    'audience_language' => 'en',
                    'requested_player_count' => $samplePlayers->count(),
                    'decision' => 'served',
                ]);

                $billableMicros = intdiv($tierOne->cpm_micros, 1000);
                $publisherEarningMicros = (int) floor($billableMicros * 0.7);
                $platformFeeMicros = $billableMicros - $publisherEarningMicros;

                foreach ($samplePlayers as $player) {
                    $raw = RawImpressionEvent::factory()->create([
                        'ad_delivery_id' => $delivery->id,
                        'server_id' => $server->id,
                        'player_session_id' => $player->id,
                        'player_hash' => $player->player_hash,
                        'validation_status' => 'valid',
                        'risk_score' => 0,
                        'validated_at' => now(),
                    ]);

                    ValidatedImpression::factory()->create([
                        'raw_impression_event_id' => $raw->id,
                        'advertiser_account_id' => $campaign->advertiser_account_id,
                        'publisher_account_id' => $server->publisher_account_id,
                        'campaign_id' => $campaign->id,
                        'creative_id' => $creative->id,
                        'server_id' => $server->id,
                        'player_hash' => $player->player_hash,
                        'billable_micros' => $billableMicros,
                        'publisher_earning_micros' => $publisherEarningMicros,
                        'platform_fee_micros' => $platformFeeMicros,
                    ]);
                }

                $impressionCount = $samplePlayers->count();

                AdvertiserLedgerEntry::factory()->create([
                    'advertiser_account_id' => $campaign->advertiser_account_id,
                    'campaign_id' => $campaign->id,
                    'type' => 'spend',
                    'amount_micros' => -1 * $billableMicros * $impressionCount,
                    'reference_type' => 'ad_delivery',
                    'reference_id' => $delivery->id,
                ]);

                PublisherLedgerEntry::factory()->create([
                    'publisher_account_id' => $server->publisher_account_id,
                    'server_id' => $server->id,
                    'type' => 'pending_earning',
                    'amount_micros' => $publisherEarningMicros * $impressionCount,
                    'reference_type' => 'ad_delivery',
                    'reference_id' => $delivery->id,
                ]);
            }
        }

        foreach ($publishers as $publisher) {
            $pendingEarnings = PublisherLedgerEntry::query()
                ->where('publisher_account_id', $publisher->id)
                ->sum('amount_micros');

            Payout::factory()->create([
                'publisher_account_id' => $publisher->id,
                'status' => 'pending',
                'amount_micros' => max(25000000, (int) $pendingEarnings),
                'period_start' => now()->subMonth()->startOfMonth(),
                'period_end' => now()->subMonth()->endOfMonth(),
            ]);
        }

        $this->command?->info('Seeded MineAds demo data. Login emails use password: password');
        $this->command?->line('Admin: '.$admin->email);
    }
}
