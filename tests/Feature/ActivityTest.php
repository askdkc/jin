<?php

use App\Enums\ActivityType;
use App\Enums\TeamRole;
use App\Models\Activity;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('major project work records concise activity entries', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => '担当メンバー']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($member);
    $list = TodoList::factory()->for($project)->create();

    $this->actingAs($member)
        ->post(route('projects.messages.store', [$team, $project]), [
            'title' => '公開日のお知らせ',
            'body' => '8月1日に公開します。',
        ])
        ->assertRedirect();

    $post = MessageBoardPost::query()->firstOrFail();

    $this->post(route('projects.messages.comments.store', [$team, $project, $post]), [
        'body' => '確認しました。',
    ])->assertRedirect();

    $this->post(route('projects.todos.store', [$team, $project, $list]), [
        'title' => '公開前チェック',
        'assignee_id' => $member->id,
    ])->assertRedirect();

    $todo = Todo::query()->firstOrFail();

    $this->patch(route('projects.todos.toggle', [$team, $project, $list, $todo]))
        ->assertRedirect();

    $this->post(route('projects.schedule.store', [$team, $project]), [
        'title' => '公開ミーティング',
        'starts_at' => '2026-08-03T10:00',
        'ends_at' => '2026-08-03T11:00',
        'all_day' => false,
    ])->assertRedirect();

    $this->post(route('projects.documents.store', [$team, $project]), [
        'title' => '運用手順',
        'body' => '公開手順をまとめます。',
    ])->assertRedirect();

    $this->post(route('projects.files.store', [$team, $project]), [
        'file' => UploadedFile::fake()->create('運用資料.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    $activities = Activity::query()->whereBelongsTo($project)->oldest('id')->get();

    expect($activities)->toHaveCount(7)
        ->and($activities->pluck('type')->all())->toEqual([
            ActivityType::MessageCreated,
            ActivityType::CommentCreated,
            ActivityType::TodoCreated,
            ActivityType::TodoCompleted,
            ActivityType::ScheduleCreated,
            ActivityType::DocumentCreated,
            ActivityType::FileUploaded,
        ])
        ->and($activities->pluck('actor_id')->unique()->all())->toBe([$member->id])
        ->and($activities->firstWhere('type', ActivityType::TodoCompleted)?->metadata['label'])->toBe('公開前チェック')
        ->and($activities->firstWhere('type', ActivityType::FileUploaded)?->metadata['label'])->toBe('運用資料.pdf');
});

test('activity page only exposes active projects visible in the current team', function () {
    $owner = User::factory()->create(['name' => 'オーナー']);
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $visibleProject = Project::factory()->for($team)->for($owner, 'creator')->create(['name' => '参加中']);
    $hiddenProject = Project::factory()->for($team)->for($owner, 'creator')->create(['name' => '未参加']);
    $archivedProject = Project::factory()->archived()->for($team)->for($owner, 'creator')->create();
    $visibleProject->members()->attach($member);
    $archivedProject->members()->attach($member);

    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($member, ['role' => TeamRole::Member->value]);
    $otherProject = Project::factory()->for($otherTeam)->for($owner, 'creator')->create();
    $otherProject->members()->attach($member);

    $visibleActivity = Activity::factory()->for($visibleProject)->for($owner, 'actor')->create([
        'type' => ActivityType::TodoCompleted,
        'metadata' => ['label' => '表示するTo-do'],
    ]);
    Activity::factory()->for($hiddenProject)->for($owner, 'actor')->create();
    Activity::factory()->for($archivedProject)->for($owner, 'actor')->create();
    Activity::factory()->for($otherProject)->for($owner, 'actor')->create();

    $this->actingAs($member)
        ->get(route('activity.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('activity/Index')
            ->has('activities', 1)
            ->where('activities.0.id', $visibleActivity->id)
            ->where('activities.0.type', ActivityType::TodoCompleted->value)
            ->where('activities.0.target', 'todos')
            ->where('activities.0.actorId', $owner->id)
            ->where('activities.0.actorName', 'オーナー')
            ->where('activities.0.subjectLabel', '表示するTo-do')
            ->where('activities.0.subjectId', $visibleActivity->subject_id)
            ->where('activities.0.project.id', $visibleProject->id)
            ->where('activities.0.project.name', '参加中')
            ->missing('activities.0.metadata')
            ->missing('activities.0.subjectType'));
});

test('activity page filters visible work by project person and search words', function () {
    $owner = User::factory()->create(['name' => '織田 信長']);
    $member = User::factory()->create(['name' => '明智 光秀']);
    $outsider = User::factory()->create(['name' => '非公開の担当者']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $launch = Project::factory()->for($team)->for($owner, 'creator')->create(['name' => '公開作戦']);
    $design = Project::factory()->for($team)->for($owner, 'creator')->create(['name' => '意匠作戦']);
    $hidden = Project::factory()->for($team)->for($owner, 'creator')->create(['name' => '非公開作戦']);
    $launch->members()->attach($member);
    $design->members()->attach($member);

    $matchingActivity = Activity::factory()->for($launch)->for($owner, 'actor')->create([
        'metadata' => ['label' => '公開前チェック'],
    ]);
    Activity::factory()->for($launch)->for($owner, 'actor')->create([
        'metadata' => ['label' => '別の仕事'],
    ]);
    Activity::factory()->for($design)->for($member, 'actor')->create([
        'metadata' => ['label' => '公開資料の確認'],
    ]);
    Activity::factory()->for($hidden)->for($outsider, 'actor')->create([
        'metadata' => ['label' => '公開してはいけない仕事'],
    ]);

    $response = $this->actingAs($member)->get(route('activity.index', [
        'current_team' => $team,
        'project' => $launch->id,
        'user' => $owner->id,
        'q' => 'チェック',
    ]));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('activity/Index')
            ->has('activities', 1)
            ->where('activities.0.id', $matchingActivity->id)
            ->has('projects', 2)
            ->has('people', 2)
            ->where('filters.q', 'チェック')
            ->where('filters.project', $launch->id)
            ->where('filters.user', $owner->id));

    expect(collect($response->inertiaProps('projects'))->pluck('id')->all())
        ->toContain($launch->id, $design->id)
        ->not->toContain($hidden->id)
        ->and(collect($response->inertiaProps('people'))->pluck('id')->all())
        ->toContain($owner->id, $member->id)
        ->not->toContain($outsider->id);
});

test('deleting an actor keeps the project activity', function () {
    $activity = Activity::factory()->create();

    $activity->actor->delete();

    expect($activity->fresh())->not->toBeNull()
        ->and($activity->fresh()?->actor_id)->toBeNull();
});
