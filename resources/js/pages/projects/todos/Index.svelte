<script module lang="ts">
    import ProjectTodoController from '@/actions/App/Http/Controllers/ProjectTodoController';
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
                title: props.currentTeam.menuNames.todos,
                href: ProjectTodoController.index([
                    props.currentTeam.slug,
                    props.project.id,
                ]),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link, router } from '@inertiajs/svelte';
    import Check from 'lucide-svelte/icons/check';
    import GripVertical from 'lucide-svelte/icons/grip-vertical';
    import Plus from 'lucide-svelte/icons/plus';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import AttachmentController from '@/actions/App/Http/Controllers/AttachmentController';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import RichTextEditor from '@/components/RichTextEditor.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
    import type { ProjectTodo, ProjectTodoList, TodoMember } from '@/types';

    let {
        currentTeam,
        project,
        lists,
        members,
    }: {
        currentTeam: Team;
        project: ProjectTool;
        lists: ProjectTodoList[];
        members: TodoMember[];
    } = $props();

    const routeArguments = $derived([currentTeam.slug, project.id] as [
        string,
        number,
    ]);
    let filter = $state('');
    let showCompleted = $state(false);
    let showListForm = $state(false);
    let addFormListId = $state<number | null>(null);
    let newSubtasks = $state<string[]>([]);
    let draggedTodo = $state<{ listId: number; todoId: number } | null>(null);

    const confirmDelete = (): boolean =>
        window.confirm(t('Are you sure you want to delete this?'));
    const visibleTodos = (list: ProjectTodoList): ProjectTodo[] => {
        const query = filter.trim().toLocaleLowerCase();

        return list.todos.filter(
            (todo) =>
                (showCompleted || !todo.completed) &&
                (query === '' ||
                    todo.title.toLocaleLowerCase().includes(query) ||
                    todo.assigneeName?.toLocaleLowerCase().includes(query)),
        );
    };
    const todoPosition = (list: ProjectTodoList, todo: ProjectTodo): number =>
        list.todos.findIndex((candidate) => candidate.id === todo.id);
    const openAddForm = (listId: number): void => {
        showListForm = false;
        addFormListId = listId;
        newSubtasks = [];
    };
    const closeAddForm = (): void => {
        addFormListId = null;
        newSubtasks = [];
    };
    const startDragging = (
        event: DragEvent,
        listId: number,
        todoId: number,
    ): void => {
        draggedTodo = { listId, todoId };

        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', String(todoId));
        }
    };
    const allowDrop = (event: DragEvent): void => {
        if (!draggedTodo) {
            return;
        }

        event.preventDefault();

        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'move';
        }
    };
    const dropTodo = (
        event: DragEvent,
        targetListId: number,
        targetPosition?: number,
    ): void => {
        event.preventDefault();
        event.stopPropagation();

        if (!draggedTodo) {
            return;
        }

        const source = draggedTodo;
        draggedTodo = null;
        router.patch(
            ProjectTodoController.moveTodo([
                currentTeam.slug,
                project.id,
                source.listId,
                source.todoId,
            ]).url,
            {
                target_list_id: targetListId,
                ...(targetPosition === undefined
                    ? {}
                    : { target_position: targetPosition }),
            },
            { preserveScroll: true },
        );
    };
</script>

<AppHead title={currentTeam.menuNames.todos} />

