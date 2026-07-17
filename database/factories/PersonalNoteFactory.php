<?php

namespace Database\Factories;

use App\Models\PersonalNote;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersonalNote>
 */
class PersonalNoteFactory extends Factory
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
            'title' => fake()->sentence(4),
            'body' => fake()->paragraphs(2, true),
        ];
    }
}
