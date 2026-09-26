<?php

namespace Database\Factories;

use App\Models\AdDelivery;
use App\Models\PlayerSession;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

class RawImpressionEventFactory extends Factory
{
    public function definition(): array
    {
        $playerHash = hash('sha256', fake()->uuid());

        return [
            'ad_delivery_id' => AdDelivery::factory(),
            'server_id' => Server::factory(),
            'player_session_id' => PlayerSession::factory(),
            'player_hash' => $playerHash,
            'displayed_at' => now()->subMinutes(fake()->numberBetween(1, 120)),
            'received_at' => now()->subMinutes(fake()->numberBetween(1, 119)),
            'validation_status' => 'pending',
            'invalid_reason' => null,
            'risk_score' => 0,
            'validated_at' => null,
        ];
    }
}
