<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoredFile>
 */
class StoredFileFactory extends Factory
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
            'material_folder_id' => null,
            'uploaded_by' => User::factory(),
            'attachable_type' => null,
            'attachable_id' => null,
            'disk' => 'local',
            'path' => 'projects/'.fake()->randomNumber().'/'.fake()->uuid().'.pdf',
            'original_name' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1, 1024 * 1024),
            'checksum' => hash('sha256', fake()->uuid()),
        ];
    }
}
