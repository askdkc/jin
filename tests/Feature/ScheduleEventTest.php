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
            ->where('calendarPeriods.data.0.startDate', '2026-07-26')
            ->where('calendarPeriods.data.0.endDate', '2026-09-05')
            ->where('calendarPeriods.data.0.weeks', fn ($weeks): bool => $weeks
                ->flatten(1)
                ->firstWhere('date', '2026-08-03')['events'][0]['title'] === '公開ミーティング'));
});

test('the month calendar continuously loads the next six week period', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $event = ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create([
        'title' => '次の期間の予定',
        'starts_at' => '2026-09-20 10:00:00',
        'ends_at' => '2026-09-20 11:00:00',
    ]);

    $response = $this->actingAs($owner)
        ->get(route('projects.schedule.index', [$team, $project, 'month' => '2026-08']));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendarPeriods.data.0.startDate', '2026-07-26')
            ->where('calendarPeriods.data.0.endDate', '2026-09-05')
            ->where('calendarPeriods.data.0.weeks', fn ($weeks): bool => $weeks
                ->flatten(1)
                ->flatMap(fn (array $day) => $day['events'])
                ->doesntContain('id', $event->id)));

    expect(data_get($response->viewData('page'), 'scrollProps.calendarPeriods'))
        ->toMatchArray([
            'pageName' => 'page',
            'previousPage' => null,
            'nextPage' => 2,
            'currentPage' => 1,
        ]);

    $this->get(route('projects.schedule.index', [
        $team,
        $project,
        'month' => '2026-08',
        'page' => 2,
    ]))->assertInertia(fn (Assert $page) => $page
        ->where('calendarPeriods.data.0.startDate', '2026-09-06')
        ->where('calendarPeriods.data.0.endDate', '2026-10-17')
        ->where('calendarPeriods.data.0.weeks', fn ($weeks): bool => $weeks
            ->flatten(1)
            ->flatMap(fn (array $day) => $day['events'])
            ->contains('id', $event->id)));
});

test('the gantt chart loads adjacent six week windows as json', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($outsider, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $nextEvent = ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create([
        'title' => '次に読み込まれる予定',
        'starts_at' => '2026-09-01 10:00:00',
        'ends_at' => '2026-09-01 11:00:00',
    ]);
    ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create([
        'title' => '範囲外の予定',
        'starts_at' => '2026-10-01 10:00:00',
        'ends_at' => '2026-10-01 11:00:00',
    ]);

    $this->actingAs($owner)
        ->getJson(route('projects.schedule.gantt-window', [
            $team,
            $project,
            'direction' => 'next',
            'boundary' => '2026-08-10',
        ]))
        ->assertOk()
        ->assertJsonPath('rangeStart', '2026-08-11')
        ->assertJsonPath('rangeEnd', '2026-09-21')
        ->assertJsonCount(1, 'events')
        ->assertJsonPath('events.0.id', $nextEvent->id);

    $this->actingAs($owner)
        ->getJson(route('projects.schedule.gantt-window', [
            $team,
            $project,
            'direction' => 'previous',
            'boundary' => '2026-08-10',
        ]))
        ->assertOk()
        ->assertJsonPath('rangeStart', '2026-06-29')
        ->assertJsonPath('rangeEnd', '2026-08-09');

    $this->actingAs($owner)
        ->getJson(route('projects.schedule.gantt-window', [
            $team,
            $project,
            'direction' => 'sideways',
            'boundary' => '2026-08-10',
        ]))
        ->assertUnprocessable();

    $this->actingAs($outsider)
        ->getJson(route('projects.schedule.gantt-window', [
            $team,
            $project,
            'direction' => 'next',
            'boundary' => '2026-08-10',
        ]))
        ->assertForbidden();
});

test('the schedule interface loads months on scroll and keeps a stable shell across views', function () {
    $schedule = file_get_contents(resource_path('js/pages/projects/schedule/Index.svelte'));

    expect($schedule)
        ->toContain('import { Form, InfiniteScroll, Link, useHttp }')
        ->toContain('<InfiniteScroll')
        ->toContain('data="calendarPeriods"')
        ->toContain('buffer={800}')
        ->toContain('onlyNext')
        ->toContain('class="mx-auto w-full max-w-5xl space-y-3 px-4 pt-2 pb-4 sm:px-6 sm:pt-3 sm:pb-6"')
        ->toContain('class="grid gap-2 lg:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] lg:items-center"')
        ->toContain('lg:col-start-2 lg:row-start-1 lg:justify-self-center')
        ->toContain('class="min-h-[38rem] w-full rounded-lg border sm:min-h-[50rem]"')
        ->toContain('class="h-[38rem] w-full overflow-hidden rounded-lg border sm:h-[50rem]"')
        ->toContain('data-test="schedule-gantt-scroll"')
        ->toContain('onscroll={handleGanttScroll}')
        ->toContain('const observer = new IntersectionObserver(')
        ->toContain("use:observeGanttEdge={'previous'}")
        ->toContain("use:observeGanttEdge={'next'}")
        ->toContain("void loadGanttWindow('previous')")
        ->toContain("void loadGanttWindow('next')")
        ->toContain('node.scrollLeft += node.scrollWidth - previousScrollWidth')
        ->toContain('data-test="schedule-view-frame"')
        ->toContain('class="pointer-events-none absolute inset-y-0 z-30 border-l-2 border-primary"')
        ->toMatch('/\{t\(\'Today\'\)\}.*<time\s+datetime=\{today\}.*\{formatShortDate\(today\)\}/s');
});

