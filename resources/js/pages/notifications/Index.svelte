<script module lang="ts">
    import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
    import type { Team } from '@/types';

    export const layout = (props: { currentTeam: Team }) => ({
        breadcrumbs: [
            {
                translationKey: 'Notifications',
                href: NotificationController.index(props.currentTeam.slug),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import CheckInController from '@/actions/App/Http/Controllers/CheckInController';
    import MessageBoardController from '@/actions/App/Http/Controllers/MessageBoardController';
    import ProjectFileController from '@/actions/App/Http/Controllers/ProjectFileController';
    import ProjectMaterialController from '@/actions/App/Http/Controllers/ProjectMaterialController';
    import ProjectTodoController from '@/actions/App/Http/Controllers/ProjectTodoController';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { Card, CardContent } from '@/components/ui/card';
    import { currentLocale, t } from '@/lib/i18n';
    import type { ProjectNotification } from '@/types';

    let {
        currentTeam,
        notifications,
    }: {
        currentTeam: Team;
        notifications: ProjectNotification[];
    } = $props();

    const hasUnread = $derived(
        notifications.some((notification) => !notification.read),
    );
    const formatDate = (value: string | null): string =>
        value
            ? new Intl.DateTimeFormat(currentLocale(), {
                  dateStyle: 'medium',
                  timeStyle: 'short',
              }).format(new Date(value))
            : '';
    const notificationText = (notification: ProjectNotification): string => {
        const actorName = notification.actorName ?? t('Deleted user');

        switch (notification.type) {
            case 'check_in_due':
                return t('A check-in is ready for your answer');
            case 'todo_assigned':
                return t(':actorName assigned you a to-do', { actorName });
            case 'todo_comment':
                return t(':actorName commented on a to-do you follow', {
                    actorName,
                });
            case 'todo_completed':
                return t(':actorName completed a to-do you follow', {
                    actorName,
                });
            case 'todo_due':
                return t('A to-do assigned to you is due today');
            case 'mention_message':
                return t(':actorName mentioned you in a message', {
                    actorName,
                });
            case 'mention_todo':
                return t(':actorName mentioned you in a to-do', { actorName });
            case 'mention_file':
                return t(':actorName mentioned you in a file', { actorName });
            case 'todo_list_comment':
                return t(':actorName commented on a to-do list you follow', {
                    actorName,
                });
            default:
                return t(':actorName commented on a subscribed message', {
                    actorName,
                });
        }
    };
    const notificationHref = (notification: ProjectNotification) => {
        const projectArguments = [currentTeam.slug, notification.projectId] as [
            string,
            number,
        ];

        switch (notification.type) {
            case 'message_comment':
            case 'mention_message':
                return notification.messageBoardPostId !== null
                    ? MessageBoardController.show([
                          ...projectArguments,
                          notification.messageBoardPostId,
                      ])
                    : MessageBoardController.index(projectArguments);
            case 'mention_file':
                return notification.storedFileId !== null
                    ? ProjectFileController.show([
                          ...projectArguments,
                          notification.storedFileId,
                      ])
                    : ProjectMaterialController.index(projectArguments);
            case 'todo_list_comment':
                return notification.todoListId !== null
                    ? ProjectTodoController.showList([
                          ...projectArguments,
                          notification.todoListId,
                      ])
                    : ProjectTodoController.index(projectArguments);
            case 'check_in_due':
                return CheckInController.index(projectArguments);
            default:
                return ProjectTodoController.index(projectArguments);
        }
    };
</script>

<AppHead title={t('Notifications')} />

<div class="mx-auto w-full max-w-3xl space-y-6 p-4 sm:p-6">
    <div
        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
    >
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                {t('Notifications')}
            </h1>
            <p class="text-sm text-muted-foreground">
                {t('Updates from work you follow')}
            </p>
        </div>
        {#if hasUnread}
            <Form
                {...NotificationController.markAllRead.form(currentTeam.slug)}
            >
                {#snippet children({ processing })}
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        disabled={processing}>{t('Mark all as read')}</Button
                    >
                {/snippet}
            </Form>
        {/if}
    </div>

    {#if notifications.length === 0}
        <Card>
            <CardContent
                class="py-10 text-center text-sm text-muted-foreground"
            >
                {t('No notifications yet')}
            </CardContent>
        </Card>
    {:else}
        <div class="space-y-3">
            {#each notifications as notification (notification.id)}
                <Card class={!notification.read ? 'bg-primary/5' : ''}>
                    <CardContent class="pt-6">
                        <Link
                            href={notificationHref(notification)}
                            class="block rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <p class="break-words text-sm">
                                {notificationText(notification)}
                            </p>
                            <p
                                class="mt-1 break-words text-sm text-muted-foreground"
                            >
                                {notification.projectName} · {notification.messageTitle ??
                                    notification.todoTitle ??
                                    notification.checkInQuestion}
                            </p>
                            <p class="mt-2 text-xs text-muted-foreground">
                                {formatDate(notification.createdAt)}
                            </p>
                        </Link>
                    </CardContent>
                </Card>
            {/each}
        </div>
    {/if}
</div>
