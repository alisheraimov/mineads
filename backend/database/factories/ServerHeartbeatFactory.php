<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServerHeartbeatFactory extends Factory
{
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'plugin_version' => '0.1.0',
            'minecraft_version' => fake()->randomElement(['1.21.1', '1.21.4', '1.21.8']),
            'platform' => fake()->randomElement(['paper', 'spigot']),
            'online_players' => fake()->numberBetween(12, 160),
            'max_players' => fake()->randomElement([50, 100, 200, 500]),
            'tps' => fake()->randomFloat(2, 18.5, 20.0),
            'received_at' => now()->subMinutes(fake()->numberBetween(1, 20)),
        ];
    }
}
