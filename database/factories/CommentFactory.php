<?php

namespace Database\Factories;

use App\Models\Comment;
use App\Models\MessageBoardPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'commentable_type' => MessageBoardPost::class,
            'commentable_id' => MessageBoardPost::factory(),
            'project_id' => fn (array $attributes): int => MessageBoardPost::query()
                ->whereKey($attributes['commentable_id'])
                ->firstOrFail()
                ->project_id,
            'body' => fake()->sentence(),
        ];
    }
}
