<?php

use Illuminate\Support\Facades\File;

const JIN_EXPECTED_TRANSLATION_KEY_COUNT = 1214;

const JIN_TRANSLATION_CATALOG_LOCALES = [
    'fr',
    'de',
    'zh_CN',
    'zh_TW',
    'es',
    'pt_BR',
    'pt_PT',
    'ko',
];

const JIN_SUPPORTED_TRANSLATION_LOCALES = [
    'en',
    'ja',
    ...JIN_TRANSLATION_CATALOG_LOCALES,
];

const JIN_REPRESENTATIVE_TRANSLATIONS = [
    'fr' => ['Log in' => 'Se connecter'],
    'de' => ['Log in' => 'Anmelden'],
    'zh_CN' => ['Log in' => '登录'],
    'zh_TW' => ['Log in' => '登入'],
    'es' => ['Log in' => 'Iniciar sesión'],
    'pt_BR' => ['Log in' => 'Entrar'],
    'pt_PT' => ['Log in' => 'Iniciar sessão'],
    'ko' => ['Log in' => '로그인'],
];

test('additional JSON catalogs match the Japanese keys and placeholders', function () {
    $japanese = jinReadTranslationCatalog('ja');

    $this->assertCount(JIN_EXPECTED_TRANSLATION_KEY_COUNT, $japanese);

    foreach ($japanese as $key => $translation) {
        $this->assertIsString($translation, "Non-string Japanese translation: {$key}");
        $this->assertNotSame('', trim($translation), "Empty Japanese translation: {$key}");
        $this->assertSame(
            jinTranslationPlaceholders($key),
            jinTranslationPlaceholders($translation),
            "Japanese placeholder mismatch for {$key}",
        );
    }

    foreach (JIN_TRANSLATION_CATALOG_LOCALES as $locale) {
        $path = lang_path($locale.'.json');

        $this->assertFileExists($path, "Missing JSON catalog for {$locale}.");

        $catalog = jinReadTranslationCatalog($locale);

        $this->assertSame(
            array_keys($japanese),
            array_keys($catalog),
            "Catalog keys differ for {$locale}.",
        );

        foreach ($catalog as $key => $translation) {
            $this->assertIsString($translation, "Non-string translation for {$locale}: {$key}");
            $this->assertNotSame('', trim($translation), "Empty translation for {$locale}: {$key}");
        }

        foreach ($japanese as $key => $translation) {
            $this->assertArrayHasKey($key, $catalog, "Missing translation for {$locale}: {$key}");
            $this->assertIsString($translation, "Non-string Japanese translation: {$key}");
            $this->assertSame(
                jinTranslationPlaceholders($translation),
                jinTranslationPlaceholders($catalog[$key]),
                "Placeholder mismatch for {$locale}: {$key}",
            );
        }

        foreach (JIN_REPRESENTATIVE_TRANSLATIONS[$locale] as $key => $translation) {
            $this->assertSame($translation, $catalog[$key], "Unexpected translation for {$locale}: {$key}");
        }
    }
});

test('JSON catalogs do not contain duplicate source keys', function () {
    foreach (['ja', ...JIN_TRANSLATION_CATALOG_LOCALES] as $locale) {
        $source = File::get(lang_path($locale.'.json'));
        preg_match_all('/^\\s*("(?:\\\\.|[^"\\\\])*")\\s*:/m', $source, $matches);
        $keys = array_map(
            fn (string $encoded): string => json_decode($encoded, true, 512, JSON_THROW_ON_ERROR),
            $matches[1] ?? [],
        );
        $duplicates = array_keys(array_filter(
            array_count_values($keys),
            fn (int $count): bool => $count > 1,
        ));

        $this->assertSame([], $duplicates, "Duplicate JSON keys for {$locale}.");
    }
});

test('translation keys are complete phrases without whitespace-dependent fragments', function () {
    foreach (JIN_SUPPORTED_TRANSLATION_LOCALES as $locale) {
        if ($locale === 'en') {
            continue;
        }

        foreach (array_keys(jinReadTranslationCatalog($locale)) as $key) {
            $this->assertSame(trim($key), $key, "Whitespace-dependent translation key for {$locale}: {$key}");
        }
    }
});

test('framework language files exist for every supported locale', function () {
    foreach (JIN_SUPPORTED_TRANSLATION_LOCALES as $locale) {
        foreach (['auth', 'pagination', 'passwords', 'validation'] as $group) {
            $this->assertFileExists(
                lang_path("{$locale}/{$group}.php"),
                "Missing {$group} catalog for {$locale}.",
            );
        }
    }
});

test('framework language files cover the complete Laravel 13 catalogs', function () {
    $frameworkPath = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en');

    foreach (['auth', 'pagination', 'passwords', 'validation'] as $group) {
        $baseline = jinFlattenFrameworkCatalog(require "{$frameworkPath}/{$group}.php");
        $baseline = array_filter(
            $baseline,
            fn (string $path): bool => ! str_starts_with($path, 'attributes.')
                && ! str_starts_with($path, 'custom.'),
            ARRAY_FILTER_USE_KEY,
        );

        foreach (JIN_SUPPORTED_TRANSLATION_LOCALES as $locale) {
            $catalog = jinFlattenFrameworkCatalog(require lang_path("{$locale}/{$group}.php"));

            foreach ($baseline as $path => $message) {
                $this->assertArrayHasKey($path, $catalog, "Missing {$group}.{$path} for {$locale}.");
                $this->assertNotSame('', trim($catalog[$path]), "Empty {$group}.{$path} for {$locale}.");
                $this->assertSame(
                    [],
                    array_values(array_diff(
                        jinFrameworkPlaceholders($catalog[$path]),
                        jinFrameworkPlaceholders($message),
                    )),
                    "Unknown placeholder for {$locale} {$group}.{$path}.",
                );
            }
        }
    }
});

