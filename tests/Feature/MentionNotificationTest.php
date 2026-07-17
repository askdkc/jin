<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use App\Notifications\MentionNotification;
use Illuminate\Support\Facades\Notification;

test('mentioning a project member in a message notifies them by database and mail', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $mentioned = User::factory()->create(['name' => '田中太郎']);
    $bystander = User::factory()->create(['name' => '傍観者']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($mentioned, ['role' => TeamRole::Member->value]);
    $team->members()->attach($bystander, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$mentioned->id, $bystander->id]);

    $this->actingAs($owner)
        ->post(route('projects.messages.store', [$team, $project]), [
            'title' => 'お知らせ',
            'body' => '<p>@田中太郎 確認をお願いします。</p>',
        ])
        ->assertRedirect();

    Notification::assertSentTo(
        $mentioned,
        MentionNotification::class,
        fn (MentionNotification $notification, array $channels): bool => $notification->type === MentionNotification::Message
            && in_array('mail', $channels, true),
    );
    Notification::assertNotSentTo($bystander, MentionNotification::class);
    Notification::assertNotSentTo($owner, MentionNotification::class);
});

test('mentioning the todo details author notifies them once', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $mentioned = User::factory()->create(['name' => 'Jane Doe']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($mentioned, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($mentioned->id);
    $list = TodoList::factory()->for($project)->create();

    $this->actingAs($owner)
        ->post(route('projects.todos.store', [$team, $project, $list]), [
            'title' => '公開前チェック',
            'details' => '<p>@Jane Doe お願いします。</p>',
        ])
        ->assertRedirect();

    $todo = Todo::query()->firstOrFail();

    Notification::assertSentTo(
        $mentioned,
        MentionNotification::class,
        fn (MentionNotification $notification): bool => $notification->type === MentionNotification::Todo
            && $notification->title === $todo->title,
    );
});

test('mentioning someone outside the project does not notify them', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $outsider = User::factory()->create(['name' => '部外者']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();

    $this->actingAs($owner)
        ->post(route('projects.messages.store', [$team, $project]), [
            'title' => 'お知らせ',
            'body' => '<p>@部外者 確認をお願いします。</p>',
        ])
        ->assertRedirect();

    Notification::assertNotSentTo($outsider, MentionNotification::class);
});
