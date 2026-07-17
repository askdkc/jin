<?php

namespace Database\Factories;

use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Todo>
 */
class TodoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'todo_list_id' => TodoList::factory(),
            'parent_id' => null,
            'assignee_id' => null,
            'created_by' => User::factory(),
            'title' => fake()->sentence(5),
            'details' => fake()->optional()->paragraph(),
            'due_on' => null,
            'completed_at' => null,
            'completed_by' => null,
            'position' => 0,
        ];
    }
}
