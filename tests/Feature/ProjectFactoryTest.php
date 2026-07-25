<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

test('default project factory keeps the creator in the generated project team', function () {
    $project = Project::factory()->create();
    $creator = User::query()->findOrFail($project->created_by);

    expect($project->team_id)->toBe($creator->current_team_id)
        ->and($project->team->members()->whereKey($creator->id)->exists())->toBeTrue();
});

test('project factory can use an existing tenant and creator', function () {
    $creator = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($creator, ['role' => TeamRole::Member->value]);

    $project = Project::factory()->forTeam($team, $creator)->create();

    expect($project->team_id)->toBe($team->id)
        ->and($project->created_by)->toBe($creator->id);
});

test('tenant-aware project factory rejects creators outside the team', function () {
    $creator = User::factory()->create();
    $team = Team::factory()->create();

    expect(fn () => Project::factory()->forTeam($team, $creator))
        ->toThrow(
            InvalidArgumentException::class,
            'The project creator must be a member of the project team.',
        );
});
