<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('notification settings page shows the effective defaults', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('notification-settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Notifications')
            ->where('preferences.enabled', true)
            ->where('preferences.scope', 'everything')
            ->where('preferences.work_days', [1, 2, 3, 4, 5])
            ->has('timezones'));
});

test('notification settings can be updated', function () {
    $user = User::factory()->create();
    $preferences = [
        'enabled' => true,
        'scope' => 'mentions',
        'event_reminders' => false,
        'assignment_reminders' => false,
        'in_app' => true,
        'email' => false,
        'show_unread_count' => false,
        'schedule' => 'work_hours',
        'work_days' => [1, 2, 3, 4],
        'work_start' => '08:30',
        'work_end' => '16:30',
        'timezone' => 'Asia/Tokyo',
    ];

    $this->actingAs($user)
        ->patch(route('notification-settings.update'), $preferences)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('notification-settings.edit'));

    expect($user->refresh()->notification_preferences)->toMatchArray($preferences);
});

test('notification settings reject invalid schedules', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('notification-settings.update'), [
            ...User::DEFAULT_NOTIFICATION_PREFERENCES,
            'scope' => 'some',
            'work_days' => [],
            'work_start' => '25:00',
            'timezone' => 'Mars/Olympus_Mons',
        ])
        ->assertSessionHasErrors(['scope', 'work_days', 'work_start', 'timezone']);

    expect($user->refresh()->notification_preferences)->toBeNull();
});

test('notification channels respect scope delivery method and work hours', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 10:00:00', 'Asia/Tokyo'));

    $user = User::factory()->create([
        'notification_preferences' => [
            ...User::DEFAULT_NOTIFICATION_PREFERENCES,
            'scope' => 'mentions',
            'schedule' => 'work_hours',
        ],
    ]);

    expect($user->notificationChannels('activity'))->toBe([])
        ->and($user->notificationChannels('mention'))->toBe(['database', 'mail']);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 20:00:00', 'Asia/Tokyo'));

    // Database notifications remain available; email is suppressed.
    expect($user->notificationChannels('mention'))->toBe(['database']);

    CarbonImmutable::setTestNow();
});

test('assignment and event reminders can be disabled independently', function () {
    $user = User::factory()->create([
        'notification_preferences' => [
            ...User::DEFAULT_NOTIFICATION_PREFERENCES,
            'assignment_reminders' => false,
            'event_reminders' => false,
        ],
    ]);

    expect($user->notificationChannels('assignment_reminder'))->toBe([])
        ->and($user->wantsEventReminders())->toBeFalse();
});
