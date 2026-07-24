<?php

namespace Database\Factories;

use App\Models\CardTable;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardTable>
 */
class CardTableFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (CardTable $cardTable): void {
            $cardTable->cardColumns()->createMany([
                [
                    'name' => 'Triage',
                    'position' => 0,
                    'is_triage' => true,
                ],
                [
                    'name' => 'Not Now',
                    'position' => 0,
                    'is_collapsed' => true,
                    'is_not_now' => true,
                ],
                [
                    'name' => 'Done',
                    'position' => 0,
                    'is_collapsed' => true,
                    'is_done' => true,
                ],
            ]);
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->words(3, true),
            'position' => 0,
        ];
    }
}
