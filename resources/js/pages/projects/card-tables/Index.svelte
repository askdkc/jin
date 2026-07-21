<script module lang="ts">
    import CardTableController from '@/actions/App/Http/Controllers/CardTableController';
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
                title: props.currentTeam.menuNames.cardTable,
                href: CardTableController.index([
                    props.currentTeam.slug,
                    props.project.id,
                ]),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link, page, router } from '@inertiajs/svelte';
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import ChevronsLeft from 'lucide-svelte/icons/chevrons-left';
    import ChevronsRight from 'lucide-svelte/icons/chevrons-right';
    import Ellipsis from 'lucide-svelte/icons/ellipsis';
    import GripVertical from 'lucide-svelte/icons/grip-vertical';
    import Pencil from 'lucide-svelte/icons/pencil';
    import Plus from 'lucide-svelte/icons/plus';
    import Search from 'lucide-svelte/icons/search';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import AttachmentController from '@/actions/App/Http/Controllers/AttachmentController';
    import AlertError from '@/components/AlertError.svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import RichTextEditor from '@/components/RichTextEditor.svelte';
    import { Avatar, AvatarFallback } from '@/components/ui/avatar';
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
        DropdownMenuSeparator,
        DropdownMenuTrigger,
    } from '@/components/ui/dropdown-menu';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
    import { cn } from '@/lib/utils';
    import type {
        ProjectCard,
        ProjectCardColumn,
        ProjectCardTable,
        TodoMember,
    } from '@/types';

    let {
        currentTeam,
        project,
        tables,
        members,
    }: {
        currentTeam: Team;
        project: ProjectTool;
        tables: ProjectCardTable[];
        members: TodoMember[];
    } = $props();

    type CardTarget = {
        tableId: number;
        columnId: number;
        card: ProjectCard;
    };

    type ColumnTarget = {
        tableId: number;
        column: ProjectCardColumn;
    };

    const routeArguments = $derived([currentTeam.slug, project.id] as [
        string,
        number,
    ]);
    const versionError = $derived(
        typeof page.props.errors?.version === 'string'
            ? page.props.errors.version
            : null,
    );
    const columnAccentClasses = [
        'bg-chart-1',
        'bg-chart-2',
        'bg-chart-3',
        'bg-chart-4',
        'bg-chart-5',
    ] as const;
    const columnAccent = (index: number): string =>
        columnAccentClasses[index % columnAccentClasses.length] ??
        columnAccentClasses[0];
    const memberInitial = (name: string | null): string =>
        name?.trim().slice(0, 1).toLocaleUpperCase('ja-JP') || '?';
    const confirmDelete = (): boolean =>
        window.confirm(t('Are you sure you want to delete this?'));
    const visibleCards = (column: ProjectCardColumn): ProjectCard[] => {
        const query = filterText.trim().toLocaleLowerCase();

        if (!query) {
            return column.cards;
        }

        return column.cards.filter((card) =>
            [card.title, card.creatorName, card.assigneeName]
                .filter(Boolean)
                .some((value) => value!.toLocaleLowerCase().includes(query)),
        );
    };
    // Filtering changes display indexes, but the move API stores positions in
    // the complete column, so drag targets must use the original card array.
    const originalCardPosition = (
        column: ProjectCardColumn,
        cardId: number,
    ): number => column.cards.findIndex((card) => card.id === cardId);

    let filterText = $state('');
    let createDialogOpen = $state(false);
    let createTarget = $state<{ tableId: number; columnId: number } | null>(
        null,
    );
    let closeCreateAfterSuccess = $state(true);
    let createDescriptionOpen = $state(false);
    let editDialogOpen = $state(false);
    let editTarget = $state<CardTarget | null>(null);
    let editDescriptionOpen = $state(false);
    let renameColumnDialogOpen = $state(false);
    let renameColumnTarget = $state<ColumnTarget | null>(null);
    let newColumnDialogOpen = $state(false);
    let newColumnTableId = $state<number | null>(null);
    let newTableDialogOpen = $state(false);
    let renameTableDialogOpen = $state(false);
    let renameTableTarget = $state<ProjectCardTable | null>(null);
    let draggedCard = $state<{
        tableId: number;
        columnId: number;
        cardId: number;
        version: number;
    } | null>(null);

    const openCreateCard = (tableId: number, columnId: number): void => {
        createTarget = { tableId, columnId };
        closeCreateAfterSuccess = true;
        createDescriptionOpen = false;
        createDialogOpen = true;
    };
    const openEditCard = (
        tableId: number,
        columnId: number,
        card: ProjectCard,
    ): void => {
        editTarget = { tableId, columnId, card };
        editDescriptionOpen = Boolean(card.description?.trim());
        editDialogOpen = true;
    };
    const openRenameColumn = (
        tableId: number,
        column: ProjectCardColumn,
    ): void => {
        renameColumnTarget = { tableId, column };
        renameColumnDialogOpen = true;
    };
    const openNewColumn = (tableId: number): void => {
        newColumnTableId = tableId;
        newColumnDialogOpen = true;
    };
    const openRenameTable = (table: ProjectCardTable): void => {
        renameTableTarget = table;
        renameTableDialogOpen = true;
    };
    const deleteCard = (target: CardTarget): void => {
        if (!confirmDelete()) {
            return;
        }

        router.delete(
            CardTableController.destroyCard([
                currentTeam.slug,
                project.id,
                target.tableId,
                target.columnId,
                target.card.id,
            ]).url,
            {
                preserveScroll: true,
                onSuccess: () => (editDialogOpen = false),
            },
        );
    };
    const startDragging = (
        event: DragEvent,
        tableId: number,
        columnId: number,
        cardId: number,
        version: number,
    ): void => {
        // The server treats version as a compare-and-swap guard against stale moves.
        draggedCard = { tableId, columnId, cardId, version };

        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', String(cardId));
        }
    };
    const allowDrop = (event: DragEvent, tableId: number): void => {
        if (draggedCard?.tableId !== tableId) {
            return;
        }

        event.preventDefault();

        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'move';
        }
    };
    const dropCard = (
        event: DragEvent,
        tableId: number,
        targetColumnId: number,
        targetPosition?: number,
    ): void => {
        event.preventDefault();
        event.stopPropagation();

        if (!draggedCard || draggedCard.tableId !== tableId) {
            return;
        }

        const source = draggedCard;
        draggedCard = null;
        router.patch(
            CardTableController.moveCard([
                currentTeam.slug,
                project.id,
                source.tableId,
                source.columnId,
                source.cardId,
            ]).url,
            {
                target_column_id: targetColumnId,
                version: source.version,
                ...(targetPosition === undefined
                    ? {}
                    : { target_position: targetPosition }),
            },
            { preserveScroll: true },
        );
    };
