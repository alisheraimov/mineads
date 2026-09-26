<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ServerApiTokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'name' => 'Primary plugin token',
            'token_hash' => hash('sha256', 'ma_srv_'.Str::random(48)),
            'last_used_at' => now()->subMinutes(fake()->numberBetween(1, 30)),
            'revoked_at' => null,
        ];
    }
}
