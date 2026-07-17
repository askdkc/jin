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
    import { Form, Link } from '@inertiajs/svelte';
    import ChevronLeft from 'lucide-svelte/icons/chevron-left';
    import Cloud from 'lucide-svelte/icons/cloud';
    import ExternalLinkIcon from 'lucide-svelte/icons/external-link';
    import Eye from 'lucide-svelte/icons/eye';
    import FileIcon from 'lucide-svelte/icons/file';
    import FilePlus from 'lucide-svelte/icons/file-plus';
    import FileText from 'lucide-svelte/icons/file-text';
    import Folder from 'lucide-svelte/icons/folder';
    import FolderPlus from 'lucide-svelte/icons/folder-plus';
    import LinkIcon from 'lucide-svelte/icons/link';
    import Plus from 'lucide-svelte/icons/plus';
    import Upload from 'lucide-svelte/icons/upload';
    import ProjectDocumentController from '@/actions/App/Http/Controllers/ProjectDocumentController';
    import ProjectFileController from '@/actions/App/Http/Controllers/ProjectFileController';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import StoredFilePreviewDialog, {
        canPreviewStoredFile,
    } from '@/components/StoredFilePreviewDialog.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import {
        Card,
        CardContent,
        CardHeader,
        CardTitle,
    } from '@/components/ui/card';
    import {
        DropdownMenu,
        DropdownMenuContent,
        DropdownMenuItem,
        DropdownMenuLabel,
        DropdownMenuSeparator,
        DropdownMenuTrigger,
    } from '@/components/ui/dropdown-menu';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
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

    let previewOpen = $state(false);
    let previewFile = $state<{ id: number; name: string } | null>(null);

    const cloudProviders = [
        'Google Drive',
        'OneDrive',
        'iCloud Drive',
    ] as const;
    type CloudProvider = (typeof cloudProviders)[number];
    const cloudProviderHints: Record<CloudProvider, string> = {
        'Google Drive': t('Paste a Google Drive share link'),
        OneDrive: t('Paste a OneDrive share link'),
        'iCloud Drive': t('Paste an iCloud Drive share link'),
    };
    let externalLinkHint = $state('');

    const focusField = (id: string): void => {
        requestAnimationFrame(() => {
            const field = document.getElementById(id);
            field?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            (field as HTMLInputElement | null)?.focus();
        });
    };
    const linkFromCloud = (provider: CloudProvider): void => {
        externalLinkHint = cloudProviderHints[provider];
        focusField('external-link-url');
    };

    const formatDate = (value: string | null): string =>
        value
            ? new Intl.DateTimeFormat('ja-JP', {
                  dateStyle: 'medium',
                  timeStyle: 'short',
              }).format(new Date(value))
            : '';
    const typeLabel = (type: MaterialItem['type']): string =>
        ({
            document: t('Document'),
            file: t('File'),
            link: t('External link'),
        })[type];
    const confirmDelete = (event: SubmitEvent): void => {
        if (!window.confirm(t('Are you sure you want to delete this?'))) {
            event.preventDefault();
        }
    };
    const openPreview = (item: MaterialItem): void => {
        previewFile = { id: item.id, name: item.title };
        previewOpen = true;
    };
</script>

<AppHead title={t('Materials')} />

