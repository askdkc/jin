<?php

use App\Enums\TeamRole;
use App\Models\Document;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('project participants can create and view documents', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($member);

    $this->actingAs($member)
        ->post(route('projects.documents.store', [$team, $project]), [
            'title' => '運用手順',
            'body' => "1. 内容を確認する\n2. 公開する",
        ])
        ->assertRedirect(route('projects.documents.index', [$team, $project]));

    $document = Document::query()->firstOrFail();

    $this->actingAs($member)
        ->get(route('projects.documents.index', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/documents/Index')
            ->where('documents.0.id', $document->id)
            ->where('documents.0.title', '運用手順')
            ->where('documents.0.canEdit', true));
});

test('document authors and team managers can edit while other members cannot', function () {
    $owner = User::factory()->create();
    $author = User::factory()->create();
    $otherMember = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($author, ['role' => TeamRole::Member->value]);
    $team->members()->attach($otherMember, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$author->id, $otherMember->id]);
    $document = Document::factory()->for($project)->for($author, 'creator')->create();

    $this->actingAs($otherMember)
        ->patch(route('projects.documents.update', [$team, $project, $document]), [
            'title' => '変更不可',
            'body' => '変更不可',
        ])
        ->assertForbidden();

    $this->actingAs($owner)
        ->patch(route('projects.documents.update', [$team, $project, $document]), [
            'title' => '管理者が更新',
            'body' => '更新済み',
        ])
        ->assertRedirect();

    expect($document->fresh()->title)->toBe('管理者が更新');
});

test('document routes reject non participants and cross project bindings', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $visibleProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $otherProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $visibleProject->members()->attach($member);
    $document = Document::factory()->for($otherProject)->for($owner, 'creator')->create();

    $this->actingAs($member)
        ->get(route('projects.documents.index', [$team, $otherProject]))
        ->assertForbidden();

    $this->actingAs($member)
        ->delete(route('projects.documents.destroy', [$team, $visibleProject, $document]))
        ->assertNotFound();
});
