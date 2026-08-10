<?php

use Illuminate\Support\Facades\File;

const JIN_README_TRANSLATIONS = [
    'ja' => ['README.md', '## 翻訳'],
    'en' => ['docs/README_en.md', '## Languages'],
    'fr' => ['docs/README_fr.md', '## Langues'],
    'de' => ['docs/README_de.md', '## Sprachen'],
    'zh_CN' => ['docs/README_zh_CN.md', '## 语言'],
    'zh_TW' => ['docs/README_zh_TW.md', '## 語言'],
    'es' => ['docs/README_es.md', '## Idiomas'],
    'pt_BR' => ['docs/README_pt_BR.md', '## Idiomas'],
    'pt_PT' => ['docs/README_pt_PT.md', '## Idiomas'],
    'ko' => ['docs/README_ko.md', '## 언어'],
];

const JIN_README_LANGUAGE_FILES = [
    'English' => 'README_en.md',
    '日本語' => 'README.md',
    'Français' => 'README_fr.md',
    'Deutsch' => 'README_de.md',
    '简体中文' => 'README_zh_CN.md',
    '繁體中文' => 'README_zh_TW.md',
    'Español' => 'README_es.md',
    'Português (Brasil)' => 'README_pt_BR.md',
    'Português (Portugal)' => 'README_pt_PT.md',
    '한국어' => 'README_ko.md',
];

test('translated readme :dataset is complete and links every supported language', function (string $relativePath, string $languageHeading) {
    $path = base_path($relativePath);

    $this->assertFileExists($path);

    $readme = File::get($path);
    $isJapaneseReadme = $relativePath === 'README.md';

    expect($readme)
        ->toStartWith('# Jin')
        ->toContain($languageHeading)
        ->and(strlen($readme))->toBeGreaterThan(6_000);

    foreach (JIN_README_LANGUAGE_FILES as $language => $file) {
        $target = match (true) {
            $isJapaneseReadme && $file === 'README.md' => 'README.md',
            $isJapaneseReadme => 'docs/'.$file,
            $file === 'README.md' => '../README.md',
            default => $file,
        };

        expect($readme)->toContain("[{$language}]({$target})");
    }

    expect($readme)
        ->toContain('composer install')
        ->toContain('php artisan jin:mcp')
        ->toContain('--platform ubuntu')
        ->toContain('IP_RESTRICTION_ENABLED')
        ->toContain('npm run build');
})->with(JIN_README_TRANSLATIONS);
