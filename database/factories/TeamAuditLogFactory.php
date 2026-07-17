<?php

namespace Database\Factories;

use App\Enums\TeamAuditEvent;
use App\Models\Team;
use App\Models\TeamAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamAuditLog>
 */
class TeamAuditLogFactory extends Factory
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
            'actor_id' => User::factory(),
            'event' => TeamAuditEvent::InvitationSent,
            'email' => fake()->safeEmail(),
            'metadata' => null,
        ];
    }
}
