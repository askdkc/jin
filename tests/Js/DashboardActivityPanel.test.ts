import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const dashboard = readFileSync(
    new URL('../../resources/js/pages/Dashboard.svelte', import.meta.url),
    'utf8',
);
const workspaceBottomNav = readFileSync(
    new URL(
        '../../resources/js/components/WorkspaceBottomNav.svelte',
        import.meta.url,
    ),
    'utf8',
);
const activityPanelState = readFileSync(
    new URL(
        '../../resources/js/lib/dashboard-activity-panel.svelte.ts',
        import.meta.url,
    ),
    'utf8',
);

test('dashboard uses a dedicated button for the recent activity panel', () => {
    assert.match(activityPanelState, /open = \$state\(true\)/);
    assert.match(activityPanelState, /this\.open = !this\.open/);

    assert.match(dashboard, /id="dashboard-recent-activity"/);
    assert.match(dashboard, /!dashboardActivityPanel\.open && 'lg:hidden'/);
    assert.match(
        dashboard,
        /dashboardActivityPanel\.open[\s\S]*?lg:grid-cols-\[15rem_minmax\(0,44rem\)_minmax\(17rem,21rem\)\][\s\S]*?lg:grid-cols-\[15rem_minmax\(0,1fr\)\]/,
    );
    assert.match(
        dashboard,
        /aria-pressed=\{dashboardActivityPanel\.open\}[\s\S]*?aria-controls="dashboard-recent-activity"[\s\S]*?onclick=\{\(\) => dashboardActivityPanel\.toggle\(\)\}/,
    );
    assert.match(dashboard, /t\('Recent activity list'\)/);

    assert.doesNotMatch(workspaceBottomNav, /dashboardActivityPanel/);
    assert.doesNotMatch(workspaceBottomNav, /usesDashboardActivityPanel/);
    assert.match(
        workspaceBottomNav,
        /aria-controls="workspace-personal-panel"/,
    );
    assert.match(workspaceBottomNav, /onclick=\{openNewForYou\}/);
});
