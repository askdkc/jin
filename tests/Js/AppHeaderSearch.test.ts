import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const appHeader = readFileSync(
    new URL('../../resources/js/components/AppHeader.svelte', import.meta.url),
    'utf8',
);
const translations = JSON.parse(
    readFileSync(new URL('../../lang/ja.json', import.meta.url), 'utf8'),
) as Record<string, string>;

test('global search input submits through the Everything form', () => {
    const queryInput = appHeader.match(
        /<input\s+bind:this=\{jumpInput\}[\s\S]*?\/>/,
    )?.[0];

    assert.ok(queryInput);
    assert.match(queryInput, /form="global-everything-search-form"/);
    assert.match(queryInput, /name="q"/);
    assert.match(queryInput, /maxlength=\{100\}/);
    assert.match(
        appHeader,
        /<Form[\s\S]*?id="global-everything-search-form"[\s\S]*?onSuccess=\{closeJump\}/,
    );
    assert.doesNotMatch(
        appHeader,
        /<input type="hidden" name="q" value=\{jumpQuery\} \/>/,
    );
});

test('global search closes cleanly and uses the simplified Japanese copy', () => {
    assert.match(
        appHeader,
        /const closeJump = \(\): void => \{\s+jumpOpen = false;\s+jumpQuery = '';\s+\};/,
    );
    assert.match(appHeader, /\{t\('Search all content'\)\}/);
    assert.doesNotMatch(appHeader, /Search all content for “:query”/);
    assert.equal(translations['Search all content'], '全コンテンツから検索');
    assert.equal(translations['Search all content for “:query”'], undefined);
});