<main class="mx-auto w-full max-w-5xl px-4 py-6 sm:px-6 sm:py-8">
    <header>
        <h1 class="text-3xl font-bold tracking-tight">
            {currentTeam.menuNames.todos}
        </h1>
        <p class="mt-1 text-sm text-muted-foreground">{project.name}</p>

        <div class="mt-5 flex flex-wrap items-center gap-2">
            <Button
                type="button"
                size="sm"
                onclick={() => {
                    closeAddForm();
                    showListForm = !showListForm;
                }}
            >
                <Plus aria-hidden="true" />
                {t('New to-do list')}
            </Button>
            <Input
                bind:value={filter}
                class="w-48"
                aria-label={t('Filter')}
                placeholder={t('Filter')}
            />
            <label
                class="flex cursor-pointer items-center gap-2 px-1 text-sm text-muted-foreground"
            >
                <input
                    type="checkbox"
                    bind:checked={showCompleted}
                    class="size-4 rounded border-input accent-primary"
                />
                {t('Show completed')}
            </label>
        </div>
    </header>

    {#if showListForm}
        <Form
            {...ProjectTodoController.storeList.form(routeArguments)}
            resetOnSuccess
            onSuccess={() => (showListForm = false)}
            class="mt-7 border-b pb-7"
        >
            {#snippet children({ errors, processing })}
                <div class="flex items-center gap-3">
                    <span
                        class="size-4 shrink-0 rounded-full bg-muted"
                        aria-hidden="true"
                    ></span>
                    <div class="min-w-0 flex-1">
                        <Label for="new-list" class="sr-only"
                            >{t('New to-do list')}</Label
                        >
                        <Input
                            id="new-list"
                            name="title"
                            maxlength={255}
                            required
                            autofocus
                            class="h-auto border-0 border-b bg-transparent px-0 py-1 text-xl font-semibold shadow-none focus-visible:ring-0"
                            placeholder={t('New to-do list')}
                        />
                        <InputError message={errors.title} />
                    </div>
                </div>
                <div class="mt-4 flex gap-2 pl-7">
                    <Button type="submit" size="sm" disabled={processing}
                        >{t('Add list')}</Button
                    >
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onclick={() => (showListForm = false)}
                        >{t('Cancel')}</Button
                    >
                </div>
            {/snippet}
        </Form>
    {/if}

    {#if lists.length === 0}
        <p class="mt-12 text-center text-sm text-muted-foreground">
            {t('No to-do lists yet')}
        </p>
    {:else}
        <div class="mt-8 space-y-10">
            {#each lists as list (list.id)}
                <section
                    aria-label={list.title}
                    ondragover={allowDrop}
                    ondrop={(event) => dropTodo(event, list.id)}
                >
                    {#if !list.isLoose}
                        <header
                            class="mb-3 flex items-start justify-between gap-3"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <span
                                    class="size-4 shrink-0 rounded-full bg-primary/40"
                                    aria-hidden="true"
                                ></span>
                                <h2
                                    class="min-w-0 break-words text-xl font-semibold"
                                >
                                    <Link
                                        href={ProjectTodoController.showList([
                                            currentTeam.slug,
                                            project.id,
                                            list.id,
                                        ])}
                                        class="hover:text-primary hover:underline"
                                    >
                                        {list.title}
                                    </Link>
                                </h2>
                            </div>
                            {#if list.canDelete}
                                <Form
                                    {...ProjectTodoController.destroyList.form([
                                        currentTeam.slug,
                                        project.id,
                                        list.id,
                                    ])}
                                    onBefore={confirmDelete}
                                >
                                    {#snippet children({ processing })}
                                        <button
                                            type="submit"
                                            disabled={processing}
                                            aria-label={t('Delete')}
                                            class="flex size-8 items-center justify-center rounded-md text-muted-foreground hover:bg-destructive/10 hover:text-destructive disabled:opacity-50"
                                        >
                                            <Trash2 class="size-4" />
                                        </button>
                                    {/snippet}
                                </Form>
                            {/if}
                        </header>
                    {/if}

                    <div class="space-y-1" role="list">
                        {#each visibleTodos(list) as todo (todo.id)}
                            <div
                                role="listitem"
                                class="group flex min-w-0 items-start gap-2 rounded-md px-1 py-1.5 hover:bg-muted/50"
                                ondragover={allowDrop}
                                ondrop={(event) =>
                                    dropTodo(
                                        event,
                                        list.id,
                                        todoPosition(list, todo),
                                    )}
                            >
                                <button
                                    type="button"
                                    draggable="true"
                                    ondragstart={(event) =>
                                        startDragging(event, list.id, todo.id)}
                                    ondragend={() => (draggedTodo = null)}
                                    aria-label={t('Drag to reorder')}
                                    title={t('Drag to reorder')}
                                    class="mt-0.5 flex size-6 shrink-0 cursor-grab items-center justify-center rounded text-muted-foreground opacity-0 hover:bg-muted group-hover:opacity-100 focus:opacity-100 active:cursor-grabbing"
                                >
                                    <GripVertical class="size-4" />
                                </button>

                                <Form
                                    {...ProjectTodoController.toggleTodo.form([
                                        currentTeam.slug,
                                        project.id,
                                        list.id,
                                        todo.id,
                                    ])}
                                >
                                    {#snippet children({ processing })}
                                        <button
                                            type="submit"
                                            disabled={processing}
                                            aria-label={todo.completed
                                                ? t('Mark incomplete')
                                                : t('Mark complete')}
                                            class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded border border-foreground/60 text-primary disabled:opacity-50"
                                        >
                                            {#if todo.completed}
                                                <Check class="size-4" />
                                            {/if}
                                        </button>
                                    {/snippet}
                                </Form>

                                <div class="min-w-0 flex-1">
                                    <Link
                                        href={ProjectTodoController.show([
                                            currentTeam.slug,
                                            project.id,
                                            list.id,
                                            todo.id,
                                        ])}
                                        class={`break-words text-[0.95rem] font-medium hover:text-primary hover:underline ${
                                            todo.completed
                                                ? 'text-muted-foreground line-through'
                                                : 'text-foreground'
                                        }`}
                                    >
                                        {todo.title}
                                    </Link>
                                    {#if todo.assigneeName || todo.dueOn}
                                        <p
                                            class="mt-0.5 flex flex-wrap gap-x-2 text-xs text-muted-foreground"
                                        >
                                            {#if todo.assigneeName}
                                                <span>{todo.assigneeName}</span>
                                            {/if}
                                            {#if todo.dueOn}
                                                <span>{todo.dueOn}</span>
                                            {/if}
                                        </p>
                                    {/if}

                                    <details class="mt-2">
                                        <summary
                                            class="cursor-pointer text-xs font-medium text-muted-foreground"
                                        >
                                            {t('Move or reorder')}
                                        </summary>
                                        <div
                                            class="mt-2 flex flex-wrap items-center gap-2"
                                        >
                                            <Form
                                                {...ProjectTodoController.moveTodo.form(
                                                    [
                                                        currentTeam.slug,
                                                        project.id,
                                                        list.id,
                                                        todo.id,
                                                    ],
                                                )}
                                            >
                                                {#snippet children({
                                                    processing,
                                                })}
                                                    <input
                                                        type="hidden"
                                                        name="target_list_id"
                                                        value={list.id}
                                                    />
                                                    <input
                                                        type="hidden"
                                                        name="target_position"
                                                        value={Math.max(
                                                            todoPosition(
                                                                list,
                                                                todo,
                                                            ) - 1,
                                                            0,
                                                        )}
                                                    />
                                                    <Button
                                                        type="submit"
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={processing ||
                                                            todoPosition(
                                                                list,
                                                                todo,
                                                            ) === 0}
                                                    >
                                                        {t('Move up')}
                                                    </Button>
                                                {/snippet}
                                            </Form>
                                            <Form
                                                {...ProjectTodoController.moveTodo.form(
                                                    [
                                                        currentTeam.slug,
                                                        project.id,
                                                        list.id,
                                                        todo.id,
                                                    ],
                                                )}
                                            >
                                                {#snippet children({
                                                    processing,
                                                })}
                                                    <input
                                                        type="hidden"
                                                        name="target_list_id"
                                                        value={list.id}
                                                    />
                                                    <input
                                                        type="hidden"
                                                        name="target_position"
                                                        value={todoPosition(
                                                            list,
                                                            todo,
                                                        ) + 1}
                                                    />
                                                    <Button
                                                        type="submit"
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={processing ||
                                                            todoPosition(
                                                                list,
                                                                todo,
                                                            ) ===
                                                                list.todos
                                                                    .length -
                                                                    1}
                                                    >
                                                        {t('Move down')}
                                                    </Button>
                                                {/snippet}
                                            </Form>
                                        </div>

                                        {#if lists.length > 1}
                                            <Form
                                                {...ProjectTodoController.moveTodo.form(
                                                    [
                                                        currentTeam.slug,
                                                        project.id,
                                                        list.id,
                                                        todo.id,
                                                    ],
                                                )}
                                                class="mt-2 flex flex-col gap-2 sm:flex-row"
                                            >
                                                {#snippet children({
                                                    processing,
                                                })}
                                                    <select
                                                        name="target_list_id"
                                                        required
                                                        aria-label={t(
                                                            'Move to list',
                                                        )}
                                                        class="border-input h-9 min-w-0 flex-1 rounded-md border bg-background px-3 text-sm"
                                                    >
                                                        {#each lists.filter((targetList) => targetList.id !== list.id) as targetList (targetList.id)}
                                                            <option
                                                                value={targetList.id}
                                                            >
                                                                {targetList.title}
                                                            </option>
                                                        {/each}
                                                    </select>
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        disabled={processing}
                                                    >
                                                        {t('Move')}
                                                    </Button>
                                                {/snippet}
                                            </Form>
                                        {/if}
                                    </details>
                                </div>
                            </div>
                        {/each}
                    </div>

                    {#if filter && visibleTodos(list).length === 0}
                        <p class="pl-7 text-sm text-muted-foreground">
                            {t('No matching to-dos')}
                        </p>
                    {/if}

                    {#if addFormListId === list.id}
                        <Form
                            {...ProjectTodoController.storeTodo.form([
                                currentTeam.slug,
                                project.id,
                                list.id,
                            ])}
                            resetOnSuccess
                            onSuccess={closeAddForm}
                            transform={(data) => ({
                                ...data,
                                subtasks: (
                                    (data.subtasks as string[] | undefined) ??
                                    []
                                ).filter((title) => title.trim() !== ''),
                            })}
                            class="mt-4 border-y py-5"
                        >
                            {#snippet children({ errors, processing })}
                                <div class="flex items-start gap-3">
                                    <span
                                        class="mt-2 size-5 shrink-0 rounded border border-foreground/60"
                                        aria-hidden="true"
                                    ></span>
                                    <div class="min-w-0 flex-1">
                                        <Input
                                            name="title"
                                            maxlength={255}
                                            required
                                            autofocus
                                            class="h-auto border-0 border-b bg-transparent px-0 py-1 text-lg shadow-none focus-visible:ring-0"
                                            placeholder={t(
                                                'Describe this to-do...',
                                            )}
                                        />
                                        <InputError message={errors.title} />
                                    </div>
                                </div>

                                <div
                                    class="mt-4 grid gap-x-5 gap-y-4 sm:grid-cols-[7rem_minmax(0,1fr)] sm:items-start"
                                >
                                    <Label
                                        for={`new-todo-assignee-${list.id}`}
                                        class="pt-2 sm:text-right"
                                        >{t('Assignee')}</Label
                                    >
                                    <select
                                        id={`new-todo-assignee-${list.id}`}
                                        name="assignee_id"
                                        class="border-input h-9 rounded-md border bg-background px-3 text-sm"
                                    >
                                        <option value=""
                                            >{t('Unassigned')}</option
                                        >
                                        {#each members as member (member.id)}
                                            <option value={member.id}
                                                >{member.name}</option
                                            >
                                        {/each}
                                    </select>

                                    <span
                                        class="pt-1 text-sm font-medium sm:text-right"
                                        >{t('When done, notify')}</span
                                    >
                                    <div
                                        class="flex flex-wrap gap-x-4 gap-y-2 pt-1"
                                    >
                                        {#each members as member (member.id)}
                                            <label
                                                class="flex items-center gap-2 text-sm"
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="completion_notify_ids[]"
                                                    value={member.id}
                                                    class="size-4 rounded accent-primary"
                                                />
                                                {member.name}
                                            </label>
                                        {/each}
                                    </div>

                                    <Label
                                        for={`new-todo-due-${list.id}`}
                                        class="pt-2 sm:text-right"
                                        >{t('Due date')}</Label
                                    >
                                    <Input
                                        id={`new-todo-due-${list.id}`}
                                        type="date"
                                        name="due_on"
                                        class="max-w-56"
                                    />

                                    <span
                                        class="pt-1 text-sm font-medium sm:text-right"
                                        >{t('Notes')}</span
                                    >
                                    <div class="min-w-0">
                                        <RichTextEditor
                                            id={`new-todo-details-${list.id}`}
                                            name="details"
                                            placeholder={t(
                                                'Add extra details...',
                                            )}
                                            ariaLabel={t('Notes')}
                                            mentionables={members}
                                            attachmentUploadUrl={AttachmentController.uploadInline.url(
                                                routeArguments,
                                            )}
                                        />
                                    </div>

                                    <span
                                        class="pt-1 text-sm font-medium sm:text-right"
                                        >{t('Subtasks')}</span
                                    >
                                    <div class="flex min-w-0 flex-col gap-2">
                                        {#each newSubtasks as _, index (index)}
                                            <div class="flex gap-2">
                                                <Input
                                                    name="subtasks[]"
                                                    maxlength={255}
                                                    bind:value={
                                                        newSubtasks[index]
                                                    }
                                                    placeholder={t(
                                                        'Subtask title',
                                                    )}
                                                />
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onclick={() =>
                                                        (newSubtasks =
                                                            newSubtasks.toSpliced(
                                                                index,
                                                                1,
                                                            ))}
                                                    >{t('Delete')}</Button
                                                >
                                            </div>
                                        {/each}
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onclick={() =>
                                                (newSubtasks = [
                                                    ...newSubtasks,
                                                    '',
                                                ])}>{t('Add subtask')}</Button
                                        >
                                    </div>
                                </div>

                                <InputError
                                    message={errors.assignee_id ||
                                        errors.due_on ||
                                        errors.completion_notify_ids ||
                                        errors.subtasks}
                                />
                                <div class="mt-5 flex gap-2">
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                        >{t('Add this to-do')}</Button
                                    >
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onclick={closeAddForm}
                                        >{t('Cancel')}</Button
                                    >
                                </div>
                            {/snippet}
                        </Form>
                    {:else}
                        <button
                            type="button"
                            class="mt-2 ml-7 text-sm font-medium text-primary hover:underline"
                            onclick={() => openAddForm(list.id)}
                        >
                            {t('Add a to-do')}
                        </button>
                    {/if}
                </section>
            {/each}
        </div>
    {/if}
</main>
