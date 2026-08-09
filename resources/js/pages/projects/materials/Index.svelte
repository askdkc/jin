<script module lang="ts">
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
        ],
    });
</script>

<script lang="ts">
    import { Form, Link, useForm } from '@inertiajs/svelte';
    import ChevronDown from 'lucide-svelte/icons/chevron-down';
    import ChevronLeft from 'lucide-svelte/icons/chevron-left';
    import Ellipsis from 'lucide-svelte/icons/ellipsis';
    import ExternalLinkIcon from 'lucide-svelte/icons/external-link';
    import FilePlus from 'lucide-svelte/icons/file-plus';
    import FileText from 'lucide-svelte/icons/file-text';
    import Folder from 'lucide-svelte/icons/folder';
    import FolderPlus from 'lucide-svelte/icons/folder-plus';
    import FolderUp from 'lucide-svelte/icons/folder-up';
    import LinkIcon from 'lucide-svelte/icons/link';
    import Pencil from 'lucide-svelte/icons/pencil';
    import Plus from 'lucide-svelte/icons/plus';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import Upload from 'lucide-svelte/icons/upload';
    import ProjectDocumentController from '@/actions/App/Http/Controllers/ProjectDocumentController';
    import ProjectFileController from '@/actions/App/Http/Controllers/ProjectFileController';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import ProjectFileThumbnail from '@/components/ProjectFileThumbnail.svelte';
    import { Button } from '@/components/ui/button';
    import {
        Dialog,
        DialogClose,
        DialogContent,
        DialogFooter,
        DialogTitle,
    } from '@/components/ui/dialog';
    import {
        DropdownMenu,
        DropdownMenuContent,
        DropdownMenuGroup,
        DropdownMenuItem,
        DropdownMenuTrigger,
    } from '@/components/ui/dropdown-menu';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { currentLocale, t } from '@/lib/i18n';
    import type {
        MaterialFilters,
        MaterialFolder,
        MaterialItem,
        TodoMember,
    } from '@/types';

    let {
        currentTeam,
        project,
        items,
        folders,
        members,
        filters,
    }: {
        currentTeam: Team;
        project: ProjectTool;
        items: MaterialItem[];
        folders: MaterialFolder[];
        members: TodoMember[];
        filters: MaterialFilters;
    } = $props();

    const routeArguments = $derived([currentTeam.slug, project.id] as [
        string,
        number,
    ]);
    const activeFolder = $derived(
        folders.find((folder) => folder.id === filters.folder) ?? null,
    );
    const uploadForm = useForm(
        () => ProjectFileController.store(routeArguments),
        {
            files: [] as File[],
            material_folder_id: null as number | null,
            folder_name: null as string | null,
        },
    );
    const uploadError = $derived(
        uploadForm.errors.files ??
            Object.entries(uploadForm.errors).find(([field]) =>
                field.startsWith('files.'),
            )?.[1] ??
            uploadForm.errors.material_folder_id ??
            uploadForm.errors.folder_name,
    );
    const hasFilters = $derived(
        filters.type !== null || filters.updater !== null,
    );
    const isEmpty = $derived(
        items.length === 0 && (activeFolder !== null || folders.length === 0),
    );

    let folderDialogOpen = $state(false);
    let linkDialogOpen = $state(false);
    let moveDialogOpen = $state(false);
    let movingItem = $state<MaterialItem | null>(null);
    let renameDialogOpen = $state(false);
    let renamingFile = $state<MaterialItem | null>(null);
    let isFileDragging = $state(false);
    let isReadingFolder = $state(false);
    let fileInput: HTMLInputElement;
    let folderInput: HTMLInputElement;

    const MAX_UPLOAD_FILES = 20;

    const formatDate = (value: string | null): string =>
        value
            ? new Intl.DateTimeFormat(currentLocale(), {
                  dateStyle: 'medium',
              }).format(new Date(value))
            : '';
    const typeLabel = (type: MaterialItem['type']): string =>
        ({
            document: t('Document'),
            file: t('File'),
            link: t('External link'),
        })[type];
    const confirmDelete = (): boolean =>
        window.confirm(t('Are you sure you want to delete this?'));
    const openMoveDialog = (item: MaterialItem): void => {
        movingItem = item;
        moveDialogOpen = true;
    };
    const openRenameDialog = (item: MaterialItem): void => {
        renamingFile = item;
        renameDialogOpen = true;
    };
    const setUploadError = (message: string): void => {
        uploadForm.clearErrors();
        uploadForm.setError('files', message);
    };
    const submitFiles = (
        files: File[],
        folderName: string | null = null,
    ): void => {
        if (files.length === 0 || uploadForm.processing) {
            return;
        }

        if (files.length > MAX_UPLOAD_FILES) {
            setUploadError(
                t('You can upload up to :count files at a time.', {
                    count: MAX_UPLOAD_FILES.toString(),
                }),
            );

            return;
        }

        uploadForm.clearErrors();
        uploadForm.files = files;
        uploadForm.material_folder_id = activeFolder?.id ?? null;
        uploadForm.folder_name = activeFolder ? null : folderName;
        uploadForm.submit({
            preserveScroll: true,
            onFinish: () => uploadForm.reset('files', 'folder_name'),
        });
    };
    const chooseFolder = (): void => {
        folderInput.value = '';
        folderInput.click();
    };
    const chooseFiles = (): void => {
        fileInput.value = '';
        fileInput.click();
    };
    const isHiddenFile = (file: File): boolean =>
        (file.webkitRelativePath || file.name)
            .split('/')
            .some((segment) => segment.startsWith('.'));
    const handleFolderPick = (event: Event): void => {
        const input = event.currentTarget as HTMLInputElement;
        const files = Array.from(input.files ?? []).filter(
            (file) => !isHiddenFile(file),
        );

        if (files.length === 0) {
            setUploadError(t('No files found in the selected folder.'));

            return;
        }

        const folderName = files[0]?.webkitRelativePath.split('/')[0] ?? null;

        submitFiles(files, folderName);
        input.value = '';
    };
    const handleFilePick = (event: Event): void => {
        const input = event.currentTarget as HTMLInputElement;

        submitFiles(Array.from(input.files ?? []));
        input.value = '';
    };
    const readFileEntry = (entry: FileSystemFileEntry): Promise<File> =>
        new Promise((resolve, reject) => entry.file(resolve, reject));
    const readDirectoryEntries = (
        entry: FileSystemDirectoryEntry,
    ): Promise<FileSystemEntry[]> =>
        new Promise((resolve, reject) => {
            const entries: FileSystemEntry[] = [];
            const reader = entry.createReader();
            const readNextBatch = (): void => {
                reader.readEntries((batch) => {
                    if (batch.length === 0) {
                        resolve(entries);

                        return;
                    }

                    entries.push(...batch);
                    readNextBatch();
                }, reject);
            };

            readNextBatch();
        });
    const collectEntryFiles = async (
        entry: FileSystemEntry,
        files: File[],
    ): Promise<void> => {
        if (files.length > MAX_UPLOAD_FILES) {
            return;
        }

        if (entry.name.startsWith('.')) {
            return;
        }

        if (entry.isFile) {
            files.push(await readFileEntry(entry as FileSystemFileEntry));

            return;
        }

        if (!entry.isDirectory) {
            return;
        }

        // ponytail: Material folders are flat; add parent_id before preserving nested paths.
        for (const child of await readDirectoryEntries(
            entry as FileSystemDirectoryEntry,
        )) {
            await collectEntryFiles(child, files);

            if (files.length > MAX_UPLOAD_FILES) {
                return;
            }
        }
    };
    const hasDraggedFiles = (event: DragEvent): boolean =>
        event.dataTransfer?.types.includes('Files') === true;
    const handleFileDrag = (event: DragEvent): void => {
        if (!hasDraggedFiles(event)) {
            return;
        }

        event.preventDefault();

        if (event.dataTransfer) {
            event.dataTransfer.dropEffect =
                uploadForm.processing || isReadingFolder ? 'none' : 'copy';
        }

        isFileDragging = !uploadForm.processing && !isReadingFolder;
    };
    const handleFileDragLeave = (event: DragEvent): void => {
        const dropTarget = event.currentTarget as HTMLElement;

        if (!dropTarget.contains(event.relatedTarget as Node | null)) {
            isFileDragging = false;
        }
    };
    const handleFileDrop = async (event: DragEvent): Promise<void> => {
        if (!hasDraggedFiles(event)) {
            return;
        }

        event.preventDefault();
        isFileDragging = false;

        if (uploadForm.processing || isReadingFolder) {
            return;
        }

        const dataTransfer = event.dataTransfer;
        const entries = Array.from(dataTransfer?.items ?? [])
            .filter((item) => item.kind === 'file')
            .map((item) => item.webkitGetAsEntry())
            .filter((entry): entry is FileSystemEntry => entry !== null);

        if (entries.length === 0) {
            submitFiles(Array.from(dataTransfer?.files ?? []));

            return;
        }

        const directories = entries.filter((entry) => entry.isDirectory);

        if (
            activeFolder === null &&
            directories.length > 0 &&
            (directories.length !== 1 || entries.length !== 1)
        ) {
            setUploadError(t('Drop one folder at a time.'));

            return;
        }

        const files: File[] = [];
        isReadingFolder = true;

        try {
            for (const entry of entries) {
                await collectEntryFiles(entry, files);

                if (files.length > MAX_UPLOAD_FILES) {
                    break;
                }
            }
        } catch {
            setUploadError(t('Could not read the dropped folder.'));

            return;
        } finally {
            isReadingFolder = false;
        }

        if (files.length === 0) {
            setUploadError(t('No files found in the selected folder.'));

            return;
        }

        submitFiles(
            files,
            activeFolder === null && directories.length === 1
                ? directories[0].name
                : null,
        );
    };
