<?php

use Illuminate\Support\Facades\File;

test('the language switcher is mounted on guest and authenticated surfaces', function () {
    $switcherPath = resource_path('js/components/LanguageSwitcher.svelte');

    $this->assertFileExists($switcherPath);

    $switcher = (string) file_get_contents($switcherPath);
    $this->assertStringContainsString("t('Language')", $switcher);
    $this->assertStringContainsString('switchMethod.url(nextLocale)', $switcher);

    foreach ([
        'js/pages/Welcome.svelte',
        'js/layouts/auth/AuthSimpleLayout.svelte',
        'js/components/UserMenuContent.svelte',
    ] as $relativePath) {
        $source = (string) file_get_contents(resource_path($relativePath));

        $this->assertStringContainsString(
            'LanguageSwitcher',
            $source,
            "Missing LanguageSwitcher import in resources/{$relativePath}.",
        );
        $this->assertStringContainsString(
            '<LanguageSwitcher',
            $source,
            "LanguageSwitcher is not rendered in resources/{$relativePath}.",
        );
    }
});

test('team confirmation dialogs translate complete placeholder-aware phrases', function () {
    $phrases = [
        'components/LeaveTeamModal.svelte' => 'Are you sure you want to leave :team?',
        'components/RemoveMemberModal.svelte' => 'Are you sure you want to remove :member from this team?',
        'components/CancelInvitationModal.svelte' => 'Are you sure you want to cancel the invitation for :email?',
        'components/DeleteTeamModal.svelte' => [
            'This action cannot be undone. This will permanently delete the team ":team".',
            'Type ":team" to confirm',
        ],
    ];

    foreach ($phrases as $relativePath => $expected) {
        $source = File::get(resource_path('js/'.$relativePath));

        foreach ((array) $expected as $phrase) {
            $this->assertStringContainsString("'{$phrase}'", $source);
        }
    }
});

test('the landing page routes decorative labels through the runtime translator', function () {
    $welcome = File::get(resource_path('js/pages/Welcome.svelte'));

    foreach ([
        'TEAM WORKSPACE',
        'JIN / DASHBOARD',
        'ONE PLACE, ONE TEAM',
        'SIMPLE WORKFLOW',
        'ACTUAL SCREENS',
        'ACCESS CONTROL',
    ] as $label) {
        $this->assertStringContainsString("t('{$label}')", $welcome);
    }
});

test('shared Inertia types expose locale metadata and translations', function () {
    $types = (string) file_get_contents(resource_path('js/types/global.d.ts'));

    foreach ([
        'locale: string;',
        'locales: string[];',
        'localeNames: Record<string, string>;',
        'translations: Record<string, string>;',
    ] as $declaration) {
        $this->assertStringContainsString($declaration, $types);
    }
});

test('the landing page routes fixed copy through the runtime translator', function () {
    $welcome = (string) file_get_contents(resource_path('js/pages/Welcome.svelte'));
    $withoutBrand = str_replace(['陣（JIN）', 'JIN（陣）'], '', $welcome);

    $this->assertStringContainsString("import { t } from '@/lib/i18n';", $welcome);
    $this->assertDoesNotMatchRegularExpression(
        '/[ぁ-んァ-ヶ一-龠]/u',
        $withoutBrand,
        'Welcome.svelte still contains fixed Japanese copy outside the JIN brand.',
    );
});

test('invitation and navigation copy uses complete translations', function () {
    $header = File::get(resource_path('js/components/AppHeader.svelte'));
    $pending = File::get(resource_path('js/components/PendingInvitationsModal.svelte'));
    $alert = File::get(resource_path('js/components/TeamInvitationAlert.svelte'));

    expect($header)->toContain("t('Home')");
    expect($pending)->toContain("t(':inviter invited you to join this team.'");
    expect($alert)
        ->toContain("'Log in to join the \":team\" team.'")
        ->toContain("'Register to join the \":team\" team.'");
});

