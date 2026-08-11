<?php

use App\Enums\ActivityType;
use App\Enums\TeamRole;
use App\Models\Activity;
use App\Models\CheckIn;
use App\Models\CheckInPrompt;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use App\Notifications\CheckInDueNotification;
use App\Notifications\MentionNotification;
use App\Notifications\NewMessageCommentNotification;
use App\Notifications\TodoActivityNotification;
use Illuminate\Support\Facades\Notification;

/**
 * @return array{owner: User, replier: User, subscriber: User, mentioned: User, team: Team, project: Project}
 */
function notificationReplyWorkspace(): array
{
    $owner = User::factory()->create();
    $replier = User::factory()->create();
    $subscriber = User::factory()->create();
    $mentioned = User::factory()->create(['name' => '田中太郎']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($replier, ['role' => TeamRole::Member->value]);
    $team->members()->attach($subscriber, ['role' => TeamRole::Member->value]);
    $team->members()->attach($mentioned, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([
        $owner->id,
        $replier->id,
        $subscriber->id,
        $mentioned->id,
    ]);

    return compact('owner', 'replier', 'subscriber', 'mentioned', 'team', 'project');
}

test('message notification replies run the complete comment workflow', function () {
    ['owner' => $owner, 'replier' => $replier, 'subscriber' => $subscriber, 'mentioned' => $mentioned, 'team' => $team, 'project' => $project] = notificationReplyWorkspace();
    $message = MessageBoardPost::factory()->for($project)->for($owner)->create([
        'title' => '公開日のお知らせ',
    ]);
    $message->subscribers()->attach([$owner->id, $replier->id, $subscriber->id]);
    $replier->notify(new NewMessageCommentNotification($owner, $project, $message));
    $sourceNotification = $replier->notifications()->firstOrFail();
    $file = StoredFile::withoutEvents(fn (): StoredFile => StoredFile::factory()
        ->for($project)
        ->for($replier, 'uploader')
        ->create([
            'is_inline' => true,
            'preview_status' => null,
        ]));

    Notification::fake();

    $this->actingAs($replier)
        ->post(route('notifications.reply', [$team, $sourceNotification]), [
            'body' => "<script>alert('x')</script><p>@{$mentioned->name} 確認しました。</p><p><a href=\"/files/{$file->id}/preview\">添付</a></p>",
        ])
        ->assertRedirect();

    $comment = $message->comments()->sole();
    $activity = Activity::query()
        ->where('subject_type', $comment->getMorphClass())
        ->where('subject_id', $comment->id)
        ->sole();

    expect($comment)
        ->project_id->toBe($project->id)
        ->user_id->toBe($replier->id)
        ->commentable_type->toBe($message->getMorphClass())
        ->commentable_id->toBe($message->id)
        ->and($comment->body)->toContain($mentioned->name)
        ->not->toContain('<script')
        ->and($file->fresh())
        ->attachable_type->toBe($comment->getMorphClass())
        ->attachable_id->toBe($comment->id)
        ->and($activity)
        ->type->toBe(ActivityType::CommentCreated)
        ->actor_id->toBe($replier->id)
        ->metadata->toBe(['label' => $message->title])
        ->and($sourceNotification->fresh()->read_at)->not->toBeNull();

    Notification::assertSentTo(
        [$owner, $subscriber],
        NewMessageCommentNotification::class,
        fn (NewMessageCommentNotification $notification): bool => $notification->messageBoardPost->is($message),
    );
    Notification::assertNotSentTo($replier, NewMessageCommentNotification::class);
    Notification::assertSentTo(
        $mentioned,
        MentionNotification::class,
        fn (MentionNotification $notification): bool => $notification->type === MentionNotification::Message
            && $notification->targetId === $message->id,
    );
    Notification::assertNotSentTo($replier, MentionNotification::class);
});

test('every todo notification reply runs the complete todo comment workflow', function (string $sourceType) {
    ['owner' => $owner, 'replier' => $replier, 'subscriber' => $assignee, 'mentioned' => $mentioned, 'team' => $team, 'project' => $project] = notificationReplyWorkspace();
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create([
        'assignee_id' => $assignee->id,
        'title' => '公開前チェック',
    ]);
    $replier->notify(new TodoActivityNotification($sourceType, $owner, $project, $todo));
    $sourceNotification = $replier->notifications()->firstOrFail();

    Notification::fake();

    $this->actingAs($replier)
        ->post(route('notifications.reply', [$team, $sourceNotification]), [
            'body' => "<p>@{$mentioned->name} 確認しました。</p>",
        ])
        ->assertRedirect();

    $comment = $todo->comments()->sole();
    $activity = Activity::query()
        ->where('subject_type', $comment->getMorphClass())
        ->where('subject_id', $comment->id)
        ->sole();

    expect($comment)
        ->project_id->toBe($project->id)
        ->user_id->toBe($replier->id)
        ->commentable_type->toBe($todo->getMorphClass())
        ->commentable_id->toBe($todo->id)
        ->and($activity)
        ->type->toBe(ActivityType::TodoCommentCreated)
        ->actor_id->toBe($replier->id)
        ->metadata->toBe(['label' => $todo->title])
        ->and($sourceNotification->fresh()->read_at)->not->toBeNull();

    Notification::assertSentTo(
        [$owner, $assignee],
        TodoActivityNotification::class,
        fn (TodoActivityNotification $notification): bool => $notification->type === TodoActivityNotification::Commented
            && $notification->todo->is($todo),
    );
    Notification::assertNotSentTo($replier, TodoActivityNotification::class);
    Notification::assertSentTo(
        $mentioned,
        MentionNotification::class,
        fn (MentionNotification $notification): bool => $notification->type === MentionNotification::Todo
            && $notification->title === $todo->title
            && $notification->targetId === $todo->id,
    );
})->with([
    'assigned' => TodoActivityNotification::Assigned,
    'commented' => TodoActivityNotification::Commented,
    'completed' => TodoActivityNotification::Completed,
    'due' => TodoActivityNotification::Due,
]);

test('a failed notification comment rolls back and stays unread', function () {
    ['owner' => $owner, 'replier' => $replier, 'team' => $team, 'project' => $project] = notificationReplyWorkspace();
    $message = MessageBoardPost::factory()->for($project)->for($owner)->create();
    $replier->notify(new NewMessageCommentNotification($owner, $project, $message));
    $sourceNotification = $replier->notifications()->firstOrFail();
    $file = StoredFile::withoutEvents(fn (): StoredFile => StoredFile::factory()
        ->for($project)
        ->for($replier, 'uploader')
        ->create([
            'is_inline' => true,
            'preview_status' => 'deleting',
        ]));

    Notification::fake();

    $this->actingAs($replier)
        ->post(route('notifications.reply', [$team, $sourceNotification]), [
            'body' => "<a href=\"/files/{$file->id}/preview\">添付</a>",
        ])
        ->assertSessionHasErrors('body');

    expect($message->comments()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0)
        ->and($file->fresh()->attachable_id)->toBeNull()
        ->and($sourceNotification->fresh()->read_at)->toBeNull();
    Notification::assertNothingSent();
});

test('a stale check-in notification reply stays unread', function () {
    ['owner' => $owner, 'replier' => $replier, 'team' => $team, 'project' => $project] = notificationReplyWorkspace();
    $checkIn = CheckIn::factory()->for($project)->for($owner, 'creator')->create();
    $prompt = CheckInPrompt::factory()->for($checkIn)->for($replier)->create([
        'answer' => '最初の回答',
        'answered_at' => now(),
    ]);
    $replier->notify(new CheckInDueNotification($prompt));
    $sourceNotification = $replier->notifications()->firstOrFail();

    $this->actingAs($replier)
        ->post(route('notifications.reply', [$team, $sourceNotification]), [
            'body' => '古い通知からの回答',
        ])
        ->assertSessionHasErrors('body');

    expect($prompt->fresh()->answer)->toBe('最初の回答')
        ->and(Activity::query()->count())->toBe(0)
        ->and($sourceNotification->fresh()->read_at)->toBeNull();
});
