<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import { toast } from 'svelte-sonner';
    import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
    import { getEcho } from '@/lib/echo';
    import { t } from '@/lib/i18n';
    import { realtimeNotificationToast } from '@/lib/realtime-notifications';
    import type { RealtimeNotificationPayload } from '@/lib/realtime-notifications';
    import { dashboard } from '@/routes';

    const openNotification = (
        notification: RealtimeNotificationPayload,
    ): void => {
        const currentTeam = page.props.currentTeam;

        if (notification.type === 'team_invitation') {
            if (currentTeam) {
                router.visit(dashboard(currentTeam.slug));
            }

            return;
        }

        const targetTeam = page.props.teams.find(
            (team) => team.id === notification.teamId,
        );

        if (targetTeam) {
            router.visit(NotificationController.index(targetTeam.slug));

            return;
        }

        window.dispatchEvent(new CustomEvent('jin:new-for-you'));
    };

    onMount(() => {
        const currentUser = page.props.auth.user;

        if (!currentUser) {
            return;
        }

        const channelName = `App.Models.User.${currentUser.id}`;
        const echo = getEcho();
        const channel = echo.private(channelName);
        const showNotification = (
            notification: RealtimeNotificationPayload,
        ): void => {
            const content = realtimeNotificationToast(notification, t);

            toast.info(content.title, {
                id: `notification:${notification.id}`,
                description: content.description,
                duration: 10_000,
                action: page.props.currentTeam
                    ? {
                          label: t('View'),
                          onClick: () => openNotification(notification),
                      }
                    : undefined,
            });

            if (
                notification.type !== 'team_invitation' &&
                notification.teamId === page.props.currentTeam?.id
            ) {
                router.reload({
                    only: ['workspaceBar.notifications', 'notificationSummary'],
                    preserveErrors: true,
                });
            }
        };

        channel.notification(showNotification);

        return () => {
            channel.stopListeningForNotification(showNotification);
            echo.leave(channelName);
        };
    });
</script>
