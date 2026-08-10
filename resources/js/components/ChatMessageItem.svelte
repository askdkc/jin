<script lang="ts">
    import LoaderCircle from 'lucide-svelte/icons/loader-circle';
    import MessageSquareText from 'lucide-svelte/icons/message-square-text';
    import Paperclip from 'lucide-svelte/icons/paperclip';
    import Pencil from 'lucide-svelte/icons/pencil';
    import Settings from 'lucide-svelte/icons/settings';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import AttachmentController from '@/actions/App/Http/Controllers/AttachmentController';
    import ChatController from '@/actions/App/Http/Controllers/ChatController';
    import ProjectFileController from '@/actions/App/Http/Controllers/ProjectFileController';
    import ReactionPicker from '@/components/ReactionPicker.svelte';
    import RichTextContent from '@/components/RichTextContent.svelte';
    import RichTextEditor from '@/components/RichTextEditor.svelte';
    import StoredFilePreviewDialog, {
        canPreviewStoredFile,
    } from '@/components/StoredFilePreviewDialog.svelte';
    import {
        Avatar,
        AvatarFallback,
        AvatarImage,
    } from '@/components/ui/avatar';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import {
        DropdownMenu,
        DropdownMenuContent,
        DropdownMenuGroup,
        DropdownMenuItem,
        DropdownMenuSeparator,
        DropdownMenuTrigger,
    } from '@/components/ui/dropdown-menu';
    import { currentLocale, t } from '@/lib/i18n';
    import { getInitials } from '@/lib/initials';
    import { cn } from '@/lib/utils';
    import type {
        ChatAttachment,
        ChatMessage,
        ChatProject,
        Team,
    } from '@/types';

    let {
        currentTeam,
        project,
        message,
        mentionables,
        canManage = false,
        processing = false,
        onOpenThread,
        onToggleReaction,
        onUpdate,
        onDelete,
    }: {
        currentTeam: Team;
        project: ChatProject;
        message: ChatMessage;
        mentionables: { id: number; name: string }[];
        canManage?: boolean;
        processing?: boolean;
        onOpenThread?: () => void;
        onToggleReaction: (
            message: ChatMessage,
            reaction: string,
        ) => Promise<void>;
        onUpdate?: (message: ChatMessage, body: string) => Promise<boolean>;
        onDelete?: (message: ChatMessage) => Promise<boolean>;
    } = $props();

    let previewFile = $state<ChatAttachment | null>(null);
    let previewOpen = $state(false);
    let editing = $state(false);
    let editHasContent = $state(false);
    let editForm = $state<HTMLFormElement>();
    let actionError = $state('');
    const attachmentUploadUrl = $derived(
        AttachmentController.uploadInline.url([currentTeam.slug, project.id]),
    );
    const standaloneAttachments = $derived(
        message.attachments.filter(
            (file) => !message.body.includes(`/files/${file.id}/`),
        ),
    );

    const messageTime = (createdAt: string): string =>
        new Intl.DateTimeFormat(currentLocale(), {
            month: 'numeric',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        }).format(new Date(createdAt));
    const userInitial = (): string => getInitials(message.user?.name) || '?';
    const threadButtonLabel = (): string =>
        message.replyCount > 0
            ? t(':count replies', {
                  count: String(message.replyCount),
              })
            : t('Reply in thread');
    const fileExtension = (file: ChatAttachment): string =>
        (file.name.split('.').pop() ?? t('File')).toUpperCase().slice(0, 8);
    const openPreview = (file: ChatAttachment): void => {
        if (!canPreviewStoredFile(file.name)) {
            return;
        }

        previewFile = file;
        previewOpen = true;
    };
    const openEditor = (): void => {
        actionError = '';
        editing = true;
    };
    const saveEdit = async (event: SubmitEvent): Promise<void> => {
        event.preventDefault();

        if (!editForm || processing || !onUpdate) {
            return;
        }

        const body = String(new FormData(editForm).get('body') ?? '').trim();

        if (body === '' && message.attachments.length === 0) {
            return;
        }

        actionError = '';

        try {
            if (await onUpdate(message, body)) {
                editing = false;

                return;
            }
        } catch {
            actionError = t('Something went wrong.');

            return;
        }

        actionError = t('Something went wrong.');
    };
    const deleteMessage = async (): Promise<void> => {
        if (
            processing ||
            !onDelete ||
            !window.confirm(t('Are you sure you want to delete this?'))
        ) {
            return;
        }

        actionError = '';

        try {
            if (await onDelete(message)) {
                return;
            }
        } catch {
            actionError = t('Something went wrong.');

            return;
        }

        actionError = t('Something went wrong.');
    };
