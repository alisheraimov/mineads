<?php

namespace Database\Factories;

use App\Models\AdCreative;
use App\Models\Campaign;
use App\Models\RateTier;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdDeliveryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'campaign_id' => Campaign::factory(),
            'creative_id' => AdCreative::factory(),
            'rate_tier_id' => RateTier::factory(),
            'cpm_micros' => 2000000,
            'placement' => 'chat',
            'audience_country' => fake()->randomElement(['US', 'BR', 'DE', 'GB']),
            'audience_language' => fake()->randomElement(['en', 'pt', 'de']),
            'requested_player_count' => fake()->numberBetween(5, 80),
            'decision' => 'served',
            'impression_token_hash' => Hash::make('imp_'.Str::random(48)),
            'expires_at' => now()->addMinutes(5),
            'requested_at' => now()->subMinutes(fake()->numberBetween(1, 120)),
        ];
    }
}
