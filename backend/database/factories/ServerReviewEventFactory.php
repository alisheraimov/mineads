<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerReviewEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerReviewEvent>
 */
class ServerReviewEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'actor_name' => 'System',
            'action' => 'submitted',
            'details' => ['review_status' => 'pending_review'],
        ];
    }
}
