<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Profile'));
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/projects/current')
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'job_title' => 'プロジェクトマネージャー',
            'location' => '東京都',
            'status' => '集中作業中',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/projects/current');

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->job_title)->toBe('プロジェクトマネージャー');
    expect($user->location)->toBe('東京都');
    expect($user->status)->toBe('集中作業中');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('profile avatar can be uploaded replaced and removed', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 400, 400)->size(500),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $firstPath = $user->refresh()->avatar_path;

    expect($firstPath)->not->toBeNull()
        ->and($user->avatar)->toContain($firstPath);
    Storage::disk('public')->assertExists($firstPath);

    $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => UploadedFile::fake()->image('replacement.webp', 600, 600)->size(700),
        ])
        ->assertSessionHasNoErrors();

    $secondPath = $user->refresh()->avatar_path;

    expect($secondPath)->not->toBe($firstPath);
    Storage::disk('public')->assertMissing($firstPath);
    Storage::disk('public')->assertExists($secondPath);

    $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.avatar.destroy'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->avatar_path)->toBeNull()
        ->and($user->avatar)->toBeNull();
    Storage::disk('public')->assertMissing($secondPath);
});

test('profile avatar must be a reasonably sized raster image', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => UploadedFile::fake()->image('avatar.png', 400, 400)->size(3000),
        ])
        ->assertSessionHasErrors('avatar');

    expect($user->refresh()->avatar_path)->toBeNull();
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('email uniqueness is case insensitive', function () {
    User::factory()->create(['email' => 'existing@example.com']);
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => '  EXISTING@EXAMPLE.COM  ',
        ]);

    $response->assertSessionHasErrors('email');
    expect($user->refresh()->email)->not->toBe('existing@example.com');
});

test('user can delete their account', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $avatarPath = "avatars/{$user->id}/avatar.jpg";
    Storage::disk('public')->put($avatarPath, 'avatar');
    $user->forceFill(['avatar_path' => $avatarPath])->save();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
    Storage::disk('public')->assertMissing($avatarPath);
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});
