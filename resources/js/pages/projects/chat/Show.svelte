<script module lang="ts">
    import ChatController from '@/actions/App/Http/Controllers/ChatController';
    import { show as showProject } from '@/routes/projects';
    import type { ChatProject, Team } from '@/types';

    export const layout = (props: {
        currentTeam: Team;
        project: ChatProject;
    }) => ({
        breadcrumbs: [
            {
                title: props.project.name,
                href: showProject([props.currentTeam.slug, props.project.id]),
            },
            {
                title: props.currentTeam.menuNames.chat,
                href: ChatController.show([
                    props.currentTeam.slug,
                    props.project.id,
                ]),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link, router, useHttp } from '@inertiajs/svelte';
    import type { ConnectionStatus } from 'laravel-echo';
    import Circle from 'lucide-svelte/icons/circle';
    import Hash from 'lucide-svelte/icons/hash';
    import LoaderCircle from 'lucide-svelte/icons/loader-circle';
    import MessageSquareText from 'lucide-svelte/icons/message-square-text';
    import Plus from 'lucide-svelte/icons/plus';
    import Send from 'lucide-svelte/icons/send';
    import X from 'lucide-svelte/icons/x';
    import { onMount, tick } from 'svelte';
    import { SvelteSet } from 'svelte/reactivity';
    import ChatChannelController from '@/actions/App/Http/Controllers/ChatChannelController';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { getEcho } from '@/lib/echo';
    import { t } from '@/lib/i18n';
    import type {
        ChatChannel,
        ChatHistoryResponse,
        ChatMessage,
        ChatStoreResponse,
        ChatThreadResponse,
    } from '@/types';

    let {
        currentTeam,
        project,
        channels,
        selectedChannel,
        messages: initialMessages,
        historyCursor: initialHistoryCursor,
    }: {
        currentTeam: Team;
        project: ChatProject;
        channels: ChatChannel[];
        selectedChannel: ChatChannel;
        messages: ChatMessage[];
        historyCursor: string | null;
    } = $props();

    const initialChannelId = (): number => selectedChannel.id;
    const initialMessageState = (): ChatMessage[] => [...initialMessages];
    const initialCursorState = (): string | null => initialHistoryCursor;
    const sendHttp = useHttp<
        { body: string; channel_id: number; parent_id: null },
        ChatStoreResponse
    >({ body: '', channel_id: initialChannelId(), parent_id: null });
    const replyHttp = useHttp<
        { body: string; channel_id: number; parent_id: number | null },
        ChatStoreResponse
    >({ body: '', channel_id: initialChannelId(), parent_id: null });
    const historyHttp = useHttp<Record<string, never>, ChatHistoryResponse>({});
    const syncHttp = useHttp<Record<string, never>, ChatHistoryResponse>({});
    const threadHttp = useHttp<Record<string, never>, ChatThreadResponse>({});

    let messages = $state<ChatMessage[]>(initialMessageState());
    let historyCursor = $state<string | null>(initialCursorState());
    let connectionStatus = $state<ConnectionStatus>('connecting');
    let isComposing = $state(false);
    let sendError = $state('');
    let showChannelForm = $state(false);
    let activeThread = $state<ChatMessage | null>(null);
    let threadMessages = $state<ChatMessage[]>([]);
    let threadCursor = $state<string | null>(null);
    let threadError = $state('');
    let messageList = $state<HTMLDivElement>();
    let threadList = $state<HTMLDivElement>();

    const receivedReplyIds = new SvelteSet<number>();
    const channelName = $derived(`projects.${project.id}.chat`);
    const connectionLabel = $derived(
        connectionStatus === 'connected'
            ? t('Connected')
            : connectionStatus === 'connecting' ||
                connectionStatus === 'reconnecting'
              ? t('Connecting')
              : t('Offline — messages are still saved'),
    );

    const messageTime = (createdAt: string): string =>
        new Intl.DateTimeFormat('ja-JP', {
            month: 'numeric',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        }).format(new Date(createdAt));

    const userInitial = (message: ChatMessage): string =>
        (message.user?.name ?? '?').slice(0, 1).toUpperCase();
    const mergeMessages = (incoming: ChatMessage[]): void => {
        const existingIds = new Set(messages.map((message) => message.id));
        const uniqueMessages = incoming.filter(
            (message) =>
                message.channelId === selectedChannel.id &&
                message.parentId === null &&
                !existingIds.has(message.id),
        );

        if (uniqueMessages.length > 0) {
            messages = [...messages, ...uniqueMessages].sort(
                (first, second) => first.id - second.id,
            );
        }
    };

    const handleReplyCreated = (reply: ChatMessage): void => {
        if (
            reply.channelId !== selectedChannel.id ||
            reply.parentId === null ||
            receivedReplyIds.has(reply.id)
        ) {
            return;
        }

        receivedReplyIds.add(reply.id);
        messages = messages.map((message) =>
            message.id === reply.parentId
                ? { ...message, replyCount: message.replyCount + 1 }
                : message,
        );

        if (activeThread?.id === reply.parentId) {
            threadMessages = [...threadMessages, reply].sort(
                (first, second) => first.id - second.id,
            );
            void scrollThreadToLatest();
        }
    };

    const scrollToLatest = async (smooth = true): Promise<void> => {
        await tick();
        messageList?.scrollTo({
            top: messageList.scrollHeight,
            behavior: smooth ? 'smooth' : 'auto',
        });
    };

    const scrollThreadToLatest = async (): Promise<void> => {
        await tick();
        threadList?.scrollTo({
            top: threadList.scrollHeight,
            behavior: 'smooth',
        });
    };

    const syncMissedMessages = async (): Promise<void> => {
        let afterId = messages.at(-1)?.id ?? 0;

        if (syncHttp.processing) {
            return;
        }

        try {
            let hasMore = false;

            do {
                const response = await syncHttp.submit(
                    ChatController.history([currentTeam.slug, project.id], {
                        query: {
                            channel_id: selectedChannel.id,
                            after_id: afterId,
                        },
                    }),
                );

                mergeMessages(response.messages);
                afterId = response.messages.at(-1)?.id ?? afterId;
                hasMore = response.hasMore ?? false;
            } while (hasMore);

            await scrollToLatest(false);
        } catch {
            connectionStatus = 'failed';
        }
    };

    const loadOlderMessages = async (): Promise<void> => {
        if (!historyCursor || historyHttp.processing) {
            return;
        }

        if (!messageList) {
            return;
        }

        const previousHeight = messageList.scrollHeight;

        try {
            const response = await historyHttp.submit(
                ChatController.history([currentTeam.slug, project.id], {
                    query: {
                        channel_id: selectedChannel.id,
                        cursor: historyCursor,
                    },
                }),
            );

            messages = [...response.messages, ...messages];
            historyCursor = response.nextCursor ?? null;
            await tick();
            messageList.scrollTop += messageList.scrollHeight - previousHeight;
        } catch {
            sendError = t('Could not load older messages.');
        }
    };

    const sendMessage = async (): Promise<void> => {
        const body = sendHttp.body.trim();

        if (!body || isComposing || sendHttp.processing) {
            return;
        }

        sendError = '';
        sendHttp.body = body;

        try {
            const response = await sendHttp.submit(
                ChatController.store([currentTeam.slug, project.id]),
            );

            if (response) {
                mergeMessages([response.message]);
                sendHttp.body = '';
                sendHttp.clearErrors();
                await scrollToLatest();
            }
        } catch {
            sendError = t('Message could not be sent. Please try again.');
        }
    };

    const openThread = async (message: ChatMessage): Promise<void> => {
        activeThread = message;
        threadMessages = [];
        threadCursor = null;
        threadError = '';
        replyHttp.parent_id = message.id;

        try {
            const response = await threadHttp.submit(
                ChatController.thread([
                    currentTeam.slug,
                    project.id,
                    message.id,
                ]),
            );

            activeThread = response.message;
            threadMessages = response.messages;
            threadCursor = response.nextCursor ?? null;
            response.messages.forEach((reply) =>
                receivedReplyIds.add(reply.id),
            );
            messages = messages.map((item) =>
                item.id === response.message.id ? response.message : item,
            );
            await scrollThreadToLatest();
        } catch {
            threadError = t('Could not load the thread.');
        }
    };

    const loadOlderReplies = async (): Promise<void> => {
        if (!activeThread || !threadCursor || threadHttp.processing) {
            return;
        }

        try {
            const response = await threadHttp.submit(
                ChatController.thread(
                    [currentTeam.slug, project.id, activeThread.id],
                    { query: { cursor: threadCursor } },
                ),
            );

            response.messages.forEach((reply) =>
                receivedReplyIds.add(reply.id),
            );
            threadMessages = [...response.messages, ...threadMessages];
            threadCursor = response.nextCursor ?? null;
        } catch {
            threadError = t('Could not load older messages.');
        }
    };

    const sendReply = async (): Promise<void> => {
        const body = replyHttp.body.trim();

        if (!activeThread || !body || isComposing || replyHttp.processing) {
            return;
        }

        threadError = '';
        replyHttp.body = body;
        replyHttp.parent_id = activeThread.id;

        try {
            const response = await replyHttp.submit(
                ChatController.store([currentTeam.slug, project.id]),
            );

            if (response) {
                handleReplyCreated(response.message);
                replyHttp.body = '';
                replyHttp.clearErrors();
            }
        } catch {
            threadError = t('Message could not be sent. Please try again.');
        }
    };

    const handleMessageKeydown = (event: KeyboardEvent): void => {
        if (
            event.key === 'Enter' &&
            !event.shiftKey &&
            !event.isComposing &&
            !isComposing
        ) {
            event.preventDefault();
            void sendMessage();
        }
    };

    const handleReplyKeydown = (event: KeyboardEvent): void => {
        if (
            event.key === 'Enter' &&
            !event.shiftKey &&
            !event.isComposing &&
            !isComposing
        ) {
            event.preventDefault();
            void sendReply();
        }
    };

    const switchChannel = (event: Event): void => {
        const channelId = Number(
            (event.currentTarget as HTMLSelectElement).value,
        );

        router.visit(
            ChatController.show([currentTeam.slug, project.id], {
                query: { channel: channelId },
            }),
        );
    };

    onMount(() => {
        void scrollToLatest(false);

        try {
            const echo = getEcho();
            connectionStatus = echo.connectionStatus();

            const stopWatchingConnection = echo.connector.onConnectionChange(
                (status) => {
                    const didReconnect =
                        connectionStatus !== 'connected' &&
                        status === 'connected';

                    connectionStatus = status;

                    if (didReconnect) {
                        void syncMissedMessages();
                    }
                },
            );

            echo.private(channelName)
                .subscribed(() => void syncMissedMessages())
                .listen('.chat.message.created', (event: ChatStoreResponse) => {
                    if (event.message.parentId === null) {
                        mergeMessages([event.message]);
                        void scrollToLatest();
                    } else {
                        handleReplyCreated(event.message);
                    }
                });

            return () => {
                stopWatchingConnection();
                echo.leave(channelName);
            };
        } catch {
            connectionStatus = 'failed';
        }
    });
</script>

<AppHead title={`${currentTeam.menuNames.chat} — ${project.name}`} />

<div
    class="relative grid min-h-0 flex-1 overflow-hidden bg-background lg:grid-cols-[15rem_minmax(0,1fr)]"
>
    <aside
        class="hidden min-h-0 flex-col border-r bg-sidebar text-sidebar-foreground lg:flex"
        aria-label={t('Chat channels')}
    >
        <div class="border-b px-4 py-4">
            <p class="truncate text-sm font-bold">{project.name}</p>
            <p class="mt-1 text-xs text-sidebar-foreground/60">
                {currentTeam.menuNames.chat}
            </p>
        </div>

        <div class="flex items-center justify-between gap-2 px-3 pt-4 pb-2">
            <span
                class="text-xs font-bold uppercase tracking-wider text-sidebar-foreground/60"
            >
                {t('Channels')}
            </span>
            <button
                type="button"
                class="rounded-md p-1 text-sidebar-foreground/70 transition hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring"
                aria-label={t('Create channel')}
                onclick={() => (showChannelForm = !showChannelForm)}
            >
                <Plus class="size-4" />
            </button>
        </div>

        {#if showChannelForm}
            <Form
                {...ChatChannelController.store.form([
                    currentTeam.slug,
                    project.id,
                ])}
                resetOnSuccess
                class="mx-3 mb-3 space-y-2 rounded-lg border border-sidebar-border bg-sidebar-accent/40 p-3"
            >
                {#snippet children({ errors, processing })}
                    <Input
                        name="name"
                        maxlength={80}
                        required
                        placeholder={t('Channel name')}
                        aria-label={t('Channel name')}
                        class="h-8 bg-sidebar"
                    />
                    <InputError message={errors.name} />
                    <div class="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onclick={() => (showChannelForm = false)}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button type="submit" size="sm" disabled={processing}>
                            {t('Create')}
                        </Button>
                    </div>
                {/snippet}
            </Form>
        {/if}

        <nav class="min-h-0 flex-1 overflow-y-auto px-2 pb-4">
            {#each channels as channel (channel.id)}
                <Link
                    href={ChatController.show([currentTeam.slug, project.id], {
                        query: { channel: channel.id },
                    })}
                    class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition {channel.id ===
                    selectedChannel.id
                        ? 'bg-sidebar-primary text-sidebar-primary-foreground'
                        : 'text-sidebar-foreground/75 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'}"
                    prefetch
                >
                    <Hash class="size-4 shrink-0" />
                    <span class="min-w-0 flex-1 truncate">{channel.name}</span>
                    <span class="text-[11px] opacity-60"
                        >{channel.messageCount}</span
                    >
                </Link>
            {/each}
        </nav>
    </aside>

    <section class="flex min-h-0 min-w-0 flex-col">
        <header
            class="flex shrink-0 items-center justify-between gap-4 border-b bg-card px-4 py-3 sm:px-6"
        >
            <div class="min-w-0 flex-1">
                <div class="hidden items-center gap-2 lg:flex">
                    <Hash class="size-5 text-muted-foreground" />
                    <h1 class="truncate text-lg font-bold">
                        {selectedChannel.name}
                    </h1>
                </div>
                <label class="sr-only" for="mobile-chat-channel"
                    >{t('Channels')}</label
                >
                <select
                    id="mobile-chat-channel"
                    value={selectedChannel.id}
                    class="h-9 max-w-full rounded-lg border border-input bg-background px-3 font-bold outline-none focus-visible:ring-2 focus-visible:ring-ring lg:hidden"
                    onchange={switchChannel}
                >
                    {#each channels as channel (channel.id)}
                        <option value={channel.id}># {channel.name}</option>
                    {/each}
                </select>
                {#if selectedChannel.description}
                    <p class="mt-0.5 truncate text-xs text-muted-foreground">
                        {selectedChannel.description}
                    </p>
                {/if}
            </div>

            <div
                class="flex shrink-0 items-center gap-2 text-xs text-muted-foreground"
                aria-live="polite"
            >
                {#if connectionStatus === 'connecting' || connectionStatus === 'reconnecting'}
                    <LoaderCircle class="size-3.5 animate-spin" />
                {:else}
                    <Circle
                        class="size-2.5 {connectionStatus === 'connected'
                            ? 'fill-emerald-500 text-emerald-500'
                            : 'fill-amber-500 text-amber-500'}"
                    />
                {/if}
                <span class="hidden sm:inline">{connectionLabel}</span>
            </div>
        </header>

        <div
            bind:this={messageList}
            class="min-h-0 flex-1 overflow-y-auto py-3"
            aria-live="polite"
            aria-label={t('Chat messages')}
        >
            {#if historyCursor}
                <div class="mb-3 text-center">
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={historyHttp.processing}
                        onclick={() => void loadOlderMessages()}
                    >
                        {historyHttp.processing
                            ? t('Loading')
                            : t('Load older messages')}
                    </Button>
                </div>
            {/if}

            {#if messages.length === 0}
                <div
                    class="flex min-h-80 flex-col items-center justify-center px-6 text-center"
                >
                    <span
                        class="mb-4 flex size-14 items-center justify-center rounded-2xl bg-primary/10 text-primary"
                    >
                        <Hash class="size-7" />
                    </span>
                    <h2 class="font-bold">#{selectedChannel.name}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {t('Start the conversation with your project team.')}
                    </p>
                </div>
            {/if}

            <div class="mx-auto max-w-5xl">
                {#each messages as message (message.id)}
                    <article
                        class="group flex gap-3 px-4 py-2.5 hover:bg-muted/35 sm:px-6"
                    >
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/15 text-sm font-bold text-primary"
                        >
                            {userInitial(message)}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-baseline gap-x-2">
                                <span class="font-bold">
                                    {message.user?.name ?? t('Deleted user')}
                                </span>
                                <time class="text-xs text-muted-foreground">
                                    {messageTime(message.createdAt)}
                                </time>
                            </div>
                            <p
                                class="mt-0.5 whitespace-pre-wrap break-words text-sm leading-6"
                            >
                                {message.body}
                            </p>
                            <button
                                type="button"
                                class="mt-1 inline-flex items-center gap-1.5 rounded-md px-1.5 py-1 text-xs font-semibold text-primary opacity-80 transition hover:bg-primary/10 hover:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                onclick={() => void openThread(message)}
                            >
                                <MessageSquareText class="size-3.5" />
                                {message.replyCount > 0
                                    ? t(':count replies', {
                                          count: String(message.replyCount),
                                      })
                                    : t('Reply in thread')}
                            </button>
                        </div>
                    </article>
                {/each}
            </div>
        </div>

        <footer
            class="shrink-0 border-t bg-card px-3 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:px-6"
        >
            <div class="mx-auto max-w-5xl">
                <div
                    class="flex items-end gap-2 rounded-xl border border-input bg-background p-1.5 shadow-sm focus-within:ring-2 focus-within:ring-ring"
                >
                    <textarea
                        bind:value={sendHttp.body}
                        rows="1"
                        maxlength="2000"
                        placeholder={t('Message #channel', {
                            channel: selectedChannel.name,
                        })}
                        aria-label={t('Write a message')}
                        class="min-h-10 max-h-32 flex-1 resize-y bg-transparent px-2 py-2 text-base outline-none placeholder:text-muted-foreground sm:text-sm"
                        onkeydown={handleMessageKeydown}
                        oncompositionstart={() => (isComposing = true)}
                        oncompositionend={() => (isComposing = false)}
                    ></textarea>
                    <Button
                        size="icon"
                        class="size-10 shrink-0 rounded-lg"
                        disabled={!sendHttp.body.trim() || sendHttp.processing}
                        aria-label={t('Send message')}
                        onclick={() => void sendMessage()}
                    >
                        {#if sendHttp.processing}
                            <LoaderCircle class="size-4 animate-spin" />
                        {:else}
                            <Send class="size-4" />
                        {/if}
                    </Button>
                </div>
                <InputError class="mt-1" message={sendHttp.errors.body} />
                {#if sendError}
                    <p class="mt-1 text-sm text-destructive">{sendError}</p>
                {/if}
            </div>
        </footer>
    </section>

    {#if activeThread}
        <aside
            class="absolute inset-0 z-20 flex min-h-0 flex-col border-l bg-card shadow-2xl lg:right-0 lg:left-auto lg:w-96"
            aria-label={t('Thread')}
        >
            <header
                class="flex shrink-0 items-center justify-between gap-3 border-b px-4 py-3"
            >
                <div>
                    <h2 class="font-bold">{t('Thread')}</h2>
                    <p class="text-xs text-muted-foreground">
                        #{selectedChannel.name}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={t('Close')}
                    onclick={() => (activeThread = null)}
                >
                    <X class="size-4" />
                </Button>
            </header>

            <div bind:this={threadList} class="min-h-0 flex-1 overflow-y-auto">
                <article class="flex gap-3 border-b bg-muted/20 px-4 py-4">
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/15 text-sm font-bold text-primary"
                    >
                        {userInitial(activeThread)}
                    </span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-baseline gap-x-2">
                            <span class="font-bold">
                                {activeThread.user?.name ?? t('Deleted user')}
                            </span>
                            <time class="text-xs text-muted-foreground">
                                {messageTime(activeThread.createdAt)}
                            </time>
                        </div>
                        <p
                            class="mt-1 whitespace-pre-wrap break-words text-sm leading-6"
                        >
                            {activeThread.body}
                        </p>
                    </div>
                </article>

                {#if threadCursor}
                    <div class="py-2 text-center">
                        <Button
                            variant="ghost"
                            size="sm"
                            disabled={threadHttp.processing}
                            onclick={() => void loadOlderReplies()}
                        >
                            {t('Load older replies')}
                        </Button>
                    </div>
                {/if}

                {#if threadHttp.processing && threadMessages.length === 0}
                    <div class="flex justify-center py-10">
                        <LoaderCircle
                            class="size-5 animate-spin text-muted-foreground"
                        />
                    </div>
                {/if}

                {#each threadMessages as reply (reply.id)}
                    <article class="flex gap-3 px-4 py-3 hover:bg-muted/30">
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-secondary text-xs font-bold"
                        >
                            {userInitial(reply)}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-baseline gap-x-2">
                                <span class="text-sm font-bold">
                                    {reply.user?.name ?? t('Deleted user')}
                                </span>
                                <time class="text-xs text-muted-foreground">
                                    {messageTime(reply.createdAt)}
                                </time>
                            </div>
                            <p
                                class="mt-0.5 whitespace-pre-wrap break-words text-sm leading-6"
                            >
                                {reply.body}
                            </p>
                        </div>
                    </article>
                {/each}
            </div>

            <footer
                class="shrink-0 border-t bg-card p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]"
            >
                <div
                    class="flex items-end gap-2 rounded-xl border border-input bg-background p-1.5 focus-within:ring-2 focus-within:ring-ring"
                >
                    <textarea
                        bind:value={replyHttp.body}
                        rows="1"
                        maxlength="2000"
                        placeholder={t('Reply')}
                        aria-label={t('Reply')}
                        class="min-h-10 max-h-32 flex-1 resize-y bg-transparent px-2 py-2 text-base outline-none placeholder:text-muted-foreground sm:text-sm"
                        onkeydown={handleReplyKeydown}
                        oncompositionstart={() => (isComposing = true)}
                        oncompositionend={() => (isComposing = false)}
                    ></textarea>
                    <Button
                        size="icon"
                        class="size-10 shrink-0 rounded-lg"
                        disabled={!replyHttp.body.trim() ||
                            replyHttp.processing}
                        aria-label={t('Send reply')}
                        onclick={() => void sendReply()}
                    >
                        {#if replyHttp.processing}
                            <LoaderCircle class="size-4 animate-spin" />
                        {:else}
                            <Send class="size-4" />
                        {/if}
                    </Button>
                </div>
                <InputError class="mt-1" message={replyHttp.errors.body} />
                {#if threadError}
                    <p class="mt-1 text-sm text-destructive">{threadError}</p>
                {/if}
            </footer>
        </aside>
    {/if}
</div>
