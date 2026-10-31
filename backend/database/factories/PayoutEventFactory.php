<?php

namespace Database\Factories;

use App\Models\Payout;
use Illuminate\Database\Eloquent\Factories\Factory;

class PayoutEventFactory extends Factory
{
    public function definition(): array
    {
        return ['payout_id' => Payout::factory(), 'actor_name' => 'System', 'action' => 'created', 'details' => ['to' => 'pending']];
    }
}
