<?php

namespace Database\Seeders;

use App\Actions\Teams\CreateTeam;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::query()->firstOrCreate([
            'email' => 'test@example.com',
        ], [
            'name' => 'Test User',
            'password' => 'password',
        ]);

        if ($user->wasRecentlyCreated) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $personalTeam = $user->personalTeam();

        if ($personalTeam === null) {
            app(CreateTeam::class)->handle($user, $user->name."'s Team", isPersonal: true);
        } elseif ($user->current_team_id === null) {
            $user->switchTeam($personalTeam);
        }
    }
}
