<?php

namespace Database\Factories;

use App\Models\PublisherAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ServerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'publisher_account_id' => PublisherAccount::factory(),
            'name' => fake()->words(2, true).' MC',
            'host' => fake()->domainName(),
            'category' => fake()->randomElement(['survival', 'skyblock', 'minigames', 'factions']),
            'status' => 'active',
            'setup_token_hash' => Hash::make('setup_'.Str::random(32)),
            'setup_token_expires_at' => now()->addDay(),
            'activated_at' => now()->subDays(fake()->numberBetween(1, 30)),
            'last_seen_at' => now()->subMinutes(fake()->numberBetween(1, 10)),
        ];
    }
}
