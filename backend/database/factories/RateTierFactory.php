<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class RateTierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'cpm_micros' => fake()->randomElement([750000, 1250000, 2000000, 3000000]),
            'is_default' => false,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }
}
