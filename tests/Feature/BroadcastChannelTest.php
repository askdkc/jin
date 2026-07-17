<?php

use App\Broadcasting\ProjectChatChannel;
use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

test('project chat private channel follows project visibility', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $participant = User::factory()->create();
    $nonParticipant = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($participant, ['role' => TeamRole::Member->value]);
    $team->members()->attach($nonParticipant, ['role' => TeamRole::Member->value]);

    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($participant);
    $channel = app(ProjectChatChannel::class);

    expect($channel->join($owner, $project))->toBeTrue()
        ->and($channel->join($admin, $project))->toBeTrue()
        ->and($channel->join($participant, $project))->toBeTrue()
        ->and($channel->join($nonParticipant, $project))->toBeFalse()
        ->and($channel->join($outsider, $project))->toBeFalse();
});
