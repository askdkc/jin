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
                translationKey: 'Message board',
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
    import { Form, Link } from '@inertiajs/svelte';
    import ChevronLeft from 'lucide-svelte/icons/chevron-left';
    import Pencil from 'lucide-svelte/icons/pencil';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import { tick } from 'svelte';
    import AttachmentController from '@/actions/App/Http/Controllers/AttachmentController';
    import CommentController from '@/actions/App/Http/Controllers/CommentController';
    import ProjectFileController from '@/actions/App/Http/Controllers/ProjectFileController';
    import AppHead from '@/components/AppHead.svelte';
    import FileDropzone from '@/components/FileDropzone.svelte';
    import InputError from '@/components/InputError.svelte';
    import ReactionPicker from '@/components/ReactionPicker.svelte';
    import RichTextContent from '@/components/RichTextContent.svelte';
    import RichTextEditor from '@/components/RichTextEditor.svelte';
    import { Avatar, AvatarFallback } from '@/components/ui/avatar';
    import { Button } from '@/components/ui/button';
    import { currentLocale, t } from '@/lib/i18n';
    import { getInitials } from '@/lib/initials';
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
            ? new Intl.DateTimeFormat(currentLocale(), {
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
    const confirmDelete = (): boolean =>
        window.confirm(t('Are you sure you want to delete this?'));
    let editingCommentId = $state<number | null>(null);
    let commentComposerOpen = $state(false);
    const openCommentComposer = async (): Promise<void> => {
        commentComposerOpen = true;
        await tick();
        document.getElementById(`message-comment-${post.id}`)?.focus();
    };
</script>

<AppHead title={post.title} />

<div
    class="min-h-[calc(100vh-7rem)] bg-slate-100/80 px-3 py-5 sm:px-6 sm:py-9 dark:bg-slate-950/50"
>
    <div class="mx-auto w-full max-w-5xl">
        <Button variant="ghost" size="sm" class="mb-3" asChild>
            {#snippet children(buttonProps)}
                <Link
                    {...buttonProps}
                    href={MessageBoardController.index([
                        currentTeam.slug,
                        project.id,
                    ])}
                    class={buttonProps.class}
                >
                    <ChevronLeft class="size-4" aria-hidden="true" />
                    {t('Message board')}
                </Link>
            {/snippet}
        </Button>

        <article
            aria-labelledby="message-title"
            class="overflow-visible rounded-2xl border border-slate-200 bg-white shadow-[0_18px_55px_rgba(15,23,42,0.08)] sm:rounded-[1.75rem] dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/25"
        >
            <header class="px-5 pt-7 pb-5 sm:px-10 sm:pt-10 sm:pb-7">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h1
                            id="message-title"
                            class="break-words text-2xl font-black tracking-[-0.035em] text-slate-900 sm:text-4xl dark:text-slate-50"
                        >
                            {post.title}
                        </h1>
                        <p
                            class="mt-2 flex flex-wrap gap-x-1.5 gap-y-1 text-sm text-slate-500 dark:text-slate-400"
                        >
                            <strong
                                class="font-semibold text-slate-700 dark:text-slate-200"
                            >
                                {post.authorName || t('Deleted user')}
                            </strong>
                            <span aria-hidden="true">·</span>
                            <time datetime={post.publishedAt}>
                                {formatDate(post.publishedAt)}
                            </time>
                            {#if post.pinned}
                                <span aria-hidden="true">·</span>
                                <span
                                    class="font-semibold text-amber-700 dark:text-amber-300"
                                    >{t('Pinned')}</span
                                >
                            {/if}
                            <span aria-hidden="true">·</span>
                            <span>
                                {t(
                                    ':count people will be notified when someone comments on this message.',
                                    {
                                        count: String(post.notifyCount),
                                    },
                                )}
                            </span>
                        </p>
                    </div>
                    {#if post.canEdit}
                        <Button variant="ghost" size="sm" asChild>
                            {#snippet children(buttonProps)}
                                <Link
                                    {...buttonProps}
                                    href={MessageBoardController.edit([
                                        currentTeam.slug,
                                        project.id,
                                        post.id,
                                    ])}
                                    class={buttonProps.class}
                                >
                                    <Pencil class="size-3.5" />
                                    {t('Edit')}
                                </Link>
                            {/snippet}
                        </Button>
                    {/if}
                </div>
            </header>

            <div
                class="flex items-start gap-3 px-5 pb-9 sm:gap-5 sm:px-10 sm:pb-12"
            >
                <Avatar
                    class="size-10 shrink-0 border border-slate-200 sm:size-12 dark:border-slate-700"
                >
                    <AvatarFallback
                        class="bg-slate-100 text-xs font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-200"
                    >
                        {getInitials(post.authorName || t('Deleted user'))}
                    </AvatarFallback>
                </Avatar>
                <div class="min-w-0 flex-1 pt-0.5">
                    <RichTextContent
                        content={post.body}
                        class="text-[0.95rem] leading-7 text-slate-700 sm:text-base dark:text-slate-200"
                    />
                    <div class="mt-7">
                        <ReactionPicker
                            action={MessageBoardController.toggleReaction.url([
                                currentTeam.slug,
                                project.id,
                                post.id,
                            ])}
                            reactions={post.reactions}
                        />
                    </div>
                </div>
            </div>

            <div
                role="separator"
                class="relative border-t border-slate-200 dark:border-slate-700"
            >
                <h2
                    id="message-comments-heading"
                    class="absolute top-0 left-5 -translate-y-full rounded-t-lg border border-b-0 border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 sm:left-10 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
                >
                    {t('Comments')} ({post.comments.length})
                </h2>
            </div>

            <section
                aria-labelledby="message-comments-heading"
                class="px-5 pt-7 sm:px-10 sm:pt-8"
            >
                {#if post.comments.length === 0}
                    <p
                        class="border-b border-slate-200 py-5 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400"
                    >
                        {t('No comments yet')}
                    </p>
                {:else}
                    <div
                        class="divide-y divide-slate-200 dark:divide-slate-700"
                    >
                        {#each post.comments as comment (comment.id)}
                            <article
                                class="flex items-start gap-3 py-5 sm:gap-4"
                            >
                                <Avatar
                                    class="size-9 shrink-0 border border-slate-200 sm:size-10 dark:border-slate-700"
                                >
                                    <AvatarFallback
                                        class="bg-slate-100 text-[0.7rem] font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                    >
                                        {getInitials(
                                            comment.authorName ||
                                                t('Deleted user'),
                                        )}
                                    </AvatarFallback>
                                </Avatar>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex min-w-0 items-start justify-between gap-3"
                                    >
                                        <p
                                            class="min-w-0 text-sm text-slate-500 dark:text-slate-400"
                                        >
                                            <strong
                                                class="font-bold text-slate-800 dark:text-slate-100"
                                            >
                                                {comment.authorName ||
                                                    t('Deleted user')}
                                            </strong>
                                            <span aria-hidden="true"> · </span>
                                            <time
                                                datetime={comment.createdAt ||
                                                    undefined}
                                            >
                                                {formatDate(comment.createdAt)}
                                            </time>
                                        </p>
                                        {#if comment.canDelete}
                                            <div
                                                class="flex shrink-0 items-center gap-0.5"
                                            >
                                                {#if editingCommentId !== comment.id}
                                                    <button
                                                        type="button"
                                                        aria-label={t('Edit')}
                                                        onclick={() =>
                                                            (editingCommentId =
                                                                comment.id)}
                                                        class="flex size-7 items-center justify-center rounded-md text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-100"
                                                    >
                                                        <Pencil
                                                            class="size-3.5"
                                                        />
                                                    </button>
                                                {/if}
                                                <Form
                                                    {...CommentController.destroy.form(
                                                        [
                                                            currentTeam.slug,
                                                            project.id,
                                                            post.id,
                                                            comment.id,
                                                        ],
                                                    )}
                                                    onBefore={confirmDelete}
                                                >
                                                    {#snippet children({
                                                        processing,
                                                    })}
                                                        <button
                                                            type="submit"
                                                            disabled={processing}
                                                            aria-label={t(
                                                                'Delete',
                                                            )}
                                                            class="flex size-7 items-center justify-center rounded-md text-slate-400 hover:bg-destructive/10 hover:text-destructive disabled:opacity-50"
                                                        >
                                                            <Trash2
                                                                class="size-3.5"
                                                            />
                                                        </button>
                                                    {/snippet}
                                                </Form>
                                            </div>
                                        {/if}
                                    </div>
                                    {#if editingCommentId === comment.id}
                                        <Form
                                            {...CommentController.update.form([
                                                currentTeam.slug,
                                                project.id,
                                                post.id,
                                                comment.id,
                                            ])}
                                            onSuccess={() =>
                                                (editingCommentId = null)}
                                            class="mt-3 w-full space-y-2"
                                        >
                                            {#snippet children({
                                                errors,
                                                processing,
                                            })}
                                                <RichTextEditor
                                                    id={`message-comment-edit-${comment.id}`}
                                                    name="body"
                                                    value={comment.body}
                                                    required
                                                    ariaLabel={t('Comment')}
                                                    mentionables={members}
                                                    {attachmentUploadUrl}
                                                />
                                                <InputError
                                                    message={errors.body}
                                                />
                                                <div class="flex gap-2">
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        disabled={processing}
                                                        >{t('Save')}</Button
                                                    >
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={processing}
                                                        onclick={() =>
                                                            (editingCommentId =
                                                                null)}
                                                        >{t('Cancel')}</Button
                                                    >
                                                </div>
                                            {/snippet}
                                        </Form>
                                    {:else}
                                        <RichTextContent
                                            content={comment.body}
                                            class="mt-1.5 text-sm leading-6 text-slate-700 dark:text-slate-200"
                                        />
                                    {/if}
                                </div>
                            </article>
                        {/each}
                    </div>
                {/if}

                <div
                    class="border-t border-slate-200 py-6 dark:border-slate-700"
                >
                    {#if commentComposerOpen}
                        <Form
                            {...CommentController.store.form([
                                currentTeam.slug,
                                project.id,
                                post.id,
                            ])}
                            resetOnSuccess
                            onSuccess={() => (commentComposerOpen = false)}
                            class="space-y-3"
                        >
                            {#snippet children({ errors, processing })}
                                <div
                                    class="min-w-0 flex-1 [&_textarea]:!min-h-56 [&_trix-editor.rich-text-editor]:!min-h-56"
                                >
                                    <RichTextEditor
                                        id={`message-comment-${post.id}`}
                                        name="body"
                                        placeholder={t('Add your comment…')}
                                        required
                                        ariaLabel={t('Comment')}
                                        mentionables={members}
                                        {attachmentUploadUrl}
                                    />
                                    <InputError message={errors.body} />
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <Button type="submit" disabled={processing}
                                        >{t('Add comment')}</Button
                                    >
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={processing}
                                        onclick={() =>
                                            (commentComposerOpen = false)}
                                        >{t('Cancel')}</Button
                                    >
                                </div>
                            {/snippet}
                        </Form>
                    {:else}
                        <button
                            type="button"
                            aria-expanded="false"
                            onclick={openCommentComposer}
                            class="flex min-h-14 w-full items-center rounded-lg px-4 text-left text-base font-medium text-slate-400 outline-none hover:bg-slate-50 hover:text-slate-600 focus-visible:ring-2 focus-visible:ring-ring dark:hover:bg-slate-800/50 dark:hover:text-slate-200"
                        >
                            {t('Comment on this')}
                        </button>
                    {/if}
                </div>
            </section>

            <details
                class="border-t border-slate-200 px-5 py-5 text-sm sm:px-10 dark:border-slate-700"
            >
                <summary
                    class="cursor-pointer font-semibold text-slate-600 dark:text-slate-300"
                >
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
                                class="flex min-w-0 flex-col gap-2 rounded-lg border border-slate-200 bg-slate-50/70 p-3 sm:flex-row sm:items-center dark:border-slate-700 dark:bg-slate-800/40"
                            >
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="break-all text-sm font-medium text-slate-800 dark:text-slate-100"
                                    >
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
                                            {...ProjectFileController.destroy.form(
                                                [
                                                    currentTeam.slug,
                                                    project.id,
                                                    attachment.id,
                                                ],
                                            )}
                                            onBefore={confirmDelete}
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
                        {t(
                            'Or drag a file into the message or comment box above.',
                        )}
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
                                accept=".pdf,.txt,.csv,.jpg,.jpeg,.png,.webp,.mp4,.webm,.mov,.m4v,.doc,.docx,.odt,.xls,.xlsx,.xlsm,.ods,.ppt,.pptx,.odp,.zip"
                            />
                            {#if progress}
                                <progress
                                    value={progress.percentage}
                                    max="100"
                                    class="h-2 w-full"
                                    >{progress.percentage}%</progress
                                >
                            {/if}
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                            >
                                {t('Attach file')}
                            </Button>
                        {/snippet}
                    </Form>
                </div>
            </details>

            <footer
                class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50/70 px-5 py-5 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-10 dark:border-slate-700 dark:bg-slate-800/30 dark:text-slate-400"
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
                            {post.subscribed
                                ? t("Don't notify me")
                                : t('Notify me')}
                        </Button>
                    {/snippet}
                </Form>
            </footer>
        </article>
    </div>
</div>
