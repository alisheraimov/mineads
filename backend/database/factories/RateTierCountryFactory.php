<?php

namespace Database\Factories;

use App\Models\RateTier;
use Illuminate\Database\Eloquent\Factories\Factory;

class RateTierCountryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'rate_tier_id' => RateTier::factory(),
            'country_code' => fake()->unique()->countryCode(),
        ];
    }
}
