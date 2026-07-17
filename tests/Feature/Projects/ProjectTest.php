<?php

use App\Enums\ActivityType;
use App\Enums\TeamRole;
use App\Models\Activity;
use App\Models\Card;
use App\Models\CardColumn;
use App\Models\CardTable;
use App\Models\ChatMessage;
use App\Models\Document;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('team owners can view their projects', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);

    $response = $this
        ->actingAs($owner)
        ->get(route('projects.index', $team));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/Index')
            ->has('projects', 1)
            ->where('projects.0.id', $project->id)
            ->where('projects.0.name', $project->name)
            ->where('permissions.canCreateProject', true),
        );
});

test('members only see projects they participate in', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $visibleProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $hiddenProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $visibleProject->members()->attach($member);

    $response = $this
        ->actingAs($member)
        ->get(route('projects.index', $team));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('projects', 1)
        ->where('projects.0.id', $visibleProject->id)
        ->where('permissions.canCreateProject', false),
    );

    expect($hiddenProject->is($visibleProject))->toBeFalse();
});

test('team administrators can create projects', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($admin)
        ->post(route('projects.store', $team), [
            'name' => '新商品サイト',
            'description' => '公開準備を管理するプロジェクト',
            'starts_on' => '2026-07-14',
            'ends_on' => '2026-08-31',
            'member_ids' => [$member->id],
        ]);

    $project = Project::query()->where('name', '新商品サイト')->firstOrFail();

    $response->assertRedirect(route('projects.show', [$team, $project]));

    expect($project->team_id)->toBe($team->id)
        ->and($project->created_by)->toBe($admin->id)
        ->and($project->members()->pluck('users.id')->all())
        ->toEqualCanonicalizing([$admin->id, $member->id])
        ->and($project->activities()->sole()->type)->toBe(ActivityType::ProjectCreated);
});

test('project forms use the team menu names', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create([
        'menu_names' => [
            ...Team::DEFAULT_MENU_NAMES,
            'todos' => '指令',
            'calendar' => '予定表',
            'chat' => '談話',
        ],
    ]);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner)
        ->get(route('projects.create', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentTeam.menuNames.todos', '指令')
            ->where('toolOptions.1.label', '指令')
            ->where('toolOptions.3.label', '予定表')
            ->where('toolOptions.4.label', '談話')
            ->where('toolOptions.5.label', '作戦盤')
            ->where('toolOptions.6.label', '振り返り')
            ->where('toolOptions.7.label', '稼働'));
});

test('regular members cannot create projects', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this
        ->actingAs($member)
        ->post(route('projects.store', $team), [
            'name' => 'Unauthorized project',
            'member_ids' => [],
        ])
        ->assertForbidden();

    expect(Project::query()->where('name', 'Unauthorized project')->exists())->toBeFalse();
});

test('project members must belong to the same team', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this
        ->actingAs($owner)
        ->post(route('projects.store', $team), [
            'name' => 'Invalid members',
            'member_ids' => [$outsider->id],
        ])
        ->assertSessionHasErrors('member_ids.0');

    expect(Project::query()->where('name', 'Invalid members')->exists())->toBeFalse();
});

test('project date range is validated', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this
        ->actingAs($owner)
        ->post(route('projects.store', $team), [
            'name' => 'Invalid dates',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-07-01',
            'member_ids' => [],
        ])
        ->assertSessionHasErrors('ends_on');
});

test('project participants can view a project', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($member);

    $this
        ->actingAs($member)
        ->get(route('projects.show', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/Show')
            ->where('project.id', $project->id)
            ->where('permissions.canUpdateProject', false),
        );
});

