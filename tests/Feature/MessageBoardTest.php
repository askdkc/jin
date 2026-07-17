<?php

use App\Enums\TeamRole;
use App\Models\Comment;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Notifications\NewMessageCommentNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

test('project participants can post messages and comments', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($member);

    $this->actingAs($member)
        ->post(route('projects.messages.store', [$team, $project]), [
            'title' => '公開日のお知らせ',
            'body' => "公開日は8月1日です。\n確認をお願いします。",
        ])
        ->assertRedirect();

    $post = MessageBoardPost::query()->firstOrFail();

    $this->actingAs($member)
        ->get(route('projects.messages.show', [$team, $project, $post]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/messages/Show')
            ->where('post.title', '公開日のお知らせ')
            ->where('post.canEdit', true));

    $this->actingAs($member)
        ->post(route('projects.messages.comments.store', [$team, $project, $post]), [
            'body' => '確認しました。',
        ])
        ->assertRedirect(route('projects.messages.show', [$team, $project, $post]));

    $this->actingAs($member)
        ->get(route('projects.messages.show', [$team, $project, $post]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('post.comments.0.body', '確認しました。'));

    $this->actingAs($member)
        ->get(route('projects.messages.index', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/messages/Index')
            ->where('posts.data.0.title', '公開日のお知らせ')
            ->where('posts.data.0.commentsCount', 1));

    expect($post->user_id)->toBe($member->id)
        ->and(Comment::query()->firstOrFail()->user_id)->toBe($member->id);
});

test('comment authors can edit their comments but other members cannot', function () {
    $owner = User::factory()->create();
    $author = User::factory()->create();
    $otherMember = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($author, ['role' => TeamRole::Member->value]);
    $team->members()->attach($otherMember, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$author->id, $otherMember->id]);
    $post = MessageBoardPost::factory()->for($project)->for($owner, 'user')->create();
    $comment = Comment::factory()->for($project)->for($author, 'user')->for($post, 'commentable')->create([
        'body' => '確認しました。',
    ]);

    $this->actingAs($otherMember)
        ->patch(route('projects.messages.comments.update', [$team, $project, $post, $comment]), [
            'body' => '横取り編集。',
        ])
        ->assertForbidden();

    $this->actingAs($author)
        ->patch(route('projects.messages.comments.update', [$team, $project, $post, $comment]), [
            'body' => '再確認しました。',
        ])
        ->assertRedirect(route('projects.messages.show', [$team, $project, $post]));

    expect($comment->fresh()->body)->toContain('再確認しました。');

    $this->actingAs($owner)
        ->patch(route('projects.messages.comments.update', [$team, $project, $post, $comment]), [
            'body' => '管理者による修正。',
        ])
        ->assertRedirect();

    expect($comment->fresh()->body)->toContain('管理者による修正。');
});

test('comment factory uses the commentable project', function () {
    $comment = Comment::factory()->create();

    expect($comment->project_id)->toBe($comment->commentable->project_id);
});

test('only authors and team managers can edit or delete messages', function () {
    $owner = User::factory()->create();
    $author = User::factory()->create();
    $otherMember = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($author, ['role' => TeamRole::Member->value]);
    $team->members()->attach($otherMember, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$author->id, $otherMember->id]);
    $post = MessageBoardPost::factory()->for($project)->for($author)->create();

    $this->actingAs($otherMember)
        ->get(route('projects.messages.edit', [$team, $project, $post]))
        ->assertForbidden();

    $this->actingAs($otherMember)
        ->patch(route('projects.messages.update', [$team, $project, $post]), [
            'title' => '変更不可',
            'body' => '変更不可',
        ])
        ->assertForbidden();

    $this->actingAs($author)
        ->patch(route('projects.messages.update', [$team, $project, $post]), [
            'title' => '本人が更新',
            'body' => '更新済み本文',
        ])
        ->assertRedirect(route('projects.messages.show', [$team, $project, $post]));

    $this->actingAs($owner)
        ->delete(route('projects.messages.destroy', [$team, $project, $post]))
        ->assertRedirect();

    expect($post->fresh())->toBeNull();
});

