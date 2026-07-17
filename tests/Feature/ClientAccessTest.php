<?php

use App\Broadcasting\ProjectChatChannel;
use App\Enums\ActivityType;
use App\Enums\TeamRole;
use App\Models\Activity;
use App\Models\Document;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use App\Notifications\NewMessageCommentNotification;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

test('client access is denied by default and granted by project or individual user', function () {
    $owner = User::factory()->create();
    $client = User::factory()->create();
    $otherClient = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($client, ['role' => TeamRole::Client->value]);
    $team->members()->attach($otherClient, ['role' => TeamRole::Client->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $privateProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $allClientsProject = Project::factory()->for($team)->for($owner, 'creator')->create([
        'allows_all_clients' => true,
    ]);
    $individualProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $individualProject->members()->attach($client);

    $channel = app(ProjectChatChannel::class);

    expect(collect(TeamRole::assignable())->pluck('value'))->toContain(TeamRole::Client->value)
        ->and($channel->join($client, $privateProject))->toBeFalse()
        ->and($channel->join($client, $allClientsProject))->toBeTrue()
        ->and($channel->join($client, $individualProject))->toBeTrue()
        ->and($channel->join($otherClient, $individualProject))->toBeFalse()
        ->and($channel->join($member, $allClientsProject))->toBeFalse();

    $this->actingAs($client)
        ->get(route('projects.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->has('projects', 2)
            ->where('projects', fn (Collection $projects): bool => $projects
                ->pluck('id')
                ->sort()
                ->values()
                ->all() === collect([$allClientsProject->id, $individualProject->id])->sort()->values()->all()));

    $this->get(route('projects.show', [$team, $privateProject]))->assertForbidden();
    $this->get(route('projects.show', [$team, $allClientsProject]))->assertOk();
    $this->get(route('projects.show', [$team, $individualProject]))->assertOk();
    $this->get(route('teams.edit', $team))->assertForbidden();
});

test('team managers can configure project and individual client access', function () {
    $owner = User::factory()->create();
    $client = User::factory()->create(['name' => '取引先担当']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($client, ['role' => TeamRole::Client->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();

    $this->actingAs($owner)
        ->get(route('projects.edit', [$team, $project]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('project.allowsAllClients', false)
            ->where('teamMembers', fn (Collection $members): bool => $members->contains(
                fn (array $member): bool => $member['id'] === $client->id
                    && $member['role'] === TeamRole::Client->value,
            )));

    $this->patch(route('projects.update', [$team, $project]), [
        'name' => $project->name,
        'description' => $project->description,
        'allows_all_clients' => true,
        'member_ids' => [],
    ])->assertRedirect(route('projects.show', [$team, $project]));

    expect($project->fresh()->allows_all_clients)->toBeTrue();

    $this->actingAs($client)
        ->post(route('projects.messages.store', [$team, $project]), [
            'title' => 'クライアントからの確認',
            'body' => '共有内容を確認しました。',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('message_board_posts', [
        'project_id' => $project->id,
        'user_id' => $client->id,
        'title' => 'クライアントからの確認',
    ]);
});

test('client project boundaries apply to every workspace view', function () {
    $owner = User::factory()->create();
    $client = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($client, ['role' => TeamRole::Client->value]);

    $allClientsProject = Project::factory()->for($team)->for($owner, 'creator')->create([
        'name' => '全社外共有',
        'allows_all_clients' => true,
    ]);
    $individualProject = Project::factory()->for($team)->for($owner, 'creator')->create([
        'name' => '個別共有',
    ]);
    $individualProject->members()->attach($client);
    $hiddenProject = Project::factory()->for($team)->for($owner, 'creator')->create([
        'name' => '社内限定',
    ]);

    foreach ([$allClientsProject, $individualProject, $hiddenProject] as $project) {
        Document::factory()->for($project)->for($owner, 'creator')->create([
            'title' => "{$project->name}の資料",
        ]);
        Activity::factory()->for($project)->for($owner, 'actor')->create([
            'type' => ActivityType::TodoCreated,
            'metadata' => ['label' => "{$project->name}の更新"],
        ]);
        ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create([
            'starts_at' => '2026-08-05 10:00:00',
            'ends_at' => '2026-08-05 11:00:00',
        ]);
        $list = TodoList::factory()->for($project)->create();
        Todo::factory()->for($list)->for($owner, 'creator')->create([
            'assignee_id' => $client->id,
        ]);
    }

    $visibleIds = collect([$allClientsProject->id, $individualProject->id])->sort()->values()->all();
    $hasOnlyVisibleProjects = fn (Collection $items): bool => $items
        ->pluck('project.id')
        ->unique()
        ->sort()
        ->values()
        ->all() === $visibleIds;

    $this->actingAs($client)
        ->get(route('dashboard', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->has('myProjects', 2)
            ->where('myProjects', fn (Collection $projects): bool => $projects
                ->pluck('id')->sort()->values()->all() === $visibleIds)
            ->has('myWork.later', 2)
            ->where('myWork.later', $hasOnlyVisibleProjects));

    $this->get(route('activity.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->has('activities', 2)
            ->where('activities', $hasOnlyVisibleProjects));

    $this->get(route('calendar.index', [$team, 'month' => '2026-08']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('events', 2)
            ->where('events', $hasOnlyVisibleProjects));

    $this->get(route('everything.index', [$team, 'type' => 'document']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('results.data', 2)
            ->where('results.data', $hasOnlyVisibleProjects)
            ->has('projects', 2));

    $this->get(route('reports.index', $team))
        ->assertInertia(fn (Assert $page) => $page->where('summary.incomplete', 2));

    $this->get(route('lineup.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->has('projects', 2)
            ->where('projects', fn (Collection $projects): bool => $projects
                ->pluck('id')->sort()->values()->all() === $visibleIds));
});

test('revoked project access immediately hides notifications and unread counts', function () {
    $owner = User::factory()->create();
    $client = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($client, ['role' => TeamRole::Client->value]);
    $visibleProject = Project::factory()->for($team)->for($owner, 'creator')->create([
        'name' => '公開案件',
        'allows_all_clients' => true,
    ]);
    $hiddenProject = Project::factory()->for($team)->for($owner, 'creator')->create([
        'name' => '非公開案件',
    ]);

    foreach ([$visibleProject, $hiddenProject] as $project) {
        $post = MessageBoardPost::factory()->for($project)->for($owner, 'user')->create();
        $client->notify(new NewMessageCommentNotification($owner, $project, $post));
    }

    $this->actingAs($client)
        ->get(route('notifications.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notificationSummary.unreadCount', 1)
            ->has('notifications', 1)
            ->where('notifications.0.projectId', $visibleProject->id));

    $this->patch(route('notifications.read-all', $team))->assertRedirect();
    expect($client->unreadNotifications()->count())->toBe(1);

    $visibleProject->update(['allows_all_clients' => false]);

    $this->get(route('notifications.index', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notificationSummary.unreadCount', 0)
            ->has('notifications', 0));
});
