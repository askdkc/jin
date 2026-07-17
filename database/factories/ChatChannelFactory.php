<?php

namespace Database\Factories;

use App\Models\ChatChannel;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatChannel>
 */
class ChatChannelFactory extends Factory
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
            'created_by' => User::factory(),
            'name' => fake()->unique()->slug(2),
            'description' => fake()->sentence(),
        ];
    }
}