test('project participants can pin messages to the top of the board', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($member);
    $newerPost = MessageBoardPost::factory()->for($project)->for($owner)->create([
        'published_at' => now(),
    ]);
    $olderPost = MessageBoardPost::factory()->for($project)->for($owner)->create([
        'published_at' => now()->subDay(),
    ]);

    $this->actingAs($member)
        ->patch(route('projects.messages.pin', [$team, $project, $olderPost]))
        ->assertRedirect(route('projects.messages.show', [$team, $project, $olderPost]));

    $this->get(route('projects.messages.index', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('posts.data.0.id', $olderPost->id)
            ->where('posts.data.0.pinned', true)
            ->where('posts.data.1.id', $newerPost->id)
            ->where('posts.data.1.pinned', false));

    $this->patch(route('projects.messages.pin', [$team, $project, $olderPost]))
        ->assertRedirect();

    expect($olderPost->fresh()->pinned_at)->toBeNull();
});

test('message subscribers receive comment notifications without notifying the commenter', function () {
    Notification::fake();

    $author = User::factory()->create();
    $subscriber = User::factory()->create();
    $commenter = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($author, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($subscriber, ['role' => TeamRole::Member->value]);
    $team->members()->attach($commenter, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($author, 'creator')->create();
    $project->members()->attach([$author->id, $subscriber->id, $commenter->id]);

    $this->actingAs($author)
        ->post(route('projects.messages.store', [$team, $project]), [
            'title' => '公開日のお知らせ',
            'body' => '公開日は8月1日です。',
        ])
        ->assertRedirect();

    $post = MessageBoardPost::query()->firstOrFail();

    $this->actingAs($subscriber)
        ->patch(route('projects.messages.subscription', [$team, $project, $post]))
        ->assertRedirect(route('projects.messages.show', [$team, $project, $post]));

    $this->actingAs($subscriber)
        ->get(route('projects.messages.show', [$team, $project, $post]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('post.subscribed', true));

    $this->actingAs($commenter)
        ->post(route('projects.messages.comments.store', [$team, $project, $post]), [
            'body' => '確認しました。',
        ])
        ->assertRedirect();

    Notification::assertSentTo(
        $author,
        NewMessageCommentNotification::class,
        fn (NewMessageCommentNotification $notification, array $channels): bool => in_array('mail', $channels, true),
    );
    Notification::assertSentTo($subscriber, NewMessageCommentNotification::class);
    Notification::assertNotSentTo($commenter, NewMessageCommentNotification::class);

    $this->actingAs($subscriber)
        ->patch(route('projects.messages.subscription', [$team, $project, $post]))
        ->assertRedirect();

    Notification::fake();

    $this->actingAs($commenter)
        ->post(route('projects.messages.comments.store', [$team, $project, $post]), [
            'body' => '再確認しました。',
        ])
        ->assertRedirect();

    Notification::assertSentTo($author, NewMessageCommentNotification::class);
    Notification::assertNotSentTo($subscriber, NewMessageCommentNotification::class);
});

test('message routes reject non participants and cross project bindings', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $visibleProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $otherProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $visibleProject->members()->attach($member);
    $post = MessageBoardPost::factory()->for($otherProject)->for($owner)->create();

    $this->actingAs($member)
        ->get(route('projects.messages.index', [$team, $otherProject]))
        ->assertForbidden();

    $this->actingAs($member)
        ->patch(route('projects.messages.update', [$team, $visibleProject, $post]), [
            'title' => '別プロジェクト',
            'body' => '別プロジェクト',
        ])
        ->assertNotFound();

    $this->actingAs($member)
        ->patch(route('projects.messages.pin', [$team, $visibleProject, $post]))
        ->assertNotFound();

    $this->actingAs($member)
        ->patch(route('projects.messages.subscription', [$team, $visibleProject, $post]))
        ->assertNotFound();
});
