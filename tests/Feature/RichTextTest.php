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

it('renders excerpts without the code-fence marker or escaped entities', function () {
    $html = "<p>本文です。</p><pre><code>lang:html\n&lt;body&gt;\n&lt;h1&gt;hello&lt;/h1&gt;</code></pre>";

    expect(RichText::plainText($html))
        ->toBe("本文です。<body>\n<h1>hello</h1>")
        ->not->toContain('lang:html')
        ->not->toContain('&lt;');

    expect(RichText::plainText(null))->toBe('');
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

it('supports visible inline attachment uploads in every project rich text editor', function () {
    $editor = file_get_contents(resource_path('js/components/RichTextEditor.svelte'));

    expect($editor)
        ->toContain('onclick={openImageChooser}')
        ->toContain("{t('Insert image')}")
        ->toMatch('/<input\s+[^>]*type="file"[^>]*accept="image\/jpeg,image\/png,image\/webp"[^>]*hidden[^>]*>/s')
        ->toContain('onchange={handleImageSelection}')
        ->toContain("editor.addEventListener('trix-file-accept', handleFileAccept)")
        ->toContain("editor.removeEventListener('trix-file-accept', handleFileAccept)")
        ->toContain('attachmentUploadError =')
        ->toContain('aria-live="polite"')
        ->toContain('inline: boolean;')
        ->toContain('payload.inline === true')
        ->not->toContain("payload.mimeType?.startsWith('image/')");

    expect(substr_count($editor, 'void uploadAttachment(file);'))->toBeGreaterThanOrEqual(2);

    $missingUploadUrls = [];
    $nonSelfClosingEditors = [];
    $editorCount = 0;
    $projectPages = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('js/pages/projects')),
    );

    foreach ($projectPages as $page) {
        if (! $page->isFile() || $page->getExtension() !== 'svelte') {
            continue;
        }

        $source = file_get_contents($page->getPathname());
        preg_match_all('/<RichTextEditor\b/', $source, $editors, PREG_OFFSET_CAPTURE);

        foreach ($editors[0] as [$start, $offset]) {
            $editorCount++;
            $tagEnd = strpos($source, '>', $offset);
            $component = $tagEnd === false
                ? substr($source, $offset)
                : substr($source, $offset, $tagEnd - $offset + 1);
            $relativePath = str_replace(base_path().'/', '', $page->getPathname());
            $line = substr_count(substr($source, 0, $offset), "\n") + 1;

            if ($tagEnd === false || ! str_ends_with(rtrim($component), '/>')) {
                $nonSelfClosingEditors[] = "{$relativePath}:{$line}";
            }

            if (str_contains($component, 'attachmentUploadUrl')) {
                continue;
            }

            $missingUploadUrls[] = "{$relativePath}:{$line}";
        }
    }

    expect($editorCount)->toBeGreaterThan(0)
        ->and($nonSelfClosingEditors)->toBeEmpty()
        ->and($missingUploadUrls)->toBeEmpty();

    $translations = json_decode(file_get_contents(lang_path('ja.json')), true);

    expect($translations['Insert image'] ?? null)->toBe('画像を挿入')
        ->and($translations['Attachment upload failed. Please try again.'] ?? null)
        ->toBe('添付ファイルのアップロードに失敗しました。もう一度お試しください。');
});
