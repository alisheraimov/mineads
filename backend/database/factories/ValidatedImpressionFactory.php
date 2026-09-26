<?php

namespace Database\Factories;

use App\Models\AdCreative;
use App\Models\AdvertiserAccount;
use App\Models\Campaign;
use App\Models\PublisherAccount;
use App\Models\RawImpressionEvent;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

class ValidatedImpressionFactory extends Factory
{
    public function definition(): array
    {
        $billableMicros = fake()->randomElement([1500, 2000, 2500, 3000]);
        $publisherEarningMicros = (int) floor($billableMicros * 0.7);

        return [
            'raw_impression_event_id' => RawImpressionEvent::factory(),
            'advertiser_account_id' => AdvertiserAccount::factory(),
            'publisher_account_id' => PublisherAccount::factory(),
            'campaign_id' => Campaign::factory(),
            'creative_id' => AdCreative::factory(),
            'server_id' => Server::factory(),
            'player_hash' => hash('sha256', fake()->uuid()),
            'billable_micros' => $billableMicros,
            'publisher_earning_micros' => $publisherEarningMicros,
            'platform_fee_micros' => $billableMicros - $publisherEarningMicros,
            'validated_at' => now()->subMinutes(fake()->numberBetween(1, 120)),
        ];
    }
}
