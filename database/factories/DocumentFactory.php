<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
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
            'created_by' => User::factory(),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraphs(3, true),
            'public_token' => null,
            'published_at' => null,
        ];
    }
}