</script>

<article
    class="group relative flex gap-3 rounded-lg px-3 py-2.5 hover:bg-muted/50 sm:px-4"
>
    {#if canManage && !editing}
        <DropdownMenu
            class="pointer-events-none absolute top-2 right-2 z-10 opacity-0 transition-opacity group-focus-within:pointer-events-auto group-focus-within:opacity-100 group-hover:pointer-events-auto group-hover:opacity-100"
        >
            <DropdownMenuTrigger asChild>
                {#snippet children(triggerProps)}
                    <Button
                        type="button"
                        variant="outline"
                        size="icon-xs"
                        class="bg-card/95 shadow-sm"
                        onclick={triggerProps.onclick}
                        aria-expanded={triggerProps['aria-expanded']}
                        data-state={triggerProps['data-state']}
                        aria-label={t('Settings')}
                        title={t('Settings')}
                    >
                        <Settings data-icon="icon" aria-hidden="true" />
                    </Button>
                {/snippet}
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                sideOffset={4}
                class="w-32 min-w-32 p-1"
            >
                <DropdownMenuGroup>
                    <DropdownMenuItem asChild>
                        {#snippet children(itemProps)}
                            <button
                                type="button"
                                class={cn(
                                    itemProps.class,
                                    'gap-2 whitespace-nowrap [&_[data-icon]]:size-4 [&_[data-icon]]:shrink-0',
                                )}
                                disabled={processing}
                                onclick={() => {
                                    itemProps.onClick?.();
                                    openEditor();
                                }}
                            >
                                <Pencil
                                    data-icon="inline-start"
                                    aria-hidden="true"
                                />
                                {t('Edit')}
                            </button>
                        {/snippet}
                    </DropdownMenuItem>
                </DropdownMenuGroup>
                <DropdownMenuSeparator />
                <DropdownMenuGroup>
                    <DropdownMenuItem asChild>
                        {#snippet children(itemProps)}
                            <button
                                type="button"
                                class={cn(
                                    itemProps.class,
                                    'gap-2 whitespace-nowrap text-destructive hover:text-destructive [&_[data-icon]]:size-4 [&_[data-icon]]:shrink-0',
                                )}
                                disabled={processing}
                                onclick={() => {
                                    itemProps.onClick?.();
                                    void deleteMessage();
                                }}
                            >
                                <Trash2
                                    data-icon="inline-start"
                                    aria-hidden="true"
                                />
                                {t('Delete')}
                            </button>
                        {/snippet}
                    </DropdownMenuItem>
                </DropdownMenuGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    {/if}

    <Avatar class="size-10 shrink-0 rounded-lg">
        {#if message.user?.avatar}
            <AvatarImage src={message.user.avatar} alt={message.user.name} />
        {/if}
        <AvatarFallback class="rounded-lg font-bold">
            {userInitial()}
        </AvatarFallback>
    </Avatar>

    <div class="min-w-0 flex-1">
        <div
            class="flex flex-wrap items-baseline gap-x-2 {canManage
                ? 'pr-9'
                : ''}"
        >
            <span class="font-semibold">
                {message.user?.name ?? t('Deleted user')}
            </span>
            <time class="text-xs text-muted-foreground">
                {messageTime(message.createdAt)}
            </time>
        </div>

        {#if editing}
            <form
                bind:this={editForm}
                class="mt-2 space-y-2"
                onsubmit={saveEdit}
            >
                <RichTextEditor
                    id={`chat-message-edit-${message.id}`}
                    name="body"
                    value={message.body}
                    required={message.attachments.length === 0}
                    ariaLabel={t('Edit message')}
                    {mentionables}
                    {attachmentUploadUrl}
                    onContentChange={(hasContent) =>
                        (editHasContent = hasContent)}
                    onSubmitShortcut={() => editForm?.requestSubmit()}
                />
                <div class="flex items-center gap-2">
                    <Button
                        type="submit"
                        size="sm"
                        disabled={processing ||
                            (!editHasContent &&
                                message.attachments.length === 0)}
                    >
                        {#if processing}
                            <LoaderCircle
                                class="animate-spin"
                                data-icon="inline-start"
                            />
                        {/if}
                        {t('Save')}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={processing}
                        onclick={() => (editing = false)}
                    >
                        {t('Cancel')}
                    </Button>
                </div>
            </form>
        {:else if message.body}
            <RichTextContent
                content={message.body}
                class="mt-0.5 text-sm leading-6"
            />
        {/if}

        {#if standaloneAttachments.length > 0}
            <div class="mt-2 flex flex-wrap gap-2">
                {#each standaloneAttachments as file (file.id)}
                    <div
                        class="relative max-w-72 overflow-hidden rounded-lg border bg-card"
                    >
                        {#if file.mimeType?.startsWith('image/')}
                            <button
                                type="button"
                                class="block w-full"
                                aria-label={t('Preview :file', {
                                    file: file.name,
                                })}
                                onclick={() => openPreview(file)}
                            >
                                <img
                                    src={ProjectFileController.preview.url([
                                        currentTeam.slug,
                                        project.id,
                                        file.id,
                                    ])}
                                    alt={file.name}
                                    class="max-h-64 w-full object-cover"
                                    loading="lazy"
                                />
                            </button>
                        {/if}

                        <div class="flex items-center gap-2 px-3 py-2">
                            <Paperclip
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            {#if canPreviewStoredFile(file.name)}
                                <button
                                    type="button"
                                    class="min-w-0 flex-1 truncate text-left text-sm font-medium hover:underline"
                                    onclick={() => openPreview(file)}
                                >
                                    {file.name}
                                </button>
                            {:else}
                                <a
                                    href={ProjectFileController.download.url([
                                        currentTeam.slug,
                                        project.id,
                                        file.id,
                                    ])}
                                    class="min-w-0 flex-1 truncate text-sm font-medium hover:underline"
                                >
                                    {file.name}
                                </a>
                            {/if}
                            <Badge variant="secondary">
                                {fileExtension(file)}
                            </Badge>
                        </div>
                    </div>
                {/each}
            </div>
        {/if}

        <div class="mt-2 flex items-center gap-1">
            <ReactionPicker
                action={ChatController.toggleReaction.url([
                    currentTeam.slug,
                    project.id,
                    message.id,
                ])}
                reactions={message.reactions}
                onToggle={(reaction) => onToggleReaction(message, reaction)}
            />

            {#if onOpenThread}
                <Button
                    type="button"
                    variant="ghost"
                    size="xs"
                    class="rounded-full"
                    aria-label={threadButtonLabel()}
                    title={threadButtonLabel()}
                    onclick={onOpenThread}
                >
                    <MessageSquareText
                        data-icon="inline-start"
                        aria-hidden="true"
                    />
                    {t('Reply in thread')}
                </Button>
            {/if}
        </div>

        {#if actionError}
            <p class="mt-1 text-sm text-destructive" role="alert">
                {actionError}
            </p>
        {/if}
    </div>
</article>

<StoredFilePreviewDialog
    {currentTeam}
    {project}
    file={previewFile}
    bind:open={previewOpen}
/>
