<?php

namespace Database\Factories;

use App\Models\CardColumn;
use App\Models\CardTable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardColumn>
 */
class CardColumnFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'card_table_id' => CardTable::factory(),
            'name' => fake()->words(2, true),
            'position' => 0,
            'is_collapsed' => false,
            'is_triage' => false,
            'is_not_now' => false,
            'is_done' => false,
        ];
    }
}
