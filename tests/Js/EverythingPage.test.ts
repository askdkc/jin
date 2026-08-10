import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const everythingPage = readFileSync(
    new URL(
        '../../resources/js/pages/everything/Index.svelte',
        import.meta.url,
    ),
    'utf8',
);
const fileThumbnail = readFileSync(
    new URL(
        '../../resources/js/components/ProjectFileThumbnail.svelte',
        import.meta.url,
    ),
    'utf8',
);
const translations = JSON.parse(
    readFileSync(new URL('../../lang/ja.json', import.meta.url), 'utf8'),
) as Record<string, string>;

test('everything page uses a compact responsive search workspace', () => {
    assert.match(everythingPage, /max-w-\[96rem\]/);
    assert.match(everythingPage, /lg:grid-cols-\[13rem_minmax\(0,1fr\)\]/);
    assert.match(
        everythingPage,
        /<h1[\s\S]*?text-2xl[\s\S]*?Find everything[\s\S]*?<\/h1>/,
    );
    assert.match(
        everythingPage,
        /<Input[\s\S]*?id="everything-query"[\s\S]*?class="h-9 /,
    );
    assert.doesNotMatch(everythingPage, /currentTeam\.name/);
    assert.doesNotMatch(
        everythingPage,
        /Search messages, to-dos, documents, files, links, and comments/,
    );

    assert.match(everythingPage, /<details[\s\S]*?<summary/);
    assert.match(everythingPage, /id="everything-refinement-panel"/);
    assert.match(everythingPage, /document\.querySelector<HTMLDetailsElement>/);
    assert.match(
        everythingPage,
        /window\.matchMedia\('\(min-width: 64rem\)'\)/,
    );
    assert.match(
        everythingPage,
        /refinementPanel\.open = desktopBreakpoint\.matches/,
    );
    assert.match(everythingPage, /<ol class="divide-y">/);
    assert.match(everythingPage, /<li class="py-3\.5">/);
    assert.doesNotMatch(everythingPage, /<Card(?:Content)?\b/);
    assert.match(everythingPage, /aria-current=\{link\.active/);
    assert.match(everythingPage, /results\.total/);
});

test('everything page submits accessible multi-select file categories', () => {
    assert.match(
        everythingPage,
        /<fieldset[\s\S]*?<legend[\s\S]*?File categories[\s\S]*?<\/fieldset>/,
    );
    assert.match(everythingPage, /name="file_categories\[\]"/);
    assert.match(
        everythingPage,
        /checked=\{filters\.fileCategories\.includes\(/,
    );
    assert.match(everythingPage, /filters\.fileCategories\.length > 0/);

    for (const value of ['image', 'video', 'office', 'pdf']) {
        assert.match(
            everythingPage,
            new RegExp(`\\{ value: '${value}', label:`),
        );
    }

    assert.equal(translations['File categories'], 'ファイル種別');
    assert.equal(translations.Images, '画像');
    assert.equal(translations.Videos, '動画');
    assert.equal(translations['Office files'], 'Officeファイル');
    assert.equal(translations['PDF files'], 'PDFファイル');
});

test('file results reuse previews and navigate with server-owned hrefs', () => {
    assert.match(everythingPage, /href=\{item\.href\}/);
    assert.doesNotMatch(everythingPage, /ProjectFileController/);
    assert.match(
        everythingPage,
        /<ProjectFileThumbnail[\s\S]*?variant="result"/,
    );
    assert.match(everythingPage, /<StoredFilePreviewDialog/);
    assert.match(everythingPage, /canPreviewStoredFile\(item\.title\)/);
    assert.match(everythingPage, /mt-4 inline-block max-w-full/);
    assert.match(
        everythingPage,
        /\{#if item\.snippet\}[\s\S]*?line-clamp-2[\s\S]*?\{item\.snippet\}[\s\S]*?\{\/if\}[\s\S]*?item\.uploader/,
    );
    assert.doesNotMatch(everythingPage, /\{@html\s+item\.snippet/);
    assert.match(
        everythingPage,
        /fileTypeLabelKey\(\s*item\.title,\s*item\.mimeType/,
    );
    assert.doesNotMatch(everythingPage, /\{item\.mimeType\s*\|\|/);
    assert.match(fileThumbnail, /'index' \| 'result' \| 'summary'/);
    assert.match(fileThumbnail, /max-h-56/);
    assert.match(fileThumbnail, /max-w-72/);
    assert.doesNotMatch(fileThumbnail, /aspect-\[4\/3\]/);
});

test('everything results support update and name sorting', () => {
    assert.match(everythingPage, /id="everything-search-form"/);
    assert.match(
        everythingPage,
        /<select[\s\S]*?name="sort"[\s\S]*?form="everything-search-form"[\s\S]*?onchange=\{submitSort\}/,
    );
    assert.match(everythingPage, /requestSubmit\(\)/);

    for (const value of [
        'updated_desc',
        'updated_asc',
        'name_asc',
        'name_desc',
    ]) {
        assert.match(everythingPage, new RegExp(`value="${value}"`));
        assert.match(
            everythingPage,
            new RegExp(`selected=\\{filters\\.sort === '${value}'\\}`),
        );
    }

    assert.equal(translations['Sort results'], '並び順');
    assert.equal(translations['Most recent'], '更新が新しい順');
    assert.equal(translations['Oldest updated'], '更新が古い順');
    assert.equal(translations['Name ascending'], '名前の昇順');
    assert.equal(translations['Name descending'], '名前の降順');
    assert.equal(translations[':count results'], ':count件の結果');
    assert.equal(translations['Preview :file'], ':fileをプレビュー');
    assert.equal(translations['Word document'], 'Word文書');
    assert.equal(translations.Presentation, 'プレゼンテーション');
    assert.equal(translations['PDF document'], 'PDF文書');
});
