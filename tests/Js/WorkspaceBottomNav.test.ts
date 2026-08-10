import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const workspaceBottomNav = readFileSync(
    new URL(
        '../../resources/js/components/WorkspaceBottomNav.svelte',
        import.meta.url,
    ),
    'utf8',
);
const realtimeNotificationToasts = readFileSync(
    new URL(
        '../../resources/js/components/RealtimeNotificationToasts.svelte',
        import.meta.url,
    ),
    'utf8',
);

test('new for you opens as a right-side vertical panel', () => {
    assert.match(
        workspaceBottomNav,
        /data-layout=\{activePanel === 'notifications'[\s\S]*?\? 'right-rail'[\s\S]*?: 'bottom-tray'\}/,
    );
    assert.match(
        workspaceBottomNav,
        /activePanel === 'notifications'[\s\S]*?\? 'top-14 right-3 bottom-14 w-\[min\(24rem,calc\(100vw-1\.5rem\)\)\]'[\s\S]*?: 'inset-x-2 bottom-14 mx-auto max-h-\[min\(72dvh,42rem\)\] max-w-3xl'/,
    );
    assert.match(workspaceBottomNav, /onclick=\{openNewForYou\}/);
    assert.match(workspaceBottomNav, /onclick=\{closePanel\}/);
});

test('new for you shows the complete count and refreshes it after a notification broadcast', () => {
    assert.match(
        workspaceBottomNav,
        /const unreadNotifications = \$derived\(\s*page\.props\.notificationSummary\.unreadCount,?\s*\)/,
    );
    assert.match(
        workspaceBottomNav,
        /import \{ Badge \} from '@\/components\/ui\/badge'/,
    );
    assert.match(
        workspaceBottomNav,
        /<Badge[\s\S]*?aria-live="polite"[\s\S]*?\{newForYouCount\}[\s\S]*?<\/Badge>/,
    );
    assert.doesNotMatch(workspaceBottomNav, /newForYouCount > 9/);
    assert.match(
        realtimeNotificationToasts,
        /only: \['workspaceBar\.notifications', 'notificationSummary'\]/,
    );
});

test('new for you count aligns inline on desktop without overlapping its label', () => {
    const notificationsButton = workspaceBottomNav.match(
        /<button(?:(?!<\/button>)[\s\S])*?onclick=\{openNewForYou\}(?:(?!<\/button>)[\s\S])*?<\/button>/,
    )?.[0];

    assert.ok(notificationsButton);
    assert.match(notificationsButton, /<Badge/);
    assert.match(notificationsButton, /absolute[\s\S]*?sm:static/);
    assert.doesNotMatch(
        workspaceBottomNav,
        /top-1\/2 right-0 -translate-y-1\/2/,
    );
    assert.doesNotMatch(workspaceBottomNav, /'h-full pr-10'/);
});

test('clicking a notification opens its target and closes the panel', () => {
    assert.match(
        workspaceBottomNav,
        /href=\{NotificationController\.open\(\[\s*currentTeam\.slug,\s*notification\.id,?\s*\]\)\}/,
    );
    assert.match(workspaceBottomNav, /as="button"/);
    assert.match(workspaceBottomNav, /onclick=\{closePanel\}/);
});

test('new for you shows a concrete notification excerpt and its location', () => {
    assert.match(
        workspaceBottomNav,
        /import \{ realtimeNotificationToast \} from '@\/lib\/realtime-notifications'/,
    );
    assert.match(
        workspaceBottomNav,
        /\{@const content = notificationContent\(notification\)\}/,
    );
    assert.match(workspaceBottomNav, /\{content\.title\}/);
    assert.match(
        workspaceBottomNav,
        /line-clamp-2[\s\S]*?\{content\.description\}/,
    );
    assert.match(
        workspaceBottomNav,
        /\{notification\.projectName\}[\s\S]*?notification\.chatChannelName/,
    );
});
