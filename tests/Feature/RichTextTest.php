<?php

use App\Enums\TeamRole;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\RichText;

it('keeps supported Trix formatting while removing executable HTML', function () {
    $post = new MessageBoardPost;
    $post->body = '<div><strong>重要</strong> <a href="javascript:alert(1)" onclick="alert(2)">確認</a><script>alert(3)</script></div>';

    expect($post->body)
        ->toContain('<strong>重要</strong>')
        ->toContain('<a rel="noopener noreferrer">確認</a>')
        ->not->toContain('javascript:')
        ->not->toContain('onclick')
        ->not->toContain('<script')
        ->not->toContain('alert(3)');
});

it('treats an empty Trix document as empty input', function () {
    expect(RichText::sanitize('<div><br></div>'))->toBe('');
});

it('keeps a relative image thumbnail but strips a remote one', function () {
    $sanitized = RichText::sanitize('<p><img src="/projects/1/files/2/preview" alt="cat.png"></p>');

    expect($sanitized)
        ->toContain('src="/projects/1/files/2/preview"')
        ->toContain('alt="cat.png"');

    $withRemoteImage = RichText::sanitize('<p><img src="https://evil.example/tracker.png" alt="x"></p>');

    expect($withRemoteImage)->not->toContain('evil.example');
});

it('does not treat an image-only body as blank', function () {
    expect(RichText::sanitize('<p><img src="/projects/1/files/2/preview" alt="cat.png"></p>'))
        ->not->toBe('');
});

it('does not convert non-string input before validation', function () {
    expect(RichText::sanitizeInput(['unexpected']))->toBe(['unexpected']);
});

it('sanitizes message HTML before validation and storage', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($user, 'creator')->create();

    $this->actingAs($user)
        ->post(route('projects.messages.store', [$team, $project]), [
            'title' => '整形された投稿',
            'body' => '<div><strong>安全</strong><script>alert(1)</script></div>',
        ])
        ->assertRedirect();

    $post = MessageBoardPost::query()->firstOrFail();

    expect($post->getRawOriginal('body'))
        ->toContain('<strong>安全</strong>')
        ->not->toContain('<script');

    $this->from(route('projects.messages.index', [$team, $project]))
        ->post(route('projects.messages.store', [$team, $project]), [
            'title' => '空の投稿',
            'body' => '<div><br></div>',
        ])
        ->assertSessionHasErrors('body');
});