<div class="mx-auto w-full max-w-6xl space-y-6 p-4 sm:p-6">
    <div
        class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
    >
        <div>
            {#if activeFolder}
                <Link
                    href={ProjectMaterialController.index(routeArguments)}
                    class="mb-2 inline-flex items-center gap-1 text-sm font-medium text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                >
                    <ChevronLeft class="size-4" />
                    {t('Materials')}
                </Link>
                <h1
                    class="flex items-center gap-2 text-2xl font-semibold tracking-tight"
                >
                    <Folder class="size-6 text-muted-foreground" />
                    {activeFolder.name}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {project.name} · {t(':count items', {
                        count: activeFolder.itemCount.toString(),
                    })}
                </p>
            {:else}
                <h1 class="text-2xl font-semibold tracking-tight">
                    {t('Materials')}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {project.name} · {t(
                        'Documents, files, and links in one place',
                    )}
                </p>
            {/if}
        </div>
        <div class="flex flex-wrap gap-2">
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    {#snippet children(triggerProps)}
                        <Button
                            size="sm"
                            onclick={triggerProps.onclick}
                            aria-expanded={triggerProps['aria-expanded']}
                            data-state={triggerProps['data-state']}
                        >
                            <Plus class="size-4" />
                            {t('Add')}
                        </Button>
                    {/snippet}
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem asChild>
                        {#snippet children(itemProps)}
                            <Link
                                href={ProjectDocumentController.index(
                                    routeArguments,
                                )}
                                class={itemProps.class}
                                onclick={() => itemProps.onClick?.()}
                            >
                                <FilePlus class="size-4" />
                                {t('New document')}
                            </Link>
                        {/snippet}
                    </DropdownMenuItem>
                    <DropdownMenuItem asChild>
                        {#snippet children(itemProps)}
                            <Link
                                href={ProjectFileController.index(
                                    routeArguments,
                                )}
                                class={itemProps.class}
                                onclick={() => itemProps.onClick?.()}
                            >
                                <Upload class="size-4" />
                                {t('Upload file')}
                            </Link>
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
                                        focusField('material-folder-name');
                                    }}
                                >
                                    <FolderPlus class="size-4" />
                                    {t('Create folder')}
                                </button>
                            {/snippet}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel>
                            {t('Link files from an external service')}
                        </DropdownMenuLabel>
                        {#each cloudProviders as provider (provider)}
                            <DropdownMenuItem asChild>
                                {#snippet children(itemProps)}
                                    <button
                                        type="button"
                                        class={itemProps.class}
                                        onclick={() => {
                                            itemProps.onClick?.();
                                            linkFromCloud(provider);
                                        }}
                                    >
                                        <Cloud class="size-4" />
                                        {provider}
                                    </button>
                                {/snippet}
                            </DropdownMenuItem>
                        {/each}
                    {/if}
                </DropdownMenuContent>
            </DropdownMenu>
            <Button variant="outline" size="sm" asChild>
                {#snippet children(buttonProps)}
                    <Link
                        {...buttonProps}
                        href={ProjectDocumentController.index(routeArguments)}
                        class={buttonProps.class}
                    >
                        <FileText class="size-4" />
                        {t('Documents')}
                    </Link>
                {/snippet}
            </Button>
            <Button variant="outline" size="sm" asChild>
                {#snippet children(buttonProps)}
                    <Link
                        {...buttonProps}
                        href={ProjectFileController.index(routeArguments)}
                        class={buttonProps.class}
                    >
                        <FileIcon class="size-4" />
                        {t('Files')}
                    </Link>
                {/snippet}
            </Button>
        </div>
    </div>

    {#if !activeFolder}
        <div class="grid gap-5 lg:grid-cols-2">
            <Card>
                <CardHeader><CardTitle>{t('Folders')}</CardTitle></CardHeader>
                <CardContent class="space-y-4">
                    <Form
                        {...ProjectMaterialController.storeFolder.form(
                            routeArguments,
                        )}
                        resetOnSuccess
                        class="flex items-start gap-2"
                    >
                        {#snippet children({ errors, processing })}
                            <div class="min-w-0 flex-1">
                                <Label
                                    for="material-folder-name"
                                    class="sr-only"
                                >
                                    {t('Folder name')}
                                </Label>
                                <Input
                                    id="material-folder-name"
                                    name="name"
                                    maxlength={255}
                                    placeholder={t('Folder name')}
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>
                            <Button type="submit" disabled={processing}>
                                {t('Add')}
                            </Button>
                        {/snippet}
                    </Form>

                    {#if folders.length === 0}
                        <p class="text-sm text-muted-foreground">
                            {t('No folders yet')}
                        </p>
                    {:else}
                        <div class="space-y-2">
                            {#each folders as folder (folder.id)}
                                <div
                                    class="flex min-w-0 items-center gap-2 rounded-lg border p-1.5"
                                >
                                    <Link
                                        href={ProjectMaterialController.index(
                                            routeArguments,
                                            { query: { folder: folder.id } },
                                        )}
                                        class="flex min-w-0 flex-1 items-center gap-3 rounded-md px-2 py-1.5 text-sm font-medium transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        <Folder
                                            class="size-4 shrink-0 text-muted-foreground"
                                        />
                                        <span class="min-w-0 flex-1 truncate">
                                            {folder.name}
                                        </span>
                                        <Badge variant="secondary">
                                            {folder.itemCount}
                                        </Badge>
                                    </Link>
                                    {#if folder.canDelete}
                                        <Form
                                            {...ProjectMaterialController.destroyFolder.form(
                                                [
                                                    currentTeam.slug,
                                                    project.id,
                                                    folder.id,
                                                ],
                                            )}
                                            onsubmit={confirmDelete}
                                        >
                                            {#snippet children({ processing })}
                                                <Button
                                                    type="submit"
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    {t('Delete')}
                                                </Button>
                                            {/snippet}
                                        </Form>
                                    {/if}
                                </div>
                            {/each}
                        </div>
                    {/if}
                </CardContent>
            </Card>

            <Card>
                <CardHeader
                    ><CardTitle>{t('Add external link')}</CardTitle></CardHeader
                >
                <CardContent>
                    <Form
                        {...ProjectMaterialController.storeLink.form(
                            routeArguments,
                        )}
                        resetOnSuccess
                        class="space-y-3"
                    >
                        {#snippet children({ errors, processing })}
                            <div class="grid gap-2">
                                <Label for="external-link-title"
                                    >{t('Title')}</Label
                                >
                                <Input
                                    id="external-link-title"
                                    name="title"
                                    maxlength={255}
                                    required
                                />
                                <InputError message={errors.title} />
                            </div>
                            <div class="grid gap-2">
                                <Label for="external-link-url">URL</Label>
                                <Input
                                    id="external-link-url"
                                    name="url"
                                    type="url"
                                    maxlength={2048}
                                    placeholder="https://"
                                    required
                                />
                                {#if externalLinkHint}
                                    <p class="text-xs text-muted-foreground">
                                        {externalLinkHint}
                                    </p>
                                {/if}
                                <InputError message={errors.url} />
                            </div>
                            <div class="grid gap-2">
                                <Label for="external-link-folder"
                                    >{t('Folder')}</Label
                                >
                                <select
                                    id="external-link-folder"
                                    name="material_folder_id"
                                    class="border-input h-10 w-full rounded-md border bg-background px-3 text-sm"
                                >
                                    <option value="">{t('No folder')}</option>
                                    {#each folders as folder (folder.id)}
                                        <option value={folder.id}
                                            >{folder.name}</option
                                        >
                                    {/each}
                                </select>
                                <InputError
                                    message={errors.material_folder_id}
                                />
                            </div>
                            <Button type="submit" disabled={processing}>
                                {t('Add link')}
                            </Button>
                        {/snippet}
                    </Form>
                </CardContent>
            </Card>
        </div>
    {/if}

    <Card>
        <CardHeader><CardTitle>{t('Filter materials')}</CardTitle></CardHeader>
        <CardContent>
            <Form
                {...ProjectMaterialController.index.form(routeArguments)}
                class="grid gap-3 sm:grid-cols-4"
            >
                {#snippet children({ processing })}
                    <div class="grid gap-2">
                        <Label for="material-type">{t('Type')}</Label>
                        <select
                            id="material-type"
                            name="type"
                            class="border-input h-10 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">{t('All types')}</option>
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
                    </div>
                    <div class="grid gap-2">
                        <Label for="material-folder-filter">{t('Folder')}</Label
                        >
                        <select
                            id="material-folder-filter"
                            name="folder"
                            class="border-input h-10 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">{t('All folders')}</option>
                            {#each folders as folder (folder.id)}
                                <option
                                    value={folder.id}
                                    selected={filters.folder === folder.id}
                                >
                                    {folder.name}
                                </option>
                            {/each}
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="material-updater">{t('Added by')}</Label>
                        <select
                            id="material-updater"
                            name="updater"
                            class="border-input h-10 rounded-md border bg-background px-3 text-sm"
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
                    </div>
                    <div class="flex items-end gap-2">
                        <Button type="submit" disabled={processing}>
                            {t('Filter')}
                        </Button>
                        <Button variant="outline" asChild>
                            {#snippet children(buttonProps)}
                                <Link
                                    {...buttonProps}
                                    href={ProjectMaterialController.index(
                                        routeArguments,
                                    )}
                                    class={buttonProps.class}
                                >
                                    {t('Clear')}
                                </Link>
                            {/snippet}
                        </Button>
                    </div>
                {/snippet}
            </Form>
        </CardContent>
    </Card>

    {#if items.length === 0}
        <Card>
            <CardContent
                class="py-12 text-center text-sm text-muted-foreground"
            >
                {t('No materials match these filters')}
            </CardContent>
        </Card>
    {:else}
        <div class="grid gap-4 md:grid-cols-2">
            {#each items as item (`${item.type}-${item.id}`)}
                <Card class="min-w-0">
                    <CardContent class="space-y-4 pt-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <div
                                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted"
                            >
                                {#if item.type === 'document'}
                                    <FileText class="size-5" />
                                {:else if item.type === 'file'}
                                    <FileIcon class="size-5" />
                                {:else}
                                    <LinkIcon class="size-5" />
                                {/if}
                            </div>
                            <div class="min-w-0 flex-1">
                                {#if item.type === 'link' && item.url}
                                    <a
                                        href={item.url}
                                        target="_blank"
                                        rel="noreferrer"
                                        class="flex min-w-0 items-center gap-1 break-all font-medium hover:underline"
                                    >
                                        {item.title}
                                        <ExternalLinkIcon
                                            class="size-3 shrink-0"
                                        />
                                    </a>
                                {:else if item.type === 'file' && canPreviewStoredFile(item.title)}
                                    <button
                                        type="button"
                                        class="flex w-full cursor-zoom-in items-start gap-1.5 break-all text-left font-medium underline-offset-4 hover:text-primary hover:underline focus-visible:rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        title={t('Preview')}
                                        onclick={() => openPreview(item)}
                                    >
                                        <span class="min-w-0 flex-1">
                                            {item.title}
                                        </span>
                                        <Eye class="mt-0.5 size-4 shrink-0" />
                                    </button>
                                {:else if item.type === 'file'}
                                    <a
                                        href={ProjectFileController.download.url(
                                            [
                                                currentTeam.slug,
                                                project.id,
                                                item.id,
                                            ],
                                        )}
                                        class="break-all font-medium hover:underline"
                                    >
                                        {item.title}
                                    </a>
                                {:else}
                                    <Link
                                        href={ProjectDocumentController.index(
                                            routeArguments,
                                        )}
                                        class="break-words font-medium hover:underline"
                                    >
                                        {item.title}
                                    </Link>
                                {/if}
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {item.updaterName || t('Deleted user')} · {formatDate(
                                        item.updatedAt,
                                    )}
                                </p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <Badge variant="secondary">
                                        {typeLabel(item.type)}
                                    </Badge>
                                    {#if item.folderName}
                                        <Badge variant="outline">
                                            {item.folderName}
                                        </Badge>
                                    {/if}
                                </div>
                            </div>
                        </div>

                        {#if item.type !== 'link' && item.canDelete}
                            <Form
                                {...item.type === 'document'
                                    ? ProjectMaterialController.moveDocument.form(
                                          [
                                              currentTeam.slug,
                                              project.id,
                                              item.id,
                                          ],
                                      )
                                    : ProjectMaterialController.moveFile.form([
                                          currentTeam.slug,
                                          project.id,
                                          item.id,
                                      ])}
                                class="flex items-end gap-2"
                            >
                                {#snippet children({ processing, errors })}
                                    <div class="min-w-0 flex-1">
                                        <Label
                                            for={`material-folder-${item.type}-${item.id}`}
                                        >
                                            {t('Move to folder')}
                                        </Label>
                                        <select
                                            id={`material-folder-${item.type}-${item.id}`}
                                            name="material_folder_id"
                                            class="border-input mt-1 h-9 w-full rounded-md border bg-background px-2 text-sm"
                                        >
                                            <option value=""
                                                >{t('No folder')}</option
                                            >
                                            {#each folders as folder (folder.id)}
                                                <option
                                                    value={folder.id}
                                                    selected={item.folderId ===
                                                        folder.id}
                                                >
                                                    {folder.name}
                                                </option>
                                            {/each}
                                        </select>
                                        <InputError
                                            message={errors.material_folder_id}
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        {t('Move')}
                                    </Button>
                                {/snippet}
                            </Form>
                        {/if}

                        {#if item.type === 'link' && item.canDelete}
                            <Form
                                {...ProjectMaterialController.destroyLink.form([
                                    currentTeam.slug,
                                    project.id,
                                    item.id,
                                ])}
                                onsubmit={confirmDelete}
                            >
                                {#snippet children({ processing })}
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        {t('Delete')}
                                    </Button>
                                {/snippet}
                            </Form>
                        {/if}
                    </CardContent>
                </Card>
            {/each}
        </div>
    {/if}
</div>

<StoredFilePreviewDialog
    {currentTeam}
    {project}
    file={previewFile}
    bind:open={previewOpen}
/>
