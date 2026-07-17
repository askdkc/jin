<?php

namespace Database\Factories;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
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
            'actor_id' => User::factory(),
            'type' => ActivityType::TodoCreated,
            'subject_type' => Todo::class,
            'subject_id' => fake()->numberBetween(1, 100_000),
            'metadata' => ['label' => fake()->sentence(4)],
        ];
    }
}
