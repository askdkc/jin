import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { notificationChime } from '../../resources/js/lib/notification-sound.ts';
import {
    realtimeNotificationToast,
    removeRealtimeNotificationOptimistically,
} from '../../resources/js/lib/realtime-notifications.ts';

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

test('formats chat mentions with the channel name', () => {
    assert.deepEqual(
        realtimeNotificationToast(
            {
                id: 'notification-id',
                type: 'mention_chat',
                projectName: '公開準備',
                actorName: '担当者',
                messageTitle: '#design',
                chatMessageExcerpt: '確認してください。',
            },
            translate,
        ),
        {
            title: '担当者 mentioned you in chat',
            description: '確認してください。',
        },
    );
});

test('formats project chat posts with the channel and message excerpt', () => {
    assert.deepEqual(
        realtimeNotificationToast(
            {
                id: 'notification-id',
                type: 'chat_message',
                projectName: '公開準備',
                actorName: '担当者',
                chatChannelName: 'design',
                chatMessageExcerpt: 'レビューをお願いします。',
            },
            translate,
        ),
        {
            title: '担当者 posted in #design',
            description: 'レビューをお願いします。',
        },
    );
});

test('optimistically removes the opened notification from the current team', () => {
    const update = removeRealtimeNotificationOptimistically(
        {
            currentTeam: { id: 2 },
            notificationSummary: { unreadCount: 2 },
            workspaceBar: {
                notifications: [
                    { id: 'opened-notification' },
                    { id: 'remaining-notification' },
                ],
                unreadPings: 0,
            },
        },
        {
            id: 'opened-notification',
            type: 'chat_message',
            teamId: 2,
        },
    );

    assert.deepEqual(update, {
        notificationSummary: { unreadCount: 1 },
        workspaceBar: {
            notifications: [{ id: 'remaining-notification' }],
            unreadPings: 0,
        },
    });
});

test('does not change the current team badge for an unrelated notification', () => {
    const update = removeRealtimeNotificationOptimistically(
        {
            currentTeam: { id: 2 },
            notificationSummary: { unreadCount: 1 },
            workspaceBar: {
                notifications: [{ id: 'current-notification' }],
            },
        },
        {
            id: 'another-team-notification',
            type: 'chat_message',
            teamId: 3,
        },
    );

    assert.deepEqual(update, {});
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
    const sonner = readFileSync(
        new URL(
            '../../resources/js/components/ui/sonner/Sonner.svelte',
            import.meta.url,
        ),
        'utf8',
    );

    assert.match(component, /`App\.Models\.User\.\$\{currentUser\.id\}`/);
    assert.match(component, /channel\.notification\(showNotification\)/);
    assert.match(component, /prepareNotificationSound\(\)/);
    assert.match(component, /void playNotificationSound\(\)/);
    assert.match(component, /toast\.info\(content\.title/);
    assert.match(component, /toast\.dismiss\(`notification:/);
    assert.match(component, /NotificationController\.open/);
    assert.match(component, /removeRealtimeNotificationOptimistically/);
    assert.doesNotMatch(component, /ChatController\.show/);
    assert.match(component, /workspaceBar\.notifications/);
    assert.match(layout, /<RealtimeNotificationToasts \/>/);
    assert.match(layout, /<Toaster offset="4rem" richColors closeButton \/>/);
    assert.match(sonner, /--info-bg: var\(--popover\)/);
    assert.match(sonner, /--info-text: var\(--popover-foreground\)/);
    assert.match(sonner, /--info-border: var\(--ring\)/);
});

test('real-time notifications use a short rising two-tone chime', () => {
    assert.deepEqual(notificationChime, [
        { frequency: 587.33, delay: 0, duration: 0.16, volume: 0.055 },
        { frequency: 880, delay: 0.11, duration: 0.22, volume: 0.045 },
    ]);
});
