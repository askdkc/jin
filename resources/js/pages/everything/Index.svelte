<script module lang="ts">
    import EverythingController from '@/actions/App/Http/Controllers/EverythingController';
    import type { Team } from '@/types';

    export const layout = (props: { currentTeam: Team }) => ({
        breadcrumbs: [
            {
                title: 'Everything',
                href: EverythingController(props.currentTeam.slug),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import ChevronDown from 'lucide-svelte/icons/chevron-down';
    import FileText from 'lucide-svelte/icons/file-text';
    import LinkIcon from 'lucide-svelte/icons/link';
    import ListTodo from 'lucide-svelte/icons/list-todo';
    import MessageSquare from 'lucide-svelte/icons/message-square';
    import Search from 'lucide-svelte/icons/search';
    import { onMount } from 'svelte';
    import AppHead from '@/components/AppHead.svelte';
    import ProjectFileThumbnail from '@/components/ProjectFileThumbnail.svelte';
    import StoredFilePreviewDialog, {
        canPreviewStoredFile,
    } from '@/components/StoredFilePreviewDialog.svelte';
    import {
        Avatar,
        AvatarFallback,
        AvatarImage,
    } from '@/components/ui/avatar';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
    import { getInitials } from '@/lib/initials';
    import type {
        EverythingFileCategory,
        EverythingFileItem,
        EverythingFilters,
        EverythingItem,
        EverythingType,
        Paginated,
        ProjectTool,
    } from '@/types';

    let {
        currentTeam,
        results,
        projects,
        filters,
    }: {
        currentTeam: Team;
        results: Paginated<EverythingItem>;
        projects: ProjectTool[];
        filters: EverythingFilters;
    } = $props();

    const fileCategoryOptions = [
        { value: 'image', label: 'Images' },
        { value: 'video', label: 'Videos' },
        { value: 'office', label: 'Office files' },
        { value: 'pdf', label: 'PDF files' },
    ] satisfies Array<{ value: EverythingFileCategory; label: string }>;

    let previewFile = $state<EverythingFileItem | null>(null);
    let previewOpen = $state(false);
    const hasFilters = $derived(
        Boolean(
            filters.q ||
            filters.type ||
            filters.project ||
            filters.fileCategories.length > 0,
        ),
    );
    const hasPaginationNavigation = $derived(
        results.links.some((link) => link.url !== null && !link.active),
    );

    onMount(() => {
        const refinementPanel = document.querySelector<HTMLDetailsElement>(
            '#everything-refinement-panel',
        );

        if (!refinementPanel) {
            return;
        }

        const desktopBreakpoint = window.matchMedia('(min-width: 64rem)');
        const syncRefinementPanel = (): void => {
            refinementPanel.open = desktopBreakpoint.matches;
        };

        syncRefinementPanel();
        desktopBreakpoint.addEventListener('change', syncRefinementPanel);

        return () => {
            desktopBreakpoint.removeEventListener(
                'change',
                syncRefinementPanel,
            );
        };
    });

    const typeLabel = (type: EverythingType): string =>
        ({
            message: t('Message'),
            todo: currentTeam.menuNames.todos,
            document: t('Document'),
            file: t('File'),
            link: t('External link'),
            comment: t('Comment'),
        })[type];
    const formatDate = (value: string | null): string =>
        value
            ? new Intl.DateTimeFormat('ja-JP', {
                  dateStyle: 'medium',
                  timeStyle: 'short',
              }).format(new Date(value))
            : '';
    const formatFileSize = (size: number): string => {
        if (size < 1024) {
            return `${size} B`;
        }

        if (size < 1024 * 1024) {
            return `${(size / 1024).toFixed(1)} KB`;
        }

        return `${(size / (1024 * 1024)).toFixed(1)} MB`;
    };
    const paginationLabel = (
        label: string,
        index: number,
        lastIndex: number,
    ): string =>
        index === 0 ? t('Previous') : index === lastIndex ? t('Next') : label;
    const submitSort = (event: Event): void => {
        (event.currentTarget as HTMLSelectElement).form?.requestSubmit();
    };
    const canShowVisualPreview = (item: EverythingFileItem): boolean =>
        item.canAccessFile &&
        canPreviewStoredFile(item.title) &&
        (item.thumbnailUrl !== null ||
            item.mimeType?.startsWith('image/') === true);
    const openFilePreview = (item: EverythingFileItem): void => {
        previewFile = item;
        previewOpen = true;
    };
</script>

<AppHead title={t('Everything')} />

<div class="min-h-[calc(100vh-7rem)] bg-muted/30 px-3 py-3 sm:px-6 sm:py-4">
    <main
        class="mx-auto w-full max-w-[96rem] overflow-hidden rounded-2xl border bg-card shadow-[0_16px_48px_color-mix(in_oklab,var(--foreground)_7%,transparent)]"
    >
        <div class="grid lg:grid-cols-[13rem_minmax(0,1fr)]">
            <Form
                {...EverythingController.form(currentTeam.slug)}
                id="everything-search-form"
                class="contents"
            >
                {#snippet children({ processing })}
                    <header class="border-b px-4 py-4 sm:px-6 lg:col-span-2">
                        <div
                            class="flex flex-col gap-3 md:flex-row md:items-center"
                        >
                            <h1
                                class="shrink-0 text-2xl font-semibold tracking-tight"
                            >
                                {t('Find everything')}
                            </h1>

                            <div
                                class="grid min-w-0 flex-1 grid-cols-[minmax(0,1fr)_auto] items-center gap-2 md:ml-4 md:flex"
                            >
                                <Label for="everything-query" class="sr-only">
                                    {t('Search')}
                                </Label>
                                <div class="relative min-w-0 md:flex-1">
                                    <Search
                                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <Input
                                        id="everything-query"
                                        name="q"
                                        type="search"
                                        maxlength={100}
                                        value={filters.q ?? ''}
                                        placeholder={t('Search words')}
                                        class="h-9 rounded-lg bg-background pr-3 pl-9 text-sm shadow-sm"
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    class="h-9 rounded-lg px-4"
                                >
                                    <Search class="size-4" aria-hidden="true" />
                                    {t('Search')}
                                </Button>
                                {#if hasFilters}
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="col-span-2 justify-self-start md:col-span-1"
                                        asChild
                                    >
                                        {#snippet children(buttonProps)}
                                            <Link
                                                {...buttonProps}
                                                href={EverythingController(
                                                    currentTeam.slug,
                                                )}
                                                class={buttonProps.class}
                                            >
                                                {t('Clear')}
                                            </Link>
                                        {/snippet}
                                    </Button>
                                {/if}
                            </div>
                        </div>
                    </header>

                    <details
                        id="everything-refinement-panel"
                        class="group border-b lg:row-start-2 lg:border-r lg:border-b-0"
                    >
                        <summary
                            class="flex cursor-pointer list-none items-center justify-between px-4 py-2.5 text-sm font-semibold marker:hidden sm:px-6 lg:hidden"
                        >
                            <span>{t('Refine results')}</span>
                            <ChevronDown
                                class="size-4 transition-transform group-open:rotate-180"
                                aria-hidden="true"
                            />
                        </summary>
                        <div
                            class="grid gap-4 px-4 pb-4 sm:px-6 lg:sticky lg:top-16 lg:px-4 lg:py-5"
                        >
                            <div class="hidden lg:block">
                                <h2 class="text-sm font-semibold">
                                    {t('Refine results')}
                                </h2>
                            </div>

                            <div class="grid gap-1.5">
                                <Label for="everything-type">{t('Type')}</Label>
                                <select
                                    id="everything-type"
                                    name="type"
                                    class="border-input h-8 w-full rounded-md border bg-background px-2.5 text-sm outline-none focus:border-ring focus:ring-2 focus:ring-ring/20"
                                >
                                    <option value="">{t('All types')}</option>
                                    <option
                                        value="message"
                                        selected={filters.type === 'message'}
                                    >
                                        {t('Messages')}
                                    </option>
                                    <option
                                        value="todo"
                                        selected={filters.type === 'todo'}
                                    >
                                        {currentTeam.menuNames.todos}
                                    </option>
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
                                    <option
                                        value="comment"
                                        selected={filters.type === 'comment'}
                                    >
                                        {t('Comments')}
                                    </option>
                                </select>
                            </div>

                            <fieldset class="grid gap-1.5">
                                <legend class="text-sm font-medium">
                                    {t('File categories')}
                                </legend>
                                <div
                                    class="grid gap-1 sm:grid-cols-2 lg:grid-cols-1"
                                >
                                    {#each fileCategoryOptions as fileCategory (fileCategory.value)}
                                        <label
                                            class="flex min-h-7 cursor-pointer items-center gap-2 rounded px-1 text-sm text-muted-foreground transition-colors hover:bg-muted/50 hover:text-foreground"
                                        >
                                            <input
                                                type="checkbox"
                                                name="file_categories[]"
                                                value={fileCategory.value}
                                                checked={filters.fileCategories.includes(
                                                    fileCategory.value,
                                                )}
                                                class="size-4 shrink-0 accent-primary outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                            />
                                            <span>{t(fileCategory.label)}</span>
                                        </label>
                                    {/each}
                                </div>
                            </fieldset>

                            <div class="grid gap-1.5">
                                <Label for="everything-project">
                                    {t('Project')}
                                </Label>
                                <select
                                    id="everything-project"
                                    name="project"
                                    class="border-input h-8 w-full rounded-md border bg-background px-2.5 text-sm outline-none focus:border-ring focus:ring-2 focus:ring-ring/20"
                                >
                                    <option value="">{t('All projects')}</option
                                    >
                                    {#each projects as project (project.id)}
                                        <option
                                            value={project.id}
                                            selected={filters.project ===
                                                project.id}
                                        >
                                            {project.name}
                                        </option>
                                    {/each}
                                </select>
                            </div>

                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                                class="w-full"
                            >
                                {t('Filter')}
                            </Button>
                        </div>
                    </details>
                {/snippet}
            </Form>

            <section
                class="min-w-0 px-4 py-4 sm:px-6 sm:py-5 lg:col-start-2 lg:row-start-2"
                aria-labelledby="everything-results-heading"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b pb-3"
                >
                    <h2 id="everything-results-heading" class="font-bold">
                        {t(':count results', {
                            count: String(results.total),
                        })}
                    </h2>
                    <div>
                        <Label for="everything-sort" class="sr-only">
                            {t('Sort results')}
                        </Label>
                        <select
                            id="everything-sort"
                            name="sort"
                            form="everything-search-form"
                            onchange={submitSort}
                            aria-label={t('Sort results')}
                            class="border-input h-8 rounded-full border bg-muted px-3 text-xs font-semibold text-muted-foreground outline-none focus:border-ring focus:ring-2 focus:ring-ring/20"
                        >
                            <option
                                value="updated_desc"
                                selected={filters.sort === 'updated_desc'}
                            >
                                {t('Most recent')}
                            </option>
                            <option
                                value="updated_asc"
                                selected={filters.sort === 'updated_asc'}
                            >
                                {t('Oldest updated')}
                            </option>
                            <option
                                value="name_asc"
                                selected={filters.sort === 'name_asc'}
                            >
                                {t('Name ascending')}
                            </option>
                            <option
                                value="name_desc"
                                selected={filters.sort === 'name_desc'}
                            >
                                {t('Name descending')}
                            </option>
                        </select>
                    </div>
                </div>

                {#if results.data.length === 0}
                    <div
                        class="flex min-h-64 flex-col items-center justify-center px-4 py-12 text-center"
                    >
                        <span
                            class="mb-4 flex size-14 items-center justify-center rounded-full bg-muted text-muted-foreground"
                        >
                            <Search class="size-7" aria-hidden="true" />
                        </span>
                        <h3 class="text-lg font-bold">
                            {t('No matching items')}
                        </h3>
                        <p
                            class="mt-2 max-w-md text-sm leading-6 text-muted-foreground"
                        >
                            {hasFilters
                                ? t(
                                      'Try changing or clearing your search filters.',
                                  )
                                : t(
                                      'Searchable project content will appear here.',
                                  )}
                        </p>
                        {#if hasFilters}
                            <Button variant="outline" class="mt-5" asChild>
                                {#snippet children(buttonProps)}
                                    <Link
                                        {...buttonProps}
                                        href={EverythingController(
                                            currentTeam.slug,
                                        )}
                                        class={buttonProps.class}
                                    >
                                        {t('Clear')}
                                    </Link>
                                {/snippet}
                            </Button>
                        {/if}
                    </div>
                {:else}
                    <ol class="divide-y">
                        {#each results.data as item (`${item.type}-${item.id}`)}
                            <li class="py-3.5">
                                {#if item.type === 'file'}
                                    <article class="min-w-0">
                                        <div
                                            class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground"
                                        >
                                            <span
                                                class="font-semibold text-foreground"
                                            >
                                                {typeLabel(item.type)}
                                            </span>
                                            <span aria-hidden="true">·</span>
                                            <span class="break-words">
                                                {item.project.name}
                                            </span>
                                            <span aria-hidden="true">·</span>
                                            <time datetime={item.updatedAt}>
                                                {formatDate(item.updatedAt)}
                                            </time>
                                        </div>

                                        <Link
                                            href={item.href}
                                            class="mt-2 block break-words text-lg font-semibold text-sky-700 underline decoration-sky-300 underline-offset-4 hover:text-sky-900 dark:text-sky-400 dark:decoration-sky-700 dark:hover:text-sky-200"
                                        >
                                            {item.title}
                                        </Link>

                                        <div
                                            class="mt-3 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
                                        >
                                            <Avatar class="size-6 border">
                                                {#if item.uploader?.avatar}
                                                    <AvatarImage
                                                        src={item.uploader
                                                            .avatar}
                                                        alt=""
                                                    />
                                                {:else}
                                                    <AvatarFallback
                                                        class="text-[0.6rem] font-bold"
                                                    >
                                                        {getInitials(
                                                            item.uploader
                                                                ?.name ||
                                                                t(
                                                                    'Deleted user',
                                                                ),
                                                        )}
                                                    </AvatarFallback>
                                                {/if}
                                            </Avatar>
                                            <span
                                                class="font-medium text-foreground"
                                            >
                                                {item.uploader?.name ||
                                                    t('Deleted user')}
                                            </span>
                                            {#if item.createdAt}
                                                <span aria-hidden="true">·</span
                                                >
                                                <time datetime={item.createdAt}>
                                                    {formatDate(item.createdAt)}
                                                </time>
                                            {/if}
                                            <span aria-hidden="true">·</span>
                                            <span
                                                >{formatFileSize(
                                                    item.size,
                                                )}</span
                                            >
                                        </div>

                                        {#if canShowVisualPreview(item)}
                                            <button
                                                type="button"
                                                onclick={() =>
                                                    openFilePreview(item)}
                                                class="mt-4 inline-block max-w-full rounded-lg text-left align-top outline-none ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                                aria-label={t('Preview :file', {
                                                    file: item.title,
                                                })}
                                            >
                                                <ProjectFileThumbnail
                                                    teamSlug={currentTeam.slug}
                                                    projectId={item.project.id}
                                                    fileId={item.id}
                                                    mimeType={item.canAccessFile
                                                        ? item.mimeType
                                                        : null}
                                                    thumbnailUrl={item.canAccessFile
                                                        ? item.thumbnailUrl
                                                        : null}
                                                    variant="result"
                                                />
                                            </button>
                                        {:else}
                                            <div
                                                class="mt-4 flex min-w-0 items-center gap-3 rounded-lg border bg-muted/30 p-3"
                                            >
                                                <ProjectFileThumbnail
                                                    teamSlug={currentTeam.slug}
                                                    projectId={item.project.id}
                                                    fileId={item.id}
                                                    mimeType={item.canAccessFile
                                                        ? item.mimeType
                                                        : null}
                                                    thumbnailUrl={item.canAccessFile
                                                        ? item.thumbnailUrl
                                                        : null}
                                                    variant="index"
                                                />
                                                <div class="min-w-0 flex-1">
                                                    <p
                                                        class="truncate text-sm font-medium"
                                                    >
                                                        {item.mimeType ||
                                                            t('File')}
                                                    </p>
                                                    <p
                                                        class="text-xs text-muted-foreground"
                                                    >
                                                        {formatFileSize(
                                                            item.size,
                                                        )}
                                                    </p>
                                                </div>
                                                {#if item.canAccessFile && canPreviewStoredFile(item.title)}
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onclick={() =>
                                                            openFilePreview(
                                                                item,
                                                            )}
                                                    >
                                                        {t('Preview')}
                                                    </Button>
                                                {/if}
                                            </div>
                                        {/if}
                                    </article>
                                {:else}
                                    <article
                                        class="grid min-w-0 grid-cols-[2rem_minmax(0,1fr)] gap-3"
                                    >
                                        <span
                                            class="flex size-8 items-center justify-center rounded-full bg-muted text-muted-foreground"
                                        >
                                            {#if item.type === 'message' || item.type === 'comment'}
                                                <MessageSquare
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                            {:else if item.type === 'todo'}
                                                <ListTodo
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                            {:else if item.type === 'link'}
                                                <LinkIcon
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                            {:else}
                                                <FileText
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                            {/if}
                                        </span>
                                        <div class="min-w-0">
                                            <div
                                                class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground"
                                            >
                                                <span
                                                    class="font-semibold text-foreground"
                                                >
                                                    {typeLabel(item.type)}
                                                </span>
                                                <span aria-hidden="true">·</span
                                                >
                                                <span class="break-words">
                                                    {item.project.name}
                                                </span>
                                                <span aria-hidden="true">·</span
                                                >
                                                <time datetime={item.updatedAt}>
                                                    {formatDate(item.updatedAt)}
                                                </time>
                                            </div>
                                            <Link
                                                href={item.href}
                                                class="mt-1.5 block break-words text-base font-semibold text-sky-700 underline decoration-sky-300 underline-offset-4 hover:text-sky-900 dark:text-sky-400 dark:decoration-sky-700 dark:hover:text-sky-200"
                                            >
                                                {item.title}
                                            </Link>
                                            {#if item.snippet}
                                                <p
                                                    class="mt-2 line-clamp-3 break-words whitespace-pre-wrap text-sm leading-6 text-muted-foreground"
                                                >
                                                    {item.snippet}
                                                </p>
                                            {/if}
                                        </div>
                                    </article>
                                {/if}
                            </li>
                        {/each}
                    </ol>

                    {#if hasPaginationNavigation}
                        <nav
                            class="mt-4 flex flex-wrap justify-center gap-2 border-t pt-4"
                            aria-label={t('Pagination')}
                        >
                            {#each results.links as link, index (index)}
                                {@const url = link.url}
                                {#if url}
                                    <Button
                                        variant={link.active
                                            ? 'default'
                                            : 'outline'}
                                        size="sm"
                                        asChild
                                    >
                                        {#snippet children(buttonProps)}
                                            <Link
                                                {...buttonProps}
                                                href={url}
                                                preserveScroll
                                                class={buttonProps.class}
                                                aria-current={link.active
                                                    ? 'page'
                                                    : undefined}
                                            >
                                                {paginationLabel(
                                                    link.label,
                                                    index,
                                                    results.links.length - 1,
                                                )}
                                            </Link>
                                        {/snippet}
                                    </Button>
                                {:else}
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled
                                    >
                                        {paginationLabel(
                                            link.label,
                                            index,
                                            results.links.length - 1,
                                        )}
                                    </Button>
                                {/if}
                            {/each}
                        </nav>
                    {/if}
                {/if}
            </section>
        </div>
    </main>
</div>

{#if previewFile}
    <StoredFilePreviewDialog
        {currentTeam}
        project={previewFile.project}
        file={{
            id: previewFile.id,
            name: previewFile.title,
            mimeType: previewFile.mimeType,
        }}
        bind:open={previewOpen}
    />
{/if}
