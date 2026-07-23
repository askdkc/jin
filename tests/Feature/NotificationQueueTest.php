<?php

use App\Notifications\CheckInDueNotification;
use App\Notifications\MentionNotification;
use App\Notifications\NewMessageCommentNotification;
use App\Notifications\NewTodoListCommentNotification;
use App\Notifications\Teams\TeamInvitation;
use App\Notifications\TodoActivityNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

test('application notifications use the dedicated notifications queue', function (string $notificationClass) {
    $notification = (new ReflectionClass($notificationClass))->newInstanceWithoutConstructor();

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->viaQueues())->toMatchArray([
            'mail' => 'notifications',
        ]);
})->with([
    CheckInDueNotification::class,
    MentionNotification::class,
    NewMessageCommentNotification::class,
    NewTodoListCommentNotification::class,
    TeamInvitation::class,
    TodoActivityNotification::class,
]);
