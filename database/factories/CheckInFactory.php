<?php

namespace Database\Factories;

use App\Enums\CheckInFrequency;
use App\Models\CheckIn;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckIn>
 */
class CheckInFactory extends Factory
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
            'question' => fake()->sentence(),
            'frequency' => CheckInFrequency::Weekly,
            'timezone' => 'Asia/Tokyo',
            'next_run_at' => now()->addDay(),
            'paused_at' => null,
        ];
    }
}
