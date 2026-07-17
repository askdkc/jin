<script module lang="ts">
    import MessageBoardController from '@/actions/App/Http/Controllers/MessageBoardController';
    import { show } from '@/routes/projects';
    import type { HeaderAction, ProjectTool, Team } from '@/types';

    export const layout = (props: {
        currentTeam: Team;
        project: ProjectTool;
        post: { id: number; title: string; canEdit: boolean; pinned: boolean };
    }) => ({
        breadcrumbs: [
            {
                title: props.project.name,
                href: show([props.currentTeam.slug, props.project.id]),
            },
            {
                title: 'Message board',
                href: MessageBoardController.index([
                    props.currentTeam.slug,
                    props.project.id,
                ]),
            },
            {
                title: props.post.title,
                href: '#',
            },
        ],
        headerActions: props.post.canEdit
            ? ([
                  {
                      type: 'link',
                      label: 'Edit',
                      href: MessageBoardController.edit([
                          props.currentTeam.slug,
                          props.project.id,
                          props.post.id,
                      ]),
                  },
                  {
                      type: 'form',
                      label: props.post.pinned ? 'Unpin' : 'Pin',
                      ...MessageBoardController.togglePin.form([
                          props.currentTeam.slug,
                          props.project.id,
                          props.post.id,
                      ]),
                  },
                  {
                      type: 'form',
                      label: 'Delete',
                      variant: 'destructive',
                      confirmMessage: 'Are you sure you want to delete this?',
                      ...MessageBoardController.destroy.form([
                          props.currentTeam.slug,
                          props.project.id,
                          props.post.id,
                      ]),
                  },
              ] satisfies HeaderAction[])
            : [],
    });
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import Pencil from 'lucide-svelte/icons/pencil';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import AttachmentController from '@/actions/App/Http/Controllers/AttachmentController';
    import CommentController from '@/actions/App/Http/Controllers/CommentController';
    import ProjectFileController from '@/actions/App/Http/Controllers/ProjectFileController';
    import AppHead from '@/components/AppHead.svelte';
    import FileDropzone from '@/components/FileDropzone.svelte';
    import InputError from '@/components/InputError.svelte';
    import RichTextContent from '@/components/RichTextContent.svelte';
    import RichTextEditor from '@/components/RichTextEditor.svelte';
    import { Button } from '@/components/ui/button';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
    import type { MessageBoardPost, TodoMember } from '@/types';

    let {
        currentTeam,
        project,
        post,
        members,
    }: {
        currentTeam: Team;
        project: ProjectTool;
        post: MessageBoardPost;
        members: TodoMember[];
    } = $props();

    const attachmentUploadUrl = $derived(
        AttachmentController.uploadInline.url([currentTeam.slug, project.id]),
    );
    const formatDate = (value: string | null): string =>
        value
            ? new Intl.DateTimeFormat('ja-JP', {
                  dateStyle: 'medium',
                  timeStyle: 'short',
              }).format(new Date(value))
            : '';
    const formatSize = (size: number): string => {
        if (size < 1024) {
            return `${size} B`;
        }

        if (size < 1024 * 1024) {
            return `${(size / 1024).toFixed(1)} KB`;
        }

        return `${(size / (1024 * 1024)).toFixed(1)} MB`;
    };
    const confirmDelete = (event: SubmitEvent): void => {
        if (!window.confirm(t('Are you sure you want to delete this?'))) {
            event.preventDefault();
        }
    };
</script>

<AppHead title={post.title} />

