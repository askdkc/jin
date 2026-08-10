import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const appHeader = readFileSync(
    new URL('../../resources/js/components/AppHeader.svelte', import.meta.url),
    'utf8',
);
const appSidebarHeader = readFileSync(
    new URL(
        '../../resources/js/components/AppSidebarHeader.svelte',
        import.meta.url,
    ),
    'utf8',
);
const workspaceBottomNav = readFileSync(
    new URL(
        '../../resources/js/components/WorkspaceBottomNav.svelte',
        import.meta.url,
    ),
    'utf8',
);

test('header layouts do not render a duplicate notification bell', () => {
    for (const header of [appHeader, appSidebarHeader]) {
        assert.doesNotMatch(header, /lucide-svelte\/icons\/bell/);
        assert.doesNotMatch(header, /notificationSummary\.unreadCount/);
        assert.doesNotMatch(header, /aria-label=\{`${t\('Notifications?'\)}/);
    }
});

test('notifications remain available from the bottom-right workspace control', () => {
    assert.match(workspaceBottomNav, /onclick=\{openNewForYou\}/);
    assert.match(workspaceBottomNav, /\{newForYouCount\}/);
    assert.match(workspaceBottomNav, /aria-live="polite"/);
});