test('breadcrumb accessibility label follows the selected locale', function () {
    $source = File::get(resource_path('js/components/ui/breadcrumb/Breadcrumb.svelte'));

    expect($source)
        ->toContain("t('Breadcrumb')")
        ->not->toContain('aria-label="breadcrumb"');
});

test('frontend locale-sensitive formatting does not hard-code Japanese', function () {
    $i18n = (string) file_get_contents(resource_path('js/lib/i18n.ts'));

    $this->assertStringContainsString('export function currentLocale()', $i18n);
    $this->assertStringContainsString("replaceAll('_', '-')", $i18n);

    foreach (File::allFiles(resource_path('js')) as $file) {
        if (! in_array($file->getExtension(), ['svelte', 'ts', 'js'], true)) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $relativePath = ltrim(
            str_replace(resource_path('js'), '', $file->getPathname()),
            DIRECTORY_SEPARATOR,
        );

        $this->assertStringNotContainsString(
            'ja-JP',
            $source,
            "Hard-coded ja-JP locale remains in resources/js/{$relativePath}.",
        );
    }
});

test('emoji picker localization follows the selected locale', function () {
    $source = File::get(resource_path('js/components/ReactionPicker.svelte'));

    expect($source)
        ->not->toContain('emoji-picker-element/i18n/ja.js')
        ->not->toContain('emoji-picker-element-data/ja/cldr-native/data.json?url')
        ->not->toContain("locale: 'ja'")
        ->toContain('loadEmojiPickerLocalization')
        ->toContain('currentLocale()')
        ->toContain('destroyed ||')
        ->toContain('loadId !== pickerLoadId')
        ->toContain('destroyed = true');
});

test('persistent workspace navigation derives locale-sensitive values reactively', function () {
    $source = File::get(resource_path('js/components/WorkspaceBottomNav.svelte'));

    expect($source)
        ->toContain('const eventFormatter = $derived(')
        ->toContain('const dockItems = $derived([');
});

test('breadcrumbs distinguish translation keys from literal model values', function () {
    $component = File::get(resource_path('js/components/Breadcrumbs.svelte'));
    $types = File::get(resource_path('js/types/navigation.ts'));

    expect($component)
        ->toContain("typeof item.translationKey === 'string'")
        ->toContain('? t(item.translationKey)')
        ->and($types)->toContain('translationKey: string;');

    foreach (File::allFiles(resource_path('js/pages')) as $file) {
        if ($file->getExtension() !== 'svelte') {
            continue;
        }

        $source = $file->getContents();
        if (! preg_match('/<script module[^>]*>(.*?)<\/script>/s', $source, $module)
            || ! str_contains($module[1], 'breadcrumbs')) {
            continue;
        }

        expect($module[1], $file->getRelativePathname())
            ->not->toMatch('/\btitle:\s*[\'\"]/');
    }
});

test('stored file previews reject obsolete asynchronous continuations', function () {
    $source = File::get(resource_path('js/components/StoredFilePreviewDialog.svelte'));

    expect($source)
        ->toContain('let previewLoadId = 0;')
        ->toContain('previewLoadId += 1;')
        ->toContain('const loadId = previewLoadId;')
        ->toContain('const isCurrentLoad = (): boolean =>')
        ->toContain('if (!isCurrentLoad())');
});

test('team menu forms submit the locale that produced their defaults', function () {
    $source = File::get(resource_path('js/pages/teams/Edit.svelte'));

    expect($source)
        ->toContain('name="menu_names_locale"')
        ->toContain("value={page.props.locale ?? 'en'}");
});

test('stored project defaults use translation catalogs instead of fixed Japanese', function () {
    $sources = collect([
        app_path('Http/Controllers/ChatController.php'),
        app_path('Http/Controllers/ProjectController.php'),
        app_path('Mcp/Tools/CreateWorkspaceItemTool.php'),
        app_path('Mcp/Tools/CreateTodoTool.php'),
    ])->map(fn (string $path): string => File::get($path))->implode("\n");

    expect($sources)
        ->toContain("__('Project-wide conversation')")
        ->toContain("__('Unsorted to-dos')")
        ->not->toContain("'プロジェクト全体の会話'", "'未分類の任務'");
});
