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
    import Maximize2 from 'lucide-svelte/icons/maximize-2';
    import Minimize2 from 'lucide-svelte/icons/minimize-2';
    import Paperclip from 'lucide-svelte/icons/paperclip';
    import Plus from 'lucide-svelte/icons/plus';
    import X from 'lucide-svelte/icons/x';
    import { onMount, tick } from 'svelte';
    import { SvelteSet } from 'svelte/reactivity';
    import AttachmentController from '@/actions/App/Http/Controllers/AttachmentController';
    import ChatChannelController from '@/actions/App/Http/Controllers/ChatChannelController';
    import AppHead from '@/components/AppHead.svelte';
    import ChatChannelActions from '@/components/ChatChannelActions.svelte';
    import ChatComposer from '@/components/ChatComposer.svelte';
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
        ChatDeleteResponse,
        ChatHistoryResponse,
        ChatMessage,
        ChatReactionResponse,
        ChatStoreResponse,
        ChatThreadResponse,
        ChatTodo,
        ChatTodoList,
        ChatUpdateResponse,
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
        canManageMessages,
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
        canManageMessages: boolean;
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
    const updateHttp = useHttp<{ body: string }, ChatUpdateResponse>({
        body: '',
    });
    const deleteHttp = useHttp<Record<string, never>, ChatDeleteResponse>({});

    let messages = $state<ChatMessage[]>(initialMessageState());
    let channelFiles = $state<ChatAttachment[]>(initialFileState());
    let historyCursor = $state<string | null>(initialCursorState());
    let connectionStatus = $state<ConnectionStatus>('connecting');
    let sendError = $state('');
    let uploadError = $state('');
    let showChannelForm = $state(false);
    let activeThread = $state<ChatMessage | null>(null);
    let threadMessages = $state<ChatMessage[]>([]);
    let threadCursor = $state<string | null>(null);
    let threadError = $state('');
    let threadFullscreen = $state(false);
    let pendingAttachments = $state<ChatAttachment[]>([]);
    let pendingUploadCount = $state(0);
    let dragActive = $state(false);
    let contextSheetOpen = $state(false);
    let contextSection = $state<ContextSection>('todos');
    let messageList = $state<HTMLDivElement>();
    let threadList = $state<HTMLDivElement>();
    let fileInput = $state<HTMLInputElement>();

    const receivedReplyIds = new SvelteSet<number>();
    const deletedMessageIds = new SvelteSet<number>();
    const channelName = $derived(`projects.${project.id}.chat`);
    const attachmentUploadUrl = $derived(
        AttachmentController.uploadInline.url([currentTeam.slug, project.id]),
    );
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

    const setMessageBody = (messageId: number, body: string): void => {
        messages = messages.map((message) =>
            message.id === messageId ? { ...message, body } : message,
        );
        threadMessages = threadMessages.map((message) =>
            message.id === messageId ? { ...message, body } : message,
        );

        if (activeThread?.id === messageId) {
            activeThread = { ...activeThread, body };
        }
    };

    const removeMessage = (event: ChatDeleteResponse): void => {
        if (deletedMessageIds.has(event.messageId)) {
            return;
        }

        deletedMessageIds.add(event.messageId);
        channelFiles = channelFiles.filter(
            (file) => !event.attachmentIds.includes(file.id),
        );

        if (event.parentId === null) {
            messages = messages.filter(
                (message) => message.id !== event.messageId,
            );

            if (activeThread?.id === event.messageId) {
                activeThread = null;
                threadMessages = [];
                threadCursor = null;
                threadFullscreen = false;
            }

            return;
        }

        threadMessages = threadMessages.filter(
            (message) => message.id !== event.messageId,
        );
        messages = messages.map((message) =>
            message.id === event.parentId
                ? {
                      ...message,
                      replyCount: Math.max(0, message.replyCount - 1),
                  }
                : message,
        );

        if (activeThread?.id === event.parentId) {
            activeThread = {
                ...activeThread,
                replyCount: Math.max(0, activeThread.replyCount - 1),
            };
        }
    };

    const updateMessage = async (
        message: ChatMessage,
        body: string,
    ): Promise<boolean> => {
        updateHttp.body = body;

        try {
            const response = await updateHttp.submit(
                ChatController.update([
                    currentTeam.slug,
                    project.id,
                    message.id,
                ]),
            );

            if (!response) {
                return false;
            }

            setMessageBody(response.messageId, response.body);

            return true;
        } catch {
            return false;
        }
    };

    const deleteMessage = async (message: ChatMessage): Promise<boolean> => {
        try {
            const response = await deleteHttp.submit(
                ChatController.destroy([
                    currentTeam.slug,
                    project.id,
                    message.id,
                ]),
            );

            if (!response) {
                return false;
            }

            removeMessage(response);

            return true;
        } catch {
            return false;
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
                const response = await fetch(attachmentUploadUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                    },
                    body: formData,
                });

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

    const richTextEditorHandlesDrop = (event: DragEvent): boolean =>
        event
            .composedPath()
            .some(
                (target) =>
                    target instanceof HTMLElement &&
                    target.tagName === 'TRIX-EDITOR',
            );

    const handleDrop = (event: DragEvent): void => {
        dragActive = false;

        if (richTextEditorHandlesDrop(event)) {
            return;
        }

        event.preventDefault();
        void uploadFiles(Array.from(event.dataTransfer?.files ?? []));
    };

    const sendMessage = async (body: string): Promise<boolean> => {
        if (
            sendHttp.processing ||
            pendingUploadCount > 0 ||
            (body === '' && pendingAttachments.length === 0)
        ) {
            return false;
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
                await scrollToLatest();

                return true;
            }
        } catch {
            sendError = t('Message could not be sent. Please try again.');
        }

        return false;
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

    const toggleThreadFullscreen = (): void => {
        threadFullscreen = !threadFullscreen;
    };

    const closeThread = (): void => {
        threadFullscreen = false;
        activeThread = null;
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

    const sendReply = async (body: string): Promise<boolean> => {
        if (!activeThread || body === '' || replyHttp.processing) {
            return false;
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

                return true;
            }
        } catch {
            threadError = t('Message could not be sent. Please try again.');
        }

        return false;
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
                )
                .listen('.chat.message.updated', (event: ChatUpdateResponse) =>
                    setMessageBody(event.messageId, event.body),
                )
                .listen('.chat.message.deleted', (event: ChatDeleteResponse) =>
                    removeMessage(event),
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
    data-chat-layout
    class="relative grid h-[calc(100dvh-6rem-1px)] min-h-0 flex-none overflow-hidden bg-background lg:grid-cols-[15rem_minmax(0,1fr)] xl:grid-cols-[15rem_minmax(0,1fr)_16rem]"
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
                        mentionables={members}
                        canManage={canManageMessages ||
                            message.user?.id === currentUserId}
                        processing={updateHttp.processing ||
                            deleteHttp.processing}
                        onToggleReaction={toggleMessageReaction}
                        onUpdate={updateMessage}
                        onDelete={deleteMessage}
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
                    role="group"
                    aria-label={t('Write a message')}
                    class:ring-2={dragActive}
                    class:ring-ring={dragActive}
                    class="rounded-xl"
                    ondragover={(event) => {
                        event.preventDefault();
                        dragActive = true;
                    }}
                    ondragleave={() => (dragActive = false)}
                    ondrop={handleDrop}
                >
                    {#if pendingAttachments.length > 0 || pendingUploadCount > 0}
                        <div class="mb-2 flex flex-wrap gap-2">
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

                    <ChatComposer
                        id={`chat-message-${selectedChannel.id}`}
                        placeholder={t('Message #:channel', {
                            channel: selectedChannel.name,
                        })}
                        mentionables={members}
                        {attachmentUploadUrl}
                        processing={sendHttp.processing ||
                            pendingUploadCount > 0}
                        allowEmpty={pendingAttachments.length > 0}
                        attachDisabled={pendingUploadCount > 0 ||
                            pendingAttachments.length >= maxChatAttachments}
                        onAttach={() => fileInput?.click()}
                        onSubmit={sendMessage}
                        submitLabel={t('Send message')}
                    />
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
            class={cn(
                'absolute inset-0 flex min-h-0 flex-col border-l bg-card shadow-2xl',
                !threadFullscreen && 'lg:right-0 lg:left-auto lg:w-96',
            )}
            aria-label={t('Thread')}
            data-thread-view={threadFullscreen ? 'fullscreen' : 'panel'}
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
                <div class="flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        class="hidden lg:inline-flex"
                        aria-label={threadFullscreen
                            ? t('Restore thread panel')
                            : t('Maximize thread')}
                        aria-pressed={threadFullscreen}
                        title={threadFullscreen
                            ? t('Restore thread panel')
                            : t('Maximize thread')}
                        onclick={toggleThreadFullscreen}
                    >
                        {#if threadFullscreen}
                            <Minimize2 />
                        {:else}
                            <Maximize2 />
                        {/if}
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={t('Close')}
                        onclick={closeThread}
                    >
                        <X />
                    </Button>
                </div>
            </header>

            <div
                bind:this={threadList}
                class="min-h-0 flex-1 overflow-y-auto p-2"
            >
                <div class="mx-auto w-full max-w-5xl">
                    <ChatMessageItem
                        {currentTeam}
                        {project}
                        message={activeThread}
                        mentionables={members}
                        canManage={canManageMessages ||
                            activeThread.user?.id === currentUserId}
                        processing={updateHttp.processing ||
                            deleteHttp.processing}
                        onToggleReaction={toggleMessageReaction}
                        onUpdate={updateMessage}
                        onDelete={deleteMessage}
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
                                mentionables={members}
                                canManage={canManageMessages ||
                                    reply.user?.id === currentUserId}
                                processing={updateHttp.processing ||
                                    deleteHttp.processing}
                                onToggleReaction={toggleMessageReaction}
                                onUpdate={updateMessage}
                                onDelete={deleteMessage}
                            />
                        {/each}
                    </div>
                </div>
            </div>

            <footer
                class="shrink-0 border-t bg-card p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]"
            >
                <div class="mx-auto w-full max-w-5xl">
                    <ChatComposer
                        id={`chat-reply-${activeThread.id}`}
                        placeholder={t('Reply')}
                        mentionables={members}
                        {attachmentUploadUrl}
                        processing={replyHttp.processing}
                        onSubmit={sendReply}
                        submitLabel={t('Send reply')}
                        compact
                    />
                    <InputError class="mt-1" message={replyHttp.errors.body} />
                    {#if threadError}
                        <p class="mt-1 text-sm text-destructive">
                            {threadError}
                        </p>
                    {/if}
                </div>
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
