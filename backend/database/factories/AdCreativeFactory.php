<?php

namespace Database\Factories;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

class AdCreativeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'type' => 'chat',
            'language' => fake()->randomElement(['en', 'es', 'pt']),
            'message_text' => '[Sponsored] '.fake()->sentence(8),
            'click_url' => 'https://example.com/'.fake()->slug(),
            'status' => 'approved',
        ];
    }
}
