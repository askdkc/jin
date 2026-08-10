<?php

use Illuminate\Support\Facades\File;

const JIN_README_TRANSLATIONS = [
    'ja' => 'README.md',
    'en' => 'docs/README_en.md',
    'fr' => 'docs/README_fr.md',
    'de' => 'docs/README_de.md',
    'zh_CN' => 'docs/README_zh_CN.md',
    'zh_TW' => 'docs/README_zh_TW.md',
    'es' => 'docs/README_es.md',
    'pt_BR' => 'docs/README_pt_BR.md',
    'pt_PT' => 'docs/README_pt_PT.md',
    'ko' => 'docs/README_ko.md',
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

test('translated readme :dataset is complete and links every supported language', function (string $relativePath) {
    $path = base_path($relativePath);

    $this->assertFileExists($path);

    $readme = File::get($path);
    $isJapaneseReadme = $relativePath === 'README.md';

    expect($readme)
        ->toStartWith('# Jin')
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
        ->toContain('git clone https://github.com/askdkc/jin.git')
        ->toContain('/home/ubuntu/jin')
        ->toContain('sudo -v')
        ->toContain('IP_RESTRICTION_ENABLED')
        ->toContain('npm run build');
})->with(JIN_README_TRANSLATIONS);
