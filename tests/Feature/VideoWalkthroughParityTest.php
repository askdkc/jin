<?php

use App\Enums\TeamRole;
use App\Models\PersonalNote;
use App\Models\Project;
use App\Models\ProjectFolder;
use App\Models\TimeEntry;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = $this->owner->currentTeam;
    $this->project = Project::factory()
        ->for($this->team)
        ->for($this->owner, 'creator')
        ->create();
    $this->project->members()->attach($this->owner);
});

test('owners can organize projects and configure the project workspace', function () {
    $lead = User::factory()->create();
    $this->team->members()->attach($lead, ['role' => TeamRole::Member->value]);

    $this->actingAs($this->owner)
        ->post(route('project-folders.store', $this->team), ['name' => '重点案件'])
        ->assertRedirect();

    $folder = ProjectFolder::query()->where('team_id', $this->team->id)->sole();

    $this->actingAs($this->owner)
        ->patch(route('projects.update', [$this->team, $this->project]), [
            'name' => $this->project->name,
            'project_folder_id' => $folder->id,
            'lead_id' => $lead->id,
            'phase' => '設計',
            'status' => 'at_risk',
            'enabled_tools_present' => true,
            'enabled_tools' => ['messages', 'todos', 'timesheets'],
            'member_ids' => [$this->owner->id, $lead->id],
        ])
        ->assertRedirect(route('projects.show', [$this->team, $this->project]));

    $this->project->refresh();

    expect($this->project->folder->is($folder))->toBeTrue()
        ->and($this->project->lead->is($lead))->toBeTrue()
        ->and($this->project->phase)->toBe('設計')
        ->and($this->project->status)->toBe('at_risk')
        ->and($this->project->enabledTools())->toBe(['messages', 'todos', 'timesheets']);

    $this->actingAs($this->owner)
        ->get(route('projects.show', [$this->team, $this->project]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('project.folder.name', '重点案件')
            ->where('project.lead.id', $lead->id)
            ->where('project.phase', '設計')
            ->where('project.status', 'at_risk')
            ->where('project.enabledTools', ['messages', 'todos', 'timesheets']));
});

test('regular members cannot create project folders', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member)
        ->post(route('project-folders.store', $this->team), ['name' => 'Unauthorized'])
        ->assertForbidden();

    expect(ProjectFolder::query()->where('name', 'Unauthorized')->exists())->toBeFalse();
});

test('project favorites are personal and shown on the grouped dashboard', function () {
    $folder = ProjectFolder::factory()->for($this->team)->for($this->owner, 'creator')->create([
        'name' => '顧客案件',
    ]);
    $this->project->update(['project_folder_id' => $folder->id]);

    $this->actingAs($this->owner)
        ->patch(route('projects.favorite', [$this->team, $this->project]))
        ->assertRedirect();

    expect($this->owner->favoriteProjects()->whereKey($this->project->id)->exists())->toBeTrue();

    $this->actingAs($this->owner)
        ->get(route('dashboard', $this->team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('myProjects.0.id', $this->project->id)
            ->where('myProjects.0.isFavorite', true)
            ->where('myProjects.0.folder.name', '顧客案件')
            ->where('projectFolders.0.name', '顧客案件'));

    $this->actingAs($this->owner)
        ->patch(route('projects.favorite', [$this->team, $this->project]));

    expect($this->owner->favoriteProjects()->whereKey($this->project->id)->exists())->toBeFalse();
});

test('disabled project tools are hidden from direct routes', function () {
    $this->project->update(['enabled_tools' => ['messages']]);

    $this->actingAs($this->owner)
        ->get(route('projects.chat.show', [$this->team, $this->project]))
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->get(route('projects.messages.index', [$this->team, $this->project]))
        ->assertOk();
});

test('project members can log time and only owners or authors can delete entries', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $this->project->members()->attach($member);
    $todoList = TodoList::factory()->for($this->project)->create();
    $todo = Todo::factory()->for($todoList, 'todoList')->create();

    $this->actingAs($member)
        ->post(route('projects.time-entries.store', [$this->team, $this->project]), [
            'worked_on' => '2026-07-16',
            'minutes' => 95,
            'todo_id' => $todo->id,
            'notes' => '設計レビュー',
        ])
        ->assertRedirect();

    $entry = TimeEntry::query()->sole();

    expect($entry->user_id)->toBe($member->id)
        ->and($entry->project_id)->toBe($this->project->id)
        ->and($entry->minutes)->toBe(95);

    $this->actingAs($this->owner)
        ->get(route('projects.time-entries.index', [$this->team, $this->project]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/time-entries/Index')
            ->where('entries.0.notes', '設計レビュー')
            ->where('totalMinutes', 95));

    $this->actingAs($this->owner)
        ->delete(route('projects.time-entries.destroy', [$this->team, $this->project, $entry]))
        ->assertRedirect();

    expect(TimeEntry::query()->exists())->toBeFalse();
});

test('time entries cannot reference a todo from another project', function () {
    $otherProject = Project::factory()->for($this->team)->for($this->owner, 'creator')->create();
    $otherList = TodoList::factory()->for($otherProject)->create();
    $otherTodo = Todo::factory()->for($otherList, 'todoList')->create();

    $this->actingAs($this->owner)
        ->post(route('projects.time-entries.store', [$this->team, $this->project]), [
            'worked_on' => '2026-07-16',
            'minutes' => 30,
            'todo_id' => $otherTodo->id,
        ])
        ->assertSessionHasErrors('todo_id');
});

test('daily time entries cannot exceed twenty four hours', function () {
    TimeEntry::factory()->for($this->project)->for($this->owner)->create([
        'worked_on' => '2026-07-16',
        'minutes' => 1400,
    ]);

    $this->actingAs($this->owner)
        ->post(route('projects.time-entries.store', [$this->team, $this->project]), [
            'worked_on' => '2026-07-16',
            'minutes' => 60,
        ])
        ->assertSessionHasErrors('minutes');

    expect(TimeEntry::query()->count())->toBe(1);
});

test('personal notes are private to their author', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($this->owner)
        ->post(route('notes.store', $this->team), [
            'title' => '個人メモ',
            'body' => '自分だけの下書き',
        ])
        ->assertRedirect();

    $note = PersonalNote::query()->sole();

    $this->actingAs($this->owner)
        ->get(route('notes.index', $this->team))
        ->assertInertia(fn (Assert $page) => $page
            ->component('notes/Index')
            ->has('notes', 1)
            ->where('notes.0.title', '個人メモ'));

    $this->actingAs($member)
        ->get(route('notes.index', $this->team))
        ->assertInertia(fn (Assert $page) => $page->has('notes', 0));

    $this->actingAs($member)
        ->patch(route('notes.update', [$this->team, $note]), [
            'title' => '盗み見',
            'body' => '更新',
        ])
        ->assertForbidden();

    expect($note->fresh()->title)->toBe('個人メモ');
});

test('quick jump only shares projects visible to the signed in member', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $this->project->members()->attach($member);
    Project::factory()->for($this->team)->for($this->owner, 'creator')->create([
        'name' => '機密案件',
    ]);

    $this->actingAs($member)
        ->get(route('dashboard', $this->team))
        ->assertInertia(fn (Assert $page) => $page
            ->has('navigationProjects', 1)
            ->where('navigationProjects.0.id', $this->project->id));
});
