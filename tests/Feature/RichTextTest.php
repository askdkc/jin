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

it('keeps Markdown heading levels and nested lists', function () {
    $html = '<h1>見出し1</h1><h2>見出し2</h2><h3>見出し3</h3>'
        .'<ul><li>箇条書き<ul><li>ネスト</li></ul></li></ul>'
        .'<ol start="3"><li>番号付き</li></ol>';

    expect(RichText::sanitize($html))
        ->toContain('<h1>見出し1</h1>')
        ->toContain('<h2>見出し2</h2>')
        ->toContain('<h3>見出し3</h3>')
        ->toContain('<ul><li>箇条書き<ul><li>ネスト</li></ul></li></ul>')
        ->toContain('<ol start="3"><li>番号付き</li></ol>');
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
        ->toContain('onclick={openFileChooser}')
        ->toContain("{t('Insert file')}")
        ->toContain('bind:this={fileInput}')
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
        ->and($translations['Insert file'] ?? null)->toBe('ファイルを挿入')
        ->and($translations['Attachment upload failed. Please try again.'] ?? null)
        ->toBe('添付ファイルのアップロードに失敗しました。もう一度お試しください。');
});

it('expands the message comment editor only when requested', function () {
    $page = file_get_contents(resource_path('js/pages/projects/messages/Show.svelte'));

    expect($page)
        ->toContain('let commentComposerOpen = $state(false)')
        ->toContain('onclick={openCommentComposer}')
        ->toContain('{#if commentComposerOpen}')
        ->toContain('onSuccess={() => (commentComposerOpen = false)}')
        ->toContain('onclick={() =>')
        ->toContain('(commentComposerOpen = false)')
        ->toContain("[&_trix-editor.rich-text-editor]:!min-h-56")
        ->toContain("{t('Add your comment…')}");
});

it('edits comments in the body column without rendering the original body twice', function () {
    $pages = [
        'messages/Show.svelte',
        'todos/Show.svelte',
        'todos/ListShow.svelte',
        'files/Show.svelte',
    ];

    foreach ($pages as $page) {
        $source = file_get_contents(resource_path("js/pages/projects/{$page}"));

        expect($source)
            ->toContain('let editingCommentId = $state<number | null>(null)')
            ->toContain('{#if editingCommentId === comment.id}')
            ->toContain('{:else}')
            ->toMatch('/editingCommentId\s*=\s*comment\.id/')
            ->toMatch('/editingCommentId\s*=\s*null/')
            ->not->toContain('min-w-[min(32rem,70vw)]')
            ->not->toContain('min-w-[min(34rem,70vw)]');
    }
});

it('switches every rich text editor between rich text and Markdown without changing form payloads', function () {
    $editor = file_get_contents(resource_path('js/components/RichTextEditor.svelte'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($editor)
        ->toContain("type EditorMode = 'rich' | 'markdown'")
        ->toContain("import { Marked, Renderer } from 'marked'")
        ->toContain('Trix.config.blockAttributes[`heading${level}`]')
        ->toContain('<ToggleGroup.Root')
        ->toContain('onValueChange={setMode}')
        ->toContain("if (nextMode === 'markdown')")
        ->toContain('markdownValue = htmlToMarkdown(hiddenInput.value)')
        ->toContain('hiddenInput.value = markdownToHtml(markdownValue)')
        ->toContain('editor.editor?.loadHTML(html)')
        ->toContain('<textarea')
        ->toContain('oninput={updateMarkdown}')
        ->toContain('data-mode={mode}')
        ->toContain('rich-text-editor-insert-tools')
        ->toContain('rich-text-editor-mode-tools')
        ->toMatch('/<input\s+[^>]*type="hidden"[^>]*\{name\}[^>]*>/s')
        ->not->toContain('Rich text mode')
        ->not->toContain("{t('Markdown supported')}");

    expect($css)
        ->toContain('.rich-text-editor-shell > trix-toolbar')
        ->toContain('grid-template-columns: auto minmax(0, 1fr) auto')
        ->toContain('trix-editor.rich-text-editor');

    $translations = json_decode(file_get_contents(lang_path('ja.json')), true);

    expect($translations['Rich text'] ?? null)->toBe('リッチテキスト')
        ->and($translations['Markdown mode'] ?? null)->toBe('Markdownモード');
});

it('clears the visible rich text document when its Inertia form resets', function () {
    $editor = file_get_contents(resource_path('js/components/RichTextEditor.svelte'));

    expect($editor)
        ->toContain('const form = hiddenInput.form')
        ->toContain("form?.addEventListener('reset', resetEditor)")
        ->toContain("form?.removeEventListener('reset', resetEditor)")
        ->toContain('const resetValue = hiddenInput.value')
        ->toContain('editor.editor?.loadHTML(resetValue)')
        ->toContain('markdownValue = htmlToMarkdown(resetValue)')
        ->toContain('markdownDirty = false');
});
