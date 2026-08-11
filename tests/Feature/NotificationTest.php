<?php

use App\Enums\TeamRole;
use App\Models\ChatChannel;
use App\Models\ChatMessage;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use App\Notifications\MentionNotification;
use App\Notifications\NewChatMessageNotification;
use App\Notifications\NewMessageCommentNotification;
use App\Notifications\NewTodoListCommentNotification;
use App\Notifications\TodoActivityNotification;
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

test('file and chat mentions and to-do list comments expose their navigation targets', function () {
    $actor = User::factory()->create(['name' => '投稿者']);
    $recipient = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($actor, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($recipient, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($actor, 'creator')->create();
    $project->members()->attach($recipient);
    $file = StoredFile::factory()->for($project)->for($actor, 'uploader')->create();
    $channel = ChatChannel::factory()->for($project)->for($actor, 'creator')->create();
    $message = ChatMessage::factory()->for($project)->for($channel, 'channel')->for($actor)->create();
    $list = TodoList::factory()->for($project)->create();
    $recipient->notify(new MentionNotification(
        MentionNotification::File,
        $actor,
        $project,
        $file->original_name,
        $file->id,
    ));
    $recipient->notify(new NewTodoListCommentNotification($actor, $project, $list));
    $recipient->notify(new MentionNotification(
        MentionNotification::Chat,
        $actor,
        $project,
        "#{$channel->name}",
        $message->id,
        $channel->id,
        '本文を確認してください。',
    ));
    $recipient->notify(new NewChatMessageNotification($actor, $project, $channel, $message));

    $response = $this->actingAs($recipient)->get(route('notifications.index', $team));
    $notifications = collect($response->inertiaProps('notifications'));

    expect($notifications->firstWhere('type', MentionNotification::File)['storedFileId'])
        ->toBe($file->id)
        ->and($notifications->firstWhere('type', MentionNotification::Chat)['chatMessageId'])
        ->toBe($message->id)
        ->and($notifications->firstWhere('type', MentionNotification::Chat)['chatChannelId'])
        ->toBe($channel->id)
        ->and($notifications->firstWhere('type', MentionNotification::Chat)['chatChannelName'])
        ->toBe($channel->name)
        ->and($notifications->firstWhere('type', MentionNotification::Chat)['chatMessageExcerpt'])
        ->toBe('本文を確認してください。')
        ->and($notifications->firstWhere('type', 'chat_message')['chatMessageId'])
        ->toBe($message->id)
        ->and($notifications->firstWhere('type', 'chat_message')['chatChannelName'])
        ->toBe($channel->name)
        ->and($notifications->firstWhere('type', 'todo_list_comment')['todoListId'])
        ->toBe($list->id);
});

test('opening a notification marks it as read, removes it from new for you, and redirects to its target', function () {
    $actor = User::factory()->create(['name' => '投稿者']);
    $recipient = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($actor, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($recipient, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($actor, 'creator')->create();
    $project->members()->attach($recipient);
    $channel = ChatChannel::factory()->for($project)->for($actor, 'creator')->create();
    $message = ChatMessage::factory()->for($project)->for($channel, 'channel')->for($actor)->create([
        'body' => '<p>画像の表示を確認してください。</p>',
    ]);
    $recipient->notify(new NewChatMessageNotification($actor, $project, $channel, $message));
    $notification = $recipient->notifications()->firstOrFail();
    $notificationData = $notification->data;
    unset($notificationData['chatMessageExcerpt']);
    $notification->forceFill(['data' => $notificationData])->save();

    $this->actingAs($recipient)
        ->get(route('dashboard', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notificationSummary.unreadCount', 1)
            ->where('workspaceBar.notifications.0.id', $notification->id)
            ->where('workspaceBar.notifications.0.messageTitle', "#{$channel->name}")
            ->where('workspaceBar.notifications.0.chatChannelName', $channel->name)
            ->where('workspaceBar.notifications.0.chatMessageExcerpt', '画像の表示を確認してください。'));

    $this->patch(route('notifications.open', [$team, $notification]))
        ->assertRedirect(route('projects.chat.show', [
            'current_team' => $team,
            'project' => $project,
            'channel' => $channel->id,
        ]));

    expect($notification->fresh()->read_at)->not->toBeNull();

    $this->get(route('dashboard', $team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notificationSummary.unreadCount', 0)
            ->where('workspaceBar.notifications', []));
});

test('users cannot open another users notification', function () {
    $actor = User::factory()->create();
    $recipient = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($actor, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($recipient, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($actor, 'creator')->create();
    $project->members()->attach($recipient);
    $post = MessageBoardPost::factory()->for($project)->for($actor)->create();
    $recipient->notify(new NewMessageCommentNotification($actor, $project, $post));
    $notification = $recipient->notifications()->firstOrFail();

    $this->actingAs($actor)
        ->patch(route('notifications.open', [$team, $notification]))
        ->assertNotFound();

    expect($notification->fresh()->read_at)->toBeNull();
});

test('opening a to-do notification redirects to the specific to-do', function (string $type) {
    $actor = User::factory()->create();
    $recipient = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($actor, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($recipient, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($actor, 'creator')->create();
    $project->members()->attach($recipient);
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($actor, 'creator')->create();
    $recipient->notify(new TodoActivityNotification(
        $type,
        $actor,
        $project,
        $todo,
    ));
    $notification = $recipient->notifications()->firstOrFail();

    $this->actingAs($recipient)
        ->patch(route('notifications.open', [$team, $notification]))
        ->assertRedirect(route('projects.todos.show', [$team, $project, $list, $todo]));

    expect($notification->fresh()->read_at)->not->toBeNull();
})->with([
    'assigned' => TodoActivityNotification::Assigned,
    'commented' => TodoActivityNotification::Commented,
]);

test('opening a to-do mention redirects to the specific to-do', function () {
    $actor = User::factory()->create();
    $recipient = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($actor, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($recipient, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($actor, 'creator')->create();
    $project->members()->attach($recipient);
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($actor, 'creator')->create();
    $recipient->notify(new MentionNotification(
        MentionNotification::Todo,
        $actor,
        $project,
        $todo->title,
        $todo->id,
    ));
    $notification = $recipient->notifications()->firstOrFail();

    expect($notification->data['todoId'])->toBe($todo->id);

    $this->actingAs($recipient)
        ->patch(route('notifications.open', [$team, $notification]))
        ->assertRedirect(route('projects.todos.show', [$team, $project, $list, $todo]));

    expect($notification->fresh()->read_at)->not->toBeNull();
});