test('representative framework messages resolve to translated strings for every supported locale', function () {
    $frameworkMessages = [
        'auth.failed',
        'passwords.reset',
        'pagination.previous',
        'pagination.next',
        'validation.required',
    ];

    foreach (JIN_SUPPORTED_TRANSLATION_LOCALES as $locale) {
        app()->setLocale($locale);

        foreach ($frameworkMessages as $key) {
            $this->assertNotSame($key, trans($key), "Missing {$locale} translation: {$key}");
        }
    }

    app()->setLocale((string) config('app.locale', 'en'));
});

test('Simplified Chinese accepted and declined rules describe their actual conditions', function () {
    $validation = require lang_path('zh_CN/validation.php');

    expect($validation['required_if_accepted'])
        ->toBe('当 :other 为接受状态时，:attribute 不能为空。')
        ->and($validation['required_if_declined'])
        ->toBe('当 :other 为拒绝状态时，:attribute 不能为空。');
});

test('literal frontend translation keys exist in the Japanese catalog', function () {
    $japanese = jinReadTranslationCatalog('ja');

    foreach (File::allFiles(resource_path('js')) as $file) {
        if (! in_array($file->getExtension(), ['svelte', 'ts', 'js'], true)) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $patterns = [
            "/\\bt\\(\\s*'((?:\\\\.|[^'\\\\])*)'/s",
            '/\bt\(\s*"((?:\\\\.|[^"\\\\])*)"/s',
        ];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $source, $matches);

            foreach ($matches[1] ?? [] as $match) {
                $key = stripcslashes($match);
                $relativePath = ltrim(
                    str_replace(resource_path('js'), '', $file->getPathname()),
                    DIRECTORY_SEPARATOR,
                );

                $this->assertArrayHasKey(
                    $key,
                    $japanese,
                    "Missing literal translation key [{$key}] in resources/js/{$relativePath}.",
                );
            }
        }
    }
});

test('dynamic frontend option labels exist in the Japanese catalog', function () {
    $japanese = jinReadTranslationCatalog('ja');

    foreach (File::allFiles(resource_path('js')) as $file) {
        if (! in_array($file->getExtension(), ['svelte', 'ts', 'js'], true)) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        preg_match_all(
            "/\\b(?:label|title|translationKey|confirmMessage)\\s*:\\s*'((?:\\\\.|[^'\\\\])*)'/s",
            $source,
            $matches,
        );

        foreach ($matches[1] ?? [] as $match) {
            $key = stripcslashes($match);

            if ($key === '') {
                continue;
            }

            $this->assertArrayHasKey(
                $key,
                $japanese,
                "Missing dynamic option translation key [{$key}] in {$file->getRelativePathname()}.",
            );
        }
    }
});

test('dynamic activity translation keys exist in the Japanese catalog', function () {
    $japanese = jinReadTranslationCatalog('ja');
    $source = File::get(resource_path('js/lib/activity.ts'));
    preg_match_all("/^\\s*'[^']+':\\s*'([^']+)'/m", $source, $matches);

    foreach ($matches[1] ?? [] as $key) {
        $this->assertArrayHasKey($key, $japanese, "Missing dynamic activity translation key [{$key}].");
    }
});

test('literal backend translation keys exist in the Japanese catalog', function () {
    $japanese = jinReadTranslationCatalog('ja');
    $roots = [app_path(), base_path('routes'), resource_path('views')];

    foreach ($roots as $root) {
        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $patterns = [
                "/(?<![A-Za-z_])__\\(\\s*'((?:\\\\.|[^'\\\\])*)'/s",
                '/(?<![A-Za-z_])__\(\s*"((?:\\\\.|[^"\\\\])*)"/s',
            ];

            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $source, $matches);

                foreach ($matches[1] ?? [] as $match) {
                    $key = stripcslashes($match);

                    if (preg_match('/^[a-z0-9_.-]+$/', $key) === 1) {
                        continue;
                    }

                    $relativePath = ltrim(
                        str_replace(base_path(), '', $file->getPathname()),
                        DIRECTORY_SEPARATOR,
                    );

                    $this->assertArrayHasKey(
                        $key,
                        $japanese,
                        "Missing literal translation key [{$key}] in {$relativePath}.",
                    );
                }
            }
        }
    }
});

/**
 * @return array<string, mixed>
 *
 * @throws JsonException
 */
function jinReadTranslationCatalog(string $locale): array
{
    return json_decode(
        (string) file_get_contents(lang_path($locale.'.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

/**
 * @param  array<string, mixed>  $catalog
 * @return array<string, string>
 */
function jinFlattenFrameworkCatalog(array $catalog, string $prefix = ''): array
{
    $flattened = [];

    foreach ($catalog as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

        if (is_array($value)) {
            $flattened += jinFlattenFrameworkCatalog($value, $path);

            continue;
        }

        $flattened[$path] = (string) $value;
    }

    return $flattened;
}

/**
 * @return list<string>
 */
function jinTranslationPlaceholders(string $translation): array
{
    preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $translation, $matches);
    $placeholders = $matches[1] ?? [];
    sort($placeholders);

    return $placeholders;
}

/**
 * Laravel validation messages intentionally support :attribute, :Attribute,
 * and :ATTRIBUTE variants, so framework placeholders are case-insensitive.
 *
 * @return list<string>
 */
function jinFrameworkPlaceholders(string $translation): array
{
    $placeholders = array_map('strtolower', jinTranslationPlaceholders($translation));
    $placeholders = array_values(array_unique($placeholders));
    sort($placeholders);

    return $placeholders;
}
