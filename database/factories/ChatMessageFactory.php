<?php

namespace Database\Factories;

use App\Models\ChatMessage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'chat_channel_id' => null,
            'user_id' => User::factory(),
            'parent_id' => null,
            'body' => fake()->sentence(),
        ];
    }
}
