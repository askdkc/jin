import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const projectHome = readFileSync(
    new URL('../../resources/js/pages/projects/Show.svelte', import.meta.url),
    'utf8',
);
const userAvatar = readFileSync(
    new URL('../../resources/js/components/UserAvatar.svelte', import.meta.url),
    'utf8',
);

test('project home uses stored avatars for every user representation', () => {
    assert.match(projectHome, /avatar=\{member\.avatar\}/);
    assert.match(projectHome, /avatar=\{activityItem\.actorAvatar\}/);
    assert.equal(
        projectHome.match(/avatar=\{message\.authorAvatar\}/g)?.length,
        3,
    );
});

test('user avatar falls back to initials when its image cannot load', () => {
    assert.match(userAvatar, /onerror=\{handleAvatarError\}/);
    assert.match(userAvatar, /avatar !== failedAvatar/);
    assert.match(userAvatar, /getInitials\(name\) \|\| '\?'/);
});
