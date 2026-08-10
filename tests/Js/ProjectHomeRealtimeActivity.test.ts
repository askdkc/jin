import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const projectHome = readFileSync(
    new URL('../../resources/js/pages/projects/Show.svelte', import.meta.url),
    'utf8',
);

test('project home refreshes only recent activity after its broadcast', () => {
    assert.match(
        projectHome,
        /const channelName = `projects\.\$\{project\.id\}\.chat`/,
    );
    assert.match(projectHome, /\.listen\(\s*'\.project\.activity\.recorded'/);
    assert.match(
        projectHome,
        /router\.reload\(\{[\s\S]*?only: \['activity'\][\s\S]*?preserveErrors: true/,
    );
    assert.match(projectHome, /return \(\) => echo\.leave\(channelName\)/);
    assert.doesNotMatch(projectHome, /home\.activity/);
});
