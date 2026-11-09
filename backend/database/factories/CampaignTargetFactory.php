<?php

namespace Database\Factories;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

class CampaignTargetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'country_code' => fake()->randomElement(['US', 'BR', 'DE', 'GB', null]),
            'server_category' => fake()->randomElement(['survival', 'skyblock', 'minigames', null]),
        ];
    }
}
