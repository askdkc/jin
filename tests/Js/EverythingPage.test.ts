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

test('everything redesign exposes only the supported newest-first presentation', () => {
    assert.match(everythingPage, /t\('Most recent'\)/);
    assert.doesNotMatch(everythingPage, /Best match/);
    assert.doesNotMatch(everythingPage, /name="sort"/);

    assert.equal(translations['Refine results'], '検索結果を絞り込む');
    assert.equal(translations['Most recent'], '更新が新しい順');
    assert.equal(translations[':count results'], ':count件の結果');
    assert.equal(translations['Preview :file'], ':fileをプレビュー');
});
