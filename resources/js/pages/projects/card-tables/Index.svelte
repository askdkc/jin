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
    import Maximize2 from 'lucide-svelte/icons/maximize-2';
    import Minimize2 from 'lucide-svelte/icons/minimize-2';
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
    import { cardTableColumnLayout } from '@/lib/card-table-columns';
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
    const columnAccents = [
        { bar: 'bg-chart-1', tint: 'bg-chart-1/15' },
        { bar: 'bg-chart-2', tint: 'bg-chart-2/15' },
        { bar: 'bg-chart-3', tint: 'bg-chart-3/15' },
        { bar: 'bg-chart-4', tint: 'bg-chart-4/15' },
        { bar: 'bg-chart-5', tint: 'bg-chart-5/15' },
    ] as const;
    const columnAccent = (index: number) =>
        columnAccents[index % columnAccents.length] ?? columnAccents[0];
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
    // Menu items can't nest <Form>: the dropdown unmounts on click, which
    // cancels the native submit. Use router calls instead.
    const deleteTable = (table: ProjectCardTable): void => {
        if (!confirmDelete()) {
            return;
        }

        router.delete(
            CardTableController.destroyTable([
                currentTeam.slug,
                project.id,
                table.id,
            ]).url,
            { preserveScroll: true },
        );
    };
    const deleteColumn = (tableId: number, columnId: number): void => {
        if (!confirmDelete()) {
            return;
        }

        router.delete(
            CardTableController.destroyColumn([
                currentTeam.slug,
                project.id,
                tableId,
                columnId,
            ]).url,
            { preserveScroll: true },
        );
    };
    const moveColumnTo = (
        tableId: number,
        columnId: number,
        targetPosition: number,
    ): void =>
        router.patch(
            CardTableController.moveColumn([
                currentTeam.slug,
                project.id,
                tableId,
                columnId,
            ]).url,
            { target_position: targetPosition },
            { preserveScroll: true },
        );
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

