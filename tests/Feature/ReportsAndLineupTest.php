<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('reports calculate overdue unassigned completed and workload totals', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => '担当者']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create(['name' => '対象案件']);
    $project->members()->attach($member);
    $todoList = TodoList::factory()->for($project)->create();
    Todo::factory()->for($todoList)->for($owner, 'creator')->create([
        'title' => '期限超過',
        'assignee_id' => $member->id,
        'due_on' => today()->subDay(),
    ]);
    Todo::factory()->for($todoList)->for($owner, 'creator')->create([
        'title' => '未割当',
        'assignee_id' => null,
        'due_on' => today()->addDay(),
    ]);
    Todo::factory()->for($todoList)->for($owner, 'creator')->create([
        'title' => '最近完了',
        'assignee_id' => $member->id,
        'completed_at' => now()->subDays(2),
        'completed_by' => $member->id,
    ]);
    Todo::factory()->for($todoList)->for($owner, 'creator')->create([
        'title' => '以前完了',
        'completed_at' => now()->subDays(40),
        'completed_by' => $member->id,
    ]);

    $summaryQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$summaryQueries): void {
        if (str_contains($query->sql, 'completed_last_30_days')) {
            $summaryQueries[] = $query->sql;
        }
    });

    $this->actingAs($member)
        ->get(route('reports.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/Index')
            ->where('summary.incomplete', 2)
            ->where('summary.overdue', 1)
            ->where('summary.unassigned', 1)
            ->where('summary.completedLast30Days', 1)
            ->has('overdue', 1)
            ->where('overdue.0.title', '期限超過')
            ->where('overdue.0.project.name', '対象案件')
            ->has('unassigned', 1)
            ->where('unassigned.0.title', '未割当')
            ->has('workload', 1)
            ->where('workload.0.name', '担当者')
            ->where('workload.0.incompleteCount', 1));

    expect($summaryQueries)->toHaveCount(1);

    $summarySql = str_replace(['"', '`', '[', ']'], '', strtolower($summaryQueries[0]));
    expect($summarySql)->toContain('select projects.id');
});

test('reports exclude inaccessible archived and other team projects', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $otherTeam = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $visibleProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $hiddenProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $archivedProject = Project::factory()->for($team)->for($owner, 'creator')->create(['archived_at' => now()]);
    $otherProject = Project::factory()->for($otherTeam)->for($owner, 'creator')->create();
    $visibleProject->members()->attach($member);
    $archivedProject->members()->attach($member);

    foreach ([$visibleProject, $hiddenProject, $archivedProject, $otherProject] as $project) {
        $list = TodoList::factory()->for($project)->create();
        Todo::factory()->for($list)->for($owner, 'creator')->create([
            'assignee_id' => null,
            'due_on' => today()->subDay(),
        ]);
    }

    $this->actingAs($member)
        ->get(route('reports.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.incomplete', 1)
            ->where('summary.overdue', 1)
            ->where('summary.unassigned', 1)
            ->has('overdue', 1)
            ->where('overdue.0.project.id', $visibleProject->id));
});

test('lineup shows active accessible projects with todo progress and next event', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create([
        'name' => '新製品',
        'starts_on' => '2026-07-01',
        'ends_on' => '2026-09-30',
    ]);
    $project->members()->attach($member);
    $todoList = TodoList::factory()->for($project)->create();
    Todo::factory()->for($todoList)->for($owner, 'creator')->create([
        'completed_at' => now(),
        'completed_by' => $member->id,
    ]);
    Todo::factory()->for($todoList)->for($owner, 'creator')->create([
        'due_on' => today()->subDay(),
    ]);
    ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create([
        'title' => '次の会議',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
    ]);
    ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create([
        'title' => 'その次の会議',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHour(),
    ]);

    $this->actingAs($member)
        ->get(route('lineup.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('lineup/Index')
            ->has('projects', 1)
            ->where('projects.0.name', '新製品')
            ->where('projects.0.startsOn', '2026-07-01')
            ->where('projects.0.endsOn', '2026-09-30')
            ->where('projects.0.todoTotal', 2)
            ->where('projects.0.todoCompleted', 1)
            ->where('projects.0.todoOverdue', 1)
            ->where('projects.0.nextEvent.title', '次の会議'));
});

test('lineup excludes projects the member cannot currently access', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $visible = Project::factory()->for($team)->for($owner, 'creator')->create(['name' => '参加中']);
    Project::factory()->for($team)->for($owner, 'creator')->create(['name' => '非参加']);
    $archived = Project::factory()->for($team)->for($owner, 'creator')->create([
        'name' => '終了済み',
        'archived_at' => now(),
    ]);
    $visible->members()->attach($member);
    $archived->members()->attach($member);

    $this->actingAs($member)
        ->get(route('lineup.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->has('projects', 1)
            ->where('projects.0.id', $visible->id));
});

test('team managers see every active project in reports and lineup', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $first = Project::factory()->for($team)->for($owner, 'creator')->create();
    $second = Project::factory()->for($team)->for($owner, 'creator')->create();

    foreach ([$first, $second] as $project) {
        $list = TodoList::factory()->for($project)->create();
        Todo::factory()->for($list)->for($owner, 'creator')->create();
    }

    $this->actingAs($owner)
        ->get(route('reports.index', $team))
        ->assertInertia(fn (Assert $page) => $page->where('summary.incomplete', 2));

    $this->actingAs($owner)
        ->get(route('lineup.index', $team))
        ->assertInertia(fn (Assert $page) => $page->has('projects', 2));
});
