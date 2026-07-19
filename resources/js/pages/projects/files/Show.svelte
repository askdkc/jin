<script module lang="ts">
    import ProjectFileController from '@/actions/App/Http/Controllers/ProjectFileController';
    import ProjectMaterialController from '@/actions/App/Http/Controllers/ProjectMaterialController';
    import { show as showProject } from '@/routes/projects';
    import type { ProjectTool, Team } from '@/types';

    export const layout = (props: {
        currentTeam: Team;
        project: ProjectTool;
        file: { id: number; name: string };
    }) => ({
        breadcrumbs: [
            {
                title: props.project.name,
                href: showProject([props.currentTeam.slug, props.project.id]),
            },
            {
                title: 'Materials',
                href: ProjectMaterialController.index([
                    props.currentTeam.slug,
                    props.project.id,
                ]),
            },
            {
                title: props.file.name,
                href: ProjectFileController.show([
                    props.currentTeam.slug,
                    props.project.id,
                    props.file.id,
                ]),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import Download from 'lucide-svelte/icons/download';
    import FileIcon from 'lucide-svelte/icons/file';
    import Pencil from 'lucide-svelte/icons/pencil';
    import RefreshCw from 'lucide-svelte/icons/refresh-cw';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import AttachmentController from '@/actions/App/Http/Controllers/AttachmentController';
    import CommentController from '@/actions/App/Http/Controllers/CommentController';
    import AppHead from '@/components/AppHead.svelte';
    import FileDropzone from '@/components/FileDropzone.svelte';
    import InputError from '@/components/InputError.svelte';
    import OnlyOfficePreviewDialog from '@/components/OnlyOfficePreviewDialog.svelte';
    import RichTextContent from '@/components/RichTextContent.svelte';
    import RichTextEditor from '@/components/RichTextEditor.svelte';
    import StoredFilePreviewDialog, {
        canPreviewStoredFile,
    } from '@/components/StoredFilePreviewDialog.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
    import type { TodoMember } from '@/types';

    type FileComment = {
        id: number;
        body: string;
        authorName: string | null;
        createdAt: string | null;
        canDelete: boolean;
    };

    let {
        currentTeam,
        project,
        file,
        onlyOffice,
        reaction,
        comments,
        members,
    }: {
        currentTeam: Team;
        project: ProjectTool;
        onlyOffice: {
            enabled: boolean;
            supported: boolean;
            configUrl: string | null;
        };
        file: {
            id: number;
            name: string;
            mimeType: string | null;
            size: number;
            previewStatus: string | null;
            previewImageUrl: string | null;
            uploaderName: string | null;
            createdAt: string | null;
            canManage: boolean;
        };
        reaction: {
            count: number;
            reacted: boolean;
            names: string[];
        };
        comments: FileComment[];
        members: TodoMember[];
    } = $props();

    const routeArguments = $derived([currentTeam.slug, project.id, file.id] as [
        string,
        number,
        number,
    ]);
    const extension = $derived(
        file.name.split('.').pop()?.toLocaleLowerCase('en-US') ?? '',
    );
    const generatedPreviewExtensions = [
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'xlsm',
        'ods',
        'odt',
        'ppt',
        'pptx',
        'odp',
    ];
    const usesGeneratedPreview = $derived(
        generatedPreviewExtensions.includes(extension),
    );
    const previewKind = $derived(
        ['jpg', 'jpeg', 'png', 'webp'].includes(extension)
            ? 'image'
            : ['mp4', 'webm', 'mov', 'm4v'].includes(extension)
              ? 'video'
              : usesGeneratedPreview
                ? 'generated'
                : canPreviewStoredFile(file.name)
                  ? 'embed'
                  : 'none',
    );
    const previewUrl = $derived(
        ProjectFileController.preview.url([
            currentTeam.slug,
            project.id,
            file.id,
        ]),
    );
    let previewOpen = $state(false);
    let onlyOfficeOpen = $state(false);
    let showRename = $state(false);
    let showReplace = $state(false);

    const confirmDelete = (): boolean =>
        window.confirm(t('Are you sure you want to delete this?'));
    const openPreview = (): void => {
        if (
            onlyOffice.enabled &&
            onlyOffice.supported &&
            onlyOffice.configUrl
        ) {
            onlyOfficeOpen = true;

            return;
        }

        previewOpen = true;
    };
    const openFallbackPreview = (): void => {
        onlyOfficeOpen = false;
        previewOpen = true;
    };
    const formatDate = (value: string | null): string =>
        value
            ? new Intl.DateTimeFormat('ja-JP', {
                  dateStyle: 'long',
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
</script>

<AppHead title={file.name} />

<main class="mx-auto w-full max-w-4xl px-4 py-6 sm:px-6 sm:py-8">
    <article class="overflow-hidden rounded-xl border bg-card">
        <header class="px-5 pt-5 pb-6 sm:px-8">
            <div class="flex items-center justify-between gap-4">
                <nav
                    class="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-1 text-sm text-muted-foreground"
                    aria-label={t('Breadcrumb')}
                >
                    <Link
                        href={showProject([currentTeam.slug, project.id])}
                        class="truncate font-semibold text-foreground hover:underline"
                        >{project.name}</Link
                    >
                    <span aria-hidden="true">‹</span>
                    <Link
                        href={ProjectMaterialController.index([
                            currentTeam.slug,
                            project.id,
                        ])}
                        class="hover:underline">{t('Materials')}</Link
                    >
                </nav>

                {#if file.canManage}
                    <div class="flex shrink-0 items-center gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onclick={() => (showRename = true)}
                        >
                            <Pencil aria-hidden="true" />
                            {t('Edit')}
                        </Button>
                        <Form
                            {...ProjectFileController.destroy.form(
                                routeArguments,
                            )}
                            onBefore={confirmDelete}
                        >
                            {#snippet children({ processing })}
                                <Button
                                    type="submit"
                                    variant="ghost"
                                    size="sm"
                                    class="text-destructive hover:text-destructive"
                                    disabled={processing}
                                >
                                    <Trash2 aria-hidden="true" />
                                    {t('Delete')}
                                </Button>
                            {/snippet}
                        </Form>
                    </div>
                {/if}
            </div>

            {#if file.canManage && showRename}
                <Form
                    {...ProjectFileController.update.form(routeArguments)}
                    onSuccess={() => (showRename = false)}
                    class="mt-4 flex items-start gap-2"
                >
                    {#snippet children({ errors, processing })}
                        <div class="min-w-0 flex-1">
                            <Label for="file-name" class="sr-only">
                                {t('Name')}
                            </Label>
                            <Input
                                id="file-name"
                                name="original_name"
                                value={file.name}
                                maxlength={255}
                                required
                                autofocus
                            />
                            <InputError message={errors.original_name} />
                        </div>
                        <Button type="submit" size="sm" disabled={processing}>
                            {t('Save')}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onclick={() => (showRename = false)}
                        >
                            {t('Cancel')}
                        </Button>
                    {/snippet}
                </Form>
            {:else}
                <h1
                    class="mt-4 break-all text-3xl font-bold tracking-tight break-words"
                >
                    {file.name}
                </h1>
            {/if}
            <p class="mt-2 text-sm text-muted-foreground">
                {t('Posted by')}
                {file.uploaderName || t('Deleted user')}
                {#if file.createdAt}
                    · {formatDate(file.createdAt)}
                {/if}
                · {formatSize(file.size)}
            </p>
        </header>

        <section class="px-5 pb-6 sm:px-8">
            <div class="overflow-hidden rounded-lg border bg-muted/30">
                {#if previewKind === 'image'}
                    <img
                        src={previewUrl}
                        alt={file.name}
                        class="mx-auto max-h-[75vh] w-auto max-w-full object-contain"
                    />
                {:else if previewKind === 'video'}
                    <!-- svelte-ignore a11y_media_has_caption -->
                    <video
                        src={previewUrl}
                        controls
                        class="mx-auto max-h-[75vh] w-full bg-black"
                    ></video>
                {:else if previewKind === 'generated'}
                    {#if file.previewImageUrl}
                        <img
                            src={file.previewImageUrl}
                            alt={file.name}
                            class="mx-auto max-h-[75vh] w-auto max-w-full object-contain"
                        />
                    {:else}
                        <div
                            class="flex min-h-80 flex-col items-center justify-center gap-3 px-6 py-16 text-center"
                            role={file.previewStatus === 'failed'
                                ? 'alert'
                                : 'status'}
                        >
                            <FileIcon class="size-10 text-muted-foreground" />
                            <p class="text-sm text-muted-foreground">
                                {file.previewStatus === 'pending' ||
                                file.previewStatus === 'processing'
                                    ? t('Preparing preview...')
                                    : t('Could not load this file preview.')}
                            </p>
                        </div>
                    {/if}
                {:else if previewKind === 'embed'}
                    <iframe
                        src={previewUrl}
                        title={file.name}
                        class="h-[75vh] w-full"
                    ></iframe>
                {:else}
                    <div
                        class="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center"
                    >
                        <FileIcon class="size-10 text-muted-foreground" />
                        <p class="text-sm text-muted-foreground">
                            {t('This file type cannot be previewed.')}
                        </p>
                    </div>
                {/if}
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                {#if canPreviewStoredFile(file.name)}
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onclick={openPreview}
                    >
                        <FileIcon aria-hidden="true" />
                        {t('Preview')}
                    </Button>
                {/if}

                <Button variant="outline" size="sm" asChild>
                    {#snippet children(buttonProps)}
                        <a
                            {...buttonProps}
                            href={ProjectFileController.download.url(
                                routeArguments,
                            )}
                            class={buttonProps.class}
                        >
                            <Download aria-hidden="true" />
                            {t('Download')}
                        </a>
                    {/snippet}
                </Button>

                <Form
                    {...ProjectFileController.toggleReaction.form(
                        routeArguments,
                    )}
                >
                    {#snippet children({ processing })}
                        <Button
                            type="submit"
                            variant={reaction.reacted ? 'secondary' : 'outline'}
                            size="sm"
                            disabled={processing}
                            aria-pressed={reaction.reacted}
                            aria-label={t('React with rocket')}
                            title={reaction.names.length > 0
                                ? reaction.names.join(', ')
                                : t('React with rocket')}
                        >
                            <span aria-hidden="true">🚀</span>
                            {#if reaction.count > 0}
                                <span>{reaction.count}</span>
                            {/if}
                        </Button>
                    {/snippet}
                </Form>

                {#if file.canManage && !showReplace}
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onclick={() => (showReplace = true)}
                    >
                        <RefreshCw aria-hidden="true" />
                        {t('Replace with a new version')}
                    </Button>
                {/if}
            </div>

            {#if file.canManage && showReplace}
                <Form
                    {...ProjectFileController.replace.form(routeArguments)}
                    resetOnSuccess
                    onSuccess={() => (showReplace = false)}
                    class="mt-4 space-y-2 rounded-lg border p-4"
                >
                    {#snippet children({ errors, processing, progress })}
                        <FileDropzone
                            id="file-replacement"
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
                        <div class="flex gap-2">
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                                >{t('Replace with a new version')}</Button
                            >
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onclick={() => (showReplace = false)}
                                >{t('Cancel')}</Button
                            >
                        </div>
                    {/snippet}
                </Form>
            {/if}
        </section>

        <section class="border-t px-5 py-6 sm:px-8" aria-label={t('Comments')}>
            <h2 class="text-base font-semibold">
                {t('Comments')} ({comments.length})
            </h2>

            {#if comments.length === 0}
                <p class="mt-3 text-sm text-muted-foreground">
                    {t('No comments yet')}
                </p>
            {:else}
                <div class="mt-4 space-y-4">
                    {#each comments as comment (comment.id)}
                        <div class="rounded-lg border bg-background p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <RichTextContent content={comment.body} />
                                    <p
                                        class="mt-2 text-xs text-muted-foreground"
                                    >
                                        {comment.authorName ||
                                            t('Deleted user')} · {formatDate(
                                            comment.createdAt,
                                        )}
                                    </p>
                                </div>

                                {#if comment.canDelete}
                                    <div
                                        class="flex shrink-0 items-start gap-1"
                                    >
                                        <details>
                                            <summary
                                                aria-label={t('Edit')}
                                                class="[&::-webkit-details-marker]:hidden flex size-7 list-none cursor-pointer items-center justify-center rounded-md text-muted-foreground hover:bg-muted"
                                            >
                                                <Pencil class="size-3.5" />
                                            </summary>
                                            <Form
                                                {...CommentController.updateFile.form(
                                                    [
                                                        currentTeam.slug,
                                                        project.id,
                                                        file.id,
                                                        comment.id,
                                                    ],
                                                )}
                                                class="mt-3 min-w-[min(32rem,70vw)] space-y-2"
                                            >
                                                {#snippet children({
                                                    errors,
                                                    processing,
                                                })}
                                                    <RichTextEditor
                                                        id={`file-comment-edit-${comment.id}`}
                                                        name="body"
                                                        value={comment.body}
                                                        required
                                                        ariaLabel={t('Comment')}
                                                        mentionables={members}
                                                        attachmentUploadUrl={AttachmentController.uploadInline.url(
                                                            [
                                                                currentTeam.slug,
                                                                project.id,
                                                            ],
                                                        )}
                                                    />
                                                    <InputError
                                                        message={errors.body}
                                                    />
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
                                            {...CommentController.destroyFile.form(
                                                [
                                                    currentTeam.slug,
                                                    project.id,
                                                    file.id,
                                                    comment.id,
                                                ],
                                            )}
                                            onBefore={confirmDelete}
                                        >
                                            {#snippet children({ processing })}
                                                <button
                                                    type="submit"
                                                    disabled={processing}
                                                    aria-label={t('Delete')}
                                                    class="flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-destructive/10 hover:text-destructive disabled:opacity-50"
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
                </div>
            {/if}

            <Form
                {...CommentController.storeFile.form(routeArguments)}
                resetOnSuccess
                class="mt-5 space-y-2"
            >
                {#snippet children({ errors, processing })}
                    <Label for="file-comment">{t('Comment')}</Label>
                    <RichTextEditor
                        id="file-comment"
                        name="body"
                        required
                        ariaLabel={t('Comment')}
                        mentionables={members}
                        attachmentUploadUrl={AttachmentController.uploadInline.url(
                            [currentTeam.slug, project.id],
                        )}
                    />
                    <InputError message={errors.body} />
                    <Button type="submit" disabled={processing}
                        >{t('Add comment')}</Button
                    >
                {/snippet}
            </Form>
        </section>
    </article>
</main>

{#if onlyOffice.configUrl}
    <OnlyOfficePreviewDialog
        {currentTeam}
        {project}
        {file}
        configUrl={onlyOffice.configUrl}
        onFallback={openFallbackPreview}
        bind:open={onlyOfficeOpen}
    />
{/if}

<StoredFilePreviewDialog
    {currentTeam}
    {project}
    {file}
    bind:open={previewOpen}
/>
