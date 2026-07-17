<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use App\Models\VoiceNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VoiceNote>
 */
class VoiceNoteFactory extends Factory
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
            'disk' => 'local',
            'path' => 'voice-notes/example.webm',
            'mime_type' => 'audio/webm',
            'size' => 1024,
        ];
    }
}
