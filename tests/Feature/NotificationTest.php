<?php

use App\Enums\TeamRole;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\TodoList;
use App\Models\User;
use App\Notifications\MentionNotification;
use App\Notifications\NewMessageCommentNotification;
use App\Notifications\NewTodoListCommentNotification;
use Inertia\Testing\AssertableInertia as Assert;

test('users can view and mark team notifications as read', function () {
    $author = User::factory()->create(['name' => '投稿者']);
    $recipient = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($author, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($recipient, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($author, 'creator')->create(['name' => '公開プロジェクト']);
    $project->members()->attach([$author->id, $recipient->id]);
    $post = MessageBoardPost::factory()->for($project)->for($author)->create(['title' => '公開日のお知らせ']);
    $recipient->notify(new NewMessageCommentNotification($author, $project, $post));

    $this->actingAs($recipient)
        ->get(route('notifications.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('notifications/Index')
            ->where('notificationSummary.unreadCount', 1)
            ->where('notifications.0.actorName', '投稿者')
            ->where('notifications.0.projectName', '公開プロジェクト')
            ->where('notifications.0.messageTitle', '公開日のお知らせ')
            ->where('notifications.0.read', false));

    $this->patch(route('notifications.read-all', $team))
        ->assertRedirect(route('notifications.index', $team));

    expect($recipient->unreadNotifications()->count())->toBe(0);
});

test('file mentions and to-do list comments expose their navigation targets', function () {
    $actor = User::factory()->create(['name' => '投稿者']);
    $recipient = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($actor, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($recipient, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($actor, 'creator')->create();
    $project->members()->attach($recipient);
    $file = StoredFile::factory()->for($project)->for($actor, 'uploader')->create();
    $list = TodoList::factory()->for($project)->create();
    $recipient->notify(new MentionNotification(
        MentionNotification::File,
        $actor,
        $project,
        $file->original_name,
        $file->id,
    ));
    $recipient->notify(new NewTodoListCommentNotification($actor, $project, $list));

    $response = $this->actingAs($recipient)->get(route('notifications.index', $team));
    $notifications = collect($response->inertiaProps('notifications'));

    expect($notifications->firstWhere('type', MentionNotification::File)['storedFileId'])
        ->toBe($file->id)
        ->and($notifications->firstWhere('type', 'todo_list_comment')['todoListId'])
        ->toBe($list->id);
});
