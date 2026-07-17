<?php

namespace Database\Factories;

use App\Models\MaterialFolder;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialFolder>
 */
class MaterialFolderFactory extends Factory
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
            'name' => fake()->words(2, true),
            'position' => 0,
        ];
    }
}
