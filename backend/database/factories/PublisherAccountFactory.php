<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PublisherAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => 'publisher']),
            'name' => fake()->company(),
            'status' => 'active',
            'currency' => 'USD',
        ];
    }
}
