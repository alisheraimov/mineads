<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AdvertiserAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => 'advertiser']),
            'name' => fake()->company(),
            'status' => 'active',
            'currency' => 'USD',
        ];
    }
}
