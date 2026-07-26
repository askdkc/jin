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

test('everything page uses a responsive refinement rail and divided result feed', () => {
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
    assert.match(everythingPage, /<ol class="max-w-3xl divide-y">/);
    assert.doesNotMatch(everythingPage, /<Card(?:Content)?\b/);
    assert.match(everythingPage, /aria-current=\{link\.active/);
    assert.match(everythingPage, /results\.total/);
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
});
