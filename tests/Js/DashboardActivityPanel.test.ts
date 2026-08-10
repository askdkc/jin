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

test('dashboard recent activity opens on hover without a toolbar button', () => {
    assert.match(activityPanelState, /open = \$state\(false\)/);
    assert.match(activityPanelState, /show\(\): void[\s\S]*?this\.open = true/);
    assert.match(
        activityPanelState,
        /hide\(\): void[\s\S]*?this\.open = false/,
    );
    assert.doesNotMatch(activityPanelState, /toggle\(\): void/);

    assert.match(
        dashboard,
        /onMount\(\(\) => \{[\s\S]*?dashboardActivityPanel\.hide\(\)/,
    );
    assert.match(dashboard, /id="dashboard-recent-activity"/);
    assert.doesNotMatch(
        dashboard,
        /!dashboardActivityPanel\.open && 'lg:hidden'/,
    );
    assert.match(
        dashboard,
        /lg:grid-cols-\[15rem_minmax\(0,1fr\)_var\(--dashboard-activity-width\)\][\s\S]*?lg:transition-\[grid-template-columns\][\s\S]*?style:--dashboard-activity-width=\{dashboardActivityPanel\.open[\s\S]*?'clamp\(13\.6rem, 20\.8vw, 16\.8rem\)'[\s\S]*?'3rem'/,
    );
    assert.match(dashboard, /lg:w-full lg:min-w-0/);
    assert.doesNotMatch(dashboard, /dashboardActivityPanel\.toggle\(\)/);
    assert.doesNotMatch(dashboard, /t\('Recent activity list'\)/);
    assert.match(
        dashboard,
        /lg:invisible lg:h-0 lg:translate-x-3 lg:opacity-0/,
    );
    assert.match(dashboard, /aria-label=\{t\('Collapse column'\)\}/);
    assert.match(dashboard, /aria-label=\{t\('Expand column'\)\}/);
    assert.match(
        dashboard,
        /onmouseenter=\{\(\) => dashboardActivityPanel\.show\(\)\}/,
    );
    assert.match(
        dashboard,
        /onmouseleave=\{\(\) => dashboardActivityPanel\.hide\(\)\}/,
    );
    assert.match(
        dashboard,
        /aria-label=\{t\('Collapse column'\)\}[\s\S]*?onclick=\{\(\) => dashboardActivityPanel\.hide\(\)\}/,
    );
    assert.match(
        dashboard,
        /aria-label=\{t\('Expand column'\)\}[\s\S]*?onclick=\{\(\) => dashboardActivityPanel\.show\(\)\}/,
    );

    assert.doesNotMatch(workspaceBottomNav, /dashboardActivityPanel/);
    assert.doesNotMatch(workspaceBottomNav, /usesDashboardActivityPanel/);
    assert.match(
        workspaceBottomNav,
        /aria-controls="workspace-personal-panel"/,
    );
    assert.match(workspaceBottomNav, /onclick=\{openNewForYou\}/);
});

test('dashboard project toolbar omits the global search shortcut', () => {
    assert.doesNotMatch(dashboard, /EverythingController/);
    assert.doesNotMatch(dashboard, /t\('Find everything'\)/);
});

test('dashboard primary actions use compact spacing', () => {
    assert.match(dashboard, /flex flex-col gap-3 lg:sticky lg:top-24 lg:gap-2/);
    assert.match(dashboard, /grid gap-2 lg:gap-1\.5/);
    assert.equal(
        dashboard.match(
            /h-auto w-full justify-start rounded-xl bg-card px-3 py-2/g,
        )?.length,
        3,
    );
});

test('dashboard recent activity history scrolls within the viewport', () => {
    assert.match(dashboard, /relative min-w-0[^']*lg:self-stretch/);
    assert.match(dashboard, /lg:flex lg:min-h-0[^']*lg:flex-col/);
    assert.match(
        dashboard,
        /\? 'lg:visible lg:max-h-\[calc\(100dvh-9rem\)\][^']*'/,
    );
    assert.match(dashboard, /: 'lg:invisible lg:h-0[^']*'/);
    assert.match(
        dashboard,
        /lg:min-h-0 lg:flex-1 lg:overflow-y-auto lg:overscroll-contain lg:pr-2/,
    );
});

test('dashboard keeps projects and personal work compact', () => {
    assert.match(dashboard, /min-h-\[calc\(100dvh-6rem\)\] bg-\[#f5f7f3\]/);
    assert.doesNotMatch(dashboard, /pb-28/);
    assert.match(
        dashboard,
        /<main[\s\S]*?lg:flex lg:min-h-\[calc\(100dvh-6rem\)\][^"]*lg:flex-col[^"]*lg:gap-4/,
    );
    assert.match(
        dashboard,
        /group flex min-h-32 min-w-0 flex-col[^"]*p-3[^"]*sm:p-4/,
    );
    assert.match(
        dashboard,
        /block break-words text-lg font-bold[^"]*sm:text-xl/,
    );
    assert.match(
        dashboard,
        /id="my-tasks"[\s\S]*?class="mt-4[^"]*p-3[^"]*sm:p-4[^"]*lg:mt-auto"/,
    );
    assert.match(dashboard, /gap-2 text-lg font-bold/);
    assert.match(dashboard, /<h3 class="text-sm font-semibold">/);
    assert.match(dashboard, /block break-words text-xs font-medium/);
    assert.match(dashboard, /grid gap-3 md:grid-cols-2 xl:grid-cols-4/);
    assert.match(
        dashboard,
        /items-start justify-between gap-2 border-b[^"]*px-3 py-2/,
    );
});