<div class="mx-auto w-full max-w-3xl space-y-6 p-4 sm:p-6">
    <div>
        <h1 class="text-2xl font-bold break-words">{post.title}</h1>
        <p class="mt-1 text-sm text-muted-foreground">
            {post.authorName || t('Deleted user')} · {formatDate(
                post.publishedAt,
            )}
            {#if post.pinned}
                · <span class="font-medium text-primary">{t('Pinned')}</span>
            {/if}
        </p>
    </div>

    <div class="rounded-lg border bg-card p-4">
        <RichTextContent content={post.body} class="text-sm leading-6" />
    </div>

    <section class="space-y-3 border-t pt-4" aria-label={t('Comments')}>
        <h2 class="text-sm font-semibold">{t('Comments')}</h2>
        {#each post.comments as comment (comment.id)}
            <div class="rounded-lg border bg-card p-3 text-sm">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <RichTextContent content={comment.body} />
                        <p class="mt-1 text-xs text-muted-foreground">
                            {comment.authorName || t('Deleted user')} · {formatDate(
                                comment.createdAt,
                            )}
                        </p>
                    </div>
                    {#if comment.canDelete}
                        <div class="flex shrink-0 items-center gap-1">
                            <details>
                                <summary
                                    aria-label={t('Edit')}
                                    class="[&::-webkit-details-marker]:hidden flex size-7 list-none cursor-pointer items-center justify-center rounded-md text-muted-foreground hover:bg-muted"
                                    ><Pencil class="size-3.5" /></summary
                                >
                                <Form
                                    {...CommentController.update.form([
                                        currentTeam.slug,
                                        project.id,
                                        post.id,
                                        comment.id,
                                    ])}
                                    class="mt-2 space-y-2"
                                >
                                    {#snippet children({ errors, processing })}
                                        <RichTextEditor
                                            id={`message-comment-edit-${comment.id}`}
                                            name="body"
                                            value={comment.body}
                                            required
                                            ariaLabel={t('Comment')}
                                            mentionables={members}
                                            {attachmentUploadUrl}
                                        />
                                        <InputError message={errors.body} />
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={processing}
                                            >{t('Save')}</Button
                                        >
                                    {/snippet}
                                </Form>
                            </details>
                            <Form
                                {...CommentController.destroy.form([
                                    currentTeam.slug,
                                    project.id,
                                    post.id,
                                    comment.id,
                                ])}
                                onsubmit={confirmDelete}
                            >
                                {#snippet children({ processing })}
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        aria-label={t('Delete')}
                                        class="flex size-7 items-center justify-center rounded-md text-destructive hover:bg-destructive/10 disabled:opacity-50"
                                    >
                                        <Trash2 class="size-3.5" />
                                    </button>
                                {/snippet}
                            </Form>
                        </div>
                    {/if}
                </div>
            </div>
        {/each}

        <Form
            {...CommentController.store.form([
                currentTeam.slug,
                project.id,
                post.id,
            ])}
            resetOnSuccess
            class="space-y-2"
        >
            {#snippet children({ errors, processing })}
                <div class="min-w-0 flex-1">
                    <Label for={`message-comment-${post.id}`} class="sr-only"
                        >{t('Comment')}</Label
                    >
                    <RichTextEditor
                        id={`message-comment-${post.id}`}
                        name="body"
                        required
                        ariaLabel={t('Comment')}
                        mentionables={members}
                        {attachmentUploadUrl}
                    />
                    <InputError message={errors.body} />
                </div>
                <Button type="submit" disabled={processing}
                    >{t('Add comment')}</Button
                >
            {/snippet}
        </Form>
    </section>

    <details class="rounded-lg border p-3 text-sm">
        <summary class="cursor-pointer font-medium text-muted-foreground">
            {t('Attachments')} ({post.attachments.length})
        </summary>
        <div class="mt-4 space-y-3">
            {#if post.attachments.length === 0}
                <p class="text-xs text-muted-foreground">
                    {t('No attachments yet')}
                </p>
            {:else}
                {#each post.attachments as attachment (attachment.id)}
                    <div
                        class="flex min-w-0 flex-col gap-2 rounded-md border bg-card p-3 sm:flex-row sm:items-center"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium break-all">
                                {attachment.name}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {formatSize(attachment.size)} · {attachment.uploaderName ||
                                    t('Deleted user')}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button variant="outline" size="sm" asChild>
                                {#snippet children(buttonProps)}
                                    <a
                                        {...buttonProps}
                                        href={ProjectFileController.download.url(
                                            [
                                                currentTeam.slug,
                                                project.id,
                                                attachment.id,
                                            ],
                                        )}
                                        class={buttonProps.class}
                                    >
                                        {t('Download')}
                                    </a>
                                {/snippet}
                            </Button>
                            {#if attachment.canDelete}
                                <Form
                                    {...ProjectFileController.destroy.form([
                                        currentTeam.slug,
                                        project.id,
                                        attachment.id,
                                    ])}
                                    onsubmit={confirmDelete}
                                >
                                    {#snippet children({ processing })}
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            size="sm"
                                            disabled={processing}
                                            >{t('Delete')}</Button
                                        >
                                    {/snippet}
                                </Form>
                            {/if}
                        </div>
                    </div>
                {/each}
            {/if}

            <p class="text-xs text-muted-foreground">
                {t('Or drag a file into the message or comment box above.')}
            </p>
            <Form
                {...AttachmentController.storeMessage.form([
                    currentTeam.slug,
                    project.id,
                    post.id,
                ])}
                resetOnSuccess
                class="space-y-2"
            >
                {#snippet children({ errors, processing, progress })}
                    <FileDropzone
                        id={`message-attachment-${post.id}`}
                        error={errors.file}
                        compact
                        accept=".pdf,.txt,.csv,.jpg,.jpeg,.png,.webp,.mp4,.webm,.mov,.m4v,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip"
                    />
                    {#if progress}
                        <progress
                            value={progress.percentage}
                            max="100"
                            class="h-2 w-full">{progress.percentage}%</progress
                        >
                    {/if}
                    <Button type="submit" size="sm" disabled={processing}>
                        {t('Attach file')}
                    </Button>
                {/snippet}
            </Form>
        </div>
    </details>

    <div
        class="flex flex-wrap items-center justify-between gap-3 border-t pt-4 text-sm text-muted-foreground"
    >
        <p>
            {t(
                ':count people will be notified when someone comments on this message.',
                {
                    count: String(post.notifyCount),
                },
            )}
        </p>
        <Form
            {...MessageBoardController.toggleSubscription.form([
                currentTeam.slug,
                project.id,
                post.id,
            ])}
        >
            {#snippet children({ processing })}
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
                    disabled={processing}
                >
                    {post.subscribed ? t("Don't notify me") : t('Notify me')}
                </Button>
            {/snippet}
        </Form>
    </div>
</div>
