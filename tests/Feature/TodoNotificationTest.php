<?php

use App\Enums\TeamRole;
use App\Jobs\QueueTodoDueReminder;
use App\Models\Project;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\TodoReminderDelivery;
use App\Models\User;
use App\Notifications\MentionNotification;
use App\Notifications\TodoActivityNotification;
use App\Support\TodoReminderDispatcher;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

test('to-do notification mail opens the specific to-do detail', function () {
    $actor = User::factory()->create();
    $recipient = User::factory()->create();
    $team = Team::factory()->create();
    $project = Project::factory()->for($team)->for($actor, 'creator')->create();
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($actor, 'creator')->create();

    $mail = (new TodoActivityNotification(
        TodoActivityNotification::Commented,
        $actor,
        $project,
        $todo,
    ))->toMail($recipient);

    expect($mail->actionUrl)->toBe(route('projects.todos.show', [$team, $project, $list, $todo]));
});

test('to-do mention mail opens the specific to-do detail', function () {
    $actor = User::factory()->create();
    $recipient = User::factory()->create();
    $team = Team::factory()->create();
    $project = Project::factory()->for($team)->for($actor, 'creator')->create();
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($actor, 'creator')->create();

    $mail = (new MentionNotification(
        MentionNotification::Todo,
        $actor,
        $project,
        $todo->title,
        $todo->id,
    ))->toMail($recipient);

    expect($mail->actionUrl)->toBe(route('projects.todos.show', [$team, $project, $list, $todo]));
});

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

test('asynchronous due reminder commands create and queue one durable delivery', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $assignee = User::factory()->create([
        'notification_preferences' => [
            ...User::DEFAULT_NOTIFICATION_PREFERENCES,
            'in_app' => false,
            'email' => true,
        ],
    ]);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($assignee, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $assignee->id]);
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->for($assignee, 'assignee')->create([
        'due_on' => today(),
    ]);

    $this->artisan('todos:send-due-reminders')->assertSuccessful();
    $this->artisan('todos:send-due-reminders')->assertSuccessful();

    expect(TodoReminderDelivery::query()->count())->toBe(1)
        ->and(TodoReminderDelivery::query()->sole()->todo_id)->toBe($todo->id);
    Queue::assertPushed(QueueTodoDueReminder::class, 1);
});

test('due reminder jobs revalidate the current recipient access and reminder date', function () {
    Notification::fake();
    $owner = User::factory()->create();
    $previousAssignee = User::factory()->create();
    $currentAssignee = User::factory()->create();
    $removedAssignee = User::factory()->create();
    $pastAssignee = User::factory()->create();
    $team = Team::factory()->create();

    foreach ([$owner, $previousAssignee, $currentAssignee, $removedAssignee, $pastAssignee] as $user) {
        $team->members()->attach($user, [
            'role' => $user->is($owner) ? TeamRole::Owner->value : TeamRole::Member->value,
        ]);
    }

    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([
        $owner->id,
        $previousAssignee->id,
        $currentAssignee->id,
        $removedAssignee->id,
        $pastAssignee->id,
    ]);
    $list = TodoList::factory()->for($project)->create();
    $reassignedTodo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->for($currentAssignee, 'assignee')->create([
        'due_on' => today(),
    ]);
    $accessRevokedTodo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->for($removedAssignee, 'assignee')->create([
        'due_on' => today(),
    ]);
    $pastTodo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->for($pastAssignee, 'assignee')->create([
        'due_on' => today()->subDay(),
    ]);
    $project->members()->detach($removedAssignee);

    $deliveries = collect([
        TodoReminderDelivery::query()->create([
            'todo_id' => $reassignedTodo->id,
            'user_id' => $previousAssignee->id,
            'reminder_on' => today(),
            'dispatched_at' => now(),
        ]),
        TodoReminderDelivery::query()->create([
            'todo_id' => $accessRevokedTodo->id,
            'user_id' => $removedAssignee->id,
            'reminder_on' => today(),
            'dispatched_at' => now(),
        ]),
        TodoReminderDelivery::query()->create([
            'todo_id' => $pastTodo->id,
            'user_id' => $pastAssignee->id,
            'reminder_on' => today()->subDay(),
            'dispatched_at' => now(),
        ]),
    ]);

    $deliveries->each(fn (TodoReminderDelivery $delivery) => (new QueueTodoDueReminder($delivery->id))->handle());

    Notification::assertNothingSent();
    expect($deliveries->map(fn (TodoReminderDelivery $delivery): ?string => $delivery->refresh()->skip_reason)->all())
        ->toBe([
            'assignee_changed',
            'project_access_revoked',
            'reminder_date_expired',
        ])
        ->and($deliveries->every(fn (TodoReminderDelivery $delivery): bool => $delivery->refresh()->queued_at === null))
        ->toBeTrue();
});

