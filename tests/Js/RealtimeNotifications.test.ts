import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { realtimeNotificationToast } from '../../resources/js/lib/realtime-notifications.ts';

const translate = (
    key: string,
    replacements: Record<string, string> = {},
): string =>
    Object.entries(replacements).reduce(
        (translated, [name, value]) => translated.replaceAll(`:${name}`, value),
        key,
    );

test('formats team invitations for the real-time toast', () => {
    assert.deepEqual(
        realtimeNotificationToast(
            {
                id: 'notification-id',
                type: 'team_invitation',
                teamId: 2,
                teamName: '新しい陣',
                inviterName: '招待者',
            },
            translate,
        ),
        {
            title: "You've been invited to join 新しい陣",
            description: '招待者 has invited you to join the 新しい陣 team.',
        },
    );
});

test('formats project activity with its human-readable context', () => {
    assert.deepEqual(
        realtimeNotificationToast(
            {
                id: 'notification-id',
                type: 'todo_assigned',
                projectName: '公開準備',
                actorName: '担当者',
                todoTitle: '最終確認',
            },
            translate,
        ),
        {
            title: '公開準備',
            description: '担当者 assigned you to 最終確認.',
        },
    );
});

test('persistent layout listens on the private user channel and renders bottom-right toasts', () => {
    const component = readFileSync(
        new URL(
            '../../resources/js/components/RealtimeNotificationToasts.svelte',
            import.meta.url,
        ),
        'utf8',
    );
    const layout = readFileSync(
        new URL(
            '../../resources/js/layouts/app/AppHeaderLayout.svelte',
            import.meta.url,
        ),
        'utf8',
    );

    assert.match(component, /`App\.Models\.User\.\$\{currentUser\.id\}`/);
    assert.match(component, /channel\.notification\(showNotification\)/);
    assert.match(component, /toast\.info\(content\.title/);
    assert.match(component, /workspaceBar\.notifications/);
    assert.match(layout, /<RealtimeNotificationToasts \/>/);
    assert.match(layout, /<Toaster offset="4rem" richColors closeButton \/>/);
});
