<?php

namespace Database\Factories;

use App\Models\Card;
use App\Models\CardColumn;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Card>
 */
class CardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'card_column_id' => CardColumn::factory(),
            'created_by' => User::factory(),
            'assignee_id' => null,
            'title' => fake()->sentence(5),
            'description' => fake()->optional()->paragraph(),
            'due_on' => null,
            'position' => 0,
            'version' => 1,
        ];
    }
}
