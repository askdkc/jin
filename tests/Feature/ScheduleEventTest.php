<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('project participants can create and view events for a selected month', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($member);

    $this->actingAs($member)
        ->post(route('projects.schedule.store', [$team, $project]), [
            'title' => '公開ミーティング',
            'details' => 'オンライン会議',
            'starts_at' => '2026-08-03T10:00',
            'ends_at' => '2026-08-03T11:00',
            'all_day' => false,
        ])
        ->assertRedirect();

    ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create([
        'starts_at' => '2026-09-01 10:00:00',
        'ends_at' => '2026-09-01 11:00:00',
    ]);

    $this->actingAs($member)
        ->get(route('projects.schedule.index', [$team, $project, 'month' => '2026-08']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/schedule/Index')
            ->where('month', '2026-08')
            ->has('events', 1)
            ->where('events.0.title', '公開ミーティング')
            ->where('events.0.startsAt', '2026-08-03T10:00'));
});

test('the schedule index supports day, week, and gantt views scoped to their own date range', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create([
        'starts_on' => '2026-08-01',
        'ends_on' => '2026-09-30',
    ]);
    ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create([
        'title' => '週内の予定',
        'starts_at' => '2026-08-05 10:00:00',
        'ends_at' => '2026-08-08 11:00:00',
    ]);
    ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create([
        'title' => '来月の予定',
        'starts_at' => '2026-09-15 10:00:00',
        'ends_at' => '2026-09-15 11:00:00',
    ]);

    $this->actingAs($owner)
        ->get(route('projects.schedule.index', [$team, $project, 'view' => 'day', 'date' => '2026-08-05']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'day')
            ->where('rangeStart', '2026-08-05')
            ->where('rangeEnd', '2026-08-05')
            ->where('weeks', null)
            ->has('events', 1)
            ->where('events.0.title', '週内の予定')
            ->where('events.0.endsAt', '2026-08-08T11:00'));

    $this->actingAs($owner)
        ->get(route('projects.schedule.index', [$team, $project, 'view' => 'week', 'date' => '2026-08-05']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'week')
            ->where('rangeStart', '2026-08-02')
            ->where('rangeEnd', '2026-08-08')
            ->has('events', 1)
            ->where('events.0.title', '週内の予定'));

    $this->actingAs($owner)
        ->get(route('projects.schedule.index', [$team, $project, 'view' => 'gantt', 'date' => '2026-08-05']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'gantt')
            ->where('rangeStart', '2026-08-01')
            ->where('rangeEnd', '2026-09-30')
            ->where('ganttUsesProjectDates', true)
            ->has('events', 2)
            ->where('events.0.endsAt', '2026-08-08T11:00'));
});

test('schedule event date ranges are validated', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();

    $this->actingAs($owner)
        ->post(route('projects.schedule.store', [$team, $project]), [
            'title' => '不正な予定',
            'starts_at' => '2026-08-03T11:00',
            'ends_at' => '2026-08-03T10:00',
            'all_day' => false,
        ])
        ->assertSessionHasErrors('ends_at');
});

test('all day events respect the Tokyo calendar month boundary', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create([
        'title' => '8月初日の終日予定',
        'starts_at' => '2026-08-01 00:00:00',
        'ends_at' => '2026-08-01 23:59:00',
        'all_day' => true,
    ]);

    $this->actingAs($owner)
        ->get(route('projects.schedule.index', [$team, $project, 'month' => '2026-07']))
        ->assertInertia(fn (Assert $page) => $page->has('events', 0));

    $this->actingAs($owner)
        ->get(route('projects.schedule.index', [$team, $project, 'month' => '2026-08']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('events', 1)
            ->where('events.0.title', '8月初日の終日予定')
            ->where('events.0.startsAt', '2026-08-01T00:00')
            ->where('events.0.allDay', true));
});

test('event authors and team managers can edit while other members cannot', function () {
    $owner = User::factory()->create();
    $author = User::factory()->create();
    $otherMember = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($author, ['role' => TeamRole::Member->value]);
    $team->members()->attach($otherMember, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$author->id, $otherMember->id]);
    $event = ScheduleEvent::factory()->for($project)->for($author, 'creator')->create();

    $payload = [
        'title' => '更新済み予定',
        'starts_at' => '2026-08-04T10:00',
        'ends_at' => '2026-08-04T11:00',
        'all_day' => false,
    ];

    $this->actingAs($otherMember)
        ->patch(route('projects.schedule.update', [$team, $project, $event]), $payload)
        ->assertForbidden();

    $this->actingAs($owner)
        ->patch(route('projects.schedule.update', [$team, $project, $event]), $payload)
        ->assertRedirect();

    expect($event->fresh()->title)->toBe('更新済み予定');
});

test('schedule routes reject non participants and cross project bindings', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $visibleProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $otherProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $visibleProject->members()->attach($member);
    $event = ScheduleEvent::factory()->for($otherProject)->for($owner, 'creator')->create();

    $this->actingAs($member)
        ->get(route('projects.schedule.index', [$team, $otherProject]))
        ->assertForbidden();

    $this->actingAs($member)
        ->delete(route('projects.schedule.destroy', [$team, $visibleProject, $event]))
        ->assertNotFound();
});

test('a weekly recurrence materializes one event per week sharing a group id', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();

    $this->actingAs($owner)
        ->post(route('projects.schedule.store', [$team, $project]), [
            'title' => '週次定例',
            'starts_at' => '2026-08-03T10:00',
            'ends_at' => '2026-08-03T11:00',
            'recurrence' => 'weekly',
            'recurrence_until' => '2026-08-24',
        ])
        ->assertRedirect();

    $events = ScheduleEvent::query()->orderBy('starts_at')->get();

    expect($events)->toHaveCount(4)
        ->and($events->pluck('recurrence')->unique()->all())->toBe(['weekly'])
        ->and($events->pluck('recurrence_group_id')->unique())->toHaveCount(1)
        ->and($events->pluck('starts_at')->map->format('Y-m-d')->all())->toBe([
            '2026-08-03',
            '2026-08-10',
            '2026-08-17',
            '2026-08-24',
        ]);

    $secondOccurrence = $events[1];

    $this->actingAs($owner)
        ->patch(route('projects.schedule.update', [$team, $project, $secondOccurrence]), [
            'title' => '週次定例（変更）',
            'starts_at' => $secondOccurrence->starts_at->format('Y-m-d\\TH:i'),
            'ends_at' => $secondOccurrence->ends_at->format('Y-m-d\\TH:i'),
        ])
        ->assertRedirect();

    expect($secondOccurrence->fresh()->title)->toBe('週次定例（変更）')
        ->and($secondOccurrence->fresh()->recurrence)->toBe('weekly')
        ->and($events[0]->fresh()->title)->toBe('週次定例');
});

test('an event without a recurrence rule creates only one occurrence', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();

    $this->actingAs($owner)
        ->post(route('projects.schedule.store', [$team, $project]), [
            'title' => '単発ミーティング',
            'starts_at' => '2026-08-03T10:00',
            'ends_at' => '2026-08-03T11:00',
        ])
        ->assertRedirect();

    $event = ScheduleEvent::query()->sole();

    expect($event->recurrence)->toBeNull()
        ->and($event->recurrence_group_id)->toBeNull();
});
