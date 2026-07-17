<script module lang="ts">
    import ProjectFileController from '@/actions/App/Http/Controllers/ProjectFileController';
    import ProjectMaterialController from '@/actions/App/Http/Controllers/ProjectMaterialController';
    import { show } from '@/routes/projects';
    import type { ProjectTool, Team } from '@/types';

    export const layout = (props: {
        currentTeam: Team;
        project: ProjectTool;
    }) => ({
        breadcrumbs: [
            {
                title: props.project.name,
                href: show([props.currentTeam.slug, props.project.id]),
            },
            {
                title: 'Materials',
                href: ProjectMaterialController.index([
                    props.currentTeam.slug,
                    props.project.id,
                ]),
            },
            {
                title: 'Files',
                href: ProjectFileController.index([
                    props.currentTeam.slug,
                    props.project.id,
                ]),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import Download from 'lucide-svelte/icons/download';
    import Eye from 'lucide-svelte/icons/eye';
    import FileIcon from 'lucide-svelte/icons/file';
    import FileArchive from 'lucide-svelte/icons/file-archive';
    import FileImage from 'lucide-svelte/icons/file-image';
    import FileSpreadsheet from 'lucide-svelte/icons/file-spreadsheet';
    import FileText from 'lucide-svelte/icons/file-text';
    import Folder from 'lucide-svelte/icons/folder';
    import Search from 'lucide-svelte/icons/search';
    import Upload from 'lucide-svelte/icons/upload';
    import AppHead from '@/components/AppHead.svelte';
    import FileDropzone from '@/components/FileDropzone.svelte';
    import InputError from '@/components/InputError.svelte';
    import StoredFilePreviewDialog, {
        canPreviewStoredFile,
    } from '@/components/StoredFilePreviewDialog.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
    import type { ProjectStoredFile, TodoMember } from '@/types';

    type FileKind = 'image' | 'spreadsheet' | 'archive' | 'document' | 'file';

    type FileGroup = {
        id: number | null;
        name: string;
        files: ProjectStoredFile[];
    };

    let {
        currentTeam,
        project,
        files,
        folders,
    }: {
        currentTeam: Team;
        project: ProjectTool;
        files: ProjectStoredFile[];
        folders: TodoMember[];
    } = $props();

    let query = $state('');
    let previewOpen = $state(false);
    let previewFile = $state<ProjectStoredFile | null>(null);

    const routeArguments = $derived([currentTeam.slug, project.id] as [
        string,
        number,
    ]);

    const normalizedQuery = $derived(query.trim().toLocaleLowerCase('ja-JP'));

    const fileMatchesQuery = (file: ProjectStoredFile): boolean =>
        file.name.toLocaleLowerCase('ja-JP').includes(normalizedQuery) ||
        (file.uploaderName ?? '')
            .toLocaleLowerCase('ja-JP')
            .includes(normalizedQuery);

    const fileGroups = $derived.by((): FileGroup[] => {
        const groups: FileGroup[] = folders.map((folder) => {
            const folderMatches = folder.name
                .toLocaleLowerCase('ja-JP')
                .includes(normalizedQuery);

            return {
                id: folder.id,
                name: folder.name,
                files: files.filter(
                    (file) =>
                        file.folderId === folder.id &&
                        (folderMatches || fileMatchesQuery(file)),
                ),
            };
        });

        const unfiled = files.filter(
            (file) => file.folderId === null && fileMatchesQuery(file),
        );

        if (unfiled.length > 0) {
            groups.push({
                id: null,
                name: t('No folder'),
                files: unfiled,
            });
        }

        return normalizedQuery
            ? groups.filter((group) => group.files.length > 0)
            : groups;
    });

    const visibleFileCount = $derived(
        fileGroups.reduce((count, group) => count + group.files.length, 0),
    );

    const formatSize = (size: number): string => {
        if (size < 1024) {
            return `${size} B`;
        }

        if (size < 1024 * 1024) {
            return `${(size / 1024).toFixed(1)} KB`;
        }

        return `${(size / (1024 * 1024)).toFixed(1)} MB`;
    };

    const formatDate = (value: string | null): string =>
        value
            ? new Intl.DateTimeFormat('ja-JP', {
                  year: 'numeric',
                  month: 'short',
                  day: 'numeric',
              }).format(new Date(value))
            : '';

    const fileKind = (file: ProjectStoredFile): FileKind => {
        const mimeType = file.mimeType ?? '';
        const extension = file.name.split('.').pop()?.toLowerCase();

        if (mimeType.startsWith('image/')) {
            return 'image';
        }

        if (['xls', 'xlsx', 'csv'].includes(extension ?? '')) {
            return 'spreadsheet';
        }

        if (extension === 'zip') {
            return 'archive';
        }

        if (
            mimeType === 'application/pdf' ||
            ['pdf', 'txt', 'doc', 'docx', 'ppt', 'pptx'].includes(
                extension ?? '',
            )
        ) {
            return 'document';
        }

        return 'file';
    };

    const fileKindLabel = (kind: FileKind): string =>
        ({
            image: t('Image'),
            spreadsheet: t('Spreadsheet'),
            archive: t('Archive'),
            document: t('Document'),
            file: t('File'),
        })[kind];

    const fileKindClass = (kind: FileKind): string =>
        ({
            image: 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
            spreadsheet:
                'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
            archive:
                'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
            document:
                'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
            file: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
        })[kind];

    const confirmDelete = (event: SubmitEvent): void => {
        if (!window.confirm(t('Are you sure you want to delete this?'))) {
            event.preventDefault();
        }
    };

    const openPreview = (file: ProjectStoredFile): void => {
        previewFile = file;
        previewOpen = true;
    };
</script>

<AppHead title={t('Files')} />

<div class="min-h-full bg-slate-50/70 dark:bg-slate-950/30">
    <div class="mx-auto w-full max-w-6xl space-y-6 p-4 sm:p-6 lg:p-8">
        <header
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div class="flex items-center gap-3">
                <div
                    class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300"
                >
                    <Folder class="size-6" />
                </div>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">
                        {t('Files')}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {project.name} · {t('Share project files in one place')}
                    </p>
                </div>
            </div>
            <Badge variant="secondary" class="w-fit px-3 py-1 text-sm">
                {t(':count files', { count: files.length.toString() })}
            </Badge>
        </header>

        <section
            class="overflow-hidden rounded-3xl border bg-card shadow-sm"
            aria-labelledby="upload-file-heading"
        >
            <div class="border-b px-5 py-4 sm:px-6">
                <h2
                    id="upload-file-heading"
                    class="flex items-center gap-2 text-lg font-bold"
                >
                    <Upload class="size-5 text-primary" />
                    {t('Upload file')}
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    {t('Drop a file, choose its folder, then upload it')}
                </p>
            </div>

            <Form
                {...ProjectFileController.store.form(routeArguments)}
                resetOnSuccess
                class="grid gap-5 p-5 sm:p-6 lg:grid-cols-[minmax(0,1fr)_18rem]"
            >
                {#snippet children({ errors, processing, progress })}
                    <FileDropzone
                        id="project-file"
                        error={errors.file}
                        hint={t(
                            'Maximum 10 MB. Allowed: PDF, text, images, Office files, ZIP.',
                        )}
                        accept=".pdf,.txt,.csv,.jpg,.jpeg,.png,.webp,.mp4,.webm,.mov,.m4v,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip"
                    />

                    <div
                        class="flex flex-col gap-4 rounded-2xl bg-muted/40 p-4 sm:p-5"
                    >
                        <div class="grid gap-2">
                            <Label for="project-file-folder">
                                {t('Upload destination')}
                            </Label>
                            <select
                                id="project-file-folder"
                                name="material_folder_id"
                                class="border-input h-11 w-full rounded-xl border bg-background px-3 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <option value="">{t('No folder')}</option>
                                {#each folders as folder (folder.id)}
                                    <option value={folder.id}>
                                        {folder.name}
                                    </option>
                                {/each}
                            </select>
                            <InputError message={errors.material_folder_id} />
                            <p class="text-xs text-muted-foreground">
                                {t('Choose where this file will appear')}
                            </p>
                        </div>

                        {#if progress}
                            <div class="grid gap-2" aria-live="polite">
                                <div
                                    class="flex items-center justify-between text-xs font-medium"
                                >
                                    <span>{t('Uploading')}</span>
                                    <span>{progress.percentage}%</span>
                                </div>
                                <progress
                                    value={progress.percentage}
                                    max="100"
                                    class="h-2 w-full overflow-hidden rounded-full"
                                >
                                    {progress.percentage}%
                                </progress>
                            </div>
                        {/if}

                        <Button
                            type="submit"
                            size="lg"
                            class="mt-auto w-full rounded-xl"
                            disabled={processing}
                        >
                            <Upload class="size-4" />
                            {processing ? t('Uploading') : t('Upload')}
                        </Button>
                    </div>
                {/snippet}
            </Form>
        </section>

        <section
            class="overflow-hidden rounded-3xl border bg-card shadow-sm"
            aria-labelledby="file-library-heading"
        >
            <div
                class="flex flex-col gap-4 border-b px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6"
            >
                <div>
                    <h2 id="file-library-heading" class="text-lg font-bold">
                        {t('Stored files')}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {t(':count files shown', {
                            count: visibleFileCount.toString(),
                        })}
                    </p>
                </div>
                <div class="relative w-full sm:max-w-xs">
                    <Label for="file-search" class="sr-only">
                        {t('Search files')}
                    </Label>
                    <Search
                        class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        id="file-search"
                        type="search"
                        bind:value={query}
                        placeholder={t('Search files')}
                        class="h-10 rounded-xl pl-9"
                    />
                </div>
            </div>

            {#if normalizedQuery && fileGroups.length === 0}
                <div class="px-5 py-14 text-center sm:px-6">
                    <Search class="mx-auto size-9 text-muted-foreground/60" />
                    <p class="mt-3 font-medium">
                        {t('No files match your search')}
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {t('Try a different file name or uploader')}
                    </p>
                </div>
            {:else if files.length === 0 && folders.length === 0}
                <div class="px-5 py-14 text-center sm:px-6">
                    <FileIcon
                        class="mx-auto size-10 text-muted-foreground/60"
                    />
                    <p class="mt-3 font-medium">{t('No files yet')}</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {t('Use the upload area above to add the first file')}
                    </p>
                </div>
            {:else}
                <div class="divide-y">
                    {#each fileGroups as group (group.id ?? 'unfiled')}
                        <section data-test="file-folder">
                            <div
                                class="flex items-center gap-3 bg-muted/25 px-5 py-4 sm:px-6"
                            >
                                <div
                                    class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300"
                                >
                                    <Folder class="size-6" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h3 class="truncate font-bold">
                                        {group.name}
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        {t(':count files', {
                                            count: group.files.length.toString(),
                                        })}
                                    </p>
                                </div>
                            </div>

                            {#if group.files.length === 0}
                                <p
                                    class="border-t px-5 py-5 text-sm text-muted-foreground sm:px-6"
                                >
                                    {t('This folder is empty')}
                                </p>
                            {:else}
                                <div
                                    class="grid gap-px border-t bg-border sm:grid-cols-2"
                                >
                                    {#each group.files as file (file.id)}
                                        {@const kind = fileKind(file)}
                                        <article
                                            class="min-w-0 bg-card p-5 transition-colors hover:bg-muted/20"
                                        >
                                            <div
                                                class="flex min-w-0 items-start gap-3"
                                            >
                                                <div
                                                    class={`flex size-12 shrink-0 items-center justify-center rounded-xl ${fileKindClass(kind)}`}
                                                >
                                                    {#if kind === 'image'}
                                                        <FileImage
                                                            class="size-6"
                                                        />
                                                    {:else if kind === 'spreadsheet'}
                                                        <FileSpreadsheet
                                                            class="size-6"
                                                        />
                                                    {:else if kind === 'archive'}
                                                        <FileArchive
                                                            class="size-6"
                                                        />
                                                    {:else if kind === 'document'}
                                                        <FileText
                                                            class="size-6"
                                                        />
                                                    {:else}
                                                        <FileIcon
                                                            class="size-6"
                                                        />
                                                    {/if}
                                                </div>
                                                <div class="min-w-0 flex-1">
                                                    <h4>
                                                        {#if canPreviewStoredFile(file.name)}
                                                            <button
                                                                type="button"
                                                                class="block w-full cursor-zoom-in break-words text-left font-semibold leading-snug underline-offset-4 hover:text-primary hover:underline focus-visible:rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                                title={t(
                                                                    'Preview',
                                                                )}
                                                                onclick={() =>
                                                                    openPreview(
                                                                        file,
                                                                    )}
                                                            >
                                                                {file.name}
                                                            </button>
                                                        {:else}
                                                            <span
                                                                class="break-words font-semibold leading-snug"
                                                            >
                                                                {file.name}
                                                            </span>
                                                        {/if}
                                                    </h4>
                                                    <p
                                                        class="mt-1 text-xs leading-relaxed text-muted-foreground"
                                                    >
                                                        {file.uploaderName ||
                                                            t('Deleted user')} · {formatDate(
                                                            file.createdAt,
                                                        )} · {formatSize(
                                                            file.size,
                                                        )}
                                                    </p>
                                                    <Badge
                                                        variant="outline"
                                                        class="mt-2"
                                                    >
                                                        {fileKindLabel(kind)}
                                                    </Badge>
                                                </div>
                                            </div>

                                            <div
                                                class="mt-4 flex flex-wrap gap-2 pl-0 sm:pl-15"
                                            >
                                                {#if canPreviewStoredFile(file.name)}
                                                    <Button
                                                        type="button"
                                                        variant="default"
                                                        size="sm"
                                                        onclick={() =>
                                                            openPreview(file)}
                                                    >
                                                        <Eye class="size-4" />
                                                        {t('Preview')}
                                                    </Button>
                                                {/if}
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    {#snippet children(
                                                        buttonProps,
                                                    )}
                                                        <a
                                                            {...buttonProps}
                                                            href={ProjectFileController.download.url(
                                                                [
                                                                    currentTeam.slug,
                                                                    project.id,
                                                                    file.id,
                                                                ],
                                                            )}
                                                            class={buttonProps.class}
                                                        >
                                                            <Download
                                                                class="size-4"
                                                            />
                                                            {t('Download')}
                                                        </a>
                                                    {/snippet}
                                                </Button>
                                                {#if file.canDelete}
                                                    <Form
                                                        {...ProjectFileController.destroy.form(
                                                            [
                                                                currentTeam.slug,
                                                                project.id,
                                                                file.id,
                                                            ],
                                                        )}
                                                        onsubmit={confirmDelete}
                                                    >
                                                        {#snippet children({
                                                            processing,
                                                        })}
                                                            <Button
                                                                type="submit"
                                                                variant="ghost"
                                                                size="sm"
                                                                class="text-destructive hover:text-destructive"
                                                                disabled={processing}
                                                            >
                                                                {t('Delete')}
                                                            </Button>
                                                        {/snippet}
                                                    </Form>
                                                {/if}
                                            </div>
                                        </article>
                                    {/each}
                                </div>
                            {/if}
                        </section>
                    {/each}
                </div>
            {/if}
        </section>
    </div>
</div>

<StoredFilePreviewDialog
    {currentTeam}
    {project}
    file={previewFile}
    bind:open={previewOpen}
/>
