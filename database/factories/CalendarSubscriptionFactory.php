<?php

namespace Database\Factories;

use App\Models\CalendarSubscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarSubscription>
 */
class CalendarSubscriptionFactory extends Factory
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
            'name' => fake()->words(2, true),
            'revoked_at' => null,
        ];
    }
}
