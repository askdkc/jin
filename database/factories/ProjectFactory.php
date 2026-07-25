<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;
use LogicException;

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
            'created_by' => User::factory(),
            'team_id' => function (array $attributes): int {
                $creatorId = $attributes['created_by'] ?? null;

                if (! is_int($creatorId)) {
                    throw new LogicException('The generated project creator must have an integer key.');
                }

                $creator = User::query()->findOrFail($creatorId);

                if ($creator->current_team_id === null) {
                    throw new LogicException('The generated project creator must have a current team.');
                }

                return $creator->current_team_id;
            },
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

    public function forTeam(Team $team, User $creator): static
    {
        if (! $team->members()->whereKey($creator->getKey())->exists()) {
            throw new InvalidArgumentException('The project creator must be a member of the project team.');
        }

        return $this
            ->for($team)
            ->for($creator, 'creator');
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
