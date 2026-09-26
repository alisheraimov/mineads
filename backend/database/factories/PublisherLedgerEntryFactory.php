<?php

namespace Database\Factories;

use App\Models\PublisherAccount;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

class PublisherLedgerEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'publisher_account_id' => PublisherAccount::factory(),
            'server_id' => Server::factory(),
            'type' => fake()->randomElement(['pending_earning', 'payable', 'adjustment']),
            'amount_micros' => fake()->numberBetween(1, 100) * 1000000,
            'currency' => 'USD',
            'reference_type' => null,
            'reference_id' => null,
        ];
    }
}