</script>

<AppHead title={t('Materials')} />

<div
    class="min-h-full bg-[#f5f7f3] px-0 pb-8 dark:bg-[#0d151b] sm:px-5 sm:pt-2 lg:px-6"
>
    <main
        class="relative mx-auto min-h-[calc(100dvh-7rem)] w-full max-w-[1088px] overflow-hidden bg-card shadow-[0_18px_55px_rgba(30,55,80,0.08)]"
        ondragenter={handleFileDrag}
        ondragover={handleFileDrag}
        ondragleave={handleFileDragLeave}
        ondrop={handleFileDrop}
    >
        {#if isFileDragging}
            <div
                class="pointer-events-none absolute inset-3 z-30 flex flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed border-primary bg-card/95 text-primary shadow-xl"
                role="status"
                aria-live="polite"
            >
                <Upload class="size-12" aria-hidden="true" />
                <p class="text-lg font-bold">
                    {t('Drop files or folders here')}
                </p>
            </div>
        {/if}

        <input
            bind:this={fileInput}
            type="file"
            multiple
            class="hidden"
            onchange={handleFilePick}
        />

        <input
            bind:this={folderInput}
            type="file"
            multiple
            webkitdirectory
            class="hidden"
            onchange={handleFolderPick}
        />

        <div class="flex min-h-10 items-center border-b px-5 sm:px-8">
            <Link
                href={show([currentTeam.slug, project.id])}
                class="text-sm font-bold hover:underline"
            >
                {project.name}
            </Link>
        </div>

        <div class="px-5 py-6 sm:px-9 sm:py-8 lg:px-10">
            {#if activeFolder}
                <Link
                    href={ProjectMaterialController.index(routeArguments)}
                    class="mb-2 inline-flex items-center gap-1 text-sm font-medium text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                >
                    <ChevronLeft class="size-4" aria-hidden="true" />
                    {t('Materials')}
                </Link>
            {/if}

            <h1
                class="flex items-center gap-2 text-3xl font-bold tracking-tight"
            >
                {#if activeFolder}
                    <Folder
                        class="size-8 text-muted-foreground"
                        aria-hidden="true"
                    />
                    {activeFolder.name}
                {:else}
                    {t('Materials')}
                {/if}
            </h1>

            <div class="mt-4 flex flex-wrap items-center gap-2 border-b pb-5">
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        {#snippet children(triggerProps)}
                            <Button
                                size="sm"
                                onclick={triggerProps.onclick}
                                aria-expanded={triggerProps['aria-expanded']}
                                data-state={triggerProps['data-state']}
                            >
                                <Plus data-icon="inline-start" />
                                {t('New...')}
                                <ChevronDown data-icon="inline-end" />
                            </Button>
                        {/snippet}
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" class="min-w-64">
                        <DropdownMenuGroup>
                            <DropdownMenuItem asChild>
                                {#snippet children(itemProps)}
                                    <Link
                                        href={ProjectDocumentController.index(
                                            routeArguments,
                                        )}
                                        class={itemProps.class}
                                        onclick={() => itemProps.onClick?.()}
                                    >
                                        <FilePlus aria-hidden="true" />
                                        {t('New document')}
                                    </Link>
                                {/snippet}
                            </DropdownMenuItem>
                            <DropdownMenuItem asChild>
                                {#snippet children(itemProps)}
                                    <button
                                        type="button"
                                        class={itemProps.class}
                                        onclick={() => {
                                            itemProps.onClick?.();
                                            chooseFolder();
                                        }}
                                    >
                                        <FolderUp aria-hidden="true" />
                                        {t('Upload folder')}
                                    </button>
                                {/snippet}
                            </DropdownMenuItem>
                            <DropdownMenuItem asChild>
                                {#snippet children(itemProps)}
                                    <button
                                        type="button"
                                        class={itemProps.class}
                                        onclick={() => {
                                            itemProps.onClick?.();
                                            chooseFiles();
                                        }}
                                    >
                                        <Upload aria-hidden="true" />
                                        {t('Upload file')}
                                    </button>
                                {/snippet}
                            </DropdownMenuItem>
                            {#if !activeFolder}
                                <DropdownMenuItem asChild>
                                    {#snippet children(itemProps)}
                                        <button
                                            type="button"
                                            class={itemProps.class}
                                            onclick={() => {
                                                itemProps.onClick?.();
                                                folderDialogOpen = true;
                                            }}
                                        >
                                            <FolderPlus aria-hidden="true" />
                                            {t('Create folder')}
                                        </button>
                                    {/snippet}
                                </DropdownMenuItem>
                            {/if}
                            <DropdownMenuItem asChild>
                                {#snippet children(itemProps)}
                                    <button
                                        type="button"
                                        class={itemProps.class}
                                        onclick={() => {
                                            itemProps.onClick?.();
                                            linkDialogOpen = true;
                                        }}
                                    >
                                        <LinkIcon aria-hidden="true" />
                                        {t('Add external link')}
                                    </button>
                                {/snippet}
                            </DropdownMenuItem>
                        </DropdownMenuGroup>
                    </DropdownMenuContent>
                </DropdownMenu>

                <Form
                    {...ProjectMaterialController.index.form(routeArguments)}
                    class="flex flex-wrap items-center gap-2"
                >
                    {#snippet children({ processing })}
                        {#if activeFolder}
                            <input
                                type="hidden"
                                name="folder"
                                value={activeFolder.id}
                            />
                        {/if}

                        <Label for="material-type" class="sr-only">
                            {t('Type')}
                        </Label>
                        <select
                            id="material-type"
                            name="type"
                            class="border-input h-8 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">{t('All files')}</option>
                            <option
                                value="document"
                                selected={filters.type === 'document'}
                            >
                                {t('Documents')}
                            </option>
                            <option
                                value="file"
                                selected={filters.type === 'file'}
                            >
                                {t('Files')}
                            </option>
                            <option
                                value="link"
                                selected={filters.type === 'link'}
                            >
                                {t('External links')}
                            </option>
                        </select>

                        <Label for="material-updater" class="sr-only">
                            {t('Added by')}
                        </Label>
                        <select
                            id="material-updater"
                            name="updater"
                            class="border-input h-8 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">{t('Everyone')}</option>
                            {#each members as member (member.id)}
                                <option
                                    value={member.id}
                                    selected={filters.updater === member.id}
                                >
                                    {member.name}
                                </option>
                            {/each}
                        </select>

                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            disabled={processing}
                        >
                            {t('Filter')}
                        </Button>
                        {#if hasFilters}
                            <Button variant="ghost" size="sm" asChild>
                                {#snippet children(buttonProps)}
                                    <Link
                                        {...buttonProps}
                                        href={activeFolder
                                            ? ProjectMaterialController.index(
                                                  routeArguments,
                                                  {
                                                      query: {
                                                          folder: activeFolder.id,
                                                      },
                                                  },
                                              )
                                            : ProjectMaterialController.index(
                                                  routeArguments,
                                              )}
                                        class={buttonProps.class}
                                    >
                                        {t('Clear')}
                                    </Link>
                                {/snippet}
                            </Button>
                        {/if}
                    {/snippet}
                </Form>
            </div>

            {#if uploadForm.processing}
                <div
                    class="mt-3 flex items-center gap-3 text-sm text-muted-foreground"
                    aria-live="polite"
                >
                    <span>{t('Uploading')}</span>
                    {#if uploadForm.progress}
                        <progress
                            value={uploadForm.progress.percentage}
                            max="100"
                            class="h-2 w-40 overflow-hidden rounded-full"
                        >
                            {uploadForm.progress.percentage}%
                        </progress>
                    {/if}
                </div>
            {:else if uploadError}
                <p class="mt-3 text-sm text-destructive" role="alert">
                    {uploadError}
                </p>
            {/if}

            {#if isEmpty}
                <p
                    class="border-b py-16 text-center text-sm text-muted-foreground"
                >
                    {t('No materials match these filters')}
                </p>
            {:else}
                <ul class="divide-y">
                    {#if !activeFolder}
                        {#each folders as folder (folder.id)}
                            <li
                                class="group flex min-h-24 items-center gap-3 py-4"
                            >
                                <Link
                                    href={ProjectMaterialController.index(
                                        routeArguments,
                                        { query: { folder: folder.id } },
                                    )}
                                    class="flex min-w-0 flex-1 items-center gap-4 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <span
                                        class="flex size-14 shrink-0 items-center justify-center text-muted-foreground"
                                    >
                                        <Folder
                                            class="size-12 fill-muted"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <span class="min-w-0">
                                        <span
                                            class="block truncate text-lg font-bold group-hover:underline"
                                        >
                                            {folder.name}
                                        </span>
                                        <span
                                            class="block text-sm text-muted-foreground"
                                        >
                                            {t(':count items', {
                                                count: folder.itemCount.toString(),
                                            })}
                                        </span>
                                    </span>
                                </Link>

                                {#if folder.canDelete}
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            {#snippet children(triggerProps)}
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    class="sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100"
                                                    aria-label={t(
                                                        'Folder actions',
                                                    )}
                                                    onclick={triggerProps.onclick}
                                                    aria-expanded={triggerProps[
                                                        'aria-expanded'
                                                    ]}
                                                    data-state={triggerProps[
                                                        'data-state'
                                                    ]}
                                                >
                                                    <Ellipsis />
                                                </Button>
                                            {/snippet}
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuGroup>
                                                <Form
                                                    {...ProjectMaterialController.destroyFolder.form(
                                                        [
                                                            currentTeam.slug,
                                                            project.id,
                                                            folder.id,
                                                        ],
                                                    )}
                                                    onBefore={confirmDelete}
                                                >
                                                    {#snippet children({
                                                        processing,
                                                    })}
                                                        <DropdownMenuItem
                                                            asChild
                                                        >
                                                            {#snippet children(
                                                                itemProps,
                                                            )}
                                                                <button
                                                                    type="submit"
                                                                    class={itemProps.class}
                                                                    disabled={processing}
                                                                >
                                                                    <Trash2
                                                                        aria-hidden="true"
                                                                    />
                                                                    {t(
                                                                        'Delete',
                                                                    )}
                                                                </button>
                                                            {/snippet}
                                                        </DropdownMenuItem>
                                                    {/snippet}
                                                </Form>
                                            </DropdownMenuGroup>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                {/if}
                            </li>
                        {/each}
                    {/if}

                    {#each items as item (`${item.type}-${item.id}`)}
                        <li class="group flex min-h-20 items-center gap-4 py-4">
                            {#if item.type === 'file'}
                                <ProjectFileThumbnail
                                    teamSlug={currentTeam.slug}
                                    projectId={project.id}
                                    fileId={item.id}
                                    mimeType={item.mimeType}
                                    thumbnailUrl={item.thumbnailUrl}
                                    variant="index"
                                />
                            {:else}
                                <span
                                    class="flex size-12 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground"
                                >
                                    {#if item.type === 'document'}
                                        <FileText
                                            class="size-7"
                                            aria-hidden="true"
                                        />
                                    {:else}
                                        <LinkIcon
                                            class="size-7"
                                            aria-hidden="true"
                                        />
                                    {/if}
                                </span>
                            {/if}

                            <div class="min-w-0 flex-1">
                                {#if item.type === 'link' && item.url}
                                    <a
                                        href={item.url}
                                        target="_blank"
                                        rel="noreferrer"
                                        class="inline-flex max-w-full items-center gap-1 font-bold hover:underline"
                                    >
                                        <span class="truncate">
                                            {item.title}
                                        </span>
                                        <ExternalLinkIcon
                                            class="size-3.5 shrink-0"
                                            aria-hidden="true"
                                        />
                                    </a>
                                {:else if item.type === 'file'}
                                    <Link
                                        href={ProjectFileController.show([
                                            currentTeam.slug,
                                            project.id,
                                            item.id,
                                        ])}
                                        class="block truncate font-bold hover:underline"
                                    >
                                        {item.title}
                                    </Link>
                                {:else}
                                    <Link
                                        href={ProjectDocumentController.index(
                                            routeArguments,
                                        )}
                                        class="block truncate font-bold hover:underline"
                                    >
                                        {item.title}
                                    </Link>
                                {/if}

                                <p
                                    class="mt-1 truncate text-sm text-muted-foreground"
                                >
                                    {typeLabel(item.type)}
                                    · {item.updaterName || t('Deleted user')}
                                    {#if item.updatedAt}
                                        · {formatDate(item.updatedAt)}
                                    {/if}
                                    {#if item.folderName}
                                        · {item.folderName}
                                    {/if}
                                </p>
                            </div>

                            {#if item.canDelete}
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        {#snippet children(triggerProps)}
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100"
                                                aria-label={t('Item actions')}
                                                onclick={triggerProps.onclick}
                                                aria-expanded={triggerProps[
                                                    'aria-expanded'
                                                ]}
                                                data-state={triggerProps[
                                                    'data-state'
                                                ]}
                                            >
                                                <Ellipsis />
                                            </Button>
                                        {/snippet}
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuGroup>
                                            {#if item.type === 'file'}
                                                <DropdownMenuItem asChild>
                                                    {#snippet children(
                                                        itemProps,
                                                    )}
                                                        <button
                                                            type="button"
                                                            class={itemProps.class}
                                                            onclick={() => {
                                                                itemProps.onClick?.();
                                                                openRenameDialog(
                                                                    item,
                                                                );
                                                            }}
                                                        >
                                                            <Pencil
                                                                aria-hidden="true"
                                                            />
                                                            {t('Rename')}
                                                        </button>
                                                    {/snippet}
                                                </DropdownMenuItem>
                                            {/if}

                                            {#if item.type !== 'link'}
                                                <DropdownMenuItem asChild>
                                                    {#snippet children(
                                                        itemProps,
                                                    )}
                                                        <button
                                                            type="button"
                                                            class={itemProps.class}
                                                            onclick={() => {
                                                                itemProps.onClick?.();
                                                                openMoveDialog(
                                                                    item,
                                                                );
                                                            }}
                                                        >
                                                            <Folder
                                                                aria-hidden="true"
                                                            />
                                                            {t(
                                                                'Move to folder',
                                                            )}
                                                        </button>
                                                    {/snippet}
                                                </DropdownMenuItem>
                                            {/if}

                                            {#if item.type === 'file'}
                                                <Form
                                                    {...ProjectFileController.destroy.form(
                                                        [
                                                            currentTeam.slug,
                                                            project.id,
                                                            item.id,
                                                        ],
                                                    )}
                                                    onBefore={confirmDelete}
                                                >
                                                    {#snippet children({
                                                        processing,
                                                    })}
                                                        <DropdownMenuItem
                                                            asChild
                                                        >
                                                            {#snippet children(
                                                                itemProps,
                                                            )}
                                                                <button
                                                                    type="submit"
                                                                    class={itemProps.class}
                                                                    disabled={processing}
                                                                >
                                                                    <Trash2
                                                                        aria-hidden="true"
                                                                    />
                                                                    {t(
                                                                        'Delete',
                                                                    )}
                                                                </button>
                                                            {/snippet}
                                                        </DropdownMenuItem>
                                                    {/snippet}
                                                </Form>
                                            {:else if item.type === 'link'}
                                                <Form
                                                    {...ProjectMaterialController.destroyLink.form(
                                                        [
                                                            currentTeam.slug,
                                                            project.id,
                                                            item.id,
                                                        ],
                                                    )}
                                                    onBefore={confirmDelete}
                                                >
                                                    {#snippet children({
                                                        processing,
                                                    })}
                                                        <DropdownMenuItem
                                                            asChild
                                                        >
                                                            {#snippet children(
                                                                itemProps,
                                                            )}
                                                                <button
                                                                    type="submit"
                                                                    class={itemProps.class}
                                                                    disabled={processing}
                                                                >
                                                                    <Trash2
                                                                        aria-hidden="true"
                                                                    />
                                                                    {t(
                                                                        'Delete',
                                                                    )}
                                                                </button>
                                                            {/snippet}
                                                        </DropdownMenuItem>
                                                    {/snippet}
                                                </Form>
                                            {/if}
                                        </DropdownMenuGroup>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            {/if}
                        </li>
                    {/each}
                </ul>
            {/if}
        </div>
    </main>
</div>

<Dialog bind:open={folderDialogOpen}>
    <DialogContent class="sm:max-w-md">
        <DialogTitle>{t('Create folder')}</DialogTitle>
        <Form
            {...ProjectMaterialController.storeFolder.form(routeArguments)}
            resetOnSuccess
            onSuccess={() => (folderDialogOpen = false)}
            class="flex flex-col gap-4"
        >
            {#snippet children({ errors, processing })}
                <div>
                    <Label for="material-folder-name">{t('Folder name')}</Label>
                    <Input
                        id="material-folder-name"
                        name="name"
                        maxlength={255}
                        required
                        class="mt-2"
                    />
                    <InputError message={errors.name} />
                </div>
                <DialogFooter class="gap-2">
                    <DialogClose>
                        <Button type="button" variant="outline">
                            {t('Cancel')}
                        </Button>
                    </DialogClose>
                    <Button type="submit" disabled={processing}>
                        {t('Add')}
                    </Button>
                </DialogFooter>
            {/snippet}
        </Form>
    </DialogContent>
</Dialog>

<Dialog bind:open={linkDialogOpen}>
    <DialogContent class="sm:max-w-md">
        <DialogTitle>{t('Add external link')}</DialogTitle>
        <Form
            {...ProjectMaterialController.storeLink.form(routeArguments)}
            resetOnSuccess
            onSuccess={() => (linkDialogOpen = false)}
            class="flex flex-col gap-4"
        >
            {#snippet children({ errors, processing })}
                <div>
                    <Label for="external-link-title">{t('Title')}</Label>
                    <Input
                        id="external-link-title"
                        name="title"
                        maxlength={255}
                        required
                        class="mt-2"
                    />
                    <InputError message={errors.title} />
                </div>
                <div>
                    <Label for="external-link-url">URL</Label>
                    <Input
                        id="external-link-url"
                        name="url"
                        type="url"
                        maxlength={2048}
                        placeholder="https://"
                        required
                        class="mt-2"
                    />
                    <InputError message={errors.url} />
                </div>
                <div>
                    <Label for="external-link-folder">{t('Folder')}</Label>
                    <select
                        id="external-link-folder"
                        name="material_folder_id"
                        class="border-input mt-2 h-10 w-full rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="">{t('No folder')}</option>
                        {#each folders as folder (folder.id)}
                            <option
                                value={folder.id}
                                selected={activeFolder?.id === folder.id}
                            >
                                {folder.name}
                            </option>
                        {/each}
                    </select>
                    <InputError message={errors.material_folder_id} />
                </div>
                <DialogFooter class="gap-2">
                    <DialogClose>
                        <Button type="button" variant="outline">
                            {t('Cancel')}
                        </Button>
                    </DialogClose>
                    <Button type="submit" disabled={processing}>
                        {t('Add link')}
                    </Button>
                </DialogFooter>
            {/snippet}
        </Form>
    </DialogContent>
</Dialog>

<Dialog bind:open={moveDialogOpen}>
    <DialogContent class="sm:max-w-md">
        <DialogTitle>{t('Move to folder')}</DialogTitle>
        {#if movingItem && movingItem.type !== 'link'}
            {@const itemToMove = movingItem}
            <Form
                {...itemToMove.type === 'document'
                    ? ProjectMaterialController.moveDocument.form([
                          currentTeam.slug,
                          project.id,
                          itemToMove.id,
                      ])
                    : ProjectMaterialController.moveFile.form([
                          currentTeam.slug,
                          project.id,
                          itemToMove.id,
                      ])}
                onSuccess={() => (moveDialogOpen = false)}
                class="flex flex-col gap-4"
            >
                {#snippet children({ processing, errors })}
                    <div>
                        <Label for="move-material-folder">{t('Folder')}</Label>
                        <select
                            id="move-material-folder"
                            name="material_folder_id"
                            class="border-input mt-2 h-10 w-full rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">{t('No folder')}</option>
                            {#each folders as folder (folder.id)}
                                <option
                                    value={folder.id}
                                    selected={itemToMove.folderId === folder.id}
                                >
                                    {folder.name}
                                </option>
                            {/each}
                        </select>
                        <InputError message={errors.material_folder_id} />
                    </div>
                    <DialogFooter class="gap-2">
                        <DialogClose>
                            <Button type="button" variant="outline">
                                {t('Cancel')}
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {t('Move')}
                        </Button>
                    </DialogFooter>
                {/snippet}
            </Form>
        {/if}
    </DialogContent>
</Dialog>

<Dialog bind:open={renameDialogOpen}>
    <DialogContent class="sm:max-w-md">
        <DialogTitle>{t('Rename')}</DialogTitle>
        {#if renamingFile && renamingFile.type === 'file'}
            {@const fileToRename = renamingFile}
            <Form
                {...ProjectFileController.update.form([
                    currentTeam.slug,
                    project.id,
                    fileToRename.id,
                ])}
                onSuccess={() => (renameDialogOpen = false)}
                class="flex flex-col gap-4"
            >
                {#snippet children({ errors, processing })}
                    <div>
                        <Label for="material-file-name">{t('Name')}</Label>
                        <Input
                            id="material-file-name"
                            name="original_name"
                            value={fileToRename.title}
                            maxlength={255}
                            required
                            class="mt-2"
                        />
                        <InputError message={errors.original_name} />
                    </div>
                    <DialogFooter class="gap-2">
                        <DialogClose>
                            <Button type="button" variant="outline">
                                {t('Cancel')}
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {t('Save')}
                        </Button>
                    </DialogFooter>
                {/snippet}
            </Form>
        {/if}
    </DialogContent>
</Dialog>
