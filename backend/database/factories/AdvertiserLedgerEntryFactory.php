<?php

namespace Database\Factories;

use App\Models\AdvertiserAccount;
use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

class AdvertiserLedgerEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'advertiser_account_id' => AdvertiserAccount::factory(),
            'campaign_id' => Campaign::factory(),
            'type' => fake()->randomElement(['deposit', 'spend', 'adjustment']),
            'amount_micros' => fake()->numberBetween(1, 100) * 1000000,
            'currency' => 'USD',
            'reference_type' => null,
            'reference_id' => null,
        ];
    }
}
