<script module lang="ts">
    import ProjectTodoController from '@/actions/App/Http/Controllers/ProjectTodoController';
    import { show as showProject } from '@/routes/projects';
    import type { ProjectTool, Team } from '@/types';

    export const layout = (props: {
        currentTeam: Team;
        project: ProjectTool;
        list: { id: number; title: string };
    }) => ({
        breadcrumbs: [
            {
                title: props.project.name,
                href: showProject([props.currentTeam.slug, props.project.id]),
            },
            {
                title: props.currentTeam.menuNames.todos,
                href: ProjectTodoController.index([
                    props.currentTeam.slug,
                    props.project.id,
                ]),
            },
            {
                title: props.list.title,
                href: ProjectTodoController.showList([
                    props.currentTeam.slug,
                    props.project.id,
                    props.list.id,
                ]),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import Bell from 'lucide-svelte/icons/bell';
    import BellOff from 'lucide-svelte/icons/bell-off';
    import Check from 'lucide-svelte/icons/check';
    import ChevronDown from 'lucide-svelte/icons/chevron-down';
    import FileText from 'lucide-svelte/icons/file-text';
    import Pencil from 'lucide-svelte/icons/pencil';
    import Plus from 'lucide-svelte/icons/plus';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import AttachmentController from '@/actions/App/Http/Controllers/AttachmentController';
    import CommentController from '@/actions/App/Http/Controllers/CommentController';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import RichTextContent from '@/components/RichTextContent.svelte';
    import RichTextEditor from '@/components/RichTextEditor.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
    import type { TodoMember } from '@/types';

    type ListTodo = {
        id: number;
        title: string;
        completed: boolean;
        assigneeName: string | null;
        dueOn: string | null;
        hasNotes: boolean;
    };
    type ListComment = {
        id: number;
        body: string;
        authorName: string | null;
        createdAt: string | null;
        canDelete: boolean;
    };

    let {
        currentTeam,
        project,
        list,
        todos,
        comments,
        members,
    }: {
        currentTeam: Team;
        project: ProjectTool;
        list: {
            id: number;
            title: string;
            canDelete: boolean;
            subscribed: boolean;
            subscriberCount: number;
        };
        todos: ListTodo[];
        comments: ListComment[];
        members: TodoMember[];
    } = $props();

    const routeArguments = $derived([currentTeam.slug, project.id, list.id] as [
        string,
        number,
        number,
    ]);
    const projectRouteArguments = $derived([currentTeam.slug, project.id] as [
        string,
        number,
    ]);
    const completedCount = $derived(
        todos.filter((todo) => todo.completed).length,
    );
    const activeTodos = $derived(todos.filter((todo) => !todo.completed));
    const completedTodos = $derived(todos.filter((todo) => todo.completed));
    let addingTodo = $state(false);
    let newSubtasks = $state<string[]>([]);

    const confirmDelete = (): boolean =>
        window.confirm(t('Are you sure you want to delete this?'));
    const closeAddForm = (): void => {
        addingTodo = false;
        newSubtasks = [];
    };
    const formatDate = (value: string | null): string =>
        value
            ? new Intl.DateTimeFormat('ja-JP', {
                  dateStyle: 'medium',
                  timeStyle: 'short',
              }).format(new Date(value))
            : '';
</script>

<AppHead title={list.title} />

<main
    class="min-h-[calc(100vh-7rem)] bg-slate-100/70 px-3 py-5 sm:px-6 sm:py-9 dark:bg-slate-950/50"
>
    <article
        class="mx-auto w-full max-w-5xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[0_18px_55px_rgba(15,23,42,0.08)] sm:rounded-[1.75rem] dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/25"
    >
        <header class="px-5 pt-6 pb-5 sm:px-10 sm:pt-9">
            <div class="flex items-center justify-between gap-4">
                <nav
                    class="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-1 text-sm text-muted-foreground"
                    aria-label={t('Breadcrumb')}
                >
                    <Link
                        href={showProject([currentTeam.slug, project.id])}
                        class="truncate font-semibold text-foreground hover:underline"
                        >{project.name}</Link
                    >
                    <span aria-hidden="true">‹</span>
                    <Link
                        href={ProjectTodoController.index([
                            currentTeam.slug,
                            project.id,
                        ])}
                        class="hover:underline"
                        >{currentTeam.menuNames.todos}</Link
                    >
                </nav>

                {#if list.canDelete}
                    <Form
                        {...ProjectTodoController.destroyList.form(
                            routeArguments,
                        )}
                        onBefore={confirmDelete}
                        class="shrink-0"
                    >
                        {#snippet children({ processing })}
                            <Button
                                type="submit"
                                variant="ghost"
                                size="sm"
                                class="text-destructive hover:text-destructive"
                                disabled={processing}
                            >
                                <Trash2 aria-hidden="true" />
                                {t('Delete')}
                            </Button>
                        {/snippet}
                    </Form>
                {/if}
            </div>

            <h1
                class="mt-4 break-words text-3xl font-black tracking-[-0.035em] text-slate-900 sm:text-4xl dark:text-slate-50"
            >
                {list.title}
            </h1>

            <div class="mt-4 flex flex-wrap items-center gap-2 text-sm">
                <span
                    class="size-3 rounded-full {completedCount ===
                        todos.length && todos.length > 0
                        ? 'bg-emerald-500'
                        : 'bg-amber-400'}"
                    aria-hidden="true"
                ></span>
                <span
                    class="rounded-full bg-slate-100 px-2.5 py-1 font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300"
                    >{completedCount}/{todos.length} {t('Completed')}</span
                >
            </div>
        </header>

        <section class="min-h-56 px-5 pb-10 sm:px-10 sm:pb-12">
            <div
                class="divide-y divide-slate-100 border-y border-slate-200 dark:divide-slate-800 dark:border-slate-700"
                role="list"
                aria-label={t('To-dos')}
            >
                {#each activeTodos as todo (todo.id)}
                    <div
                        role="listitem"
                        class="group flex min-w-0 items-start gap-3 py-3.5"
                    >
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
                                    class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-md border-2 border-slate-400 bg-white text-emerald-600 transition hover:border-emerald-500 disabled:opacity-50 dark:bg-slate-900"
                                >
                                    {#if todo.completed}
                                        <Check class="size-4" />
                                    {/if}
                                </button>
                            {/snippet}
                        </Form>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <Link
                                    href={ProjectTodoController.show([
                                        currentTeam.slug,
                                        project.id,
                                        list.id,
                                        todo.id,
                                    ])}
                                    class="break-words text-[0.95rem] font-medium text-slate-800 hover:text-primary hover:underline sm:text-base dark:text-slate-100"
                                >
                                    {todo.title}
                                </Link>
                                {#if todo.hasNotes}
                                    <FileText
                                        class="size-3.5 shrink-0 text-slate-400"
                                        aria-label={t('Notes')}
                                    />
                                {/if}
                            </div>
                            {#if todo.assigneeName || todo.dueOn}
                                <p
                                    class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500 dark:text-slate-400"
                                >
                                    {#if todo.assigneeName}
                                        <span
                                            class="rounded-full bg-slate-100 px-2 py-0.5 dark:bg-slate-800"
                                            >{todo.assigneeName}</span
                                        >
                                    {/if}
                                    {#if todo.dueOn}
                                        <time datetime={todo.dueOn}
                                            >{todo.dueOn}</time
                                        >
                                    {/if}
                                </p>
                            {/if}
                        </div>
                    </div>
                {/each}
            </div>

            {#if addingTodo}
                <Form
                    {...ProjectTodoController.storeTodo.form(routeArguments)}
                    resetOnSuccess
                    onSuccess={closeAddForm}
                    transform={(data) => ({
                        ...data,
                        subtasks: (
                            (data.subtasks as string[] | undefined) ?? []
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
                                    placeholder={t('Describe this to-do...')}
                                />
                                <InputError message={errors.title} />
                            </div>
                        </div>

                        <div
                            class="mt-4 grid gap-x-5 gap-y-4 pl-8 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-start"
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
                                <option value="">{t('Unassigned')}</option>
                                {#each members as member (member.id)}
                                    <option value={member.id}
                                        >{member.name}</option
                                    >
                                {/each}
                            </select>

                            <span class="pt-1 text-sm font-medium sm:text-right"
                                >{t('When done, notify')}</span
                            >
                            <div class="flex flex-wrap gap-x-4 gap-y-2 pt-1">
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

                            <span class="pt-1 text-sm font-medium sm:text-right"
                                >{t('Notes')}</span
                            >
                            <div class="min-w-0">
                                <RichTextEditor
                                    id={`new-todo-details-${list.id}`}
                                    name="details"
                                    placeholder={t('Add extra details...')}
                                    ariaLabel={t('Notes')}
                                    mentionables={members}
                                    attachmentUploadUrl={AttachmentController.uploadInline.url(
                                        projectRouteArguments,
                                    )}
                                />
                            </div>

                            <span class="pt-1 text-sm font-medium sm:text-right"
                                >{t('Subtasks')}</span
                            >
                            <div class="flex min-w-0 flex-col gap-2">
                                {#each newSubtasks as _, index (index)}
                                    <div class="flex gap-2">
                                        <Input
                                            name="subtasks[]"
                                            maxlength={255}
                                            bind:value={newSubtasks[index]}
                                            placeholder={t('Subtask title')}
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
                                                    ))}>{t('Delete')}</Button
                                        >
                                    </div>
                                {/each}
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onclick={() =>
                                        (newSubtasks = [...newSubtasks, ''])}
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
                        <div class="mt-5 flex gap-2 pl-8">
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
                                onclick={closeAddForm}>{t('Cancel')}</Button
                            >
                        </div>
                    {/snippet}
                </Form>
            {:else}
                <button
                    type="button"
                    class="mt-3 flex items-center gap-1.5 text-sm font-semibold text-sky-700 hover:underline dark:text-sky-400"
                    onclick={() => {
                        addingTodo = true;
                        newSubtasks = [];
                    }}
                >
                    <Plus class="size-4" />
                    {t('Add a to-do')}
                </button>
            {/if}

            {#if completedTodos.length > 0}
                <details class="group/completed mt-7">
                    <summary
                        class="[&::-webkit-details-marker]:hidden flex w-fit cursor-pointer list-none items-center gap-2 rounded-lg px-1 py-1 text-sm font-semibold text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100"
                    >
                        <span
                            class="flex size-5 items-center justify-center rounded-md border border-emerald-300 bg-emerald-50 text-emerald-600 dark:border-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
                        >
                            <Check class="size-3.5" />
                        </span>
                        {completedTodos.length}
                        {t('Completed')}
                        <ChevronDown
                            class="size-4 transition-transform group-open/completed:rotate-180"
                            aria-hidden="true"
                        />
                    </summary>

                    <div
                        class="mt-2 divide-y divide-slate-100 border-y border-slate-200 dark:divide-slate-800 dark:border-slate-700"
                        role="list"
                        aria-label={t('Completed')}
                    >
                        {#each completedTodos as todo (todo.id)}
                            <div
                                role="listitem"
                                class="group flex min-w-0 items-start gap-3 py-3"
                            >
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
                                            aria-label={t('Mark incomplete')}
                                            class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-md border border-emerald-400 bg-emerald-50 text-emerald-600 disabled:opacity-50 dark:bg-emerald-950/40 dark:text-emerald-300"
                                        >
                                            <Check class="size-4" />
                                        </button>
                                    {/snippet}
                                </Form>

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-1.5">
                                        <Link
                                            href={ProjectTodoController.show([
                                                currentTeam.slug,
                                                project.id,
                                                list.id,
                                                todo.id,
                                            ])}
                                            class="break-words text-[0.95rem] font-medium text-slate-400 line-through hover:text-slate-600 hover:underline dark:text-slate-500 dark:hover:text-slate-300"
                                        >
                                            {todo.title}
                                        </Link>
                                        {#if todo.hasNotes}
                                            <FileText
                                                class="size-3.5 shrink-0 text-slate-400"
                                                aria-label={t('Notes')}
                                            />
                                        {/if}
                                    </div>
                                    {#if todo.assigneeName || todo.dueOn}
                                        <p
                                            class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-slate-400 dark:text-slate-500"
                                        >
                                            {#if todo.assigneeName}
                                                <span>{todo.assigneeName}</span>
                                            {/if}
                                            {#if todo.dueOn}
                                                <time datetime={todo.dueOn}
                                                    >{todo.dueOn}</time
                                                >
                                            {/if}
                                        </p>
                                    {/if}
                                </div>
                            </div>
                        {/each}
                    </div>
                </details>
            {/if}
        </section>

        <section
            class="border-t border-slate-200 bg-slate-50/40 px-5 py-6 sm:px-10 dark:border-slate-700 dark:bg-slate-800/20"
            aria-label={t('Comments')}
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-base font-semibold">
                    {t('Comments')} ({comments.length})
                </h2>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-muted-foreground">
                        {t(':count people will be notified about comments.', {
                            count: String(list.subscriberCount),
                        })}
                    </span>
                    <Form
                        {...ProjectTodoController.toggleListSubscription.form(
                            routeArguments,
                        )}
                    >
                        {#snippet children({ processing })}
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={processing}
                            >
                                {#if list.subscribed}
                                    <BellOff class="size-3.5" />
                                    {t("Don't notify me")}
                                {:else}
                                    <Bell class="size-3.5" />
                                    {t('Notify me')}
                                {/if}
                            </Button>
                        {/snippet}
                    </Form>
                </div>
            </div>

            {#if comments.length === 0}
                <p class="mt-3 text-sm text-muted-foreground">
                    {t('No comments yet')}
                </p>
            {:else}
                <div class="mt-4 space-y-4">
                    {#each comments as comment (comment.id)}
                        <div class="rounded-lg border bg-background p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <RichTextContent content={comment.body} />
                                    <p
                                        class="mt-2 text-xs text-muted-foreground"
                                    >
                                        {comment.authorName ||
                                            t('Deleted user')} · {formatDate(
                                            comment.createdAt,
                                        )}
                                    </p>
                                </div>

                                {#if comment.canDelete}
                                    <div
                                        class="flex shrink-0 items-start gap-1"
                                    >
                                        <details>
                                            <summary
                                                aria-label={t('Edit')}
                                                class="[&::-webkit-details-marker]:hidden flex size-7 list-none cursor-pointer items-center justify-center rounded-md text-muted-foreground hover:bg-muted"
                                            >
                                                <Pencil class="size-3.5" />
                                            </summary>
                                            <Form
                                                {...CommentController.updateList.form(
                                                    [
                                                        currentTeam.slug,
                                                        project.id,
                                                        list.id,
                                                        comment.id,
                                                    ],
                                                )}
                                                class="mt-3 min-w-[min(32rem,70vw)] space-y-2"
                                            >
                                                {#snippet children({
                                                    errors,
                                                    processing,
                                                })}
                                                    <RichTextEditor
                                                        id={`list-comment-edit-${comment.id}`}
                                                        name="body"
                                                        value={comment.body}
                                                        required
                                                        ariaLabel={t('Comment')}
                                                        mentionables={members}
                                                        attachmentUploadUrl={AttachmentController.uploadInline.url(
                                                            projectRouteArguments,
                                                        )}
                                                    />
                                                    <InputError
                                                        message={errors.body}
                                                    />
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        disabled={processing}
                                                        >{t('Save')}</Button
                                                    >
                                                {/snippet}
                                            </Form>
                                        </details>
                                        <Form
                                            {...CommentController.destroyList.form(
                                                [
                                                    currentTeam.slug,
                                                    project.id,
                                                    list.id,
                                                    comment.id,
                                                ],
                                            )}
                                            onBefore={confirmDelete}
                                        >
                                            {#snippet children({ processing })}
                                                <button
                                                    type="submit"
                                                    disabled={processing}
                                                    aria-label={t('Delete')}
                                                    class="flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-destructive/10 hover:text-destructive disabled:opacity-50"
                                                >
                                                    <Trash2 class="size-3.5" />
                                                </button>
                                            {/snippet}
                                        </Form>
                                    </div>
                                {/if}
                            </div>
                        </div>
                    {/each}
                </div>
            {/if}

            <Form
                {...CommentController.storeList.form(routeArguments)}
                resetOnSuccess
                class="mt-5 space-y-2"
            >
                {#snippet children({ errors, processing })}
                    <Label for="list-comment">{t('Comment')}</Label>
                    <RichTextEditor
                        id="list-comment"
                        name="body"
                        required
                        ariaLabel={t('Comment')}
                        mentionables={members}
                        attachmentUploadUrl={AttachmentController.uploadInline.url(
                            projectRouteArguments,
                        )}
                    />
                    <InputError message={errors.body} />
                    <Button type="submit" disabled={processing}
                        >{t('Add comment')}</Button
                    >
                {/snippet}
            </Form>
        </section>
    </article>
</main>