test('an event must end after it starts', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();

    $this->actingAs($owner)
        ->post(route('projects.schedule.store', [$team, $project]), [
            'title' => 'Zero duration',
            'starts_at' => '2026-08-03T10:00',
            'ends_at' => '2026-08-03T10:00',
            'all_day' => false,
        ])
        ->assertSessionHasErrors('ends_at');

    expect($project->scheduleEvents()->exists())->toBeFalse();
});

test('valid events remain visible in chronological order', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create([
        'starts_at' => '2026-08-03 10:00:00',
        'ends_at' => '2026-08-03 10:30:00',
    ]);
    ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create([
        'starts_at' => '2026-08-03 11:00:00',
        'ends_at' => '2026-08-03 11:30:00',
    ]);

    $this->actingAs($owner)
        ->get(route('projects.schedule.index', [$team, $project, 'view' => 'day', 'date' => '2026-08-03']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('events', 2)
            ->where('events.0.startsAt', '2026-08-03T10:00')
            ->where('events.0.endsAt', '2026-08-03T10:30')
            ->where('events.1.startsAt', '2026-08-03T11:00')
            ->where('events.1.endsAt', '2026-08-03T11:30'));
});

test('the month grid shows a cross-month event on every overlapping day', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $event = ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create([
        'starts_at' => '2026-07-25 23:00:00',
        'ends_at' => '2026-08-02 00:00:00',
    ]);

    $this->actingAs($owner)
        ->get(route('projects.schedule.index', [$team, $project, 'month' => '2026-08']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendarPeriods.data.0.weeks', fn ($weeks): bool => $weeks
                ->flatten(1)
                ->filter(fn (array $day): bool => collect($day['events'])->contains('id', $event->id))
                ->pluck('date')
                ->values()
                ->all() === [
                    '2026-07-26',
                    '2026-07-27',
                    '2026-07-28',
                    '2026-07-29',
                    '2026-07-30',
                    '2026-07-31',
                    '2026-08-01',
                ]));
});

test('project participants can open an event detail and add comments and reactions', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $team->members()->attach($outsider, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($member);
    $event = ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create([
        'title' => '予定の詳細',
    ]);

    $showUrl = route('projects.schedule.show', [$team, $project, $event]);
    $commentUrl = route('projects.schedule.comments.store', [$team, $project, $event]);
    $reactionUrl = route('projects.schedule.reaction.toggle', [$team, $project, $event]);

    $this->actingAs($member)
        ->get($showUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/schedule/Show')
            ->where('event.id', $event->id)
            ->where('event.title', '予定の詳細')
            ->has('event.reactions', 0)
            ->has('event.comments', 0));

    $this->actingAs($member)
        ->post($commentUrl, ['body' => '<div>参加します</div>'])
        ->assertRedirect();

    expect($event->comments()->where('user_id', $member->id)->value('body'))
        ->toContain('参加します');

    $this->actingAs($member)->post($reactionUrl, ['reaction' => '👏'])->assertRedirect();
    expect($event->reactors()->whereKey($member->id)->first()?->pivot?->reaction)->toBe('👏');

    $this->actingAs($member)->post($reactionUrl, ['reaction' => '👏'])->assertRedirect();
    expect($event->reactors()->whereKey($member->id)->exists())->toBeFalse();

    $this->actingAs($outsider)->get($showUrl)->assertForbidden();
    $this->actingAs($outsider)->post($commentUrl, ['body' => '不可'])->assertForbidden();
    $this->actingAs($outsider)->post($reactionUrl, ['reaction' => '👏'])->assertForbidden();
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
        ->get(route('projects.schedule.index', [$team, $project, 'month' => '2026-08']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendarPeriods.data.0.weeks', fn ($weeks): bool => collect(
                $weeks->flatten(1)->firstWhere('date', '2026-08-01')['events'],
            )->contains(fn (array $event): bool => $event['title'] === '8月初日の終日予定'
                && $event['startsAt'] === '2026-08-01T00:00'
                && $event['allDay'] === true)));
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

test('schedule interface labels are translated into Japanese', function () {
    $translations = json_decode(
        file_get_contents(lang_path('ja.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $expectedTranslations = [
        'Type the name of the event…' => '予定名を入力…',
        'Link' => 'リンク',
        'Repeat' => '繰り返し',
        'Does not repeat' => '繰り返さない',
        'Repeat until' => '繰り返しの終了日',
        'Repeats daily' => '毎日繰り返し',
        'Repeats weekly' => '毎週繰り返し',
        'Repeats monthly' => '毎月繰り返し',
        'Post this event' => '予定を投稿する',
        '+:count more' => '他:count件',
        'TODAY' => '今日',
    ];

    foreach ($expectedTranslations as $key => $translation) {
        expect($translations[$key] ?? null)->toBe($translation);
    }
});
