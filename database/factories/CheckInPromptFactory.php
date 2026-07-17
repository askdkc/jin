<?php

namespace Database\Factories;

use App\Models\CheckIn;
use App\Models\CheckInPrompt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckInPrompt>
 */
class CheckInPromptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'check_in_id' => CheckIn::factory(),
            'user_id' => User::factory(),
            'scheduled_for' => now(),
            'requested_at' => now(),
            'answer' => null,
            'answered_at' => null,
        ];
    }
}
