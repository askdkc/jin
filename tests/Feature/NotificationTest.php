<?php

use App\Enums\TeamRole;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Notifications\NewMessageCommentNotification;
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
