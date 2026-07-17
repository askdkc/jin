<?php

use App\Models\User;

test('database seeding is idempotent', function () {
    $this->seed();

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $personalTeam = $user->personalTeam();

    $this->seed();

    expect(User::query()->where('email', 'test@example.com')->count())->toBe(1)
        ->and($user->teams()->count())->toBe(1)
        ->and($personalTeam)->not->toBeNull()
        ->and($personalTeam?->slug)->not->toBeEmpty()
        ->and($user->fresh()->current_team_id)->toBe($personalTeam?->id);
});

test('database seeding repairs an existing seed user without a team', function () {
    $user = User::query()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $this->seed();

    $user->refresh();

    expect($user->teams()->count())->toBe(1)
        ->and($user->personalTeam())->not->toBeNull()
        ->and($user->current_team_id)->toBe($user->personalTeam()?->id);
});