</script>

<AppHead title={currentTeam.menuNames.cardTable} />

<div class="min-h-full bg-muted/40 px-0 pb-8 sm:px-5 sm:pt-2 lg:px-6">
    <main
        class="mx-auto min-h-[calc(100dvh-7rem)] w-full max-w-[120rem] overflow-hidden bg-card shadow-sm"
    >
        <div class="flex min-h-10 items-center border-b px-5 sm:px-8">
            <Link
                href={show([currentTeam.slug, project.id])}
                class="text-sm font-bold hover:underline"
            >
                {project.name}
            </Link>
        </div>

        {#if versionError}
            <div class="px-5 pt-5 sm:px-8">
                <AlertError
                    errors={[versionError]}
                    title={t('Update conflict')}
                />
            </div>
        {/if}

        {#if tables.length === 0}
            <section class="px-5 py-16 text-center sm:px-8">
                <h1 class="text-3xl font-bold">
                    {currentTeam.menuNames.cardTable}
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    {t('No card tables yet')}
                </p>
                <Button
                    type="button"
                    class="mt-5"
                    onclick={() => (newTableDialogOpen = true)}
                >
                    <Plus data-icon="inline-start" />
                    {t('Add table')}
                </Button>
            </section>
        {:else}
            {#each tables as table (table.id)}
                <section aria-labelledby={`card-table-${table.id}`}>
                    <header class="border-b px-5 py-6 sm:px-8">
                        <div
                            class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
                        >
                            <div class="min-w-0">
                                <h1
                                    id={`card-table-${table.id}`}
                                    class="break-words text-3xl font-bold tracking-tight sm:text-4xl"
                                >
                                    {table.name}
                                </h1>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    {#if table.columns[0]}
                                        <Button
                                            type="button"
                                            onclick={() =>
                                                openCreateCard(
                                                    table.id,
                                                    table.columns[0].id,
                                                )}
                                        >
                                            <Plus data-icon="inline-start" />
                                            {t('Add a card')}
                                        </Button>
                                    {/if}
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onclick={() => openNewColumn(table.id)}
                                    >
                                        <Plus data-icon="inline-start" />
                                        {t('Add column')}
                                    </Button>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            {#snippet children(triggerProps)}
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    onclick={triggerProps.onclick}
                                                    aria-expanded={triggerProps[
                                                        'aria-expanded'
                                                    ]}
                                                    data-state={triggerProps[
                                                        'data-state'
                                                    ]}
                                                    aria-label={t(
                                                        'Table settings',
                                                    )}
                                                >
                                                    <Ellipsis />
                                                </Button>
                                            {/snippet}
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="start">
                                            <DropdownMenuGroup>
                                                <DropdownMenuItem asChild>
                                                    {#snippet children(
                                                        itemProps,
                                                    )}
                                                        <button
                                                            type="button"
                                                            class={itemProps.class}
                                                            onclick={() => {
                                                                itemProps.onClick?.();
                                                                openRenameTable(
                                                                    table,
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
                                                <DropdownMenuItem asChild>
                                                    {#snippet children(
                                                        itemProps,
                                                    )}
                                                        <button
                                                            type="button"
                                                            class={itemProps.class}
                                                            onclick={() => {
                                                                itemProps.onClick?.();
                                                                newTableDialogOpen = true;
                                                            }}
                                                        >
                                                            <Plus
                                                                aria-hidden="true"
                                                            />
                                                            {t('Add table')}
                                                        </button>
                                                    {/snippet}
                                                </DropdownMenuItem>
                                            </DropdownMenuGroup>
                                            {#if table.canDelete}
                                                <DropdownMenuSeparator />
                                                <DropdownMenuGroup>
                                                    <Form
                                                        {...CardTableController.destroyTable.form(
                                                            [
                                                                currentTeam.slug,
                                                                project.id,
                                                                table.id,
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
                                                                        onclick={() =>
                                                                            itemProps.onClick?.()}
                                                                    >
                                                                        <Trash2
                                                                            aria-hidden="true"
                                                                        />
                                                                        {t(
                                                                            'Delete table',
                                                                        )}
                                                                    </button>
                                                                {/snippet}
                                                            </DropdownMenuItem>
                                                        {/snippet}
                                                    </Form>
                                                </DropdownMenuGroup>
                                            {/if}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </div>

                            <div class="relative w-full max-w-xs">
                                <Label
                                    for={`card-filter-${table.id}`}
                                    class="sr-only"
                                >
                                    {t('Filter')}
                                </Label>
                                <Search
                                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <Input
                                    id={`card-filter-${table.id}`}
                                    bind:value={filterText}
                                    class="pl-9"
                                    placeholder={t('Filter...')}
                                />
                            </div>
                        </div>
                    </header>

                    {#if table.columns.length === 0}
                        <div class="px-5 py-16 text-center sm:px-8">
                            <p class="text-sm text-muted-foreground">
                                {t('No columns yet')}
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                class="mt-4"
                                onclick={() => openNewColumn(table.id)}
                            >
                                <Plus data-icon="inline-start" />
                                {t('Add column')}
                            </Button>
                        </div>
                    {:else}
                        <div
                            class="flex min-h-[35rem] items-stretch overflow-x-auto"
                        >
                            {#each table.columns as column, columnIndex (column.id)}
                                {@const visibleColumnCards =
                                    visibleCards(column)}
                                <section
                                    class={cn(
                                        'relative shrink-0 border-r last:border-r-0',
                                        column.collapsed
                                            ? 'w-14'
                                            : 'w-[min(26rem,88vw)]',
                                    )}
                                    aria-labelledby={`column-${column.id}`}
                                    ondragover={(event) =>
                                        allowDrop(event, table.id)}
                                    ondrop={(event) =>
                                        dropCard(event, table.id, column.id)}
                                >
                                    <span
                                        class={cn(
                                            'absolute inset-x-0 top-0 h-1',
                                            columnAccent(columnIndex),
                                        )}
                                        aria-hidden="true"
                                    ></span>

                                    {#if column.collapsed}
                                        <div
                                            class="flex h-full min-h-[35rem] flex-col items-center gap-3 bg-muted/30 px-2 pt-4"
                                        >
                                            <Form
                                                {...CardTableController.toggleColumn.form(
                                                    [
                                                        currentTeam.slug,
                                                        project.id,
                                                        table.id,
                                                        column.id,
                                                    ],
                                                )}
                                            >
                                                {#snippet children({
                                                    processing,
                                                })}
                                                    <Button
                                                        type="submit"
                                                        variant="ghost"
                                                        size="icon"
                                                        disabled={processing}
                                                        aria-label={t(
                                                            'Expand column',
                                                        )}
                                                    >
                                                        <ChevronsRight />
                                                    </Button>
                                                {/snippet}
                                            </Form>
                                            <span
                                                class="text-xs text-muted-foreground"
                                            >
                                                {visibleColumnCards.length}
                                            </span>
                                            <h2
                                                id={`column-${column.id}`}
                                                class="font-semibold whitespace-nowrap"
                                                style="writing-mode: vertical-rl"
                                            >
                                                {column.name}
                                            </h2>
                                        </div>
                                    {:else}
                                        <div
                                            class="flex min-h-full flex-col bg-muted/20"
                                        >
                                            <header
                                                class="flex items-center justify-between gap-3 px-4 pt-5 pb-3"
                                            >
                                                <h2
                                                    id={`column-${column.id}`}
                                                    class="min-w-0 break-words font-semibold"
                                                >
                                                    {column.name}
                                                    <span
                                                        class="font-normal text-muted-foreground"
                                                    >
                                                        ({visibleColumnCards.length})
                                                    </span>
                                                </h2>
                                                <div
                                                    class="flex shrink-0 items-center gap-1"
                                                >
                                                    <Form
                                                        {...CardTableController.toggleColumn.form(
                                                            [
                                                                currentTeam.slug,
                                                                project.id,
                                                                table.id,
                                                                column.id,
                                                            ],
                                                        )}
                                                    >
                                                        {#snippet children({
                                                            processing,
                                                        })}
                                                            <Button
                                                                type="submit"
                                                                variant="ghost"
                                                                size="icon"
                                                                disabled={processing}
                                                                aria-label={t(
                                                                    'Collapse column',
                                                                )}
                                                            >
                                                                <ChevronsLeft />
                                                            </Button>
                                                        {/snippet}
                                                    </Form>
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger
                                                            asChild
                                                        >
                                                            {#snippet children(
                                                                triggerProps,
                                                            )}
                                                                <Button
                                                                    type="button"
                                                                    variant="outline"
                                                                    size="icon"
                                                                    onclick={triggerProps.onclick}
                                                                    aria-expanded={triggerProps[
                                                                        'aria-expanded'
                                                                    ]}
                                                                    data-state={triggerProps[
                                                                        'data-state'
                                                                    ]}
                                                                    aria-label={t(
                                                                        'Column settings',
                                                                    )}
                                                                >
                                                                    <Ellipsis />
                                                                </Button>
                                                            {/snippet}
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent
                                                            align="end"
                                                        >
                                                            <DropdownMenuGroup>
                                                                <DropdownMenuItem
                                                                    asChild
                                                                >
                                                                    {#snippet children(
                                                                        itemProps,
                                                                    )}
                                                                        <button
                                                                            type="button"
                                                                            class={itemProps.class}
                                                                            onclick={() => {
                                                                                itemProps.onClick?.();
                                                                                openCreateCard(
                                                                                    table.id,
                                                                                    column.id,
                                                                                );
                                                                            }}
                                                                        >
                                                                            <Plus
                                                                                aria-hidden="true"
                                                                            />
                                                                            {t(
                                                                                'Add card',
                                                                            )}
                                                                        </button>
                                                                    {/snippet}
                                                                </DropdownMenuItem>
                                                                <DropdownMenuItem
                                                                    asChild
                                                                >
                                                                    {#snippet children(
                                                                        itemProps,
                                                                    )}
                                                                        <button
                                                                            type="button"
                                                                            class={itemProps.class}
                                                                            onclick={() => {
                                                                                itemProps.onClick?.();
                                                                                openRenameColumn(
                                                                                    table.id,
                                                                                    column,
                                                                                );
                                                                            }}
                                                                        >
                                                                            <Pencil
                                                                                aria-hidden="true"
                                                                            />
                                                                            {t(
                                                                                'Rename',
                                                                            )}
                                                                        </button>
                                                                    {/snippet}
                                                                </DropdownMenuItem>
                                                                <Form
                                                                    {...CardTableController.moveColumn.form(
                                                                        [
                                                                            currentTeam.slug,
                                                                            project.id,
                                                                            table.id,
                                                                            column.id,
                                                                        ],
                                                                    )}
                                                                >
                                                                    {#snippet children({
                                                                        processing,
                                                                    })}
                                                                        <input
                                                                            type="hidden"
                                                                            name="target_position"
                                                                            value={Math.max(
                                                                                columnIndex -
                                                                                    1,
                                                                                0,
                                                                            )}
                                                                        />
                                                                        <DropdownMenuItem
                                                                            asChild
                                                                        >
                                                                            {#snippet children(
                                                                                itemProps,
                                                                            )}
                                                                                <button
                                                                                    type="submit"
                                                                                    class={itemProps.class}
                                                                                    disabled={processing ||
                                                                                        columnIndex ===
                                                                                            0}
                                                                                    onclick={() =>
                                                                                        itemProps.onClick?.()}
                                                                                >
                                                                                    <ChevronsLeft
                                                                                        aria-hidden="true"
                                                                                    />
                                                                                    {t(
                                                                                        'Move left',
                                                                                    )}
                                                                                </button>
                                                                            {/snippet}
                                                                        </DropdownMenuItem>
                                                                    {/snippet}
                                                                </Form>
                                                                <Form
                                                                    {...CardTableController.moveColumn.form(
                                                                        [
                                                                            currentTeam.slug,
                                                                            project.id,
                                                                            table.id,
                                                                            column.id,
                                                                        ],
                                                                    )}
                                                                >
                                                                    {#snippet children({
                                                                        processing,
                                                                    })}
                                                                        <input
                                                                            type="hidden"
                                                                            name="target_position"
                                                                            value={columnIndex +
                                                                                1}
                                                                        />
                                                                        <DropdownMenuItem
                                                                            asChild
                                                                        >
                                                                            {#snippet children(
                                                                                itemProps,
                                                                            )}
                                                                                <button
                                                                                    type="submit"
                                                                                    class={itemProps.class}
                                                                                    disabled={processing ||
                                                                                        columnIndex ===
                                                                                            table
                                                                                                .columns
                                                                                                .length -
                                                                                                1}
                                                                                    onclick={() =>
                                                                                        itemProps.onClick?.()}
                                                                                >
                                                                                    <ChevronsRight
                                                                                        aria-hidden="true"
                                                                                    />
                                                                                    {t(
                                                                                        'Move right',
                                                                                    )}
                                                                                </button>
                                                                            {/snippet}
                                                                        </DropdownMenuItem>
                                                                    {/snippet}
                                                                </Form>
                                                            </DropdownMenuGroup>
                                                            {#if column.canDelete}
                                                                <DropdownMenuSeparator
                                                                />
                                                                <DropdownMenuGroup
                                                                >
                                                                    <Form
                                                                        {...CardTableController.destroyColumn.form(
                                                                            [
                                                                                currentTeam.slug,
                                                                                project.id,
                                                                                table.id,
                                                                                column.id,
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
                                                                                        onclick={() =>
                                                                                            itemProps.onClick?.()}
                                                                                    >
                                                                                        <Trash2
                                                                                            aria-hidden="true"
                                                                                        />
                                                                                        {t(
                                                                                            'Delete column',
                                                                                        )}
                                                                                    </button>
                                                                                {/snippet}
                                                                            </DropdownMenuItem>
                                                                        {/snippet}
                                                                    </Form>
                                                                </DropdownMenuGroup>
                                                            {/if}
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                </div>
                                            </header>

                                            <div
                                                class="flex flex-1 flex-col gap-2 px-3 pb-4"
                                            >
                                                {#each visibleColumnCards as card (card.id)}
                                                    <article
                                                        class="group rounded-lg border bg-card shadow-sm"
                                                        draggable="true"
                                                        ondragstart={(event) =>
                                                            startDragging(
                                                                event,
                                                                table.id,
                                                                column.id,
                                                                card.id,
                                                                card.version,
                                                            )}
                                                        ondragend={() =>
                                                            (draggedCard =
                                                                null)}
                                                        ondragover={(event) =>
                                                            allowDrop(
                                                                event,
                                                                table.id,
                                                            )}
                                                        ondrop={(event) =>
                                                            dropCard(
                                                                event,
                                                                table.id,
                                                                column.id,
                                                                originalCardPosition(
                                                                    column,
                                                                    card.id,
                                                                ),
                                                            )}
                                                    >
                                                        <div
                                                            class="flex items-start gap-1"
                                                        >
                                                            <GripVertical
                                                                class="mt-3 size-4 shrink-0 cursor-grab text-muted-foreground"
                                                                aria-label={t(
                                                                    'Drag to reorder',
                                                                )}
                                                            />
                                                            <button
                                                                type="button"
                                                                class="min-w-0 flex-1 px-1 py-3 text-left"
                                                                onclick={() =>
                                                                    openEditCard(
                                                                        table.id,
                                                                        column.id,
                                                                        card,
                                                                    )}
                                                            >
                                                                <span
                                                                    class="block break-words text-sm font-medium"
                                                                >
                                                                    {card.title}
                                                                </span>
                                                                <span
                                                                    class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground"
                                                                >
                                                                    {#if card.creatorName}
                                                                        <span>
                                                                            {t(
                                                                                'By :name',
                                                                                {
                                                                                    name: card.creatorName,
                                                                                },
                                                                            )}
                                                                        </span>
                                                                    {/if}
                                                                    {#if card.dueOn}
                                                                        <span
                                                                            class="inline-flex items-center gap-1"
                                                                        >
                                                                            <CalendarDays
                                                                                class="size-3.5"
                                                                                aria-hidden="true"
                                                                            />
                                                                            {card.dueOn}
                                                                        </span>
                                                                    {/if}
                                                                </span>
                                                            </button>
                                                            {#if card.assigneeName}
                                                                <Avatar
                                                                    class="mt-2 mr-2 size-8"
                                                                >
                                                                    <AvatarFallback
                                                                    >
                                                                        {memberInitial(
                                                                            card.assigneeName,
                                                                        )}
                                                                    </AvatarFallback>
                                                                </Avatar>
                                                            {/if}
                                                        </div>
                                                    </article>
                                                {:else}
                                                    <button
                                                        type="button"
                                                        class="min-h-24 rounded-md border border-dashed p-4 text-sm text-muted-foreground"
                                                        onclick={() =>
                                                            openCreateCard(
                                                                table.id,
                                                                column.id,
                                                            )}
                                                        ondragover={(event) =>
                                                            allowDrop(
                                                                event,
                                                                table.id,
                                                            )}
                                                        ondrop={(event) =>
                                                            dropCard(
                                                                event,
                                                                table.id,
                                                                column.id,
                                                            )}
                                                    >
                                                        {filterText
                                                            ? t(
                                                                  'No matching cards',
                                                              )
                                                            : t('Add card')}
                                                    </button>
                                                {/each}

                                                {#if visibleColumnCards.length > 0}
                                                    <button
                                                        type="button"
                                                        class="min-h-14 rounded-md border border-dashed text-sm text-muted-foreground hover:bg-accent"
                                                        onclick={() =>
                                                            openCreateCard(
                                                                table.id,
                                                                column.id,
                                                            )}
                                                        ondragover={(event) =>
                                                            allowDrop(
                                                                event,
                                                                table.id,
                                                            )}
                                                        ondrop={(event) =>
                                                            dropCard(
                                                                event,
                                                                table.id,
                                                                column.id,
                                                            )}
                                                    >
                                                        <Plus
                                                            class="mx-auto size-4"
                                                            aria-hidden="true"
                                                        />
                                                        <span class="sr-only">
                                                            {t('Add card')}
                                                        </span>
                                                    </button>
                                                {/if}
                                            </div>
                                        </div>
                                    {/if}
                                </section>
                            {/each}
                        </div>
                    {/if}
                </section>
            {/each}
        {/if}
    </main>
</div>

<Dialog bind:open={newTableDialogOpen}>
    <DialogContent class="sm:max-w-md">
        <DialogTitle>{t('New card table')}</DialogTitle>
        <Form
            {...CardTableController.storeTable.form(routeArguments)}
            resetOnSuccess
            onSuccess={() => (newTableDialogOpen = false)}
            class="mt-5 flex flex-col gap-4"
        >
            {#snippet children({ errors, processing })}
                <div class="flex flex-col gap-2">
                    <Label for="new-card-table">{t('Name')}</Label>
                    <Input
                        id="new-card-table"
                        name="name"
                        maxlength={255}
                        required
                        autofocus
                    />
                    <InputError message={errors.name} />
                </div>
                <DialogFooter>
                    <DialogClose>
                        <Button type="button" variant="outline">
                            {t('Cancel')}
                        </Button>
                    </DialogClose>
                    <Button type="submit" disabled={processing}>
                        {t('Add table')}
                    </Button>
                </DialogFooter>
            {/snippet}
        </Form>
    </DialogContent>
</Dialog>

<Dialog bind:open={renameTableDialogOpen}>
    <DialogContent class="sm:max-w-md">
        <DialogTitle>{t('Rename table')}</DialogTitle>
        {#if renameTableTarget}
            {@const target = renameTableTarget}
            <Form
                {...CardTableController.updateTable.form([
                    currentTeam.slug,
                    project.id,
                    target.id,
                ])}
                onSuccess={() => (renameTableDialogOpen = false)}
                class="mt-5 flex flex-col gap-4"
            >
                {#snippet children({ errors, processing })}
                    <div class="flex flex-col gap-2">
                        <Label for="rename-card-table">{t('Name')}</Label>
                        <Input
                            id="rename-card-table"
                            name="name"
                            value={target.name}
                            maxlength={255}
                            required
                            autofocus
                        />
                        <InputError message={errors.name} />
                    </div>
                    <DialogFooter>
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

<Dialog bind:open={newColumnDialogOpen}>
    <DialogContent class="sm:max-w-md">
        <DialogTitle>{t('New column')}</DialogTitle>
        {#if newColumnTableId}
            <Form
                {...CardTableController.storeColumn.form([
                    currentTeam.slug,
                    project.id,
                    newColumnTableId,
                ])}
                resetOnSuccess
                onSuccess={() => (newColumnDialogOpen = false)}
                class="mt-5 flex flex-col gap-4"
            >
                {#snippet children({ errors, processing })}
                    <div class="flex flex-col gap-2">
                        <Label for="new-column-name">{t('Name')}</Label>
                        <Input
                            id="new-column-name"
                            name="name"
                            maxlength={255}
                            required
                            autofocus
                        />
                        <InputError message={errors.name} />
                    </div>
                    <DialogFooter>
                        <DialogClose>
                            <Button type="button" variant="outline">
                                {t('Cancel')}
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {t('Add column')}
                        </Button>
                    </DialogFooter>
                {/snippet}
            </Form>
        {/if}
    </DialogContent>
</Dialog>

<Dialog bind:open={renameColumnDialogOpen}>
    <DialogContent class="sm:max-w-md">
        <DialogTitle>{t('Rename column')}</DialogTitle>
        {#if renameColumnTarget}
            {@const target = renameColumnTarget}
            <Form
                {...CardTableController.updateColumn.form([
                    currentTeam.slug,
                    project.id,
                    target.tableId,
                    target.column.id,
                ])}
                onSuccess={() => (renameColumnDialogOpen = false)}
                class="mt-5 flex flex-col gap-4"
            >
                {#snippet children({ errors, processing })}
                    <div class="flex flex-col gap-2">
                        <Label for="rename-column-name">{t('Name')}</Label>
                        <Input
                            id="rename-column-name"
                            name="name"
                            value={target.column.name}
                            maxlength={255}
                            required
                            autofocus
                        />
                        <InputError message={errors.name} />
                    </div>
                    <DialogFooter>
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

<Dialog bind:open={createDialogOpen}>
    <DialogContent
        class="max-h-[calc(100dvh-2rem)] max-w-3xl overflow-y-auto sm:p-8"
    >
        <DialogTitle>{t('Add a new card')}</DialogTitle>
        {#if createTarget}
            {@const target = createTarget}
            <Form
                {...CardTableController.storeCard.form([
                    currentTeam.slug,
                    project.id,
                    target.tableId,
                    target.columnId,
                ])}
                resetOnSuccess
                onSuccess={() => {
                    if (closeCreateAfterSuccess) {
                        createDialogOpen = false;
                    }
                }}
                class="mt-5 flex flex-col gap-5"
            >
                {#snippet children({ errors, processing })}
                    <div class="flex flex-col gap-2">
                        <Label for="new-card-title">{t('Title')}</Label>
                        <Input
                            id="new-card-title"
                            name="title"
                            maxlength={255}
                            required
                            autofocus
                        />
                        <InputError message={errors.title} />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="flex flex-col gap-2">
                            <Label for="new-card-assignee">
                                {t('Assign card')}
                            </Label>
                            <select
                                id="new-card-assignee"
                                name="assignee_id"
                                class="border-input h-10 rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="">{t('Unassigned')}</option>
                                {#each members as member (member.id)}
                                    <option value={member.id}>
                                        {member.name}
                                    </option>
                                {/each}
                            </select>
                            <InputError message={errors.assignee_id} />
                        </div>
                        <div class="flex flex-col gap-2">
                            <Label for="new-card-due">{t('Due date')}</Label>
                            <Input
                                id="new-card-due"
                                type="date"
                                name="due_on"
                            />
                            <InputError message={errors.due_on} />
                        </div>
                    </div>
                    {#if createDescriptionOpen}
                        <div class="flex flex-col gap-2">
                            <Label for="new-card-description">
                                {t('Description')}
                            </Label>
                            <RichTextEditor
                                id="new-card-description"
                                name="description"
                                ariaLabel={t('Description')}
                                attachmentUploadUrl={AttachmentController.uploadInline.url(
                                    routeArguments,
                                )}
                            />
                            <InputError message={errors.description} />
                        </div>
                    {:else}
                        <Button
                            type="button"
                            variant="outline"
                            class="self-start"
                            onclick={() => (createDescriptionOpen = true)}
                        >
                            <Plus data-icon="inline-start" />
                            {t('Add description')}
                        </Button>
                    {/if}
                    <DialogFooter>
                        <DialogClose>
                            <Button type="button" variant="outline">
                                {t('Never mind')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            onclick={() => (closeCreateAfterSuccess = false)}
                        >
                            {t('Save and add another')}
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing}
                            onclick={() => (closeCreateAfterSuccess = true)}
                        >
                            {t('Save card')}
                        </Button>
                    </DialogFooter>
                {/snippet}
            </Form>
        {/if}
    </DialogContent>
</Dialog>

<Dialog bind:open={editDialogOpen}>
    <DialogContent
        class="max-h-[calc(100dvh-2rem)] max-w-3xl overflow-y-auto sm:p-8"
    >
        <DialogTitle>{t('Edit card')}</DialogTitle>
        {#if editTarget}
            {@const target = editTarget}
            {#key target.card.id}
                <Form
                    {...CardTableController.updateCard.form([
                        currentTeam.slug,
                        project.id,
                        target.tableId,
                        target.columnId,
                        target.card.id,
                    ])}
                    onSuccess={() => (editDialogOpen = false)}
                    class="mt-5 flex flex-col gap-5"
                >
                    {#snippet children({ errors, processing })}
                        <input
                            type="hidden"
                            name="version"
                            value={target.card.version}
                        />
                        <div class="flex flex-col gap-2">
                            <Label for="edit-card-title">{t('Title')}</Label>
                            <Input
                                id="edit-card-title"
                                name="title"
                                value={target.card.title}
                                maxlength={255}
                                required
                            />
                            <InputError message={errors.title} />
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="flex flex-col gap-2">
                                <Label for="edit-card-assignee">
                                    {t('Assign card')}
                                </Label>
                                <select
                                    id="edit-card-assignee"
                                    name="assignee_id"
                                    class="border-input h-10 rounded-md border bg-background px-3 text-sm"
                                >
                                    <option value="">{t('Unassigned')}</option>
                                    {#each members as member (member.id)}
                                        <option
                                            value={member.id}
                                            selected={member.id ===
                                                target.card.assigneeId}
                                        >
                                            {member.name}
                                        </option>
                                    {/each}
                                </select>
                                <InputError message={errors.assignee_id} />
                            </div>
                            <div class="flex flex-col gap-2">
                                <Label for="edit-card-due">
                                    {t('Due date')}
                                </Label>
                                <Input
                                    id="edit-card-due"
                                    type="date"
                                    name="due_on"
                                    value={target.card.dueOn || ''}
                                />
                                <InputError message={errors.due_on} />
                            </div>
                        </div>
                        {#if editDescriptionOpen}
                            <div class="flex flex-col gap-2">
                                <Label for="edit-card-description">
                                    {t('Description')}
                                </Label>
                                <RichTextEditor
                                    id="edit-card-description"
                                    name="description"
                                    value={target.card.description}
                                    ariaLabel={t('Description')}
                                    attachmentUploadUrl={AttachmentController.uploadInline.url(
                                        routeArguments,
                                    )}
                                />
                                <InputError message={errors.description} />
                            </div>
                        {:else}
                            <Button
                                type="button"
                                variant="outline"
                                class="self-start"
                                onclick={() => (editDescriptionOpen = true)}
                            >
                                <Plus data-icon="inline-start" />
                                {t('Add description')}
                            </Button>
                        {/if}
                        <InputError message={errors.version} />
                        <DialogFooter>
                            {#if target.card.canDelete}
                                <Button
                                    type="button"
                                    variant="destructive"
                                    onclick={() => deleteCard(target)}
                                >
                                    <Trash2 data-icon="inline-start" />
                                    {t('Delete')}
                                </Button>
                            {/if}
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
            {/key}
        {/if}
    </DialogContent>
</Dialog>
