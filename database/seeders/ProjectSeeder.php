<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $project = Project::factory()
            ->for($team)
            ->for($user, 'creator')
            ->create();

        $project->members()->attach($user);
    }
}
