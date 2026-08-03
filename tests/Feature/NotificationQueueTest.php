<?php

use App\Notifications\CheckInDueNotification;
use App\Notifications\MentionNotification;
use App\Notifications\NewMessageCommentNotification;
use App\Notifications\NewTodoListCommentNotification;
use App\Notifications\Teams\TeamInvitation;
use App\Notifications\TodoActivityNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

test('application notifications use the default queue with a retry budget', function (string $notificationClass) {
    $notification = (new ReflectionClass($notificationClass))->newInstanceWithoutConstructor();

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->queue)->toBeNull()
        ->and($notification->tries)->toBe(3)
        ->and($notification->timeout)->toBe(60);
})->with([
    CheckInDueNotification::class,
    MentionNotification::class,
    NewMessageCommentNotification::class,
    NewTodoListCommentNotification::class,
    TeamInvitation::class,
    TodoActivityNotification::class,
]);
