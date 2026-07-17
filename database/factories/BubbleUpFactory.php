<?php

namespace Database\Factories;

use App\Models\BubbleUp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BubbleUp>
 */
class BubbleUpFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'label' => fake()->sentence(3),
            'url' => '/notifications',
            'remind_at' => now()->addDay(),
            'completed_at' => null,
        ];
    }
}
