<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PlayerSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'player_hash' => hash('sha256', fake()->uuid()),
            'session_token' => 'ps_'.Str::random(40),
            'country_code' => fake()->randomElement(['US', 'BR', 'DE', 'GB', 'CA']),
            'language' => fake()->randomElement(['en', 'pt', 'de']),
            'joined_at' => now()->subMinutes(fake()->numberBetween(15, 240)),
            'last_seen_at' => now()->subMinutes(fake()->numberBetween(1, 10)),
        ];
    }
}
