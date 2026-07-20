<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use App\Notifications\TodoActivityNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

test('todo assignment comments and completion notify related users except the actor', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $assignee = User::factory()->create();
    $commenter = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($assignee, ['role' => TeamRole::Member->value]);
    $team->members()->attach($commenter, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $assignee->id, $commenter->id]);
    $list = TodoList::factory()->for($project)->create();

    $this->actingAs($owner)
        ->post(route('projects.todos.store', [$team, $project, $list]), [
            'title' => '公開前チェック',
            'assignee_id' => $assignee->id,
            'due_on' => now()->addDay()->toDateString(),
        ])
        ->assertRedirect();

    $todo = Todo::query()->firstOrFail();

    Notification::assertSentTo(
        $assignee,
        TodoActivityNotification::class,
        fn (TodoActivityNotification $notification, array $channels): bool => $notification->type === TodoActivityNotification::Assigned
            && in_array('mail', $channels, true),
    );
    Notification::assertNotSentTo($owner, TodoActivityNotification::class);

    Notification::fake();

    $this->actingAs($commenter)
        ->post(route('projects.todos.comments.store', [$team, $project, $list, $todo]), [
            'body' => '確認しました。',
        ])
        ->assertRedirect();

    Notification::assertSentTo(
        [$owner, $assignee],
        TodoActivityNotification::class,
        fn (TodoActivityNotification $notification): bool => $notification->type === TodoActivityNotification::Commented,
    );
    Notification::assertNotSentTo($commenter, TodoActivityNotification::class);

    Notification::fake();

    $this->patch(route('projects.todos.toggle', [$team, $project, $list, $todo]))
        ->assertRedirect();

    Notification::assertSentTo(
        [$owner, $assignee],
        TodoActivityNotification::class,
        fn (TodoActivityNotification $notification): bool => $notification->type === TodoActivityNotification::Completed,
    );
    Notification::assertNotSentTo($commenter, TodoActivityNotification::class);
});

test('completion watchers chosen on the todo are notified when it is done', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $watcher = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($watcher, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $watcher->id]);
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create([
        'completion_notify_ids' => [$watcher->id],
    ]);

    $this->actingAs($owner)
        ->patch(route('projects.todos.toggle', [$team, $project, $list, $todo]))
        ->assertRedirect();

    Notification::assertSentTo(
        $watcher,
        TodoActivityNotification::class,
        fn (TodoActivityNotification $notification): bool => $notification->type === TodoActivityNotification::Completed,
    );
});

test('due todo reminders are sent once per day', function () {
    $owner = User::factory()->create();
    $assignee = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($assignee, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $assignee->id]);
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->for($assignee, 'assignee')->create([
        'due_on' => today(),
    ]);
    Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->for($assignee, 'assignee')->create([
        'due_on' => today(),
        'completed_at' => now(),
        'completed_by' => $owner->id,
    ]);

    $this->artisan('todos:send-due-reminders')->assertSuccessful();
    $this->artisan('todos:send-due-reminders')->assertSuccessful();

    expect($assignee->notifications()
        ->where('data->type', TodoActivityNotification::Due)
        ->where('data->todoId', $todo->id)
        ->count())->toBe(1);

    $this->actingAs($assignee)
        ->get(route('notifications.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.0.type', TodoActivityNotification::Due)
            ->where('notifications.0.todoTitle', $todo->title)
            ->where('notifications.0.actorName', null));
});

test('due todo reminders respect the assignees reminder setting', function () {
    $owner = User::factory()->create();
    $assignee = User::factory()->create([
        'notification_preferences' => [
            ...User::DEFAULT_NOTIFICATION_PREFERENCES,
            'assignment_reminders' => false,
        ],
    ]);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($assignee, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $assignee->id]);
    $list = TodoList::factory()->for($project)->create();
    Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->for($assignee, 'assignee')->create([
        'due_on' => today(),
    ]);

    $this->artisan('todos:send-due-reminders')
        ->expectsOutput('0 due reminder(s) sent.')
        ->assertSuccessful();

    expect($assignee->notifications()->count())->toBe(0);
});
