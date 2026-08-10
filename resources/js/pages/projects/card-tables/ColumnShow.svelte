<script module lang="ts">
    import CardTableController from '@/actions/App/Http/Controllers/CardTableController';
    import { show } from '@/routes/projects';
    import type { ProjectCardColumnDetail, ProjectTool, Team } from '@/types';

    export const layout = (props: {
        currentTeam: Team;
        project: ProjectTool;
        table: { id: number; name: string };
        column: ProjectCardColumnDetail;
    }) => ({
        breadcrumbs: [
            {
                title: props.project.name,
                href: show([props.currentTeam.slug, props.project.id]),
            },
            {
                title: props.table.name,
                href: CardTableController.index([
                    props.currentTeam.slug,
                    props.project.id,
                ]),
            },
            {
                title: props.column.name,
                href: CardTableController.showColumn([
                    props.currentTeam.slug,
                    props.project.id,
                    props.table.id,
                    props.column.id,
                ]),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import ChevronLeft from 'lucide-svelte/icons/chevron-left';
    import Ellipsis from 'lucide-svelte/icons/ellipsis';
    import Pencil from 'lucide-svelte/icons/pencil';
    import Plus from 'lucide-svelte/icons/plus';
    import Search from 'lucide-svelte/icons/search';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import { tick } from 'svelte';
    import AttachmentController from '@/actions/App/Http/Controllers/AttachmentController';
    import CommentController from '@/actions/App/Http/Controllers/CommentController';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import RichTextContent from '@/components/RichTextContent.svelte';
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
        DropdownMenuItem,
        DropdownMenuTrigger,
    } from '@/components/ui/dropdown-menu';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { submitFormById } from '@/lib/form-submit';
    import { currentLocale, t } from '@/lib/i18n';
    import { getInitials } from '@/lib/initials';
    import type { MessageComment, ProjectCard, TodoMember } from '@/types';

    let {
        currentTeam,
        project,
        table,
        column,
        members,
    }: {
        currentTeam: Team;
        project: ProjectTool;
        table: { id: number; name: string };
        column: ProjectCardColumnDetail;
        members: TodoMember[];
    } = $props();

    let filterText = $state('');
    let createDialogOpen = $state(false);
    let createDescriptionOpen = $state(false);
    let renameDialogOpen = $state(false);
    let editingCommentId = $state<number | null>(null);
    let commentComposerOpen = $state(false);

    const routeArguments = $derived([
        currentTeam.slug,
        project.id,
        table.id,
        column.id,
    ] as [string, number, number, number]);
    const attachmentUploadUrl = $derived(
        AttachmentController.uploadInline.url([currentTeam.slug, project.id]),
    );
    const visibleCards = $derived.by((): ProjectCard[] => {
        const query = filterText.trim().toLocaleLowerCase();

        if (!query) {
            return column.cards;
        }

        return column.cards.filter((card) =>
            [card.title, card.creatorName, card.assigneeName]
                .filter(Boolean)
                .some((value) => value!.toLocaleLowerCase().includes(query)),
        );
    });
    const columnSurfaceClass = $derived(
        column.isDone
            ? 'border-chart-2 bg-chart-2/15'
            : column.isNotNow
              ? 'border-muted-foreground bg-muted'
              : column.isTriage
                ? 'border-border bg-muted/50'
                : 'border-chart-3 bg-chart-3/15',
    );
    const columnDisplayName = $derived(
        (column.isTriage && column.name === 'Triage') ||
            (column.isNotNow && column.name === 'Not Now') ||
            (column.isDone && column.name === 'Done')
            ? t(column.name)
            : column.name,
    );
    const formatDate = (value: string | null): string =>
        value
            ? new Intl.DateTimeFormat(currentLocale(), {
                  dateStyle: 'medium',
                  timeStyle: 'short',
              }).format(new Date(value))
            : '';
    const memberInitial = (name: string | null): string =>
        name?.trim().slice(0, 1).toLocaleUpperCase(currentLocale()) || '?';
    const confirmDelete = (): boolean =>
        window.confirm(t('Are you sure you want to delete this?'));
    const openCommentComposer = async (): Promise<void> => {
        commentComposerOpen = true;
        await tick();
        document.getElementById(`card-column-comment-${column.id}`)?.focus();
    };
    const commentRouteArguments = (comment: MessageComment) =>
        [...routeArguments, comment.id] as [
            string,
            number,
            number,
            number,
            number,
        ];
</script>

<AppHead title={columnDisplayName} />

<div class="min-h-[calc(100vh-7rem)] bg-muted/40 px-3 py-5 sm:px-6 sm:py-8">
    <main
        class="mx-auto w-full max-w-6xl overflow-hidden rounded-xl border bg-card shadow-sm"
    >
        <div class="flex min-h-10 items-center border-b px-4">
            <Link
                href={CardTableController.index([currentTeam.slug, project.id])}
                class="inline-flex items-center gap-1 text-sm font-bold hover:underline"
            >
                <ChevronLeft class="size-4" aria-hidden="true" />
                {table.name}
            </Link>
        </div>

        <section class="px-5 py-7 sm:px-10 sm:py-10">
            <h1 class="break-words text-4xl font-bold tracking-tight">
                {columnDisplayName}
            </h1>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    size="sm"
                    onclick={() => {
                        createDescriptionOpen = false;
                        createDialogOpen = true;
                    }}
                >
                    <Plus data-icon="inline-start" />
                    {t('Add a card')}
                </Button>
                <div class="relative w-40">
                    <Label for="column-card-filter" class="sr-only">
                        {t('Filter')}
                    </Label>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        id="column-card-filter"
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
                                aria-expanded={triggerProps['aria-expanded']}
                                data-state={triggerProps['data-state']}
                                aria-label={t('Column settings')}
                            >
                                <Ellipsis aria-hidden="true" />
                            </Button>
                        {/snippet}
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem asChild>
                            {#snippet children(itemProps)}
                                <button
                                    type="button"
                                    class={itemProps.class}
                                    onclick={() => {
                                        itemProps.onClick?.();
                                        renameDialogOpen = true;
                                    }}
                                >
                                    <Pencil aria-hidden="true" />
                                    {t('Rename')}
                                </button>
                            {/snippet}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <section
                aria-labelledby="column-cards-heading"
                class={`mt-7 max-w-3xl rounded-md border-t-4 p-2.5 ${columnSurfaceClass}`}
            >
                <h2 id="column-cards-heading" class="px-1 pb-2 font-semibold">
                    {t(':count cards', { count: String(visibleCards.length) })}
                </h2>
                <div class="space-y-2">
                    {#each visibleCards as card (card.id)}
                        <article
                            class="rounded-md border bg-card px-3 py-2 shadow-sm"
                        >
                            <div class="flex items-start gap-3">
                                <div class="min-w-0 flex-1">
                                    <h3 class="break-words font-medium">
                                        {card.title}
                                    </h3>
                                    <p
                                        class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
                                    >
                                        {#if card.creatorName}
                                            <span>
                                                {t('By :name', {
                                                    name: card.creatorName,
                                                })}
                                            </span>
                                        {/if}
                                        {#if card.dueOn}
                                            <span
                                                class="inline-flex items-center gap-1"
                                            >
                                                <CalendarDays
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                                {card.dueOn}
                                            </span>
                                        {/if}
                                    </p>
                                </div>
                                {#if card.assigneeName}
                                    <Avatar class="size-8 shrink-0">
                                        <AvatarFallback>
                                            {memberInitial(card.assigneeName)}
                                        </AvatarFallback>
                                    </Avatar>
                                {/if}
                            </div>
                        </article>
                    {:else}
                        <div
                            class="flex min-h-16 items-center justify-center rounded-md border border-dashed bg-card/40 px-3 text-sm text-muted-foreground"
                        >
                            {filterText
                                ? t('No matching cards')
                                : t('No cards yet')}
                        </div>
                    {/each}
                </div>
            </section>
        </section>

        <section
            aria-labelledby="column-comments-heading"
            class="border-t px-5 py-7 sm:px-10 sm:py-9"
        >
            <h2 id="column-comments-heading" class="text-lg font-bold">
                {t('Comments')} ({column.comments.length})
            </h2>

            {#if column.comments.length === 0}
                <p class="border-b py-5 text-sm text-muted-foreground">
                    {t('No comments yet')}
                </p>
            {:else}
                <div class="mt-2 divide-y">
                    {#each column.comments as comment (comment.id)}
                        <article class="flex items-start gap-3 py-5">
                            <Avatar class="size-9 shrink-0">
                                <AvatarFallback class="text-xs font-bold">
                                    {getInitials(
                                        comment.authorName || t('Deleted user'),
                                    )}
                                </AvatarFallback>
                            </Avatar>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex items-start justify-between gap-3"
                                >
                                    <p class="text-sm text-muted-foreground">
                                        <strong class="text-foreground">
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
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    class="size-7"
                                                    aria-label={t('Edit')}
                                                    onclick={() =>
                                                        (editingCommentId =
                                                            comment.id)}
                                                >
                                                    <Pencil class="size-3.5" />
                                                </Button>
                                            {/if}
                                            <Form
                                                {...CommentController.destroyCardColumn.form(
                                                    commentRouteArguments(
                                                        comment,
                                                    ),
                                                )}
                                                onBefore={confirmDelete}
                                            >
                                                {#snippet children({
                                                    processing,
                                                })}
                                                    <Button
                                                        type="submit"
                                                        variant="ghost"
                                                        size="icon"
                                                        class="size-7 text-muted-foreground hover:text-destructive"
                                                        disabled={processing}
                                                        aria-label={t('Delete')}
                                                    >
                                                        <Trash2
                                                            class="size-3.5"
                                                        />
                                                    </Button>
                                                {/snippet}
                                            </Form>
                                        </div>
                                    {/if}
                                </div>
                                {#if editingCommentId === comment.id}
                                    <Form
                                        {...CommentController.updateCardColumn.form(
                                            commentRouteArguments(comment),
                                        )}
                                        onSuccess={() =>
                                            (editingCommentId = null)}
                                        class="mt-3 space-y-2"
                                    >
                                        {#snippet children({
                                            errors,
                                            processing,
                                        })}
                                            <RichTextEditor
                                                id={`card-column-comment-edit-${comment.id}`}
                                                name="body"
                                                value={comment.body}
                                                required
                                                ariaLabel={t('Comment')}
                                                mentionables={members}
                                                {attachmentUploadUrl}
                                            />
                                            <InputError message={errors.body} />
                                            <div class="flex gap-2">
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    {t('Save')}
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={processing}
                                                    onclick={() =>
                                                        (editingCommentId =
                                                            null)}
                                                >
                                                    {t('Cancel')}
                                                </Button>
                                            </div>
                                        {/snippet}
                                    </Form>
                                {:else}
                                    <RichTextContent
                                        content={comment.body}
                                        class="mt-1.5 text-sm leading-6"
                                    />
                                {/if}
                            </div>
                        </article>
                    {/each}
                </div>
            {/if}

            <div class="mt-6 border-t pt-6">
                {#if commentComposerOpen}
                    <Form
                        {...CommentController.storeCardColumn.form(
                            routeArguments,
                        )}
                        id={`card-column-comment-form-${column.id}`}
                        resetOnSuccess
                        onSuccess={() => (commentComposerOpen = false)}
                        class="space-y-3"
                    >
                        {#snippet children({ errors, processing })}
                            <RichTextEditor
                                id={`card-column-comment-${column.id}`}
                                name="body"
                                placeholder={t('Add your comment…')}
                                required
                                ariaLabel={t('Comment')}
                                mentionables={members}
                                {attachmentUploadUrl}
                                onSubmitShortcut={() =>
                                    !processing &&
                                    submitFormById(
                                        `card-column-comment-form-${column.id}`,
                                    )}
                            />
                            <InputError message={errors.body} />
                            <div class="flex flex-wrap gap-2">
                                <Button type="submit" disabled={processing}>
                                    {t('Add comment')}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={processing}
                                    onclick={() =>
                                        (commentComposerOpen = false)}
                                >
                                    {t('Cancel')}
                                </Button>
                            </div>
                        {/snippet}
                    </Form>
                {:else}
                    <button
                        type="button"
                        aria-expanded="false"
                        onclick={openCommentComposer}
                        class="flex min-h-14 w-full items-center rounded-lg px-4 text-left text-base font-medium text-muted-foreground outline-none hover:bg-muted/60 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        {t('Comment on this')}
                    </button>
                {/if}
            </div>
        </section>
    </main>
</div>

<Dialog bind:open={renameDialogOpen}>
    <DialogContent class="sm:max-w-md">
        <DialogTitle>{t('Rename column')}</DialogTitle>
        <Form
            {...CardTableController.updateColumn.form(routeArguments)}
            onSuccess={() => (renameDialogOpen = false)}
            class="mt-5 flex flex-col gap-4"
        >
            {#snippet children({ errors, processing })}
                <div class="flex flex-col gap-2">
                    <Label for="rename-column-name">{t('Name')}</Label>
                    <Input
                        id="rename-column-name"
                        name="name"
                        value={column.name}
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
    </DialogContent>
</Dialog>

<Dialog bind:open={createDialogOpen}>
    <DialogContent
        class="max-h-[calc(100dvh-2rem)] max-w-3xl overflow-y-auto sm:p-8"
    >
        <DialogTitle>{t('Add a new card')}</DialogTitle>
        <Form
            {...CardTableController.storeCard.form(routeArguments)}
            resetOnSuccess
            onSuccess={() => (createDialogOpen = false)}
            class="mt-5 flex flex-col gap-5"
        >
            {#snippet children({ errors, processing })}
                <div class="flex flex-col gap-2">
                    <Label for="column-new-card-title">{t('Title')}</Label>
                    <Input
                        id="column-new-card-title"
                        name="title"
                        maxlength={255}
                        required
                        autofocus
                    />
                    <InputError message={errors.title} />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="flex flex-col gap-2">
                        <Label for="column-new-card-assignee">
                            {t('Assign card')}
                        </Label>
                        <select
                            id="column-new-card-assignee"
                            name="assignee_id"
                            class="border-input h-10 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">{t('Unassigned')}</option>
                            {#each members as member (member.id)}
                                <option value={member.id}>{member.name}</option>
                            {/each}
                        </select>
                        <InputError message={errors.assignee_id} />
                    </div>
                    <div class="flex flex-col gap-2">
                        <Label for="column-new-card-due">
                            {t('Due date')}
                        </Label>
                        <Input
                            id="column-new-card-due"
                            type="date"
                            name="due_on"
                        />
                        <InputError message={errors.due_on} />
                    </div>
                </div>
                {#if createDescriptionOpen}
                    <div class="flex flex-col gap-2">
                        <Label for="column-new-card-description">
                            {t('Description')}
                        </Label>
                        <RichTextEditor
                            id="column-new-card-description"
                            name="description"
                            ariaLabel={t('Description')}
                            {attachmentUploadUrl}
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
                    <Button type="submit" disabled={processing}>
                        {t('Save card')}
                    </Button>
                </DialogFooter>
            {/snippet}
        </Form>
    </DialogContent>
</Dialog>
