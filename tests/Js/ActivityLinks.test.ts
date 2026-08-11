import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const activity = readFileSync(
    new URL('../../resources/js/lib/activity.ts', import.meta.url),
    'utf8',
);
const activityPage = readFileSync(
    new URL('../../resources/js/pages/activity/Index.svelte', import.meta.url),
    'utf8',
);

test('to-do comment activity links to the specific to-do detail', () => {
    assert.match(activity, /activity\.type === 'todo\.comment\.created'/);
    assert.match(
        activity,
        /ProjectTodoController\.show\(\[\s*teamSlug,\s*activity\.project\.id,\s*activity\.todoListId,\s*activity\.todoId,?\s*\]\)/,
    );
});

test('activity page does not advertise an inline comment action', () => {
    assert.doesNotMatch(activityPage, /View and comment/);
    assert.doesNotMatch(activityPage, /canComment/);
});