test('due reminder jobs reclaim stale processing without sending twice', function () {
    Notification::fake();
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
    $delivery = TodoReminderDelivery::query()->create([
        'todo_id' => $todo->id,
        'user_id' => $assignee->id,
        'reminder_on' => today(),
        'dispatched_at' => now(),
        'processing_at' => now(),
    ]);
    $job = new QueueTodoDueReminder($delivery->id);

    $job->handle();
    Notification::assertNothingSent();

    $this->travel(5)->minutes();
    $job->handle();
    $job->handle();

    expect(Notification::sent($assignee, TodoActivityNotification::class))->toHaveCount(1)
        ->and($delivery->refresh()->processing_at)->toBeNull()
        ->and($delivery->queued_at)->not->toBeNull()
        ->and($delivery->skipped_at)->toBeNull();
});

test('pending due reminder dispatch failures return a failure and retry later the same day', function () {
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
    $delivery = TodoReminderDelivery::query()->create([
        'todo_id' => $todo->id,
        'user_id' => $assignee->id,
        'reminder_on' => today(),
    ]);
    $unavailableBus = Mockery::mock(Dispatcher::class);
    $unavailableBus->shouldReceive('dispatch')
        ->once()
        ->andThrow(new RuntimeException('queue unavailable'));
    $this->app->instance(Dispatcher::class, $unavailableBus);

    $this->artisan('todos:dispatch-pending-reminders')
        ->expectsOutputToContain('Failed to queue 1 pending to-do reminder deliveries.')
        ->assertFailed();

    expect($delivery->refresh()->dispatched_at)->toBeNull()
        ->and($delivery->last_error)->toBe('queue unavailable');

    $recoveredBus = Mockery::mock(Dispatcher::class);
    $recoveredBus->shouldReceive('dispatch')
        ->once()
        ->with(Mockery::type(QueueTodoDueReminder::class))
        ->andReturn(1);
    $this->app->instance(Dispatcher::class, $recoveredBus);

    $this->artisan('todos:dispatch-pending-reminders')->assertSuccessful();

    expect($delivery->refresh()->dispatched_at)->not->toBeNull()
        ->and($delivery->last_error)->toBeNull();
});

test('terminal due reminder failures become dispatchable again without reopening finished deliveries', function () {
    $owner = User::factory()->create();
    $assignee = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($assignee, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $assignee->id]);
    $list = TodoList::factory()->for($project)->create();
    $todos = Todo::factory()
        ->count(3)
        ->for($list, 'todoList')
        ->for($owner, 'creator')
        ->for($assignee, 'assignee')
        ->create(['due_on' => today()]);
    $failedDelivery = TodoReminderDelivery::query()->create([
        'todo_id' => $todos[0]->id,
        'user_id' => $assignee->id,
        'reminder_on' => today(),
        'dispatched_at' => now(),
        'processing_at' => now(),
    ]);
    $queuedDelivery = TodoReminderDelivery::query()->create([
        'todo_id' => $todos[1]->id,
        'user_id' => $assignee->id,
        'reminder_on' => today(),
        'dispatched_at' => now(),
        'queued_at' => now(),
    ]);
    $skippedDelivery = TodoReminderDelivery::query()->create([
        'todo_id' => $todos[2]->id,
        'user_id' => $assignee->id,
        'reminder_on' => today(),
        'dispatched_at' => now(),
        'skipped_at' => now(),
        'skip_reason' => 'todo_completed',
    ]);
    $exception = new RuntimeException('terminal reminder failure');

    (new QueueTodoDueReminder($failedDelivery->id))->failed($exception);
    (new QueueTodoDueReminder($queuedDelivery->id))->failed($exception);
    (new QueueTodoDueReminder($skippedDelivery->id))->failed($exception);

    expect($failedDelivery->refresh())
        ->dispatched_at->toBeNull()
        ->processing_at->toBeNull()
        ->last_error->toBe('terminal reminder failure')
        ->and($queuedDelivery->refresh()->dispatched_at)->not->toBeNull()
        ->and($queuedDelivery->last_error)->toBeNull()
        ->and($skippedDelivery->refresh()->dispatched_at)->not->toBeNull()
        ->and($skippedDelivery->last_error)->toBeNull();

    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')
        ->once()
        ->with(Mockery::type(QueueTodoDueReminder::class))
        ->andReturn(1);
    $this->app->instance(Dispatcher::class, $bus);

    $result = app(TodoReminderDispatcher::class)->dispatchPending();

    expect($result)->toBe(['dispatched' => 1, 'failed' => 0])
        ->and($failedDelivery->refresh()->dispatched_at)->not->toBeNull()
        ->and($failedDelivery->processing_at)->toBeNull()
        ->and($failedDelivery->last_error)->toBeNull();
});

test('pending due reminder dispatcher is registered with the scheduler', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('todos:send-due-reminders')
        ->expectsOutputToContain('todos:dispatch-pending-reminders')
        ->assertSuccessful();
});
