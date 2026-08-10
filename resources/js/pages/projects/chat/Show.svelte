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
    import FileText from 'lucide-svelte/icons/file-text';
    import Hash from 'lucide-svelte/icons/hash';
    import ListTodo from 'lucide-svelte/icons/list-todo';
    import LoaderCircle from 'lucide-svelte/icons/loader-circle';
    import Paperclip from 'lucide-svelte/icons/paperclip';
    import Plus from 'lucide-svelte/icons/plus';
    import Send from 'lucide-svelte/icons/send';
    import X from 'lucide-svelte/icons/x';
    import { onMount, tick } from 'svelte';
    import { SvelteSet } from 'svelte/reactivity';
    import AttachmentController from '@/actions/App/Http/Controllers/AttachmentController';
    import ChatChannelController from '@/actions/App/Http/Controllers/ChatChannelController';
    import AppHead from '@/components/AppHead.svelte';
    import ChatChannelActions from '@/components/ChatChannelActions.svelte';
    import ChatContextPanel from '@/components/ChatContextPanel.svelte';
    import ChatMessageItem from '@/components/ChatMessageItem.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import {
        Sheet,
        SheetContent,
        SheetDescription,
        SheetHeader,
        SheetTitle,
    } from '@/components/ui/sheet';
    import { getEcho } from '@/lib/echo';
    import { t } from '@/lib/i18n';
    import { cn } from '@/lib/utils';
    import type {
        ChatAttachment,
        ChatChannel,
        ChatHistoryResponse,
        ChatMessage,
        ChatReactionResponse,
        ChatStoreResponse,
        ChatThreadResponse,
        ChatTodo,
        ChatTodoList,
        ProjectReaction,
        TodoMember,
    } from '@/types';

    type ChatMessagePayload = {
        body: string;
        channel_id: number;
        parent_id: number | null;
        attachment_ids: number[];
    };
    type ChatReactionBroadcast = {
        messageId: number;
        reactions: Array<
            Omit<ProjectReaction, 'reacted'> & { userIds: number[] }
        >;
    };
    type ContextSection = 'todos' | 'files';

    let {
        currentTeam,
        project,
        channels,
        selectedChannel,
        messages: initialMessages,
        historyCursor: initialHistoryCursor,
        todoLists,
        relatedTodos,
        linkableTodos,
        channelFiles: initialChannelFiles,
        members,
        maxChatAttachments,
        currentUserId,
    }: {
        currentTeam: Team;
        project: ChatProject;
        channels: ChatChannel[];
        selectedChannel: ChatChannel;
        messages: ChatMessage[];
        historyCursor: string | null;
        todoLists: ChatTodoList[];
        relatedTodos: ChatTodo[];
        linkableTodos: ChatTodo[];
        channelFiles: ChatAttachment[];
        members: TodoMember[];
        maxChatAttachments: number;
        currentUserId: number;
    } = $props();

    const initialChannelId = (): number => selectedChannel.id;
    const initialMessageState = (): ChatMessage[] => [...initialMessages];
    const initialFileState = (): ChatAttachment[] => [...initialChannelFiles];
    const initialCursorState = (): string | null => initialHistoryCursor;
    const sendHttp = useHttp<ChatMessagePayload, ChatStoreResponse>({
        body: '',
        channel_id: initialChannelId(),
        parent_id: null,
        attachment_ids: [],
    });
    const replyHttp = useHttp<ChatMessagePayload, ChatStoreResponse>({
        body: '',
        channel_id: initialChannelId(),
        parent_id: null,
        attachment_ids: [],
    });
    const historyHttp = useHttp<Record<string, never>, ChatHistoryResponse>({});
    const syncHttp = useHttp<Record<string, never>, ChatHistoryResponse>({});
    const threadHttp = useHttp<Record<string, never>, ChatThreadResponse>({});
    const reactionHttp = useHttp<{ reaction: string }, ChatReactionResponse>({
        reaction: '',
    });

    let messages = $state<ChatMessage[]>(initialMessageState());
    let channelFiles = $state<ChatAttachment[]>(initialFileState());
    let historyCursor = $state<string | null>(initialCursorState());
    let connectionStatus = $state<ConnectionStatus>('connecting');
    let isComposing = $state(false);
    let sendError = $state('');
    let uploadError = $state('');
    let showChannelForm = $state(false);
    let activeThread = $state<ChatMessage | null>(null);
    let threadMessages = $state<ChatMessage[]>([]);
    let threadCursor = $state<string | null>(null);
    let threadError = $state('');
    let pendingAttachments = $state<ChatAttachment[]>([]);
    let pendingUploadCount = $state(0);
    let dragActive = $state(false);
    let contextSheetOpen = $state(false);
    let contextSection = $state<ContextSection>('todos');
    let messageList = $state<HTMLDivElement>();
    let threadList = $state<HTMLDivElement>();
    let fileInput = $state<HTMLInputElement>();
    let composerShell = $state<HTMLDivElement>();
    let messageComposer = $state<HTMLTextAreaElement>();
    let composerExpanded = $state(false);

    const receivedReplyIds = new SvelteSet<number>();
    const channelName = $derived(`projects.${project.id}.chat`);
    const incompleteTodoCount = $derived(
        relatedTodos.filter((todo) => !todo.completed).length,
    );
    const connectionLabel = $derived(
        connectionStatus === 'connected'
            ? t('Connected')
            : connectionStatus === 'connecting' ||
                connectionStatus === 'reconnecting'
              ? t('Connecting')
              : t('Offline — messages are still saved'),
    );
    const canSend = $derived(
        !sendHttp.processing &&
            pendingUploadCount === 0 &&
            (sendHttp.body.trim() !== '' || pendingAttachments.length > 0),
    );

    const mergeChannelFiles = (incoming: ChatAttachment[]): void => {
        const existingIds = new Set(channelFiles.map((file) => file.id));
        const uniqueFiles = incoming.filter(
            (file) => !existingIds.has(file.id),
        );

        if (uniqueFiles.length > 0) {
            channelFiles = [...uniqueFiles.reverse(), ...channelFiles];
        }
    };

    const mergeMessages = (incoming: ChatMessage[]): void => {
        const existingIds = new Set(messages.map((message) => message.id));
        const uniqueMessages = incoming.filter(
            (message) =>
                message.channelId === selectedChannel.id &&
                message.parentId === null &&
                !existingIds.has(message.id),
        );

        incoming.forEach((message) => mergeChannelFiles(message.attachments));

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
        mergeChannelFiles(reply.attachments);
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

    const setMessageReactions = (
        messageId: number,
        reactions: ProjectReaction[],
    ): void => {
        messages = messages.map((message) =>
            message.id === messageId ? { ...message, reactions } : message,
        );
        threadMessages = threadMessages.map((message) =>
            message.id === messageId ? { ...message, reactions } : message,
        );

        if (activeThread?.id === messageId) {
            activeThread = { ...activeThread, reactions };
        }
    };

    const toggleMessageReaction = async (
        message: ChatMessage,
        reaction: string,
    ): Promise<void> => {
        reactionHttp.reaction = reaction;

        const response = await reactionHttp.submit(
            ChatController.toggleReaction([
                currentTeam.slug,
                project.id,
                message.id,
            ]),
        );

        if (!response) {
            throw new Error('Chat reaction response was empty.');
        }

        setMessageReactions(response.messageId, response.reactions);
    };

    const handleReactionBroadcast = (event: ChatReactionBroadcast): void => {
        setMessageReactions(
            event.messageId,
            event.reactions.map(({ userIds: reactorIds, ...reaction }) => ({
                ...reaction,
                reacted: reactorIds.includes(currentUserId),
            })),
        );
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
        if (!historyCursor || historyHttp.processing || !messageList) {
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

    const uploadFiles = async (files: File[]): Promise<void> => {
        if (files.length === 0) {
            return;
        }

        expandComposer();

        const availableSlots =
            maxChatAttachments - pendingAttachments.length - pendingUploadCount;

        if (files.length > availableSlots) {
            uploadError = t('You can upload up to :count files at a time.', {
                count: String(maxChatAttachments),
            });

            return;
        }

        uploadError = '';
        pendingUploadCount += files.length;

        for (const file of files) {
            try {
                const formData = new FormData();
                formData.append('file', file);
                const csrfToken = document.querySelector<HTMLMetaElement>(
                    'meta[name="csrf-token"]',
                )?.content;
                const response = await fetch(
                    AttachmentController.uploadInline.url([
                        currentTeam.slug,
                        project.id,
                    ]),
                    {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                        },
                        body: formData,
                    },
                );

                if (!response.ok) {
                    throw new Error(`Upload failed: ${response.status}`);
                }

                const attachment = (await response.json()) as ChatAttachment;
                pendingAttachments = [...pendingAttachments, attachment];
            } catch {
                uploadError = t('Attachment upload failed. Please try again.');
            } finally {
                pendingUploadCount -= 1;
            }
        }
    };

    const onFileInputChange = (event: Event): void => {
        const input = event.currentTarget as HTMLInputElement;
        const files = Array.from(input.files ?? []);
        input.value = '';
        void uploadFiles(files);
    };

    const handleDrop = (event: DragEvent): void => {
        event.preventDefault();
        dragActive = false;
        void uploadFiles(Array.from(event.dataTransfer?.files ?? []));
    };

    const sendMessage = async (): Promise<void> => {
        const body = sendHttp.body.trim();

        if (!canSend || isComposing) {
            return;
        }

        sendError = '';
        sendHttp.body = body;
        sendHttp.attachment_ids = pendingAttachments.map((file) => file.id);

        try {
            const response = await sendHttp.submit(
                ChatController.store([currentTeam.slug, project.id]),
            );

            if (response) {
                mergeMessages([response.message]);
                sendHttp.body = '';
                sendHttp.attachment_ids = [];
                pendingAttachments = [];
                sendHttp.clearErrors();
                await tick();
                resizeMessageComposer();
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

    const resizeMessageComposer = (): void => {
        if (!messageComposer) {
            return;
        }

        if (!composerExpanded) {
            messageComposer.style.height = '';
            messageComposer.style.overflowY = 'hidden';

            return;
        }

        messageComposer.style.height = 'auto';
        messageComposer.style.height = `${Math.min(messageComposer.scrollHeight, 192)}px`;
        messageComposer.style.overflowY =
            messageComposer.scrollHeight > 192 ? 'auto' : 'hidden';
    };

    const expandComposer = (): void => {
        composerExpanded = true;
        void tick().then(resizeMessageComposer);
    };

    const collapseComposerIfIdle = (): void => {
        requestAnimationFrame(() => {
            if (
                pendingAttachments.length > 0 ||
                pendingUploadCount > 0 ||
                composerShell?.contains(document.activeElement)
            ) {
                return;
            }

            composerExpanded = false;
            requestAnimationFrame(resizeMessageComposer);
        });
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

    const openContextSheet = (section: ContextSection): void => {
        contextSection = section;
        contextSheetOpen = true;
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
                })
                .listen(
                    '.chat.message.reaction.updated',
                    handleReactionBroadcast,
                );

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
    class="relative grid min-h-0 flex-1 overflow-hidden bg-background lg:grid-cols-[15rem_minmax(0,1fr)] xl:grid-cols-[15rem_minmax(0,1fr)_20rem]"
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
                options={{ preserveState: 'errors' }}
                resetOnSuccess
                onSuccess={() => (showChannelForm = false)}
                class="mx-3 mb-3 flex flex-col gap-2 rounded-lg border border-sidebar-border bg-sidebar-accent/40 p-3"
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
                <div class="group/channel relative">
                    <Link
                        href={ChatController.show(
                            [currentTeam.slug, project.id],
                            {
                                query: { channel: channel.id },
                            },
                        )}
                        class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition {channel.canManage
                            ? 'pr-10'
                            : ''} {channel.id === selectedChannel.id
                            ? 'bg-sidebar-primary text-sidebar-primary-foreground'
                            : 'text-sidebar-foreground/75 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'}"
                        prefetch
                    >
                        <Hash class="size-4 shrink-0" />
                        <span class="min-w-0 flex-1 truncate">
                            {channel.name}
                        </span>
                        <span
                            class="text-[11px] opacity-60 {channel.canManage
                                ? 'group-focus-within/channel:opacity-0 group-hover/channel:opacity-0'
                                : ''}"
                        >
                            {channel.messageCount}
                        </span>
                    </Link>
                    {#if channel.canManage}
                        <ChatChannelActions
                            {currentTeam}
                            {project}
                            {channel}
                            selectedChannelId={selectedChannel.id}
                            canDelete={channels.length > 1}
                        />
                    {/if}
                </div>
            {/each}
        </nav>
    </aside>

    <section class="flex min-h-0 min-w-0 flex-col">
        <header
            class="flex shrink-0 items-center gap-2 border-b bg-card px-3 py-2.5 sm:px-4"
        >
            <div class="min-w-0 flex-1">
                <div class="hidden items-center gap-2 lg:flex">
                    <Hash class="size-5 text-muted-foreground" />
                    <h1 class="truncate text-base font-bold">
                        {selectedChannel.name}
                    </h1>
                </div>
                <label class="sr-only" for="mobile-chat-channel">
                    {t('Channels')}
                </label>
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

            <Badge variant="outline" class="hidden gap-1.5 sm:flex">
                {#if connectionStatus === 'connecting' || connectionStatus === 'reconnecting'}
                    <LoaderCircle class="animate-spin" />
                {:else}
                    <Circle class="fill-current" />
                {/if}
                {connectionLabel}
            </Badge>

            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="xl:hidden"
                onclick={() => openContextSheet('todos')}
            >
                <ListTodo data-icon="inline-start" />
                <span class="hidden sm:inline">{t('To-dos')}</span>
                <Badge variant="secondary">{incompleteTodoCount}</Badge>
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="xl:hidden"
                onclick={() => openContextSheet('files')}
            >
                <FileText data-icon="inline-start" />
                <span class="hidden sm:inline">{t('Files')}</span>
                <Badge variant="secondary">{channelFiles.length}</Badge>
            </Button>
        </header>

        <div
            bind:this={messageList}
            class:ring-2={dragActive}
            class:ring-inset={dragActive}
            class:ring-primary={dragActive}
            class="min-h-0 flex-1 overflow-y-auto py-3"
            role="region"
            aria-live="polite"
            aria-label={t('Chat messages')}
            ondragover={(event) => {
                event.preventDefault();
                dragActive = true;
            }}
            ondragleave={() => (dragActive = false)}
            ondrop={handleDrop}
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

            <div class="mx-auto flex max-w-5xl flex-col gap-0.5 px-1">
                {#each messages as message (message.id)}
                    <ChatMessageItem
                        {currentTeam}
                        {project}
                        {message}
                        onToggleReaction={toggleMessageReaction}
                        onOpenThread={() => void openThread(message)}
                    />
                {/each}
            </div>
        </div>

        <footer
            class="shrink-0 px-3 pt-2 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:px-5"
        >
            <div class="mx-auto max-w-5xl">
                <input
                    bind:this={fileInput}
                    type="file"
                    multiple
                    class="hidden"
                    onchange={onFileInputChange}
                />
                <div
                    bind:this={composerShell}
                    data-expanded={composerExpanded}
                    role="group"
                    aria-label={t('Write a message')}
                    class:ring-2={dragActive}
                    class:ring-ring={dragActive}
                    class="overflow-hidden rounded-xl border border-input bg-card shadow-sm transition focus-within:border-ring focus-within:ring-2 focus-within:ring-ring"
                    ondragover={(event) => {
                        event.preventDefault();
                        dragActive = true;
                    }}
                    ondragleave={() => (dragActive = false)}
                    ondrop={handleDrop}
                    onfocusout={collapseComposerIfIdle}
                >
                    {#if pendingAttachments.length > 0 || pendingUploadCount > 0}
                        <div class="flex flex-wrap gap-2 px-3 pt-3">
                            {#each pendingAttachments as file (file.id)}
                                <Badge variant="secondary" class="gap-1.5 py-1">
                                    <Paperclip />
                                    <span class="max-w-44 truncate"
                                        >{file.name}</span
                                    >
                                    <button
                                        type="button"
                                        class="rounded-full p-0.5 hover:bg-background/70"
                                        aria-label={t('Remove')}
                                        onclick={() =>
                                            (pendingAttachments =
                                                pendingAttachments.filter(
                                                    (item) =>
                                                        item.id !== file.id,
                                                ))}
                                    >
                                        <X class="size-3" />
                                    </button>
                                </Badge>
                            {/each}
                            {#if pendingUploadCount > 0}
                                <Badge variant="outline" class="gap-1.5 py-1">
                                    <LoaderCircle class="animate-spin" />
                                    {t('Uploading')}
                                </Badge>
                            {/if}
                        </div>
                    {/if}

                    <div
                        class={cn(
                            !composerExpanded &&
                                'flex h-12 items-center gap-1.5 px-2',
                        )}
                    >
                        {#if !composerExpanded}
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-8 shrink-0 rounded-full"
                                aria-label={t('Attach file')}
                                title={t('Attach file')}
                                disabled={pendingUploadCount > 0 ||
                                    pendingAttachments.length >=
                                        maxChatAttachments}
                                onclick={() => fileInput?.click()}
                            >
                                <Paperclip />
                            </Button>
                        {/if}

                        <textarea
                            bind:this={messageComposer}
                            bind:value={sendHttp.body}
                            rows={composerExpanded ? 3 : 1}
                            maxlength="2000"
                            placeholder={t('Message #:channel', {
                                channel: selectedChannel.name,
                            })}
                            aria-label={t('Write a message')}
                            class={cn(
                                'resize-none overflow-hidden bg-transparent text-base leading-5 outline-none placeholder:text-muted-foreground sm:text-sm',
                                composerExpanded
                                    ? 'block min-h-20 max-h-48 w-full px-3 py-2.5'
                                    : 'h-9 min-h-9 min-w-0 flex-1 px-1 py-2',
                            )}
                            onfocus={expandComposer}
                            oninput={resizeMessageComposer}
                            onkeydown={handleMessageKeydown}
                            oncompositionstart={() => (isComposing = true)}
                            oncompositionend={() => (isComposing = false)}
                        ></textarea>

                        {#if !composerExpanded}
                            <Button
                                type="button"
                                size="icon"
                                class="size-8 shrink-0 rounded-full"
                                disabled={!canSend}
                                aria-label={t('Send message')}
                                onclick={() => void sendMessage()}
                            >
                                {#if sendHttp.processing}
                                    <LoaderCircle class="animate-spin" />
                                {:else}
                                    <Send />
                                {/if}
                            </Button>
                        {/if}
                    </div>

                    {#if composerExpanded}
                        <div class="flex items-center gap-1 px-2 py-1.5">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-8 shrink-0 rounded-full"
                                aria-label={t('Attach file')}
                                title={t('Attach file')}
                                disabled={pendingUploadCount > 0 ||
                                    pendingAttachments.length >=
                                        maxChatAttachments}
                                onclick={() => fileInput?.click()}
                            >
                                <Paperclip />
                            </Button>
                            <span
                                class="ml-auto hidden text-xs text-muted-foreground sm:inline"
                            >
                                {t('Drop files to attach')}
                            </span>
                            <Button
                                type="button"
                                size="icon"
                                class="size-8 shrink-0 rounded-full"
                                disabled={!canSend}
                                aria-label={t('Send message')}
                                onclick={() => void sendMessage()}
                            >
                                {#if sendHttp.processing}
                                    <LoaderCircle class="animate-spin" />
                                {:else}
                                    <Send />
                                {/if}
                            </Button>
                        </div>
                    {/if}
                </div>
                <InputError
                    class="mt-1"
                    message={sendHttp.errors.body ||
                        sendHttp.errors.attachment_ids}
                />
                {#if uploadError}
                    <p class="mt-1 text-sm text-destructive">{uploadError}</p>
                {/if}
                {#if sendError}
                    <p class="mt-1 text-sm text-destructive">{sendError}</p>
                {/if}
            </div>
        </footer>
    </section>

    <aside class="hidden min-h-0 border-l xl:flex" aria-label={t('To-dos')}>
        <ChatContextPanel
            {currentTeam}
            {project}
            {selectedChannel}
            {todoLists}
            {relatedTodos}
            {linkableTodos}
            {channelFiles}
            {members}
        />
    </aside>

    {#if activeThread}
        <aside
            class="absolute inset-0 flex min-h-0 flex-col border-l bg-card shadow-2xl lg:right-0 lg:left-auto lg:w-96"
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
                    <X />
                </Button>
            </header>

            <div
                bind:this={threadList}
                class="min-h-0 flex-1 overflow-y-auto p-2"
            >
                <ChatMessageItem
                    {currentTeam}
                    {project}
                    message={activeThread}
                    onToggleReaction={toggleMessageReaction}
                />

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

                <div class="mt-2 flex flex-col gap-0.5 border-t pt-2">
                    {#each threadMessages as reply (reply.id)}
                        <ChatMessageItem
                            {currentTeam}
                            {project}
                            message={reply}
                            onToggleReaction={toggleMessageReaction}
                        />
                    {/each}
                </div>
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
                        class="max-h-32 min-h-10 flex-1 resize-y bg-transparent px-2 py-2 text-base outline-none placeholder:text-muted-foreground sm:text-sm"
                        onkeydown={handleReplyKeydown}
                        oncompositionstart={() => (isComposing = true)}
                        oncompositionend={() => (isComposing = false)}
                    ></textarea>
                    <Button
                        size="icon"
                        disabled={!replyHttp.body.trim() ||
                            replyHttp.processing}
                        aria-label={t('Send reply')}
                        onclick={() => void sendReply()}
                    >
                        {#if replyHttp.processing}
                            <LoaderCircle class="animate-spin" />
                        {:else}
                            <Send />
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

<Sheet bind:open={contextSheetOpen}>
    <SheetContent side="right" class="w-full max-w-sm gap-0 p-0">
        <SheetHeader class="border-b p-4 text-left">
            <SheetTitle>
                {contextSection === 'todos' ? t('To-dos') : t('Files')}
            </SheetTitle>
            <SheetDescription>#{selectedChannel.name}</SheetDescription>
        </SheetHeader>
        <ChatContextPanel
            {currentTeam}
            {project}
            {selectedChannel}
            {todoLists}
            {relatedTodos}
            {linkableTodos}
            {channelFiles}
            {members}
            show={contextSection}
        />
    </SheetContent>
</Sheet>