test('project home summarizes the latest work', function () {
    $this->travelTo('2026-07-15 09:00:00');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);

    MessageBoardPost::factory()->for($project)->create([
        'user_id' => $owner->id,
        'title' => '古いお知らせ',
        'published_at' => now()->subDay(),
    ]);
    MessageBoardPost::factory()->for($project)->create([
        'user_id' => $owner->id,
        'title' => '最新のお知らせ',
        'body' => '公開準備の最終確認をお願いします。',
        'published_at' => now(),
    ]);

    $todoList = TodoList::factory()->for($project)->create();
    Todo::factory()->for($todoList, 'todoList')->create([
        'due_on' => now()->subDay(),
    ]);
    Todo::factory()->for($todoList, 'todoList')->create([
        'completed_at' => now(),
        'completed_by' => $owner->id,
    ]);
    Todo::factory()->for($todoList, 'todoList')->create([
        'title' => '公開前チェック',
        'due_on' => now()->addDay(),
    ]);

    $cardTable = CardTable::factory()->for($project)->create(['name' => '制作フロー']);
    $cardColumn = CardColumn::factory()->for($cardTable)->create(['name' => '確認中']);
    Card::factory()->for($cardColumn)->for($owner, 'creator')->create();

    ScheduleEvent::factory()->for($project)->create([
        'title' => '次の予定',
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(2),
    ]);
    Document::factory()->for($project)->create([
        'title' => '進行資料',
    ]);
    StoredFile::factory()->for($project)->create([
        'original_name' => '要件.pdf',
    ]);
    ChatMessage::factory()->for($project)->create([
        'user_id' => $owner->id,
        'body' => '確認をお願いします',
    ]);
    Activity::factory()->for($project)->for($owner, 'actor')->create([
        'type' => ActivityType::TodoCompleted,
        'metadata' => ['label' => '公開前チェック'],
    ]);

    $this->actingAs($owner)
        ->get(route('projects.show', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/Show')
            ->where('home.messages.count', 2)
            ->where('home.messages.latest.0.title', '最新のお知らせ')
            ->where('home.messages.latest.0.body', '公開準備の最終確認をお願いします。')
            ->where('home.todos.total', 3)
            ->where('home.todos.completed', 1)
            ->where('home.todos.overdue', 1)
            ->where('home.todos.latest.0.title', '公開前チェック')
            ->where('home.cardTables.preview.name', '制作フロー')
            ->where('home.cardTables.preview.columns.0.name', '確認中')
            ->where('home.cardTables.preview.columns.0.cardCount', 1)
            ->where('home.schedule.upcoming.0.title', '次の予定')
            ->where('home.materials.documents.0.title', '進行資料')
            ->where('home.materials.files.0.name', '要件.pdf')
            ->where('home.activity.latest.0.type', ActivityType::TodoCompleted->value)
            ->where('home.activity.latest.0.subjectLabel', '公開前チェック')
            ->where('home.activity.activeLastSevenDays', 1)
            ->where('home.chat.latest.body', '確認をお願いします')
            ->where('home.chat.recent.0.body', '確認をお願いします'),
        );
});

test('project home activity query count does not grow with activity volume', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);
    Activity::factory()->for($project)->for($owner, 'actor')->create();

    $this->actingAs($owner)->get(route('projects.show', [$team, $project]))->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(route('projects.show', [$team, $project]))->assertOk();
    $singleActivityQueryCount = count(DB::getQueryLog());

    Activity::factory()->count(20)->for($project)->for($owner, 'actor')->create();

    DB::flushQueryLog();
    $this->get(route('projects.show', [$team, $project]))->assertOk();
    $manyActivitiesQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($manyActivitiesQueryCount)->toBe($singleActivityQueryCount);
});

test('non participants cannot view a project', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();

    $this
        ->actingAs($member)
        ->get(route('projects.show', [$team, $project]))
        ->assertForbidden();
});

test('projects are scoped to the current team route', function () {
    $owner = User::factory()->create();
    $firstTeam = Team::factory()->create();
    $secondTeam = Team::factory()->create();
    $firstTeam->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $secondTeam->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $secondProject = Project::factory()->for($secondTeam)->for($owner, 'creator')->create();

    $this
        ->actingAs($owner)
        ->get(route('projects.show', [$firstTeam, $secondProject]))
        ->assertNotFound();
});

test('team owners can update projects and participants', function () {
    $owner = User::factory()->create();
    $firstMember = User::factory()->create();
    $secondMember = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($firstMember, ['role' => TeamRole::Member->value]);
    $team->members()->attach($secondMember, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($firstMember);

    $response = $this
        ->actingAs($owner)
        ->patch(route('projects.update', [$team, $project]), [
            'name' => '更新済みプロジェクト',
            'description' => null,
            'starts_on' => null,
            'ends_on' => null,
            'member_ids' => [$secondMember->id],
        ]);

    $response->assertRedirect(route('projects.show', [$team, $project]));

    expect($project->fresh()->name)->toBe('更新済みプロジェクト')
        ->and($project->members()->pluck('users.id')->all())
        ->toEqualCanonicalizing([$secondMember->id]);
});

test('team owners can archive projects', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();

    $this
        ->actingAs($owner)
        ->patch(route('projects.archive', [$team, $project]))
        ->assertRedirect(route('projects.index', $team));

    expect($project->fresh()->archived_at)->not->toBeNull();
});

test('regular members cannot archive projects', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($member);

    $this
        ->actingAs($member)
        ->patch(route('projects.archive', [$team, $project]))
        ->assertForbidden();

    expect($project->fresh()->archived_at)->toBeNull();
});
