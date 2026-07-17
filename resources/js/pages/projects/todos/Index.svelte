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
    import { Form, router } from '@inertiajs/svelte';
    import Check from 'lucide-svelte/icons/check';
    import Circle from 'lucide-svelte/icons/circle';
    import Eye from 'lucide-svelte/icons/eye';
    import EyeOff from 'lucide-svelte/icons/eye-off';
    import GripVertical from 'lucide-svelte/icons/grip-vertical';
    import AttachmentController from '@/actions/App/Http/Controllers/AttachmentController';
    import CommentController from '@/actions/App/Http/Controllers/CommentController';
    import ProjectFileController from '@/actions/App/Http/Controllers/ProjectFileController';
    import AppHead from '@/components/AppHead.svelte';
    import FileDropzone from '@/components/FileDropzone.svelte';
    import InputError from '@/components/InputError.svelte';
    import RichTextContent from '@/components/RichTextContent.svelte';
    import RichTextEditor from '@/components/RichTextEditor.svelte';
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
    const confirmDelete = (): boolean =>
        window.confirm(t('Are you sure you want to delete this?'));
    const formatDate = (value: string | null): string =>
        value
            ? new Intl.DateTimeFormat('ja-JP', {
                  dateStyle: 'medium',
                  timeStyle: 'short',
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
    let draggedTodo = $state<{ listId: number; todoId: number } | null>(null);
    let showCompleted = $state(false);
    const visibleTodos = (list: ProjectTodoList): ProjectTodo[] =>
        showCompleted
            ? list.todos
            : list.todos.filter((todo) => !todo.completed);
    const todoPosition = (list: ProjectTodoList, todo: ProjectTodo): number =>
        list.todos.findIndex((candidate) => candidate.id === todo.id);
    let addFormListId = $state<number | null>(null);
    let newSubtasks = $state<string[]>([]);
    const openAddForm = (listId: number): void => {
        addFormListId = listId;
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
        if (draggedTodo) {
            event.preventDefault();

            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'move';
            }
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

<div class="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                {currentTeam.menuNames.todos}
            </h1>
            <p class="text-sm text-muted-foreground">{project.name}</p>
        </div>
        <Button
            type="button"
            variant="outline"
            size="sm"
            aria-pressed={showCompleted}
            onclick={() => (showCompleted = !showCompleted)}
        >
            {#if showCompleted}<EyeOff class="size-4" />{:else}<Eye
                    class="size-4"
                />{/if}
            {showCompleted ? t('Hide completed') : t('Show completed')}
        </Button>
    </div>

    <Card>
        <CardContent class="pt-6">
            <Form
                {...ProjectTodoController.storeList.form(routeArguments)}
                resetOnSuccess
                class="flex flex-col gap-2 sm:flex-row"
            >
                {#snippet children({ errors, processing })}
                    <div class="min-w-0 flex-1">
                        <Label for="new-list" class="sr-only"
                            >{t('New to-do list')}</Label
                        >
                        <Input
                            id="new-list"
                            name="title"
                            maxlength={255}
                            required
                            placeholder={t('New to-do list')}
                        />
                        <InputError message={errors.title} />
                    </div>
                    <Button type="submit" disabled={processing}
                        >{t('Add list')}</Button
                    >
                {/snippet}
            </Form>
        </CardContent>
    </Card>

    {#if lists.length === 0}
        <Card
            ><CardContent
                class="py-10 text-center text-sm text-muted-foreground"
                >{t('No to-do lists yet')}</CardContent
            ></Card
        >
    {:else}
        <div class="grid gap-6 lg:grid-cols-2">
            {#each lists as list (list.id)}
                <Card
                    class={list.isLoose ? 'min-w-0 lg:col-span-2' : 'min-w-0'}
                >
                    <CardHeader
                        class="flex-row items-start justify-between gap-3"
                    >
                        <CardTitle class="break-words">{list.title}</CardTitle>
                        {#if list.canDelete && !list.isLoose}
                            <Form
                                {...ProjectTodoController.destroyList.form([
                                    currentTeam.slug,
                                    project.id,
                                    list.id,
                                ])}
                                onBefore={confirmDelete}
                            >
                                {#snippet children({ processing })}
                                    <Button
                                        type="submit"
                                        variant="ghost"
                                        size="sm"
                                        disabled={processing}
                                        >{t('Delete')}</Button
                                    >
                                {/snippet}
                            </Form>
                        {/if}
                    </CardHeader>
                    <CardContent class="space-y-5">
                        <div class="space-y-3">
                            {#each visibleTodos(list) as todo (todo.id)}
                                <article
                                    class="rounded-lg border p-3"
                                    ondragover={allowDrop}
                                    ondrop={(event) =>
                                        dropTodo(
                                            event,
                                            list.id,
                                            todoPosition(list, todo),
                                        )}
                                >
                                    <div class="flex items-start gap-3">
                                        <button
                                            type="button"
                                            draggable="true"
                                            ondragstart={(event) =>
                                                startDragging(
                                                    event,
                                                    list.id,
                                                    todo.id,
                                                )}
                                            ondragend={() =>
                                                (draggedTodo = null)}
                                            aria-label={t('Drag to reorder')}
                                            title={t('Drag to reorder')}
                                            class="mt-1 cursor-grab rounded p-1 text-muted-foreground hover:bg-muted active:cursor-grabbing"
                                        >
                                            <GripVertical class="size-4" />
                                        </button>
                                        <Form
                                            {...ProjectTodoController.toggleTodo.form(
                                                [
                                                    currentTeam.slug,
                                                    project.id,
                                                    list.id,
                                                    todo.id,
                                                ],
                                            )}
                                        >
                                            {#snippet children({ processing })}
                                                <Button
                                                    type="submit"
                                                    variant="ghost"
                                                    size="icon"
                                                    disabled={processing}
                                                    aria-label={todo.completed
                                                        ? t('Mark incomplete')
                                                        : t('Mark complete')}
                                                    class="size-9 shrink-0"
                                                >
                                                    {#if todo.completed}<Check
                                                            class="size-5"
                                                        />{:else}<Circle
                                                            class="size-5"
                                                        />{/if}
                                                </Button>
                                            {/snippet}
                                        </Form>

                                        <div class="min-w-0 flex-1">
                                            <h2
                                                class:line-through={todo.completed}
                                                class:text-muted-foreground={todo.completed}
                                                class="break-words text-sm font-medium"
                                            >
                                                {todo.title}
                                            </h2>
                                            {#if todo.details}
                                                <RichTextContent
                                                    content={todo.details}
                                                    class="mt-1 text-sm text-muted-foreground"
                                                />
                                            {/if}
                                            <div
                                                class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground"
                                            >
                                                <span
                                                    >{t('Assignee')}: {todo.assigneeName ||
                                                        t('Unassigned')}</span
                                                >
                                                <span
                                                    >{t('Due date')}: {todo.dueOn ||
                                                        t('None')}</span
                                                >
                                            </div>
                                        </div>
                                    </div>

                                    <details class="mt-3 border-t pt-3">
                                        <summary
                                            class="cursor-pointer text-xs font-medium"
                                        >
                                            {t('Attachments')} ({todo
                                                .attachments.length})
                                        </summary>
                                        <div class="mt-3 space-y-3">
                                            {#if todo.attachments.length === 0}
                                                <p
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    {t('No attachments yet')}
                                                </p>
                                            {:else}
                                                {#each todo.attachments as attachment (attachment.id)}
                                                    <div
                                                        class="flex min-w-0 flex-col gap-2 rounded-md bg-muted/50 p-3 sm:flex-row sm:items-center"
                                                    >
                                                        <div
                                                            class="min-w-0 flex-1"
                                                        >
                                                            <p
                                                                class="break-all text-sm font-medium"
                                                            >
                                                                {attachment.name}
                                                            </p>
                                                            <p
                                                                class="text-xs text-muted-foreground"
                                                            >
                                                                {formatSize(
                                                                    attachment.size,
                                                                )} · {attachment.uploaderName ||
                                                                    t(
                                                                        'Deleted user',
                                                                    )}
                                                            </p>
                                                        </div>
                                                        <div
                                                            class="flex flex-wrap gap-2"
                                                        >
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
                                                                                attachment.id,
                                                                            ],
                                                                        )}
                                                                        class={buttonProps.class}
                                                                    >
                                                                        {t(
                                                                            'Download',
                                                                        )}
                                                                    </a>
                                                                {/snippet}
                                                            </Button>
                                                            {#if attachment.canDelete}
                                                                <Form
                                                                    {...ProjectFileController.destroy.form(
                                                                        [
                                                                            currentTeam.slug,
                                                                            project.id,
                                                                            attachment.id,
                                                                        ],
                                                                    )}
                                                                    onBefore={confirmDelete}
                                                                >
                                                                    {#snippet children({
                                                                        processing,
                                                                    })}
                                                                        <Button
                                                                            type="submit"
                                                                            variant="destructive"
                                                                            size="sm"
                                                                            disabled={processing}
                                                                            >{t(
                                                                                'Delete',
                                                                            )}</Button
                                                                        >
                                                                    {/snippet}
                                                                </Form>
                                                            {/if}
                                                        </div>
                                                    </div>
                                                {/each}
                                            {/if}

                                            <Form
                                                {...AttachmentController.storeTodo.form(
                                                    [
                                                        currentTeam.slug,
                                                        project.id,
                                                        list.id,
                                                        todo.id,
                                                    ],
                                                )}
                                                resetOnSuccess
                                                class="space-y-2"
                                            >
                                                {#snippet children({
                                                    errors,
                                                    processing,
                                                    progress,
                                                })}
                                                    <FileDropzone
                                                        id={`todo-attachment-${todo.id}`}
                                                        error={errors.file}
                                                        compact
                                                        accept=".pdf,.txt,.csv,.jpg,.jpeg,.png,.webp,.mp4,.webm,.mov,.m4v,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip"
                                                    />
                                                    {#if progress}
                                                        <progress
                                                            value={progress.percentage}
                                                            max="100"
                                                            class="h-2 w-full"
                                                            >{progress.percentage}%</progress
                                                        >
                                                    {/if}
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        disabled={processing}
                                                        >{t(
                                                            'Attach file',
                                                        )}</Button
                                                    >
                                                {/snippet}
                                            </Form>
                                        </div>
                                    </details>

                                    <details class="mt-3 border-t pt-3">
                                        <summary
                                            class="cursor-pointer text-xs font-medium"
                                        >
                                            {t('Subtasks')} ({todo.subtasks
                                                .length})
                                        </summary>
                                        <div class="mt-3 space-y-2">
                                            {#if todo.subtasks.length === 0}
                                                <p
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    {t('No subtasks yet')}
                                                </p>
                                            {:else}
                                                {#each todo.subtasks as subtask (subtask.id)}
                                                    <div
                                                        class="flex min-w-0 items-center gap-2 rounded-md bg-muted/50 p-2"
                                                    >
                                                        <Form
                                                            {...ProjectTodoController.toggleTodo.form(
                                                                [
                                                                    currentTeam.slug,
                                                                    project.id,
                                                                    list.id,
                                                                    subtask.id,
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
                                                                    aria-label={subtask.completed
                                                                        ? t(
                                                                              'Mark incomplete',
                                                                          )
                                                                        : t(
                                                                              'Mark complete',
                                                                          )}
                                                                    class="size-8 shrink-0"
                                                                >
                                                                    {#if subtask.completed}<Check
                                                                            class="size-4"
                                                                        />{:else}<Circle
                                                                            class="size-4"
                                                                        />{/if}
                                                                </Button>
                                                            {/snippet}
                                                        </Form>
                                                        <span
                                                            class:line-through={subtask.completed}
                                                            class:text-muted-foreground={subtask.completed}
                                                            class="min-w-0 flex-1 break-words text-sm"
                                                        >
                                                            {subtask.title}
                                                        </span>
                                                        {#if subtask.canEdit}
                                                            <Form
                                                                {...ProjectTodoController.destroyTodo.form(
                                                                    [
                                                                        currentTeam.slug,
                                                                        project.id,
                                                                        list.id,
                                                                        subtask.id,
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
                                                                        >{t(
                                                                            'Delete',
                                                                        )}</Button
                                                                    >
                                                                {/snippet}
                                                            </Form>
                                                        {/if}
                                                    </div>
                                                {/each}
                                            {/if}

                                            <Form
                                                {...ProjectTodoController.storeSubtask.form(
                                                    [
                                                        currentTeam.slug,
                                                        project.id,
                                                        list.id,
                                                        todo.id,
                                                    ],
                                                )}
                                                resetOnSuccess
                                                class="flex flex-col gap-2 sm:flex-row"
                                            >
                                                {#snippet children({
                                                    errors,
                                                    processing,
                                                })}
                                                    <div class="min-w-0 flex-1">
                                                        <Label
                                                            for={`subtask-${todo.id}`}
                                                            class="sr-only"
                                                            >{t(
                                                                'Subtask title',
                                                            )}</Label
                                                        >
                                                        <Input
                                                            id={`subtask-${todo.id}`}
                                                            name="title"
                                                            maxlength={255}
                                                            required
                                                            placeholder={t(
                                                                'Subtask title',
                                                            )}
                                                        />
                                                        <InputError
                                                            message={errors.title}
                                                        />
                                                    </div>
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        disabled={processing}
                                                        >{t(
                                                            'Add subtask',
                                                        )}</Button
                                                    >
                                                {/snippet}
                                            </Form>
                                        </div>
                                    </details>

                                    <details class="mt-3 border-t pt-3">
                                        <summary
                                            class="cursor-pointer text-xs font-medium"
                                            >{t('Move or reorder')}</summary
                                        >
                                        <div
                                            class="mt-3 flex flex-wrap items-center gap-2"
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
                                                        >{t('Move up')}</Button
                                                    >
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
                                                        >{t(
                                                            'Move down',
                                                        )}</Button
                                                    >
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
                                                class="mt-3 flex flex-col gap-2 sm:flex-row"
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
                                                                >{targetList.title}</option
                                                            >
                                                        {/each}
                                                    </select>
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        disabled={processing}
                                                        >{t('Move')}</Button
                                                    >
                                                {/snippet}
                                            </Form>
                                        {/if}
                                    </details>

                                    {#if todo.canEdit}
                                        <details class="mt-3 border-t pt-3">
                                            <summary
                                                class="cursor-pointer text-xs font-medium"
                                                >{t('Edit to-do')}</summary
                                            >
                                            <Form
                                                {...ProjectTodoController.updateTodo.form(
                                                    [
                                                        currentTeam.slug,
                                                        project.id,
                                                        list.id,
                                                        todo.id,
                                                    ],
                                                )}
                                                class="mt-3 space-y-3"
                                            >
                                                {#snippet children({
                                                    errors,
                                                    processing,
                                                })}
                                                    <Input
                                                        name="title"
                                                        value={todo.title}
                                                        maxlength={255}
                                                        required
                                                    />
                                                    <InputError
                                                        message={errors.title}
                                                    />
                                                    <RichTextEditor
                                                        id={`todo-details-${todo.id}`}
                                                        name="details"
                                                        value={todo.details}
                                                        ariaLabel={t('Details')}
                                                        mentionables={members}
                                                    />
                                                    <div
                                                        class="grid gap-3 sm:grid-cols-2"
                                                    >
                                                        <select
                                                            name="assignee_id"
                                                            class="border-input h-9 rounded-md border bg-background px-3 text-sm"
                                                            value={todo.assigneeId ??
                                                                ''}
                                                        >
                                                            <option value=""
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
                                                        <Input
                                                            type="date"
                                                            name="due_on"
                                                            value={todo.dueOn ??
                                                                ''}
                                                            aria-label={t(
                                                                'Due date',
                                                            )}
                                                        />
                                                    </div>
                                                    <div
                                                        class="flex flex-wrap gap-x-4 gap-y-1"
                                                    >
                                                        <span
                                                            class="w-full text-xs font-medium"
                                                            >{t(
                                                                'When done, notify',
                                                            )}</span
                                                        >
                                                        {#each members as member (member.id)}
                                                            <label
                                                                class="flex items-center gap-1.5 text-sm"
                                                            >
                                                                <input
                                                                    type="checkbox"
                                                                    name="completion_notify_ids[]"
                                                                    value={member.id}
                                                                    checked={todo.completionNotifyIds.includes(
                                                                        member.id,
                                                                    )}
                                                                    class="accent-primary"
                                                                />
                                                                {member.name}
                                                            </label>
                                                        {/each}
                                                    </div>
                                                    <InputError
                                                        message={errors.assignee_id ||
                                                            errors.due_on ||
                                                            errors.completion_notify_ids}
                                                    />
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        disabled={processing}
                                                        >{t('Save')}</Button
                                                    >
                                                {/snippet}
                                            </Form>
                                            <Form
                                                {...ProjectTodoController.destroyTodo.form(
                                                    [
                                                        currentTeam.slug,
                                                        project.id,
                                                        list.id,
                                                        todo.id,
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
                                                        >{t('Delete')}</Button
                                                    >
                                                {/snippet}
                                            </Form>
                                        </details>
                                    {/if}

                                    <details class="mt-3 border-t pt-3">
                                        <summary
                                            class="cursor-pointer text-xs font-medium"
                                        >
                                            {t('Comments')} ({todo.comments
                                                .length})
                                        </summary>
                                        <section
                                            class="mt-3 space-y-3"
                                            aria-label={t('Comments')}
                                        >
                                            {#if todo.comments.length === 0}
                                                <p
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    {t('No comments yet')}
                                                </p>
                                            {:else}
                                                {#each todo.comments as comment (comment.id)}
                                                    <div
                                                        class="rounded-lg bg-muted/50 p-3 text-sm"
                                                    >
                                                        <div
                                                            class="flex items-start justify-between gap-3"
                                                        >
                                                            <div
                                                                class="min-w-0"
                                                            >
                                                                <RichTextContent
                                                                    content={comment.body}
                                                                />
                                                                <p
                                                                    class="mt-1 text-xs text-muted-foreground"
                                                                >
                                                                    {comment.authorName ||
                                                                        t(
                                                                            'Deleted user',
                                                                        )} · {formatDate(
                                                                        comment.createdAt,
                                                                    )}
                                                                </p>
                                                            </div>
                                                            {#if comment.canDelete}
                                                                <div
                                                                    class="flex shrink-0 gap-1"
                                                                >
                                                                    <details>
                                                                        <summary
                                                                            class="cursor-pointer rounded-md px-2 py-1 text-xs font-medium text-muted-foreground hover:bg-muted"
                                                                            >{t(
                                                                                'Edit',
                                                                            )}</summary
                                                                        >
                                                                        <Form
                                                                            {...CommentController.updateTodo.form(
                                                                                [
                                                                                    currentTeam.slug,
                                                                                    project.id,
                                                                                    list.id,
                                                                                    todo.id,
                                                                                    comment.id,
                                                                                ],
                                                                            )}
                                                                            class="mt-2 space-y-2"
                                                                        >
                                                                            {#snippet children({
                                                                                errors,
                                                                                processing,
                                                                            })}
                                                                                <RichTextEditor
                                                                                    id={`todo-comment-edit-${comment.id}`}
                                                                                    name="body"
                                                                                    value={comment.body}
                                                                                    required
                                                                                    ariaLabel={t(
                                                                                        'Comment',
                                                                                    )}
                                                                                    mentionables={members}
                                                                                />
                                                                                <InputError
                                                                                    message={errors.body}
                                                                                />
                                                                                <Button
                                                                                    type="submit"
                                                                                    size="sm"
                                                                                    disabled={processing}
                                                                                    >{t(
                                                                                        'Save',
                                                                                    )}</Button
                                                                                >
                                                                            {/snippet}
                                                                        </Form>
                                                                    </details>
                                                                    <Form
                                                                        {...CommentController.destroyTodo.form(
                                                                            [
                                                                                currentTeam.slug,
                                                                                project.id,
                                                                                list.id,
                                                                                todo.id,
                                                                                comment.id,
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
                                                                                >{t(
                                                                                    'Delete',
                                                                                )}</Button
                                                                            >
                                                                        {/snippet}
                                                                    </Form>
                                                                </div>
                                                            {/if}
                                                        </div>
                                                    </div>
                                                {/each}
                                            {/if}

                                            <Form
                                                {...CommentController.storeTodo.form(
                                                    [
                                                        currentTeam.slug,
                                                        project.id,
                                                        list.id,
                                                        todo.id,
                                                    ],
                                                )}
                                                resetOnSuccess
                                                class="space-y-2"
                                            >
                                                {#snippet children({
                                                    errors,
                                                    processing,
                                                })}
                                                    <Label
                                                        for={`todo-comment-${todo.id}`}
                                                        >{t('Comment')}</Label
                                                    >
                                                    <RichTextEditor
                                                        id={`todo-comment-${todo.id}`}
                                                        name="body"
                                                        required
                                                        ariaLabel={t('Comment')}
                                                        mentionables={members}
                                                    />
                                                    <InputError
                                                        message={errors.body}
                                                    />
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        disabled={processing}
                                                        >{t(
                                                            'Add comment',
                                                        )}</Button
                                                    >
                                                {/snippet}
                                            </Form>
                                        </section>
                                    </details>
                                </article>
                            {/each}
                            <button
                                type="button"
                                tabindex="-1"
                                aria-label={t('Drop at end')}
                                ondragover={allowDrop}
                                ondrop={(event) => dropTodo(event, list.id)}
                                class="h-2 w-full rounded transition-colors {draggedTodo !==
                                null
                                    ? 'bg-primary/20'
                                    : ''}"
                            ></button>
                        </div>

                        {#if addFormListId === list.id}
                            <Form
                                {...ProjectTodoController.storeTodo.form([
                                    currentTeam.slug,
                                    project.id,
                                    list.id,
                                ])}
                                resetOnSuccess
                                onSuccess={() => (newSubtasks = [])}
                                transform={(data) => ({
                                    ...data,
                                    subtasks: (
                                        (data.subtasks as
                                            string[] | undefined) ?? []
                                    ).filter((title) => title.trim() !== ''),
                                })}
                                class="space-y-3 rounded-lg border bg-muted/40 p-4"
                            >
                                {#snippet children({ errors, processing })}
                                    <Input
                                        name="title"
                                        maxlength={255}
                                        required
                                        placeholder={t(
                                            'Describe this to-do...',
                                        )}
                                    />
                                    <InputError message={errors.title} />
                                    <div
                                        class="grid gap-x-4 gap-y-3 sm:grid-cols-[7rem_1fr] sm:items-start"
                                    >
                                        <Label
                                            for={`new-todo-assignee-${list.id}`}
                                            class="pt-2 text-right max-sm:text-left"
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
                                            class="pt-1 text-right text-sm font-medium max-sm:text-left"
                                            >{t('When done, notify')}</span
                                        >
                                        <div
                                            class="flex flex-wrap gap-x-4 gap-y-1 pt-1.5"
                                        >
                                            {#each members as member (member.id)}
                                                <label
                                                    class="flex items-center gap-1.5 text-sm"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        name="completion_notify_ids[]"
                                                        value={member.id}
                                                        class="accent-primary"
                                                    />
                                                    {member.name}
                                                </label>
                                            {/each}
                                        </div>

                                        <Label
                                            for={`new-todo-due-${list.id}`}
                                            class="pt-2 text-right max-sm:text-left"
                                            >{t('Due date')}</Label
                                        >
                                        <Input
                                            id={`new-todo-due-${list.id}`}
                                            type="date"
                                            name="due_on"
                                        />

                                        <span
                                            class="pt-1 text-right text-sm font-medium max-sm:text-left"
                                            >{t('Notes')}</span
                                        >
                                        <RichTextEditor
                                            id={`new-todo-details-${list.id}`}
                                            name="details"
                                            placeholder={t(
                                                'Add extra details...',
                                            )}
                                            ariaLabel={t('Notes')}
                                            mentionables={members}
                                        />

                                        <span
                                            class="pt-1 text-right text-sm font-medium max-sm:text-left"
                                            >{t('Subtasks')}</span
                                        >
                                        <div class="space-y-2">
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
                                                    ])}
                                                >{t('Add subtask')}</Button
                                            >
                                        </div>
                                    </div>
                                    <InputError
                                        message={errors.assignee_id ||
                                            errors.due_on ||
                                            errors.completion_notify_ids ||
                                            errors.subtasks}
                                    />
                                    <div class="flex gap-2">
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
                                            onclick={() =>
                                                (addFormListId = null)}
                                            >{t('Cancel')}</Button
                                        >
                                    </div>
                                {/snippet}
                            </Form>
                        {:else}
                            <button
                                type="button"
                                class="text-sm font-medium text-primary hover:underline"
                                onclick={() => openAddForm(list.id)}
                            >
                                + {t('Add a to-do')}
                            </button>
                        {/if}
                    </CardContent>
                </Card>
            {/each}
        </div>
    {/if}
</div>
