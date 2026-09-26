<?php

namespace Database\Factories;

use App\Models\AdvertiserAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'advertiser_account_id' => AdvertiserAccount::factory(),
            'name' => fake()->catchPhrase(),
            'status' => 'active',
            'pricing_model' => 'cpm',
            'daily_budget_micros' => fake()->numberBetween(20, 100) * 1000000,
            'total_budget_micros' => fake()->numberBetween(250, 1000) * 1000000,
            'starts_at' => now()->subDays(fake()->numberBetween(1, 10)),
            'ends_at' => now()->addDays(fake()->numberBetween(15, 45)),
        ];
    }
}
