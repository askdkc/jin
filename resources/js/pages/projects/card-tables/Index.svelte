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
    import { Form, page, router } from '@inertiajs/svelte';
    import ChevronsLeft from 'lucide-svelte/icons/chevrons-left';
    import ChevronsRight from 'lucide-svelte/icons/chevrons-right';
    import Ellipsis from 'lucide-svelte/icons/ellipsis';
    import GripVertical from 'lucide-svelte/icons/grip-vertical';
    import AttachmentController from '@/actions/App/Http/Controllers/AttachmentController';
    import AlertError from '@/components/AlertError.svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import RichTextContent from '@/components/RichTextContent.svelte';
    import RichTextEditor from '@/components/RichTextEditor.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import {
        Card,
        CardContent,
        CardHeader,
        CardTitle,
    } from '@/components/ui/card';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
    import type { ProjectCardTable, TodoMember } from '@/types';

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
        'bg-violet-400 dark:bg-violet-500',
        'bg-amber-400 dark:bg-amber-500',
        'bg-pink-400 dark:bg-pink-500',
        'bg-emerald-400 dark:bg-emerald-500',
        'bg-sky-400 dark:bg-sky-500',
    ] as const;
    const columnSurfaceClasses = [
        'bg-violet-50/70 dark:bg-violet-950/20',
        'bg-amber-50/70 dark:bg-amber-950/20',
        'bg-pink-50/70 dark:bg-pink-950/20',
        'bg-emerald-50/70 dark:bg-emerald-950/20',
        'bg-sky-50/70 dark:bg-sky-950/20',
    ] as const;
    const columnAccent = (index: number): string =>
        columnAccentClasses[index % columnAccentClasses.length] ??
        columnAccentClasses[0];
    const columnSurface = (index: number): string =>
        columnSurfaceClasses[index % columnSurfaceClasses.length] ??
        columnSurfaceClasses[0];
    const confirmDelete = (): boolean =>
        window.confirm(t('Are you sure you want to delete this?'));
    let draggedCard = $state<{
        tableId: number;
        columnId: number;
        cardId: number;
        version: number;
    } | null>(null);
    const startDragging = (
        event: DragEvent,
        tableId: number,
        columnId: number,
        cardId: number,
        version: number,
    ): void => {
        draggedCard = { tableId, columnId, cardId, version };

        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', String(cardId));
        }
    };
    const allowDrop = (event: DragEvent, tableId: number): void => {
        if (draggedCard?.tableId === tableId) {
            event.preventDefault();

            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'move';
            }
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

<div
    class="min-h-[calc(100vh-7rem)] w-full space-y-5 bg-slate-100/70 p-3 sm:p-5 lg:p-6 dark:bg-slate-950/50"
>
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">
            {currentTeam.menuNames.cardTable}
        </h1>
        <p class="text-sm text-muted-foreground">{project.name}</p>
    </div>

    {#if versionError}
        <AlertError errors={[versionError]} title={t('Update conflict')} />
    {/if}

    <Card class="mx-auto max-w-4xl shadow-none">
        <CardContent class="pt-6">
            <Form
                {...CardTableController.storeTable.form(routeArguments)}
                resetOnSuccess
                class="flex flex-col gap-2 sm:flex-row"
            >
                {#snippet children({ errors, processing })}
                    <div class="min-w-0 flex-1">
                        <Label for="new-card-table" class="sr-only">
                            {t('New card table')}
                        </Label>
                        <Input
                            id="new-card-table"
                            name="name"
                            maxlength={255}
                            required
                            placeholder={t('New card table')}
                        />
                        <InputError message={errors.name} />
                    </div>
                    <Button type="submit" disabled={processing}>
                        {t('Add table')}
                    </Button>
                {/snippet}
            </Form>
        </CardContent>
    </Card>

    {#if tables.length === 0}
        <Card>
            <CardContent
                class="py-10 text-center text-sm text-muted-foreground"
            >
                {t('No card tables yet')}
            </CardContent>
        </Card>
    {:else}
        {#each tables as table (table.id)}
            <section
                class="min-w-0 space-y-4 overflow-hidden rounded-2xl border border-slate-200 bg-white p-4 shadow-[0_14px_40px_rgba(15,23,42,0.06)] sm:p-5 dark:border-slate-800 dark:bg-slate-900"
            >
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="min-w-0">
                        <h2 class="break-words text-xl font-semibold">
                            {table.name}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {t(':count columns', {
                                count: String(table.columns.length),
                            })}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <details class="relative">
                            <summary class="cursor-pointer text-sm font-medium">
                                {t('Rename')}
                            </summary>
                            <Form
                                {...CardTableController.updateTable.form([
                                    currentTeam.slug,
                                    project.id,
                                    table.id,
                                ])}
                                class="mt-2 flex min-w-0 gap-2"
                            >
                                {#snippet children({ errors, processing })}
                                    <div class="min-w-0 flex-1">
                                        <Input
                                            name="name"
                                            value={table.name}
                                            maxlength={255}
                                            required
                                            aria-label={t('Table name')}
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        {t('Save')}
                                    </Button>
                                {/snippet}
                            </Form>
                        </details>
                        {#if table.canDelete}
                            <Form
                                {...CardTableController.destroyTable.form([
                                    currentTeam.slug,
                                    project.id,
                                    table.id,
                                ])}
                                onBefore={confirmDelete}
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
                    </div>
                </div>

                <Form
                    {...CardTableController.storeColumn.form([
                        currentTeam.slug,
                        project.id,
                        table.id,
                    ])}
                    resetOnSuccess
                    class="flex flex-col gap-2 sm:flex-row"
                >
                    {#snippet children({ errors, processing })}
                        <div class="min-w-0 flex-1">
                            <Label
                                for={`new-column-${table.id}`}
                                class="sr-only"
                            >
                                {t('New column')}
                            </Label>
                            <Input
                                id={`new-column-${table.id}`}
                                name="name"
                                maxlength={255}
                                required
                                placeholder={t('New column')}
                            />
                            <InputError message={errors.name} />
                        </div>
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                        >
                            {t('Add column')}
                        </Button>
                    {/snippet}
                </Form>

                {#if table.columns.length === 0}
                    <div
                        class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
                    >
                        {t('No columns yet')}
                    </div>
                {:else}
                    <div
                        class="flex min-w-0 items-stretch gap-2.5 overflow-x-auto pb-3"
                    >
                        {#each table.columns as column, columnIndex (column.id)}
                            <div
                                class={column.collapsed
                                    ? 'w-14 shrink-0'
                                    : 'w-[min(20rem,84vw)] shrink-0'}
                                role="group"
                                aria-label={column.name}
                                ondragover={(event) =>
                                    allowDrop(event, table.id)}
                                ondrop={(event) =>
                                    dropCard(event, table.id, column.id)}
                            >
                                <Card
                                    class={`relative h-full min-w-0 gap-0 overflow-hidden border-slate-200 py-0 shadow-none dark:border-slate-700 ${columnSurface(columnIndex)}`}
                                >
                                    <span
                                        class={`absolute inset-x-0 top-0 z-10 h-1 ${columnAccent(columnIndex)}`}
                                        aria-hidden="true"
                                    ></span>
                                    <CardHeader
                                        class={column.collapsed
                                            ? 'min-h-[28rem] px-2 pt-3 pb-4'
                                            : 'space-y-2 border-b border-slate-200/80 px-3 pt-5 pb-3 dark:border-slate-700/80'}
                                    >
                                        {#if column.collapsed}
                                            <div
                                                class="flex h-full min-h-[25rem] flex-col items-center gap-3"
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
                                                            title={t(
                                                                'Expand column',
                                                            )}
                                                        >
                                                            <ChevronsRight
                                                                class="size-4"
                                                            />
                                                        </Button>
                                                    {/snippet}
                                                </Form>
                                                <span
                                                    class="text-xs font-semibold text-muted-foreground"
                                                >
                                                    {column.cards.length}
                                                </span>
                                                <CardTitle
                                                    class="flex flex-1 items-start text-sm"
                                                >
                                                    <span
                                                        class="break-words whitespace-nowrap"
                                                        style="writing-mode: vertical-rl"
                                                    >
                                                        {column.name}
                                                    </span>
                                                </CardTitle>
                                            </div>
                                        {:else}
                                            <div
                                                class="flex items-start justify-between gap-2"
                                            >
                                                <div class="min-w-0">
                                                    <CardTitle
                                                        class="break-words text-base"
                                                    >
                                                        {column.name}
                                                    </CardTitle>
                                                    <p
                                                        class="text-xs text-muted-foreground"
                                                    >
                                                        {t(':count cards', {
                                                            count: String(
                                                                column.cards
                                                                    .length,
                                                            ),
                                                        })}
                                                    </p>
                                                </div>
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
                                                            aria-label={column.collapsed
                                                                ? t(
                                                                      'Expand column',
                                                                  )
                                                                : t(
                                                                      'Collapse column',
                                                                  )}
                                                            title={column.collapsed
                                                                ? t(
                                                                      'Expand column',
                                                                  )
                                                                : t(
                                                                      'Collapse column',
                                                                  )}
                                                        >
                                                            {#if column.collapsed}
                                                                <ChevronsRight
                                                                    class="size-4"
                                                                />
                                                            {:else}
                                                                <ChevronsLeft
                                                                    class="size-4"
                                                                />
                                                            {/if}
                                                        </Button>
                                                    {/snippet}
                                                </Form>
                                                {#if column.canDelete}
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

                                            <details>
                                                <summary
                                                    class="[&::-webkit-details-marker]:hidden flex size-7 cursor-pointer list-none items-center justify-center rounded-md text-muted-foreground hover:bg-white/70 hover:text-foreground dark:hover:bg-slate-800"
                                                    aria-label={t(
                                                        'Column settings',
                                                    )}
                                                >
                                                    <Ellipsis
                                                        class="size-4"
                                                        aria-hidden="true"
                                                    />
                                                </summary>
                                                <div class="mt-3 space-y-3">
                                                    <Form
                                                        {...CardTableController.updateColumn.form(
                                                            [
                                                                currentTeam.slug,
                                                                project.id,
                                                                table.id,
                                                                column.id,
                                                            ],
                                                        )}
                                                        class="flex min-w-0 gap-2"
                                                    >
                                                        {#snippet children({
                                                            errors,
                                                            processing,
                                                        })}
                                                            <div
                                                                class="min-w-0 flex-1"
                                                            >
                                                                <Input
                                                                    name="name"
                                                                    value={column.name}
                                                                    maxlength={255}
                                                                    required
                                                                    aria-label={t(
                                                                        'Column name',
                                                                    )}
                                                                />
                                                                <InputError
                                                                    message={errors.name}
                                                                />
                                                            </div>
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                disabled={processing}
                                                            >
                                                                {t('Save')}
                                                            </Button>
                                                        {/snippet}
                                                    </Form>
                                                    <div
                                                        class="flex flex-wrap gap-2"
                                                    >
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
                                                                <Button
                                                                    type="submit"
                                                                    variant="outline"
                                                                    size="sm"
                                                                    disabled={processing ||
                                                                        columnIndex ===
                                                                            0}
                                                                >
                                                                    {t(
                                                                        'Move left',
                                                                    )}
                                                                </Button>
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
                                                                <Button
                                                                    type="submit"
                                                                    variant="outline"
                                                                    size="sm"
                                                                    disabled={processing ||
                                                                        columnIndex ===
                                                                            table
                                                                                .columns
                                                                                .length -
                                                                                1}
                                                                >
                                                                    {t(
                                                                        'Move right',
                                                                    )}
                                                                </Button>
                                                            {/snippet}
                                                        </Form>
                                                    </div>
                                                </div>
                                            </details>
                                        {/if}
                                    </CardHeader>

                                    {#if !column.collapsed}<CardContent
                                            class="space-y-2 p-2"
                                        >
                                            {#each column.cards as card, cardIndex (card.id)}
                                                <article
                                                    class="min-w-0 rounded-lg border border-slate-200 bg-white p-2.5 shadow-sm dark:border-slate-700 dark:bg-slate-900"
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
                                                        (draggedCard = null)}
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
                                                            cardIndex,
                                                        )}
                                                >
                                                    <div
                                                        class="flex min-w-0 items-start gap-2"
                                                    >
                                                        <GripVertical
                                                            class="mt-0.5 size-3.5 shrink-0 cursor-grab text-muted-foreground/70"
                                                            aria-label={t(
                                                                'Drag to reorder',
                                                            )}
                                                        />
                                                        <div
                                                            class="min-w-0 flex-1"
                                                        >
                                                            <h3
                                                                class="break-words text-[0.82rem] leading-5 font-semibold"
                                                            >
                                                                {card.title}
                                                            </h3>
                                                            <div
                                                                class="mt-2 flex flex-wrap gap-1.5"
                                                            >
                                                                {#if card.assigneeName}
                                                                    <Badge
                                                                        variant="secondary"
                                                                        class="h-5 px-1.5 text-[0.65rem]"
                                                                    >
                                                                        {card.assigneeName}
                                                                    </Badge>
                                                                {/if}
                                                                {#if card.dueOn}
                                                                    <Badge
                                                                        variant="outline"
                                                                        class="h-5 px-1.5 text-[0.65rem]"
                                                                    >
                                                                        {card.dueOn}
                                                                    </Badge>
                                                                {/if}
                                                            </div>
                                                        </div>
                                                    </div>

                                                    {#if card.description}
                                                        <RichTextContent
                                                            content={card.description}
                                                            class="mt-2 text-[0.7rem] leading-4.5 text-muted-foreground"
                                                        />
                                                    {/if}

                                                    <details
                                                        class="mt-3 border-t pt-3"
                                                    >
                                                        <summary
                                                            class="cursor-pointer text-xs font-medium"
                                                        >
                                                            {t(
                                                                'Move or reorder',
                                                            )}
                                                        </summary>
                                                        <div
                                                            class="mt-3 space-y-3"
                                                        >
                                                            <div
                                                                class="flex flex-wrap gap-2"
                                                            >
                                                                <Form
                                                                    {...CardTableController.moveCard.form(
                                                                        [
                                                                            currentTeam.slug,
                                                                            project.id,
                                                                            table.id,
                                                                            column.id,
                                                                            card.id,
                                                                        ],
                                                                    )}
                                                                >
                                                                    {#snippet children({
                                                                        processing,
                                                                    })}
                                                                        <input
                                                                            type="hidden"
                                                                            name="target_column_id"
                                                                            value={column.id}
                                                                        />
                                                                        <input
                                                                            type="hidden"
                                                                            name="target_position"
                                                                            value={Math.max(
                                                                                cardIndex -
                                                                                    1,
                                                                                0,
                                                                            )}
                                                                        />
                                                                        <input
                                                                            type="hidden"
                                                                            name="version"
                                                                            value={card.version}
                                                                        />
                                                                        <Button
                                                                            type="submit"
                                                                            variant="outline"
                                                                            size="sm"
                                                                            disabled={processing ||
                                                                                cardIndex ===
                                                                                    0}
                                                                        >
                                                                            {t(
                                                                                'Move up',
                                                                            )}
                                                                        </Button>
                                                                    {/snippet}
                                                                </Form>
                                                                <Form
                                                                    {...CardTableController.moveCard.form(
                                                                        [
                                                                            currentTeam.slug,
                                                                            project.id,
                                                                            table.id,
                                                                            column.id,
                                                                            card.id,
                                                                        ],
                                                                    )}
                                                                >
                                                                    {#snippet children({
                                                                        processing,
                                                                    })}
                                                                        <input
                                                                            type="hidden"
                                                                            name="target_column_id"
                                                                            value={column.id}
                                                                        />
                                                                        <input
                                                                            type="hidden"
                                                                            name="target_position"
                                                                            value={cardIndex +
                                                                                1}
                                                                        />
                                                                        <input
                                                                            type="hidden"
                                                                            name="version"
                                                                            value={card.version}
                                                                        />
                                                                        <Button
                                                                            type="submit"
                                                                            variant="outline"
                                                                            size="sm"
                                                                            disabled={processing ||
                                                                                cardIndex ===
                                                                                    column
                                                                                        .cards
                                                                                        .length -
                                                                                        1}
                                                                        >
                                                                            {t(
                                                                                'Move down',
                                                                            )}
                                                                        </Button>
                                                                    {/snippet}
                                                                </Form>
                                                            </div>

                                                            {#if table.columns.length > 1}
                                                                <Form
                                                                    {...CardTableController.moveCard.form(
                                                                        [
                                                                            currentTeam.slug,
                                                                            project.id,
                                                                            table.id,
                                                                            column.id,
                                                                            card.id,
                                                                        ],
                                                                    )}
                                                                    class="flex flex-col gap-2 sm:flex-row"
                                                                >
                                                                    {#snippet children({
                                                                        errors,
                                                                        processing,
                                                                    })}
                                                                        <select
                                                                            name="target_column_id"
                                                                            required
                                                                            aria-label={t(
                                                                                'Move to column',
                                                                            )}
                                                                            class="border-input h-9 min-w-0 flex-1 rounded-md border bg-background px-3 text-sm"
                                                                        >
                                                                            {#each table.columns.filter((target) => target.id !== column.id) as target (target.id)}
                                                                                <option
                                                                                    value={target.id}
                                                                                >
                                                                                    {target.name}
                                                                                </option>
                                                                            {/each}
                                                                        </select>
                                                                        <input
                                                                            type="hidden"
                                                                            name="version"
                                                                            value={card.version}
                                                                        />
                                                                        <Button
                                                                            type="submit"
                                                                            size="sm"
                                                                            disabled={processing}
                                                                        >
                                                                            {t(
                                                                                'Move',
                                                                            )}
                                                                        </Button>
                                                                        <InputError
                                                                            message={errors.version}
                                                                        />
                                                                    {/snippet}
                                                                </Form>
                                                            {/if}
                                                        </div>
                                                    </details>

                                                    <details
                                                        class="mt-3 border-t pt-3"
                                                    >
                                                        <summary
                                                            class="cursor-pointer text-xs font-medium"
                                                        >
                                                            {t('Edit card')}
                                                        </summary>
                                                        <Form
                                                            {...CardTableController.updateCard.form(
                                                                [
                                                                    currentTeam.slug,
                                                                    project.id,
                                                                    table.id,
                                                                    column.id,
                                                                    card.id,
                                                                ],
                                                            )}
                                                            class="mt-3 space-y-3"
                                                        >
                                                            {#snippet children({
                                                                errors,
                                                                processing,
                                                            })}
                                                                <input
                                                                    type="hidden"
                                                                    name="version"
                                                                    value={card.version}
                                                                />
                                                                <div>
                                                                    <Label
                                                                        for={`card-title-${card.id}`}
                                                                    >
                                                                        {t(
                                                                            'Title',
                                                                        )}
                                                                    </Label>
                                                                    <Input
                                                                        id={`card-title-${card.id}`}
                                                                        name="title"
                                                                        value={card.title}
                                                                        maxlength={255}
                                                                        required
                                                                    />
                                                                    <InputError
                                                                        message={errors.title}
                                                                    />
                                                                </div>
                                                                <div>
                                                                    <Label
                                                                        for={`card-description-${card.id}`}
                                                                    >
                                                                        {t(
                                                                            'Description',
                                                                        )}
                                                                    </Label>
                                                                    <RichTextEditor
                                                                        id={`card-description-${card.id}`}
                                                                        name="description"
                                                                        value={card.description}
                                                                        ariaLabel={t(
                                                                            'Description',
                                                                        )}
                                                                        attachmentUploadUrl={AttachmentController.uploadInline.url(
                                                                            routeArguments,
                                                                        )}
                                                                    />
                                                                    <InputError
                                                                        message={errors.description}
                                                                    />
                                                                </div>
                                                                <div>
                                                                    <Label
                                                                        for={`card-assignee-${card.id}`}
                                                                    >
                                                                        {t(
                                                                            'Assignee',
                                                                        )}
                                                                    </Label>
                                                                    <select
                                                                        id={`card-assignee-${card.id}`}
                                                                        name="assignee_id"
                                                                        class="border-input h-10 w-full rounded-md border bg-background px-3 text-sm"
                                                                    >
                                                                        <option
                                                                            value=""
                                                                            >{t(
                                                                                'Unassigned',
                                                                            )}</option
                                                                        >
                                                                        {#each members as member (member.id)}
                                                                            <option
                                                                                value={member.id}
                                                                                selected={member.id ===
                                                                                    card.assigneeId}
                                                                            >
                                                                                {member.name}
                                                                            </option>
                                                                        {/each}
                                                                    </select>
                                                                    <InputError
                                                                        message={errors.assignee_id}
                                                                    />
                                                                </div>
                                                                <div>
                                                                    <Label
                                                                        for={`card-due-${card.id}`}
                                                                    >
                                                                        {t(
                                                                            'Due date',
                                                                        )}
                                                                    </Label>
                                                                    <Input
                                                                        id={`card-due-${card.id}`}
                                                                        type="date"
                                                                        name="due_on"
                                                                        value={card.dueOn ||
                                                                            ''}
                                                                    />
                                                                    <InputError
                                                                        message={errors.due_on}
                                                                    />
                                                                </div>
                                                                <InputError
                                                                    message={errors.version}
                                                                />
                                                                <div
                                                                    class="flex flex-wrap gap-2"
                                                                >
                                                                    <Button
                                                                        type="submit"
                                                                        size="sm"
                                                                        disabled={processing}
                                                                    >
                                                                        {t(
                                                                            'Save',
                                                                        )}
                                                                    </Button>
                                                                </div>
                                                            {/snippet}
                                                        </Form>
                                                        {#if card.canDelete}
                                                            <Form
                                                                {...CardTableController.destroyCard.form(
                                                                    [
                                                                        currentTeam.slug,
                                                                        project.id,
                                                                        table.id,
                                                                        column.id,
                                                                        card.id,
                                                                    ],
                                                                )}
                                                                onBefore={confirmDelete}
                                                                class="mt-2"
                                                            >
                                                                {#snippet children({
                                                                    processing,
                                                                })}
                                                                    <Button
                                                                        type="submit"
                                                                        variant="destructive"
                                                                        size="sm"
                                                                        disabled={processing}
                                                                    >
                                                                        {t(
                                                                            'Delete',
                                                                        )}
                                                                    </Button>
                                                                {/snippet}
                                                            </Form>
                                                        {/if}
                                                    </details>
                                                </article>
                                            {/each}

                                            <button
                                                type="button"
                                                class="w-full rounded-md border border-dashed border-slate-300 px-2 py-1.5 text-[0.68rem] text-muted-foreground transition-colors hover:bg-white/60 dark:border-slate-700 dark:hover:bg-slate-900/60"
                                                ondragover={(event) =>
                                                    allowDrop(event, table.id)}
                                                ondrop={(event) =>
                                                    dropCard(
                                                        event,
                                                        table.id,
                                                        column.id,
                                                    )}
                                            >
                                                {t('Drop at end')}
                                            </button>

                                            <details
                                                class="rounded-lg border border-slate-200 bg-white/50 p-2 dark:border-slate-700 dark:bg-slate-900/40"
                                            >
                                                <summary
                                                    class="cursor-pointer text-xs font-medium text-muted-foreground"
                                                >
                                                    {t('Add card')}
                                                </summary>
                                                <Form
                                                    {...CardTableController.storeCard.form(
                                                        [
                                                            currentTeam.slug,
                                                            project.id,
                                                            table.id,
                                                            column.id,
                                                        ],
                                                    )}
                                                    resetOnSuccess
                                                    class="mt-3 space-y-3"
                                                >
                                                    {#snippet children({
                                                        errors,
                                                        processing,
                                                    })}
                                                        <div>
                                                            <Label
                                                                for={`new-card-title-${column.id}`}
                                                            >
                                                                {t('Title')}
                                                            </Label>
                                                            <Input
                                                                id={`new-card-title-${column.id}`}
                                                                name="title"
                                                                maxlength={255}
                                                                required
                                                            />
                                                            <InputError
                                                                message={errors.title}
                                                            />
                                                        </div>
                                                        <div>
                                                            <Label
                                                                for={`new-card-description-${column.id}`}
                                                            >
                                                                {t(
                                                                    'Description',
                                                                )}
                                                            </Label>
                                                            <RichTextEditor
                                                                id={`new-card-description-${column.id}`}
                                                                name="description"
                                                                ariaLabel={t(
                                                                    'Description',
                                                                )}
                                                                attachmentUploadUrl={AttachmentController.uploadInline.url(
                                                                    routeArguments,
                                                                )}
                                                            />
                                                            <InputError
                                                                message={errors.description}
                                                            />
                                                        </div>
                                                        <div
                                                            class="grid gap-3 sm:grid-cols-2"
                                                        >
                                                            <div>
                                                                <Label
                                                                    for={`new-card-assignee-${column.id}`}
                                                                >
                                                                    {t(
                                                                        'Assignee',
                                                                    )}
                                                                </Label>
                                                                <select
                                                                    id={`new-card-assignee-${column.id}`}
                                                                    name="assignee_id"
                                                                    class="border-input h-10 w-full rounded-md border bg-background px-3 text-sm"
                                                                >
                                                                    <option
                                                                        value=""
                                                                        >{t(
                                                                            'Unassigned',
                                                                        )}</option
                                                                    >
                                                                    {#each members as member (member.id)}
                                                                        <option
                                                                            value={member.id}
                                                                            >{member.name}</option
                                                                        >
                                                                    {/each}
                                                                </select>
                                                                <InputError
                                                                    message={errors.assignee_id}
                                                                />
                                                            </div>
                                                            <div>
                                                                <Label
                                                                    for={`new-card-due-${column.id}`}
                                                                >
                                                                    {t(
                                                                        'Due date',
                                                                    )}
                                                                </Label>
                                                                <Input
                                                                    id={`new-card-due-${column.id}`}
                                                                    type="date"
                                                                    name="due_on"
                                                                />
                                                                <InputError
                                                                    message={errors.due_on}
                                                                />
                                                            </div>
                                                        </div>
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            disabled={processing}
                                                        >
                                                            {t('Add card')}
                                                        </Button>
                                                    {/snippet}
                                                </Form>
                                            </details>
                                        </CardContent>{/if}
                                </Card>
                            </div>
                        {/each}
                    </div>
                {/if}
            </section>
        {/each}
    {/if}
</div>
