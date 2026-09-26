<?php

namespace Database\Factories;

use App\Models\PublisherAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class PayoutFactory extends Factory
{
    public function definition(): array
    {
        return [
            'publisher_account_id' => PublisherAccount::factory(),
            'status' => 'pending',
            'amount_micros' => fake()->numberBetween(25, 200) * 1000000,
            'currency' => 'USD',
            'provider' => 'stripe',
            'provider_payout_id' => null,
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
            'paid_at' => null,
        ];
    }
}
