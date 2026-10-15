<?php

namespace Database\Seeders;

use App\Models\AdCreative;
use App\Models\AdDelivery;
use App\Models\AdvertiserAccount;
use App\Models\AdvertiserLedgerEntry;
use App\Models\Campaign;
use App\Models\CampaignTarget;
use App\Models\PlayerSession;
use App\Models\PublisherAccount;
use App\Models\RawImpressionEvent;
use App\Models\Server;
use App\Models\User;
use App\Models\ValidatedImpression;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class AdvertiserDashboardSeeder extends Seeder
{
    /**
     * Seed deterministic advertiser portal data for local dashboard review.
     */
    public function run(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'advertiser@mineads.test'],
            [
                'name' => 'Alex Morgan',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
                'role' => 'advertiser',
                'status' => 'active',
            ],
        );

        $advertiser = AdvertiserAccount::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['name' => 'RealmCraft', 'status' => 'active', 'currency' => 'USD'],
        );

        $publisherUser = User::query()->updateOrCreate(
            ['email' => 'publisher@mineads.test'],
            [
                'name' => 'Emerald Network',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
                'role' => 'publisher',
                'status' => 'active',
            ],
        );

        $publisher = PublisherAccount::query()->updateOrCreate(
            ['user_id' => $publisherUser->id],
            ['name' => 'Emerald Network', 'status' => 'active', 'currency' => 'USD'],
        );

        $server = Server::query()->updateOrCreate(
            ['publisher_account_id' => $publisher->id, 'name' => 'Emerald Survival'],
            [
                'host' => 'play.emerald.test',
                'category' => 'survival',
                'status' => 'active',
                'setup_token_hash' => Hash::make('setup-play.emerald.test'),
                'setup_token_expires_at' => now()->addDay(),
                'activated_at' => now()->subDays(45),
                'last_seen_at' => now()->subMinutes(2),
            ],
        );

        $this->removePreviousSeedRows($advertiser);

        $campaigns = collect([
            [
                'name' => 'RealmCraft · Survival launch',
                'daily_budget_micros' => 100000000,
                'total_budget_micros' => 2000000000,
                'creative_count' => 2,
            ],
            [
                'name' => 'RealmCraft · Skyblock',
                'daily_budget_micros' => 60000000,
                'total_budget_micros' => 1200000000,
                'creative_count' => 2,
            ],
        ])->map(function (array $data) use ($advertiser) {
            $campaign = Campaign::query()->create([
                'advertiser_account_id' => $advertiser->id,
                'name' => $data['name'],
                'status' => 'active',
                'pricing_model' => 'cpm',
                'daily_budget_micros' => $data['daily_budget_micros'],
                'total_budget_micros' => $data['total_budget_micros'],
                'starts_at' => now()->subDays(30),
                'ends_at' => now()->addDays(30),
            ]);

            CampaignTarget::query()->create([
                'campaign_id' => $campaign->id,
                'country_code' => 'US',
                'language' => 'en',
                'server_category' => 'survival',
            ]);

            $creatives = collect(range(1, $data['creative_count']))
                ->map(fn (int $index) => AdCreative::query()->create([
                    'campaign_id' => $campaign->id,
                    'type' => 'chat',
                    'language' => 'en',
                    'message_text' => "[Sponsored] {$data['name']} creative {$index}.",
                    'click_url' => 'https://realmcraft.test',
                    'status' => 'approved',
                ]));

            return [$campaign, $creatives->first()];
        });

        $pendingCampaign = $campaigns->first()[0];
        AdCreative::query()->create([
            'campaign_id' => $pendingCampaign->id,
            'type' => 'chat',
            'language' => 'en',
            'message_text' => '[Sponsored] RealmCraft seasonal tournament starts soon.',
            'click_url' => 'https://realmcraft.test/tournament',
            'status' => 'pending_review',
        ]);

        AdvertiserLedgerEntry::query()->create([
            'advertiser_account_id' => $advertiser->id,
            'campaign_id' => null,
            'type' => 'deposit',
            'amount_micros' => 150000000,
            'currency' => 'USD',
            'reference_type' => 'advertiser_dashboard_seed',
            'reference_id' => $advertiser->id,
            'created_at' => now()->subDays(31),
            'updated_at' => now()->subDays(31),
        ]);

        $billableMicros = 2000;

        foreach (range(0, 29) as $dayOffset) {
            foreach ($campaigns as $campaignIndex => [$campaign, $creative]) {
                $impressionCount = 18 + (($dayOffset * 7 + $campaignIndex * 9) % 35);
                $displayedAt = now()->subDays(29 - $dayOffset)->setTime(12 + $campaignIndex, 0);

                $delivery = AdDelivery::query()->create([
                    'server_id' => $server->id,
                    'campaign_id' => $campaign->id,
                    'creative_id' => $creative->id,
                    'placement' => 'chat',
                    'audience_country' => 'US',
                    'audience_language' => 'en',
                    'requested_player_count' => $impressionCount,
                    'decision' => 'served',
                    'impression_token_hash' => hash('sha256', "dashboard-seed-{$campaign->id}-{$dayOffset}"),
                    'expires_at' => $displayedAt->copy()->addMinutes(5),
                    'requested_at' => $displayedAt->copy()->subMinute(),
                ]);

                foreach (range(1, $impressionCount) as $playerIndex) {
                    $player = PlayerSession::query()->create([
                        'server_id' => $server->id,
                        'player_hash' => hash('sha256', "dashboard-seed-player-{$campaign->id}-{$dayOffset}-{$playerIndex}"),
                        'session_token' => 'ps_dashboard_'.hash('xxh3', "{$campaign->id}-{$dayOffset}-{$playerIndex}"),
                        'country_code' => 'US',
                        'language' => 'en',
                        'joined_at' => $displayedAt->copy()->subMinutes(10),
                        'last_seen_at' => $displayedAt->copy()->addMinutes(1),
                    ]);

                    $raw = RawImpressionEvent::query()->create([
                        'ad_delivery_id' => $delivery->id,
                        'server_id' => $server->id,
                        'player_session_id' => $player->id,
                        'player_hash' => $player->player_hash,
                        'displayed_at' => $displayedAt,
                        'received_at' => $displayedAt->copy()->addSeconds(10),
                        'validation_status' => 'valid',
                        'invalid_reason' => null,
                        'risk_score' => 0,
                        'validated_at' => $displayedAt->copy()->addSeconds(15),
                    ]);

                    ValidatedImpression::query()->create([
                        'raw_impression_event_id' => $raw->id,
                        'advertiser_account_id' => $advertiser->id,
                        'publisher_account_id' => $server->publisher_account_id,
                        'campaign_id' => $campaign->id,
                        'creative_id' => $creative->id,
                        'server_id' => $server->id,
                        'player_hash' => $player->player_hash,
                        'billable_micros' => $billableMicros,
                        'publisher_earning_micros' => 1400,
                        'platform_fee_micros' => 600,
                        'validated_at' => $displayedAt->copy()->addSeconds(15),
                    ]);
                }

                AdvertiserLedgerEntry::query()->create([
                    'advertiser_account_id' => $advertiser->id,
                    'campaign_id' => $campaign->id,
                    'type' => 'spend',
                    'amount_micros' => -1 * $billableMicros * $impressionCount,
                    'currency' => 'USD',
                    'reference_type' => 'advertiser_dashboard_seed',
                    'reference_id' => $delivery->id,
                    'created_at' => $displayedAt->copy()->addSeconds(20),
                    'updated_at' => $displayedAt->copy()->addSeconds(20),
                ]);
            }
        }

        Cache::forget("advertiser_dashboard:{$advertiser->id}");

        $this->command?->info('Seeded advertiser dashboard demo account.');
        $this->command?->line('Advertiser: advertiser@mineads.test / password');
    }

    private function removePreviousSeedRows(AdvertiserAccount $advertiser): void
    {
        Campaign::query()
            ->where('advertiser_account_id', $advertiser->id)
            ->whereIn('name', [
                'RealmCraft · Survival launch',
                'RealmCraft · Skyblock',
            ])
            ->get()
            ->each(fn (Campaign $campaign) => $campaign->delete());

        AdvertiserLedgerEntry::query()
            ->where('advertiser_account_id', $advertiser->id)
            ->where('reference_type', 'advertiser_dashboard_seed')
            ->delete();
    }
}
