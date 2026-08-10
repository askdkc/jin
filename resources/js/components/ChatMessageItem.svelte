<script lang="ts">
    import MessageSquareText from 'lucide-svelte/icons/message-square-text';
    import Paperclip from 'lucide-svelte/icons/paperclip';
    import ChatController from '@/actions/App/Http/Controllers/ChatController';
    import ProjectFileController from '@/actions/App/Http/Controllers/ProjectFileController';
    import ReactionPicker from '@/components/ReactionPicker.svelte';
    import StoredFilePreviewDialog, {
        canPreviewStoredFile,
    } from '@/components/StoredFilePreviewDialog.svelte';
    import { Avatar, AvatarFallback } from '@/components/ui/avatar';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { currentLocale, t } from '@/lib/i18n';
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
        onOpenThread,
        onToggleReaction,
    }: {
        currentTeam: Team;
        project: ChatProject;
        message: ChatMessage;
        onOpenThread?: () => void;
        onToggleReaction: (
            message: ChatMessage,
            reaction: string,
        ) => Promise<void>;
    } = $props();

    let previewFile = $state<ChatAttachment | null>(null);
    let previewOpen = $state(false);

    const messageTime = (createdAt: string): string =>
        new Intl.DateTimeFormat(currentLocale(), {
            month: 'numeric',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        }).format(new Date(createdAt));
    const userInitial = (): string =>
        (message.user?.name ?? '?').slice(0, 1).toUpperCase();
    const fileExtension = (file: ChatAttachment): string =>
        (file.name.split('.').pop() ?? t('File')).toUpperCase().slice(0, 8);
    const openPreview = (file: ChatAttachment): void => {
        if (!canPreviewStoredFile(file.name)) {
            return;
        }

        previewFile = file;
        previewOpen = true;
    };
</script>

<article
    class="group flex gap-3 rounded-lg px-3 py-2.5 hover:bg-muted/50 sm:px-4"
>
    <Avatar class="size-10 shrink-0 rounded-lg">
        <AvatarFallback class="rounded-lg font-bold">
            {userInitial()}
        </AvatarFallback>
    </Avatar>

    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-baseline gap-x-2">
            <span class="font-semibold">
                {message.user?.name ?? t('Deleted user')}
            </span>
            <time class="text-xs text-muted-foreground">
                {messageTime(message.createdAt)}
            </time>
        </div>

        {#if message.body}
            <p class="mt-0.5 whitespace-pre-wrap break-words text-sm leading-6">
                {message.body}
            </p>
        {/if}

        {#if message.attachments.length > 0}
            <div class="mt-2 flex flex-wrap gap-2">
                {#each message.attachments as file (file.id)}
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

        <div class="mt-2">
            <ReactionPicker
                action={ChatController.toggleReaction.url([
                    currentTeam.slug,
                    project.id,
                    message.id,
                ])}
                reactions={message.reactions}
                onToggle={(reaction) => onToggleReaction(message, reaction)}
            />
        </div>

        {#if onOpenThread}
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="mt-1.5"
                onclick={onOpenThread}
            >
                <MessageSquareText data-icon="inline-start" />
                {message.replyCount > 0
                    ? t(':count replies', {
                          count: String(message.replyCount),
                      })
                    : t('Reply in thread')}
            </Button>
        {/if}
    </div>
</article>

<StoredFilePreviewDialog
    {currentTeam}
    {project}
    file={previewFile}
    bind:open={previewOpen}
/>
