<script lang="ts">
    import type { ReloadOptions } from '@inertiajs/core';
    import { Form, Link, page, router, WhenVisible } from '@inertiajs/svelte';
    import Bookmark from 'lucide-svelte/icons/bookmark';
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import Clock3 from 'lucide-svelte/icons/clock-3';
    import MessageCircle from 'lucide-svelte/icons/message-circle';
    import NotebookPen from 'lucide-svelte/icons/notebook-pen';
    import Send from 'lucide-svelte/icons/send';
    import CheckSquare2 from 'lucide-svelte/icons/square-check-big';
    import X from 'lucide-svelte/icons/x';
    import { onMount } from 'svelte';
    import { SvelteDate } from 'svelte/reactivity';
    import BubbleUpController from '@/actions/App/Http/Controllers/BubbleUpController';
    import DirectMessageController from '@/actions/App/Http/Controllers/DirectMessageController';
    import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
    import NotificationReplyController from '@/actions/App/Http/Controllers/NotificationReplyController';
    import PersonalNoteAttachmentController from '@/actions/App/Http/Controllers/PersonalNoteAttachmentController';
    import PersonalNoteController from '@/actions/App/Http/Controllers/PersonalNoteController';
    import ProjectTodoController from '@/actions/App/Http/Controllers/ProjectTodoController';
    import WorkspaceCalendarController from '@/actions/App/Http/Controllers/WorkspaceCalendarController';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import { Button } from '@/components/ui/button';
    import { getEcho } from '@/lib/echo';
    import { t } from '@/lib/i18n';
    import { dashboard } from '@/routes';
    import { show } from '@/routes/projects';
    import type { Team, WorkspacePing } from '@/types';

    type Panel = 'tasks' | 'events' | 'bookmarks' | 'notes' | 'notifications';
    type NewForYouTab = 'notifications' | 'pings' | 'later';
    type PingCreatedPayload = { teamId: number; message: WorkspacePing };
    type LazyPanel = 'tasks' | 'events' | 'bookmarks' | 'pings';
    type LazyLoadState = 'idle' | 'loading' | 'error';

    const loadRichTextEditor = () =>
        import('@/components/RichTextEditor.svelte');
    type RichTextEditorPromise = ReturnType<typeof loadRichTextEditor>;

    let { currentTeam }: { currentTeam: Team } = $props();

    const workspace = $derived(page.props.workspaceBar);
    const tasks = $derived(workspace.tasks ?? []);
    const events = $derived(workspace.events ?? []);
    const bookmarks = $derived(workspace.bookmarks ?? []);
    const people = $derived(page.props.navigationPeople ?? []);
    // Recipients are mutable, so refresh them with pings before enabling a send.
    const pingsLazyData = ['workspaceBar.pings', 'navigationPeople'];
    const currentUserId = $derived(page.props.auth.user.id);
    const unreadNotifications = $derived(
        workspace.notifications.filter((item) => !item.read).length,
    );
    const dueBubbleUps = $derived(
        workspace.bubbleUps.filter((item) => item.due).length,
    );
    const newForYouCount = $derived(
        unreadNotifications + workspace.unreadPings + dueBubbleUps,
    );
    let activePanel = $state<Panel | null>(null);
    let newForYouTab = $state<NewForYouTab>('notifications');
    let richTextEditorModule = $state<RichTextEditorPromise | null>(null);
    let livePings = $state<PingCreatedPayload[]>([]);
    let pingBody = $state('');
    let now = $state(Date.now());
    let lazyLoadStates = $state<Record<LazyPanel, LazyLoadState>>({
        tasks: 'idle',
        events: 'idle',
        bookmarks: 'idle',
        pings: 'idle',
    });
    const pings = $derived.by(() => {
        const all = [
            ...livePings
                .filter(({ teamId }) => teamId === currentTeam.id)
                .map(({ message }) => message),
            ...(workspace.pings ?? []),
        ];

        // Sort after merging so an old live item cannot displace newer canonical rows.
        return Array.from(
            new Map(all.map((message) => [message.id, message])).values(),
        )
            .sort((left, right) => left.id - right.id)
            .slice(-30);
    });
    const reminderMinutes = $derived(
        workspace.reminder
            ? Math.max(
                  0,
                  Math.ceil(
                      (new Date(workspace.reminder.startsAt).getTime() - now) /
                          60_000,
                  ),
              )
            : null,
    );
    const reminderClass = $derived(
        reminderMinutes !== null && reminderMinutes <= 5
            ? 'border-emerald-400 bg-emerald-600 text-white'
            : reminderMinutes !== null && reminderMinutes <= 10
              ? 'border-lime-400 bg-lime-100 text-lime-950 dark:bg-lime-800 dark:text-white'
              : 'border-amber-300 bg-amber-50 text-amber-950 dark:bg-amber-900 dark:text-amber-50',
    );
    const eventFormatter = new Intl.DateTimeFormat('ja-JP', {
        timeZone: 'Asia/Tokyo',
        month: 'numeric',
        day: 'numeric',
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
    const dockItems = [
        { panel: 'tasks' as const, label: t('My tasks'), icon: CheckSquare2 },
        { panel: 'events' as const, label: t('My events'), icon: CalendarDays },
        {
            panel: 'bookmarks' as const,
            label: t('My bookmarks'),
            icon: Bookmark,
        },
        { panel: 'notes' as const, label: t('My notes'), icon: NotebookPen },
    ];

    const itemClasses = (isActive: boolean): string =>
        `flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 rounded-lg px-1 py-0.5 text-[9px] font-medium whitespace-nowrap transition sm:flex-none sm:flex-row sm:px-2 sm:text-xs ${
            isActive
                ? 'bg-slate-800 text-white dark:bg-slate-100 dark:text-slate-900'
                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-white'
        }`;
    const closePanel = (): void => {
        activePanel = null;
    };
    const togglePanel = (panel: Panel): void => {
        const nextPanel = activePanel === panel ? null : panel;

        if (nextPanel === 'notes' && !richTextEditorModule) {
            // Trix and Markdown rendering are large; load them only for users who open notes.
            richTextEditorModule = loadRichTextEditor();
        }

        activePanel = nextPanel;
    };
    const openNewForYou = (): void => {
        activePanel = 'notifications';
    };
    const tomorrowAtNine = (): string => {
        const date = new SvelteDate();
        date.setDate(date.getDate() + 1);
        date.setHours(9, 0, 0, 0);

        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}T09:00`;
    };
    const handleKeydown = (event: KeyboardEvent): void => {
        if (event.key === 'Escape') {
            closePanel();
        }
    };
    const lazyPanelIsLoaded = (panel: LazyPanel): boolean =>
        page.props.workspaceBar[panel] !== undefined &&
        (panel !== 'pings' || page.props.navigationPeople !== undefined);
    const lazyLoadParams = (panel: LazyPanel): ReloadOptions => {
        const fail = (): void => {
            lazyLoadStates[panel] = 'error';
        };
        const handleNetworkError = (): false => {
            fail();

            // This panel renders the retry UI, so suppress Inertia's global error handling.
            return false;
        };
        const settleFromCurrentProps = (): void => {
            lazyLoadStates[panel] = lazyPanelIsLoaded(panel) ? 'idle' : 'error';
        };

        return {
            onStart: () => {
                lazyLoadStates[panel] = 'loading';
            },
            onSuccess: () => {
                lazyLoadStates[panel] = 'idle';
            },
            // Preserved validation errors can trigger onError after a successful partial reload.
            onError: settleFromCurrentProps,
            onHttpException: fail,
            onNetworkError: handleNetworkError,
            onCancel: settleFromCurrentProps,
        };
    };
    const retryLazyPanel = (
        panel: LazyPanel,
        data: string | string[],
    ): void => {
        router.reload({
            only: Array.isArray(data) ? data : [data],
            preserveErrors: true,
            ...lazyLoadParams(panel),
        });
    };
    const refreshPings = (): void => {
        // The broadcast is best-effort; refresh after writes so the sender also gets canonical state.
        router.reload({
            only: ['workspaceBar.pings', 'workspaceBar.unreadPings'],
            preserveErrors: true,
            ...lazyLoadParams('pings'),
        });
    };

    onMount(() => {
        const timer = window.setInterval(() => (now = Date.now()), 30_000);
        const openHandler = (): void => openNewForYou();
        window.addEventListener('jin:new-for-you', openHandler);

        const channelName = `users.${currentUserId}.pings`;
        const echo = getEcho();
        echo.private(channelName).listen(
            '.ping.created',
            (payload: PingCreatedPayload) => {
                if (payload.teamId !== currentTeam.id) {
                    return;
                }

                // Persistent layouts share the user channel across teams; retain only unique, bounded messages.
                livePings = [
                    ...livePings.filter(
                        ({ message }) => message.id !== payload.message.id,
                    ),
                    payload,
                ].slice(-30);
            },
        );

        return () => {
            window.clearInterval(timer);
            window.removeEventListener('jin:new-for-you', openHandler);
            echo.leave(channelName);
        };
    });
</script>

{#snippet lazyLoading()}
    <p
        class="px-5 py-12 text-center text-sm text-muted-foreground"
        role="status"
    >
        {t('Loading')}
    </p>
{/snippet}

{#snippet lazyLoadFailure(panel: LazyPanel, data: string | string[])}
    <div class="space-y-3 px-5 py-8 text-center" role="alert">
        <p class="text-sm text-destructive">
            {t('Could not load this panel.')}
        </p>
        <Button
            type="button"
            size="sm"
            variant="outline"
            onclick={() => retryLazyPanel(panel, data)}
        >
            {t('Retry')}
        </Button>
    </div>
{/snippet}

<svelte:window onkeydown={handleKeydown} />

{#if workspace.reminder && reminderMinutes !== null}
    <aside
        class={`fixed right-3 bottom-16 z-50 flex max-w-[calc(100vw-1.5rem)] items-center gap-3 rounded-2xl border px-4 py-3 shadow-xl transition-colors ${reminderClass}`}
        aria-live="polite"
    >
        <Clock3 class="size-5 shrink-0" />
        <div class="min-w-0 text-sm">
            <p class="truncate font-bold">{workspace.reminder.title}</p>
            <p class="truncate text-xs opacity-80">
                {reminderMinutes === 0
                    ? t('Starting now')
                    : t('Starts in :count minutes', {
                          count: String(reminderMinutes),
                      })}
                · {workspace.reminder.project.name}
            </p>
        </div>
        {#if workspace.reminder.meetingUrl}
            <a
                href={workspace.reminder.meetingUrl}
                target="_blank"
                rel="noopener noreferrer"
                class="shrink-0 rounded-lg bg-white/90 px-3 py-2 text-xs font-bold text-slate-900 hover:bg-white"
                >{t('Join')}</a
            >
        {/if}
    </aside>
{/if}

{#if activePanel}
    <button
        type="button"
        class="fixed inset-0 bottom-12 z-40 bg-slate-950/20 backdrop-blur-[1px]"
        aria-label={t('Close personal menu')}
        onclick={closePanel}
    ></button>

    <div
        id="workspace-personal-panel"
        role="dialog"
        aria-modal="true"
        aria-label={activePanel === 'notifications'
            ? t('Pings + Notifications')
            : dockItems.find((item) => item.panel === activePanel)?.label}
        class="fixed inset-x-2 bottom-14 z-50 mx-auto max-h-[min(72dvh,42rem)] max-w-3xl overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-[#14242c]"
    >
        <header
            class="sticky top-0 z-10 flex items-center justify-between gap-3 border-b bg-white/95 px-4 py-3 backdrop-blur dark:bg-[#14242c]/95 sm:px-5"
        >
            <h2 class="font-bold text-slate-900 dark:text-white">
                {activePanel === 'notifications'
                    ? t('New for you')
                    : dockItems.find((item) => item.panel === activePanel)
                          ?.label}
            </h2>
            <button
                type="button"
                class="rounded-full p-2 hover:bg-muted"
                aria-label={t('Close')}
                onclick={closePanel}
            >
                <X class="size-4" />
            </button>
        </header>

        {#if activePanel === 'tasks'}
            <WhenVisible
                data="workspaceBar.tasks"
                params={lazyLoadParams('tasks')}
                always
            >
                {#snippet fallback()}
                    {@render lazyLoading()}
                {/snippet}
                {#if lazyLoadStates.tasks === 'error'}
                    {@render lazyLoadFailure('tasks', 'workspaceBar.tasks')}
                {/if}
                {#if workspace.tasks !== undefined}
                    <div class="divide-y">
                        {#each tasks as task (task.id)}
                            <Link
                                href={ProjectTodoController.index([
                                    currentTeam.slug,
                                    task.project.id,
                                ])}
                                class="flex items-center gap-3 px-5 py-3 hover:bg-muted/60"
                                onclick={closePanel}
                            >
                                <span
                                    class="size-5 shrink-0 rounded-md border-2"
                                ></span>
                                <span class="min-w-0">
                                    <span
                                        class="block truncate text-sm font-semibold"
                                        >{task.title}</span
                                    >
                                    <span
                                        class="block truncate text-xs text-muted-foreground"
                                        >{task.project.name}</span
                                    >
                                </span>
                            </Link>
                        {:else}
                            <p
                                class="px-5 py-12 text-center text-sm text-muted-foreground"
                            >
                                {t('No assigned tasks')}
                            </p>
                        {/each}
                    </div>
                {:else if lazyLoadStates.tasks === 'loading'}
                    {@render lazyLoading()}
                {/if}
            </WhenVisible>
        {:else if activePanel === 'events'}
            <WhenVisible
                data="workspaceBar.events"
                params={lazyLoadParams('events')}
                always
            >
                {#snippet fallback()}
                    {@render lazyLoading()}
                {/snippet}
                {#if lazyLoadStates.events === 'error'}
                    {@render lazyLoadFailure('events', 'workspaceBar.events')}
                {/if}
                {#if workspace.events !== undefined}
                    <div class="divide-y">
                        {#each events as event (event.id)}
                            <Link
                                href={WorkspaceCalendarController(
                                    currentTeam.slug,
                                )}
                                class="flex items-start gap-3 px-5 py-3 hover:bg-muted/60"
                                onclick={closePanel}
                            >
                                <CalendarDays
                                    class="mt-0.5 size-5 shrink-0 text-blue-500"
                                />
                                <span class="min-w-0">
                                    <span
                                        class="block truncate text-sm font-semibold"
                                        >{event.title}</span
                                    >
                                    <span
                                        class="block truncate text-xs text-muted-foreground"
                                        >{eventFormatter.format(
                                            new Date(event.startsAt),
                                        )} · {event.project.name}</span
                                    >
                                </span>
                            </Link>
                        {:else}
                            <p
                                class="px-5 py-12 text-center text-sm text-muted-foreground"
                            >
                                {t('No events in the next 7 days')}
                            </p>
                        {/each}
                    </div>
                {:else if lazyLoadStates.events === 'loading'}
                    {@render lazyLoading()}
                {/if}
            </WhenVisible>
        {:else if activePanel === 'bookmarks'}
            <WhenVisible
                data="workspaceBar.bookmarks"
                params={lazyLoadParams('bookmarks')}
                always
            >
                {#snippet fallback()}
                    {@render lazyLoading()}
                {/snippet}
                {#if lazyLoadStates.bookmarks === 'error'}
                    {@render lazyLoadFailure(
                        'bookmarks',
                        'workspaceBar.bookmarks',
                    )}
                {/if}
                {#if workspace.bookmarks !== undefined}
                    <div class="grid gap-3 p-4 sm:grid-cols-2 sm:p-5">
                        {#each bookmarks as project (project.id)}
                            <Link
                                href={show([currentTeam.slug, project.id])}
                                class="rounded-xl border p-4 hover:bg-muted/40"
                                onclick={closePanel}
                            >
                                <span
                                    class="flex items-center gap-2 font-semibold"
                                    ><Bookmark
                                        class="size-4 fill-amber-400 text-amber-500"
                                    />{project.name}</span
                                >
                                {#if project.description}<span
                                        class="mt-1 line-clamp-2 block text-xs text-muted-foreground"
                                        >{project.description}</span
                                    >{/if}
                            </Link>
                        {:else}
                            <p
                                class="col-span-full py-12 text-center text-sm text-muted-foreground"
                            >
                                {t("You haven't bookmarked anything yet")}
                            </p>
                        {/each}
                    </div>
                {:else if lazyLoadStates.bookmarks === 'loading'}
                    {@render lazyLoading()}
                {/if}
            </WhenVisible>
        {:else if activePanel === 'notes'}
            <Form
                {...PersonalNoteController.store.form(currentTeam.slug)}
                resetOnSuccess
                class="p-4 sm:p-5"
            >
                {#snippet children({ errors, processing })}
                    {#if richTextEditorModule}
                        {#await richTextEditorModule}
                            <p
                                class="py-12 text-center text-sm text-muted-foreground"
                                role="status"
                            >
                                {t('Loading')}
                            </p>
                        {:then { default: RichTextEditor }}
                            <div
                                class="overflow-hidden rounded-xl border bg-card"
                            >
                                <input
                                    name="title"
                                    maxlength="255"
                                    required
                                    aria-label={t('Title')}
                                    placeholder={t('Title')}
                                    class="w-full border-b bg-transparent px-4 py-3 text-sm font-semibold outline-none"
                                />
                                <RichTextEditor
                                    id="workspace-quick-note-body"
                                    name="body"
                                    required
                                    ariaLabel={t('Note')}
                                    placeholder={t('Jot something down...')}
                                    attachmentUploadUrl={PersonalNoteAttachmentController.upload.url(
                                        currentTeam.slug,
                                    )}
                                />
                            </div>
                            {#if errors.title || errors.body}<p
                                    class="mt-2 text-sm text-destructive"
                                >
                                    {errors.title || errors.body}
                                </p>{/if}
                            <div class="mt-3 text-right">
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}
                                    >{t('Save note')}</Button
                                >
                            </div>
                        {:catch}
                            <div class="py-8 text-center" role="alert">
                                <p class="text-sm text-destructive">
                                    {t('Could not load the editor.')}
                                </p>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    class="mt-3"
                                    onclick={() => window.location.reload()}
                                    >{t('Retry')}</Button
                                >
                            </div>
                        {/await}
                    {/if}
                {/snippet}
            </Form>
        {:else}
            <nav
                class="grid grid-cols-3 border-b p-2"
                aria-label={t('New for you')}
            >
                <button
                    type="button"
                    class:font-bold={newForYouTab === 'notifications'}
                    class="rounded-lg px-3 py-2 text-sm hover:bg-muted"
                    onclick={() => (newForYouTab = 'notifications')}
                    >{t('Notifications')} ({unreadNotifications})</button
                >
                <button
                    type="button"
                    class:font-bold={newForYouTab === 'pings'}
                    class="rounded-lg px-3 py-2 text-sm hover:bg-muted"
                    onclick={() => (newForYouTab = 'pings')}
                    >{t('Pings')} ({workspace.unreadPings})</button
                >
                <button
                    type="button"
                    class:font-bold={newForYouTab === 'later'}
                    class="rounded-lg px-3 py-2 text-sm hover:bg-muted"
                    onclick={() => (newForYouTab = 'later')}
                    >{t('Later')} ({workspace.bubbleUps.length})</button
                >
            </nav>

            {#if newForYouTab === 'notifications'}
                <div class="divide-y">
                    {#each workspace.notifications as notification (notification.id)}
                        <article
                            class={!notification.read
                                ? 'bg-primary/5 px-5 py-3'
                                : 'px-5 py-3'}
                        >
                            <p class="text-sm font-semibold">
                                {notification.actorName || t('Notification')} · {notification.projectName}
                            </p>
                            <p class="mt-0.5 text-sm text-muted-foreground">
                                {notification.title || t('Project update')}
                            </p>
                            {#if notification.replyable}
                                <Form
                                    {...NotificationReplyController.store.form([
                                        currentTeam.slug,
                                        notification.id,
                                    ])}
                                    resetOnSuccess
                                    class="mt-2 flex gap-2"
                                >
                                    {#snippet children({ processing })}
                                        <input
                                            name="body"
                                            required
                                            maxlength="5000"
                                            placeholder={t(
                                                'Reply without leaving this page',
                                            )}
                                            class="min-w-0 flex-1 rounded-md border bg-background px-2.5 py-1.5 text-sm"
                                        />
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={processing}
                                            >{t('Reply')}</Button
                                        >
                                    {/snippet}
                                </Form>
                            {/if}
                            <details class="mt-2 text-xs">
                                <summary
                                    class="cursor-pointer font-semibold text-primary"
                                    >{t('Bubble up later')}</summary
                                >
                                <Form
                                    {...BubbleUpController.store.form(
                                        currentTeam.slug,
                                    )}
                                    resetOnSuccess
                                    class="mt-2 flex flex-wrap items-center gap-2"
                                >
                                    {#snippet children({ processing })}
                                        <input
                                            type="hidden"
                                            name="label"
                                            value={`${notification.projectName}: ${notification.title || t('Project update')}`}
                                        />
                                        <input
                                            type="hidden"
                                            name="url"
                                            value={NotificationController.index(
                                                currentTeam.slug,
                                            ).url}
                                        />
                                        <input
                                            type="datetime-local"
                                            name="remind_at"
                                            value={tomorrowAtNine()}
                                            required
                                            class="rounded-md border bg-background px-2 py-1.5"
                                        />
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="outline"
                                            disabled={processing}
                                            >{t('Save')}</Button
                                        >
                                    {/snippet}
                                </Form>
                            </details>
                        </article>
                    {:else}
                        <p
                            class="px-5 py-12 text-center text-sm text-muted-foreground"
                        >
                            {t('No notifications yet')}
                        </p>
                    {/each}
                </div>
                <footer class="border-t p-3 text-center">
                    <Button variant="outline" size="sm" asChild
                        >{#snippet children(props)}<Link
                                {...props}
                                href={NotificationController.index(
                                    currentTeam.slug,
                                )}
                                class={props.class}
                                onclick={closePanel}
                                >{t('Open notifications')}</Link
                            >{/snippet}</Button
                    >
                </footer>
            {:else if newForYouTab === 'pings'}
                <WhenVisible
                    data={pingsLazyData}
                    params={lazyLoadParams('pings')}
                    always
                >
                    {#snippet fallback()}
                        {@render lazyLoading()}
                    {/snippet}
                    {#if lazyLoadStates.pings === 'error'}
                        {@render lazyLoadFailure('pings', pingsLazyData)}
                    {/if}
                    {#if workspace.pings !== undefined && page.props.navigationPeople !== undefined}
                        <div
                            class="max-h-72 space-y-2 overflow-y-auto bg-muted/20 p-4"
                        >
                            {#each pings as message (message.id)}
                                <div
                                    class:ml-auto={message.sender.id ===
                                        currentUserId}
                                    class="max-w-[85%] rounded-2xl border bg-card px-3 py-2 text-sm shadow-xs"
                                >
                                    <p
                                        class="text-xs font-bold text-muted-foreground"
                                    >
                                        {message.sender.name}
                                    </p>
                                    <p
                                        class="mt-1 whitespace-pre-wrap break-words"
                                    >
                                        {message.body}
                                    </p>
                                </div>
                            {:else}
                                <p
                                    class="py-8 text-center text-sm text-muted-foreground"
                                >
                                    {t('No pings yet')}
                                </p>
                            {/each}
                        </div>
                        <Form
                            {...DirectMessageController.store.form(
                                currentTeam.slug,
                            )}
                            resetOnSuccess
                            onSuccess={() => {
                                pingBody = '';
                                refreshPings();
                            }}
                            class="grid gap-2 border-t p-4 sm:grid-cols-[12rem_1fr_auto_auto]"
                        >
                            {#snippet children({ errors, processing })}
                                <select
                                    name="recipient_id"
                                    required
                                    class="h-10 rounded-md border bg-background px-3 text-sm"
                                    aria-label={t('Recipient')}
                                >
                                    <option value=""
                                        >{t('Choose a person')}</option
                                    >
                                    {#each people as person (person.id)}<option
                                            value={person.id}
                                            >{person.name}</option
                                        >{/each}
                                </select>
                                <input
                                    name="body"
                                    bind:value={pingBody}
                                    maxlength="4000"
                                    required
                                    placeholder={t('Write a ping')}
                                    class="h-10 min-w-0 rounded-md border bg-background px-3 text-sm"
                                />
                                <Button
                                    type="submit"
                                    size="icon"
                                    disabled={processing}
                                    aria-label={t('Send')}
                                    ><Send class="size-4" /></Button
                                >
                                {#if errors.recipient_id || errors.body}<p
                                        class="text-sm text-destructive sm:col-span-3"
                                    >
                                        {errors.recipient_id || errors.body}
                                    </p>{/if}
                            {/snippet}
                        </Form>
                        {#if workspace.unreadPings > 0}
                            <Form
                                {...DirectMessageController.markRead.form(
                                    currentTeam.slug,
                                )}
                                onSuccess={refreshPings}
                                class="border-t p-3 text-right"
                                >{#snippet children({ processing })}<Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        disabled={processing}
                                        >{t('Mark pings as read')}</Button
                                    >{/snippet}</Form
                            >
                        {/if}
                    {:else if lazyLoadStates.pings === 'loading'}
                        {@render lazyLoading()}
                    {/if}
                </WhenVisible>
            {:else}
                <div class="divide-y">
                    {#each workspace.bubbleUps as item (item.id)}
                        <div
                            class={item.due
                                ? 'flex items-center gap-3 bg-amber-50 px-5 py-3 dark:bg-amber-950/20'
                                : 'flex items-center gap-3 px-5 py-3'}
                        >
                            <Clock3 class="size-4 shrink-0" />
                            <Link
                                href={item.url}
                                class="min-w-0 flex-1"
                                onclick={closePanel}
                            >
                                <span
                                    class="block truncate text-sm font-semibold"
                                    >{item.label}</span
                                >
                                <span class="text-xs text-muted-foreground"
                                    >{eventFormatter.format(
                                        new Date(item.remindAt),
                                    )}</span
                                >
                            </Link>
                            <Form
                                {...BubbleUpController.complete.form([
                                    currentTeam.slug,
                                    item.id,
                                ])}
                                >{#snippet children({ processing })}<Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        disabled={processing}
                                        >{t('Done')}</Button
                                    >{/snippet}</Form
                            >
                        </div>
                    {:else}
                        <p
                            class="px-5 py-12 text-center text-sm text-muted-foreground"
                        >
                            {t('Nothing saved for later')}
                        </p>
                    {/each}
                </div>
            {/if}
        {/if}
    </div>
{/if}

<nav
    class="fixed inset-x-0 bottom-0 z-50 border-t border-slate-200/80 bg-white/95 shadow-[0_-8px_30px_rgba(15,23,42,0.08)] backdrop-blur dark:border-[#495257] dark:bg-[#1e292f]"
    aria-label={t('Personal menu')}
>
    <div
        class="mx-auto grid h-12 max-w-[96rem] grid-cols-[1fr_auto] items-stretch px-2 sm:grid-cols-[3.5rem_1fr_3.5rem] sm:px-0"
    >
        <Link
            href={dashboard.url(currentTeam.slug)}
            class="hidden items-center sm:flex"
            aria-label={t('Dashboard')}><AppLogoIcon class="size-8" /></Link
        >
        <div class="flex min-w-0 items-stretch justify-center">
            {#each dockItems as item (item.panel)}
                <button
                    type="button"
                    class={itemClasses(activePanel === item.panel)}
                    aria-expanded={activePanel === item.panel}
                    aria-controls="workspace-personal-panel"
                    onclick={() => togglePanel(item.panel)}
                >
                    <item.icon class="size-3.5 shrink-0 sm:hidden" /><span
                        class="truncate">{item.label}</span
                    >
                </button>
            {/each}
        </div>
        <button
            type="button"
            class={`${itemClasses(activePanel === 'notifications')} sm:justify-self-end`}
            aria-expanded={activePanel === 'notifications'}
            aria-controls="workspace-personal-panel"
            onclick={openNewForYou}
        >
            <span class="relative md:hidden"
                ><MessageCircle class="size-3.5" />{#if newForYouCount > 0}<span
                        class="absolute -right-2 -top-2 min-w-4 rounded-full bg-red-500 px-0.5 text-[9px] font-bold leading-4 text-white"
                        >{newForYouCount > 9 ? '9+' : newForYouCount}</span
                    >{/if}</span
            >
            <span class="hidden md:inline">{t('Pings + Notifications')}</span
            ><span class="md:hidden">{t('Updates')}</span>
        </button>
    </div>
</nav>
