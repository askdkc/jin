<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
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
            'created_by' => User::factory(),
            'name' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'project_folder_id' => null,
            'lead_id' => null,
            'phase' => null,
            'status' => 'on_track',
            'enabled_tools' => null,
            'allows_all_clients' => false,
            'starts_on' => null,
            'ends_on' => null,
            'archived_at' => null,
        ];
    }

    /**
     * Mark the project as archived.
     */
    public function archived(): static
    {
        return $this->state(fn (): array => [
            'archived_at' => now(),
        ]);
    }
}