{#snippet columnMenu(
    table: ProjectCardTable,
    column: ProjectCardColumn,
    columnIndex: number,
)}
    <DropdownMenu>
        <DropdownMenuTrigger asChild>
            {#snippet children(triggerProps)}
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-6"
                    onclick={triggerProps.onclick}
                    aria-expanded={triggerProps['aria-expanded']}
                    data-state={triggerProps['data-state']}
                    aria-label={t('Column settings')}
                >
                    <Ellipsis />
                </Button>
            {/snippet}
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    {#snippet children(itemProps)}
                        <button
                            type="button"
                            class={itemProps.class}
                            onclick={() => {
                                itemProps.onClick?.();
                                openCreateCard(table.id, column.id);
                            }}
                        >
                            <Plus aria-hidden="true" />
                            {t('Add card')}
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
                                openRenameColumn(table.id, column);
                            }}
                        >
                            <Pencil aria-hidden="true" />
                            {t('Rename')}
                        </button>
                    {/snippet}
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    {#snippet children(itemProps)}
                        <button
                            type="button"
                            class={itemProps.class}
                            disabled={columnIndex === 0}
                            onclick={() => {
                                itemProps.onClick?.();
                                moveColumnTo(
                                    table.id,
                                    column.id,
                                    Math.max(columnIndex - 1, 0),
                                );
                            }}
                        >
                            <ChevronsLeft aria-hidden="true" />
                            {t('Move left')}
                        </button>
                    {/snippet}
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    {#snippet children(itemProps)}
                        <button
                            type="button"
                            class={itemProps.class}
                            disabled={columnIndex === table.columns.length - 1}
                            onclick={() => {
                                itemProps.onClick?.();
                                moveColumnTo(
                                    table.id,
                                    column.id,
                                    columnIndex + 1,
                                );
                            }}
                        >
                            <ChevronsRight aria-hidden="true" />
                            {t('Move right')}
                        </button>
                    {/snippet}
                </DropdownMenuItem>
            </DropdownMenuGroup>
            {#if column.canDelete}
                <DropdownMenuSeparator />
                <DropdownMenuGroup>
                    <DropdownMenuItem asChild>
                        {#snippet children(itemProps)}
                            <button
                                type="button"
                                class={itemProps.class}
                                onclick={() => {
                                    itemProps.onClick?.();
                                    deleteColumn(table.id, column.id);
                                }}
                            >
                                <Trash2 aria-hidden="true" />
                                {t('Delete column')}
                            </button>
                        {/snippet}
                    </DropdownMenuItem>
                </DropdownMenuGroup>
            {/if}
        </DropdownMenuContent>
    </DropdownMenu>
{/snippet}

{#snippet columnHeader(
    table: ProjectCardTable,
    column: ProjectCardColumn,
    columnIndex: number,
    visibleCount: number,
)}
    <header class="flex items-center justify-between gap-2 px-3 py-2">
        <h2
            id={`column-${column.id}`}
            class="min-w-0 break-words font-semibold"
        >
            <Link
                href={CardTableController.showColumn([
                    currentTeam.slug,
                    project.id,
                    table.id,
                    column.id,
                ])}
                class="hover:underline"
            >
                {column.name}
                <span class="font-normal text-muted-foreground">
                    ({visibleCount})
                </span>
            </Link>
        </h2>
        <div class="flex shrink-0 items-center gap-1">
            <Form
                {...CardTableController.toggleColumn.form([
                    currentTeam.slug,
                    project.id,
                    table.id,
                    column.id,
                ])}
                options={{ preserveScroll: true }}
            >
                {#snippet children({ processing })}
                    <Button
                        type="submit"
                        variant="ghost"
                        size="icon"
                        class="size-6"
                        disabled={processing}
                        aria-label={t('Collapse column')}
                        title={t('Collapse column')}
                    >
                        <Minimize2 class="size-4" aria-hidden="true" />
                    </Button>
                {/snippet}
            </Form>
            {@render columnMenu(table, column, columnIndex)}
        </div>
    </header>
{/snippet}

{#snippet cardArticle(
    table: ProjectCardTable,
    column: ProjectCardColumn,
    card: ProjectCard,
    className: string,
)}
    <article
        class={cn('group rounded-md border bg-card shadow-sm', className)}
        draggable="true"
        ondragstart={(event) =>
            startDragging(event, table.id, column.id, card.id, card.version)}
        ondragend={() => (draggedCard = null)}
        ondragover={(event) => allowDrop(event, table.id)}
        ondrop={(event) =>
            dropCard(
                event,
                table.id,
                column.id,
                originalCardPosition(column, card.id),
            )}
    >
        <div class="flex items-start gap-2 px-2.5 py-1.5">
            <button
                type="button"
                class="min-w-0 flex-1 text-left"
                onclick={() => openEditCard(table.id, column.id, card)}
            >
                <span class="block break-words text-sm font-medium">
                    {card.title}
                </span>
                <span
                    class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground"
                >
                    {#if card.creatorName}
                        <span>
                            {t('By :name', { name: card.creatorName })}
                        </span>
                    {/if}
                    {#if card.dueOn}
                        <span class="inline-flex items-center gap-1">
                            <CalendarDays class="size-3.5" aria-hidden="true" />
                            {card.dueOn}
                        </span>
                    {/if}
                </span>
            </button>
            {#if card.assigneeName}
                <Avatar class="size-8 shrink-0">
                    <AvatarFallback>
                        {memberInitial(card.assigneeName)}
                    </AvatarFallback>
                </Avatar>
            {/if}
        </div>
    </article>
{/snippet}

{#snippet emptySlot(
    table: ProjectCardTable,
    column: ProjectCardColumn,
    className: string,
)}
    <button
        type="button"
        class={cn('rounded-md border border-dashed', className)}
        aria-label={t('Add card')}
        onclick={() => openCreateCard(table.id, column.id)}
        ondragover={(event) => allowDrop(event, table.id)}
        ondrop={(event) => dropCard(event, table.id, column.id)}
    >
        {#if filterText}
            <span class="text-sm text-muted-foreground">
                {t('No matching cards')}
            </span>
        {/if}
    </button>
{/snippet}

{#snippet systemColumnStrip(
    table: ProjectCardTable,
    column: ProjectCardColumn,
    className: string,
)}
    <section
        class={cn(
            'flex w-12 shrink-0 self-stretch flex-col items-center gap-3 px-2 py-4',
            className,
        )}
        aria-labelledby={`column-${column.id}`}
        ondragover={(event) => allowDrop(event, table.id)}
        ondrop={(event) => dropCard(event, table.id, column.id)}
    >
        <span class="text-xs text-muted-foreground">
            ({visibleCards(column).length})
        </span>
        <h2
            id={`column-${column.id}`}
            class="font-semibold whitespace-nowrap"
            style="writing-mode: vertical-rl"
        >
            <Link
                href={CardTableController.showColumn([
                    currentTeam.slug,
                    project.id,
                    table.id,
                    column.id,
                ])}
                class="rounded-sm hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                {t(column.name)}
            </Link>
        </h2>
    </section>
{/snippet}

{#snippet collapsedStrip(
    table: ProjectCardTable,
    column: ProjectCardColumn,
    columnIndex: number,
)}
    <section
        class={cn(
            'relative flex w-12 shrink-0 self-stretch flex-col items-center gap-3 overflow-hidden rounded-md px-2 py-3',
            columnAccent(columnIndex).tint,
        )}
        aria-labelledby={`column-${column.id}`}
        ondragover={(event) => allowDrop(event, table.id)}
        ondrop={(event) => dropCard(event, table.id, column.id)}
    >
        <span
            class={cn(
                'absolute inset-x-0 top-0 h-1',
                columnAccent(columnIndex).bar,
            )}
            aria-hidden="true"
        ></span>
        {@render columnMenu(table, column, columnIndex)}
        <span class="text-xs text-muted-foreground">
            ({visibleCards(column).length})
        </span>
        <h2
            id={`column-${column.id}`}
            class="font-semibold whitespace-nowrap"
            style="writing-mode: vertical-rl"
        >
            <Link
                href={CardTableController.showColumn([
                    currentTeam.slug,
                    project.id,
                    table.id,
                    column.id,
                ])}
                class="rounded-sm hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                {column.name}
            </Link>
        </h2>
        <Form
            {...CardTableController.toggleColumn.form([
                currentTeam.slug,
                project.id,
                table.id,
                column.id,
            ])}
            options={{ preserveScroll: true }}
        >
            {#snippet children({ processing })}
                <Button
                    type="submit"
                    variant="ghost"
                    size="icon"
                    class="size-6"
                    disabled={processing}
                    aria-label={t('Expand column')}
                    title={t('Expand column')}
                >
                    <Maximize2 class="size-4" aria-hidden="true" />
                </Button>
            {/snippet}
        </Form>
    </section>
{/snippet}

<div class="min-h-full bg-muted/40 px-0 pb-8 sm:px-5 sm:pt-2 lg:px-6">
    <main
        class="mx-auto flex min-h-[calc(100dvh-7rem)] w-full max-w-[120rem] flex-col overflow-hidden bg-card shadow-sm"
    >
        <div class="flex min-h-10 items-center border-b px-4">
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
                {@const columnLayout = cardTableColumnLayout(table.columns)}
                <section
                    class="flex min-h-[calc(100dvh-9.5rem)] flex-col"
                    aria-labelledby={`card-table-${table.id}`}
                >
                    <header class="shrink-0 border-b px-4 py-3">
                        <h1
                            id={`card-table-${table.id}`}
                            class="break-words text-4xl font-bold tracking-tight"
                        >
                            {table.name}
                        </h1>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <Button
                                type="button"
                                size="sm"
                                onclick={() =>
                                    openCreateCard(table.id, table.triage.id)}
                            >
                                <Plus data-icon="inline-start" />
                                {t('Add a card')}
                            </Button>
                            <div class="relative w-36">
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
                                    class="h-8 pl-9 text-xs"
                                    placeholder={t('Filter...')}
                                />
                            </div>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    {#snippet children(triggerProps)}
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="size-8"
                                            onclick={triggerProps.onclick}
                                            aria-expanded={triggerProps[
                                                'aria-expanded'
                                            ]}
                                            data-state={triggerProps[
                                                'data-state'
                                            ]}
                                            aria-label={t('Table settings')}
                                        >
                                            <Ellipsis />
                                        </Button>
                                    {/snippet}
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="start">
                                    <DropdownMenuGroup>
                                        <DropdownMenuItem asChild>
                                            {#snippet children(itemProps)}
                                                <button
                                                    type="button"
                                                    class={itemProps.class}
                                                    onclick={() => {
                                                        itemProps.onClick?.();
                                                        openRenameTable(table);
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
                                            {#snippet children(itemProps)}
                                                <button
                                                    type="button"
                                                    class={itemProps.class}
                                                    onclick={() => {
                                                        itemProps.onClick?.();
                                                        newTableDialogOpen = true;
                                                    }}
                                                >
                                                    <Plus aria-hidden="true" />
                                                    {t('Add table')}
                                                </button>
                                            {/snippet}
                                        </DropdownMenuItem>
                                    </DropdownMenuGroup>
                                    {#if table.canDelete}
                                        <DropdownMenuSeparator />
                                        <DropdownMenuGroup>
                                            <DropdownMenuItem asChild>
                                                {#snippet children(itemProps)}
                                                    <button
                                                        type="button"
                                                        class={itemProps.class}
                                                        onclick={() => {
                                                            itemProps.onClick?.();
                                                            deleteTable(table);
                                                        }}
                                                    >
                                                        <Trash2
                                                            aria-hidden="true"
                                                        />
                                                        {t('Delete table')}
                                                    </button>
                                                {/snippet}
                                            </DropdownMenuItem>
                                        </DropdownMenuGroup>
                                    {/if}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </header>

                    <div class="flex min-h-0 flex-1 flex-col">
                        <div class="flex min-w-0 items-stretch gap-3">
                            <section
                                class="min-w-0 flex-1 py-4 pl-4"
                                aria-labelledby={`column-${table.triage.id}`}
                                ondragover={(event) =>
                                    allowDrop(event, table.id)}
                                ondrop={(event) =>
                                    dropCard(event, table.id, table.triage.id)}
                            >
                                <h2
                                    id={`column-${table.triage.id}`}
                                    class="font-semibold"
                                >
                                    <Link
                                        href={CardTableController.showColumn([
                                            currentTeam.slug,
                                            project.id,
                                            table.id,
                                            table.triage.id,
                                        ])}
                                        class="hover:underline"
                                    >
                                        {table.triage.name}
                                        <span
                                            class="font-normal text-muted-foreground"
                                        >
                                            ({visibleCards(table.triage)
                                                .length})
                                        </span>
                                    </Link>
                                </h2>
                                <div
                                    class="mt-2 flex gap-2 overflow-x-auto overscroll-x-contain pb-2"
                                >
                                    {#each visibleCards(table.triage) as card (card.id)}
                                        {@render cardArticle(
                                            table,
                                            table.triage,
                                            card,
                                            'w-48 max-w-[75vw] shrink-0',
                                        )}
                                    {:else}
                                        {@render emptySlot(
                                            table,
                                            table.triage,
                                            'min-h-16 w-48 max-w-[75vw] shrink-0',
                                        )}
                                    {/each}
                                </div>
                            </section>
                            {@render systemColumnStrip(
                                table,
                                table.notNow,
                                'min-h-32 border-l bg-muted',
                            )}
                        </div>

                        <div
                            class="flex min-h-0 min-w-0 flex-1 items-stretch gap-2 border-t pl-4"
                        >
                            {#if columnLayout.expandedColumns.length > 0}
                                <div
                                    class="flex min-w-0 flex-1 items-start gap-2 overflow-x-auto overscroll-x-contain pt-4 pb-3 has-data-[state=open]:overflow-visible"
                                >
                                    {#each columnLayout.expandedColumns as column (column.id)}
                                        {@const columnIndex =
                                            table.columns.indexOf(column)}
                                        {@const visibleColumnCards =
                                            visibleCards(column)}
                                        <section
                                            class={cn(
                                                'min-w-72 grow shrink-0 basis-[calc((100%_-_1.5rem)/4)] rounded-md',
                                                columnAccent(columnIndex).tint,
                                            )}
                                            aria-labelledby={`column-${column.id}`}
                                            ondragover={(event) =>
                                                allowDrop(event, table.id)}
                                            ondrop={(event) =>
                                                dropCard(
                                                    event,
                                                    table.id,
                                                    column.id,
                                                )}
                                        >
                                            <span
                                                class={cn(
                                                    'block h-1 rounded-t-md',
                                                    columnAccent(columnIndex)
                                                        .bar,
                                                )}
                                                aria-hidden="true"
                                            ></span>
                                            {@render columnHeader(
                                                table,
                                                column,
                                                columnIndex,
                                                visibleColumnCards.length,
                                            )}
                                            <div
                                                class="flex flex-col gap-2 px-2 pb-2"
                                            >
                                                {#each visibleColumnCards as card (card.id)}
                                                    {@render cardArticle(
                                                        table,
                                                        column,
                                                        card,
                                                        '',
                                                    )}
                                                {:else}
                                                    {@render emptySlot(
                                                        table,
                                                        column,
                                                        'min-h-10',
                                                    )}
                                                {/each}
                                            </div>
                                        </section>
                                    {/each}
                                </div>
                            {:else if columnLayout.collapsedColumns.length === 0}
                                <div
                                    class="mt-4 flex min-h-10 min-w-0 flex-1 items-center justify-center rounded-md border border-dashed"
                                >
                                    <span class="text-sm text-muted-foreground">
                                        {t('No columns yet')}
                                    </span>
                                </div>
                            {:else}
                                <div class="min-w-0 flex-1"></div>
                            {/if}
                            {#if columnLayout.collapsedColumns.length > 0}
                                <div
                                    class="flex shrink-0 items-stretch gap-2 pt-4 pb-3"
                                    data-collapsed-column-rails
                                >
                                    {#each columnLayout.collapsedColumns as column (column.id)}
                                        {@render collapsedStrip(
                                            table,
                                            column,
                                            table.columns.indexOf(column),
                                        )}
                                    {/each}
                                </div>
                            {/if}
                            <Button
                                type="button"
                                size="icon"
                                class="mt-4 size-6 shrink-0 self-start p-0"
                                onclick={() => openNewColumn(table.id)}
                                aria-label={t('Add column')}
                                title={t('Add column')}
                            >
                                <Plus class="size-4" aria-hidden="true" />
                            </Button>
                            {@render systemColumnStrip(
                                table,
                                table.done,
                                'border-l border-r-4 border-r-chart-2 bg-chart-2/15',
                            )}
                        </div>
                    </div>
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
