<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

test('non participants cannot access project tools', function (string $method, string $routeName) {
    $owner = User::factory()->create();
    $nonParticipant = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($nonParticipant, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();

    $this->actingAs($nonParticipant)
        ->call($method, route($routeName, [$team, $project]))
        ->assertForbidden();
})->with([
    'project home' => ['GET', 'projects.show'],
    'message board read' => ['GET', 'projects.messages.index'],
    'message board write' => ['POST', 'projects.messages.store'],
    'chat read' => ['GET', 'projects.chat.show'],
    'chat history' => ['GET', 'projects.chat.messages.index'],
    'chat write' => ['POST', 'projects.chat.messages.store'],
    'chat channel write' => ['POST', 'projects.chat.channels.store'],
    'todos read' => ['GET', 'projects.todos.index'],
    'todos write' => ['POST', 'projects.todo-lists.store'],
    'schedule read' => ['GET', 'projects.schedule.index'],
    'schedule write' => ['POST', 'projects.schedule.store'],
    'documents read' => ['GET', 'projects.documents.index'],
    'documents write' => ['POST', 'projects.documents.store'],
    'files read' => ['GET', 'projects.files.index'],
    'files write' => ['POST', 'projects.files.store'],
    'card tables read' => ['GET', 'projects.card-tables.index'],
    'card tables write' => ['POST', 'projects.card-tables.store'],
    'check-ins read' => ['GET', 'projects.check-ins.index'],
    'check-ins write' => ['POST', 'projects.check-ins.store'],
    'materials read' => ['GET', 'projects.materials.index'],
    'material folders write' => ['POST', 'projects.material-folders.store'],
    'external links write' => ['POST', 'projects.external-links.store'],
    'time entries read' => ['GET', 'projects.time-entries.index'],
    'time entries write' => ['POST', 'projects.time-entries.store'],
]);
