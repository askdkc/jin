import assert from 'node:assert/strict';
import test from 'node:test';
import { interpolateTranslation } from '../../resources/js/lib/translation.ts';

test('translation interpolation treats replacement values literally', () => {
    assert.equal(
        interpolateTranslation('Delete :team?', { team: '$& `$\' $$' }),
        'Delete $& `$\' $$?',
    );
});

test('translation interpolation handles overlapping placeholder names', () => {
    assert.equal(
        interpolateTranslation(':username invited :user.', {
            user: 'Alice',
            username: 'alice@example.com',
        }),
        'alice@example.com invited Alice.',
    );
});
