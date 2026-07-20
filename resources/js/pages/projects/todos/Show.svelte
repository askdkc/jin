<script module lang="ts">
    import ProjectTodoController from '@/actions/App/Http/Controllers/ProjectTodoController';
    import { show as showProject } from '@/routes/projects';
    import type { ProjectTodo, ProjectTool, Team } from '@/types';

    export const layout = (props: {
        currentTeam: Team;
        project: ProjectTool;
        list: { id: number; title: string };
        todo: ProjectTodo;
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
                title: props.todo.title,
                href: ProjectTodoController.show([
                    props.currentTeam.slug,
                    props.project.id,
                    props.list.id,
                    props.todo.id,
                ]),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link, router } from '@inertiajs/svelte';
    import Check from 'lucide-svelte/icons/check';
    import Download from 'lucide-svelte/icons/download';
    import Paperclip from 'lucide-svelte/icons/paperclip';
    import Pencil from 'lucide-svelte/icons/pencil';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import AttachmentController from '@/actions/App/Http/Controllers/AttachmentController';
    import CommentController from '@/actions/App/Http/Controllers/CommentController';
    import ProjectFileController from '@/actions/App/Http/Controllers/ProjectFileController';
    import AppHead from '@/components/AppHead.svelte';
    import FileDropzone from '@/components/FileDropzone.svelte';
    import InputError from '@/components/InputError.svelte';
    import RichTextContent from '@/components/RichTextContent.svelte';
    import RichTextEditor from '@/components/RichTextEditor.svelte';
    import StoredFilePreviewDialog, {
        canPreviewStoredFile,
    } from '@/components/StoredFilePreviewDialog.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
    import type { TodoMember } from '@/types';

    let {
        currentTeam,
        project,
        list,
        todo,
        members,
    }: {
        currentTeam: Team;
        project: ProjectTool;
        list: { id: number; title: string };
        todo: ProjectTodo;
        members: TodoMember[];
    } = $props();

    const routeArguments = $derived([
        currentTeam.slug,
        project.id,
        list.id,
        todo.id,
    ] as [string, number, number, number]);
    const attachmentUploadUrl = $derived(
        AttachmentController.uploadInline.url([currentTeam.slug, project.id]),
    );
    const completionNotifyNames = $derived(
        members
            .filter((member) => todo.completionNotifyIds.includes(member.id))
            .map((member) => member.name),
    );
    let previewOpen = $state(false);
    let previewFile = $state<ProjectTodo['attachments'][number] | null>(null);
    let showAttachmentUpload = $state(false);
    let editingCommentId = $state<number | null>(null);

    type EditableField = 'title' | 'assignee' | 'due' | 'notify' | 'notes';
    let editingField = $state<EditableField | null>(null);
    let titleDraft = $state(''); // set from todo.title in openField('title') before use
    let notifyDraft = $state<number[]>([]);

    // ponytail: updateTodo requires a full valid payload (title is required, and an
    // absent completion_notify_ids wipes it), so every inline save resends current
    // values and overrides just the edited field — no backend partial-update support.
    const patchField = (overrides: Record<string, unknown>): void => {
        router.patch(
            ProjectTodoController.updateTodo(routeArguments).url,
            {
                title: todo.title,
                details: todo.details ?? '',
                assignee_id: todo.assigneeId ?? '',
                due_on: todo.dueOn ?? '',
                completion_notify_ids: todo.completionNotifyIds,
                ...overrides,
            },
            { preserveScroll: true, onSuccess: () => (editingField = null) },
        );
    };
    const openField = (field: EditableField): void => {
        if (!todo.canEdit) {
            return;
        }

        editingField = field;

        if (field === 'title') {
            titleDraft = todo.title;
        }

        if (field === 'notify') {
            notifyDraft = [...todo.completionNotifyIds];
        }
    };
    const closeField = (): void => {
        editingField = null;
    };
    const saveTitle = (): void => {
        if (editingField !== 'title') {
            return;
        }

        const value = titleDraft.trim();
        editingField = null;

        if (value && value !== todo.title) {
            patchField({ title: value });
        }
    };
    const cancelTitle = (): void => {
        editingField = null;
        titleDraft = todo.title;
    };
    const toggleNotifyDraft = (id: number, checked: boolean): void => {
        notifyDraft = checked
            ? [...notifyDraft, id]
            : notifyDraft.filter((current) => current !== id);
    };

    const confirmDelete = (): boolean =>
        window.confirm(t('Are you sure you want to delete this?'));
    const openPreview = (file: ProjectTodo['attachments'][number]): void => {
        previewFile = file;
        previewOpen = true;
    };
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
</script>

<AppHead title={todo.title} />

<main class="mx-auto w-full max-w-4xl px-4 py-6 sm:px-6 sm:py-8">
    <article class="overflow-hidden rounded-xl border bg-card">
        <header class="px-5 pt-5 pb-6 sm:px-8">
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
                    <span aria-hidden="true">‹</span>
                    <span class="truncate text-foreground">{list.title}</span>
                </nav>

                {#if todo.canEdit}
                    <Form
                        {...ProjectTodoController.destroyTodo.form(
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

            <div class="mt-4">
                {#if editingField === 'title'}
                    <!-- svelte-ignore a11y_autofocus -->
                    <input
                        bind:value={titleDraft}
                        maxlength={255}
                        autofocus
                        aria-label={t('Title')}
                        onblur={saveTitle}
                        onkeydown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                saveTitle();
                            } else if (event.key === 'Escape') {
                                cancelTitle();
                            }
                        }}
                        class="w-full rounded-md border-0 bg-transparent px-0 py-0 text-3xl font-bold tracking-tight shadow-none focus-visible:outline-none focus-visible:ring-0"
                    />
                {:else if todo.canEdit}
                    <button
                        type="button"
                        onclick={() => openField('title')}
                        title={t('Edit')}
                        class:line-through={todo.completed}
                        class="block break-words text-left text-3xl font-bold tracking-tight hover:text-primary"
                    >
                        {todo.title}
                    </button>
                {:else}
                    <h1
                        class:line-through={todo.completed}
                        class="break-words text-3xl font-bold tracking-tight"
                    >
                        {todo.title}
                    </h1>
                {/if}
            </div>

            <div class="mt-6 flex flex-wrap items-center gap-x-4 gap-y-2">
                <Form
                    {...ProjectTodoController.toggleTodo.form(routeArguments)}
                >
                    {#snippet children({ processing })}
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            disabled={processing}
                        >
                            <span
                                class="flex size-5 items-center justify-center rounded border border-foreground/60"
                            >
                                {#if todo.completed}
                                    <Check class="size-4" />
                                {/if}
                            </span>
                            {todo.completed
                                ? t('Mark incomplete')
                                : t('Mark complete')}
                        </Button>
                    {/snippet}
                </Form>
                <p class="text-sm text-muted-foreground">
                    {t('Added by')}
                    {todo.creatorName || t('Deleted user')}
                    {#if todo.createdAt}
                        · {formatDate(todo.createdAt)}
                    {/if}
                </p>
            </div>
        </header>

        <section class="px-5 pt-2 pb-4 sm:px-8">
            <dl
                class="grid gap-x-8 gap-y-4 text-sm sm:grid-cols-[10rem_minmax(0,1fr)] sm:items-start"
            >
                <dt class="font-semibold sm:pt-1.5 sm:text-right">
                    {t('Assignee')}
                </dt>
                <dd>
                    {#if editingField === 'assignee'}
                        <!-- svelte-ignore a11y_autofocus -->
                        <select
                            autofocus
                            value={todo.assigneeId ?? ''}
                            onchange={(event) =>
                                patchField({
                                    assignee_id: event.currentTarget.value,
                                })}
                            onblur={closeField}
                            class="border-input h-9 w-full max-w-xs rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">{t('Unassigned')}</option>
                            {#each members as member (member.id)}
                                <option value={member.id}>{member.name}</option>
                            {/each}
                        </select>
                    {:else if todo.canEdit}
                        <button
                            type="button"
                            onclick={() => openField('assignee')}
                            class="block w-full rounded-md px-2 py-1 text-left transition hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            {#if todo.assigneeName}
                                {todo.assigneeName}
                            {:else}
                                <span class="text-muted-foreground"
                                    >{t('Unassigned')}</span
                                >
                            {/if}
                        </button>
                    {:else}
                        <span class="px-2 py-1 text-muted-foreground"
                            >{todo.assigneeName || t('Unassigned')}</span
                        >
                    {/if}
                </dd>

                <dt class="font-semibold sm:pt-1.5 sm:text-right">
                    {t('When done, notify')}
                </dt>
                <dd>
                    {#if editingField === 'notify'}
                        <div class="space-y-3">
                            <div class="flex flex-wrap gap-x-5 gap-y-2">
                                {#each members as member (member.id)}
                                    <label
                                        class="flex items-center gap-2 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={notifyDraft.includes(
                                                member.id,
                                            )}
                                            onchange={(event) =>
                                                toggleNotifyDraft(
                                                    member.id,
                                                    event.currentTarget.checked,
                                                )}
                                            class="size-4 rounded accent-primary"
                                        />
                                        {member.name}
                                    </label>
                                {/each}
                            </div>
                            <div class="flex gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    onclick={() =>
                                        patchField({
                                            completion_notify_ids: notifyDraft,
                                        })}>{t('Save')}</Button
                                >
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onclick={closeField}>{t('Cancel')}</Button
                                >
                            </div>
                        </div>
                    {:else if todo.canEdit}
                        <button
                            type="button"
                            onclick={() => openField('notify')}
                            class="block w-full rounded-md px-2 py-1 text-left transition hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            {#if completionNotifyNames.length > 0}
                                {completionNotifyNames.join('、')}
                            {:else}
                                <span class="text-muted-foreground"
                                    >{t('None')}</span
                                >
                            {/if}
                        </button>
                    {:else}
                        <span class="px-2 py-1 text-muted-foreground"
                            >{completionNotifyNames.join('、') ||
                                t('None')}</span
                        >
                    {/if}
                </dd>

                <dt class="font-semibold sm:pt-1.5 sm:text-right">
                    {t('Due date')}
                </dt>
                <dd>
                    {#if editingField === 'due'}
                        <!-- svelte-ignore a11y_autofocus -->
                        <input
                            type="date"
                            autofocus
                            value={todo.dueOn ?? ''}
                            onchange={(event) =>
                                patchField({
                                    due_on: event.currentTarget.value,
                                })}
                            onblur={closeField}
                            class="border-input h-9 max-w-56 rounded-md border bg-background px-3 text-sm"
                        />
                    {:else if todo.canEdit}
                        <button
                            type="button"
                            onclick={() => openField('due')}
                            class="block w-full rounded-md px-2 py-1 text-left transition hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            {#if todo.dueOn}
                                {todo.dueOn}
                            {:else}
                                <span class="text-muted-foreground"
                                    >{t('None')}</span
                                >
                            {/if}
                        </button>
                    {:else}
                        <span class="px-2 py-1 text-muted-foreground"
                            >{todo.dueOn || t('None')}</span
                        >
                    {/if}
                </dd>

                <dt class="font-semibold sm:pt-1.5 sm:text-right">
                    {t('Notes')}
                </dt>
                <dd class="min-w-0">
                    {#if editingField === 'notes'}
                        <Form
                            {...ProjectTodoController.updateTodo.form(
                                routeArguments,
                            )}
                            onSuccess={closeField}
                            class="space-y-2"
                        >
                            {#snippet children({ errors, processing })}
                                <input
                                    type="hidden"
                                    name="title"
                                    value={todo.title}
                                />
                                <input
                                    type="hidden"
                                    name="assignee_id"
                                    value={todo.assigneeId ?? ''}
                                />
                                <input
                                    type="hidden"
                                    name="due_on"
                                    value={todo.dueOn ?? ''}
                                />
                                {#each todo.completionNotifyIds as notifyId (notifyId)}
                                    <input
                                        type="hidden"
                                        name="completion_notify_ids[]"
                                        value={notifyId}
                                    />
                                {/each}
                                <RichTextEditor
                                    id="todo-details"
                                    name="details"
                                    value={todo.details}
                                    ariaLabel={t('Notes')}
                                    mentionables={members}
                                    {attachmentUploadUrl}
                                />
                                <InputError message={errors.details} />
                                <div class="flex gap-2">
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                        >{t('Save')}</Button
                                    >
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onclick={closeField}
                                        >{t('Cancel')}</Button
                                    >
                                </div>
                            {/snippet}
                        </Form>
                    {:else if todo.details}
                        <div
                            class="group flex items-start justify-between gap-2"
                        >
                            <RichTextContent
                                content={todo.details}
                                class="min-w-0 flex-1 leading-6"
                            />
                            {#if todo.canEdit}
                                <button
                                    type="button"
                                    onclick={() => openField('notes')}
                                    aria-label={t('Edit')}
                                    title={t('Edit')}
                                    class="shrink-0 rounded-md p-1 text-muted-foreground opacity-0 transition hover:bg-muted focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring group-hover:opacity-100"
                                >
                                    <Pencil class="size-3.5" />
                                </button>
                            {/if}
                        </div>
                    {:else if todo.canEdit}
                        <button
                            type="button"
                            onclick={() => openField('notes')}
                            class="block w-full rounded-md px-2 py-1 text-left text-muted-foreground transition hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            {t('Add extra details...')}
                        </button>
                    {:else}
                        <span class="px-2 py-1 text-muted-foreground"
                            >{t('No notes')}</span
                        >
                    {/if}
                </dd>
            </dl>
        </section>

        <section class="px-5 pb-4 sm:px-8" aria-label={t('Subtasks')}>
            <div
                class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-[10rem_minmax(0,1fr)] sm:items-start"
            >
                <p class="font-semibold sm:pt-1 sm:text-right">
                    {t('Subtasks')} ({todo.subtasks.length})
                </p>
                <div class="min-w-0">
                    {#if todo.subtasks.length > 0}
                        <div class="space-y-2">
                            {#each todo.subtasks as subtask (subtask.id)}
                                <div class="flex min-w-0 items-center gap-3">
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
                                        {#snippet children({ processing })}
                                            <button
                                                type="submit"
                                                disabled={processing}
                                                aria-label={subtask.completed
                                                    ? t('Mark incomplete')
                                                    : t('Mark complete')}
                                                class="flex size-5 shrink-0 items-center justify-center rounded border border-foreground/60 text-primary disabled:opacity-50"
                                            >
                                                {#if subtask.completed}
                                                    <Check class="size-4" />
                                                {/if}
                                            </button>
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
                                    {/if}
                                </div>
                            {/each}
                        </div>
                    {/if}

                    <Form
                        {...ProjectTodoController.storeSubtask.form(
                            routeArguments,
                        )}
                        resetOnSuccess
                        class="flex flex-col gap-2 sm:flex-row {todo.subtasks
                            .length > 0
                            ? 'mt-3'
                            : ''}"
                    >
                        {#snippet children({ errors, processing })}
                            <div class="min-w-0 flex-1">
                                <Label for="new-subtask" class="sr-only"
                                    >{t('Subtask title')}</Label
                                >
                                <Input
                                    id="new-subtask"
                                    name="title"
                                    maxlength={255}
                                    required
                                    placeholder={t('Subtask title')}
                                />
                                <InputError message={errors.title} />
                            </div>
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}>{t('Add subtask')}</Button
                            >
                        {/snippet}
                    </Form>
                </div>
            </div>
        </section>

        <section class="px-5 pb-6 sm:px-8" aria-label={t('Attachments')}>
            <div
                class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-[10rem_minmax(0,1fr)] sm:items-start"
            >
                <p class="font-semibold sm:pt-1 sm:text-right">
                    {t('Attachments')} ({todo.attachments.length})
                </p>
                <div class="min-w-0">
                    {#if todo.attachments.length > 0}
                        <div class="divide-y">
                            {#each todo.attachments as attachment (attachment.id)}
                                <div
                                    class="flex min-w-0 flex-col gap-3 py-3 sm:flex-row sm:items-center"
                                >
                                    <Paperclip
                                        class="size-5 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <div class="min-w-0 flex-1">
                                        {#if canPreviewStoredFile(attachment.name)}
                                            <button
                                                type="button"
                                                class="cursor-zoom-in break-all text-left text-sm font-medium underline-offset-4 hover:text-primary hover:underline focus-visible:rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                title={t('Preview')}
                                                onclick={() =>
                                                    openPreview(attachment)}
                                            >
                                                {attachment.name}
                                            </button>
                                        {:else}
                                            <p
                                                class="break-all text-sm font-medium"
                                            >
                                                {attachment.name}
                                            </p>
                                        {/if}
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {formatSize(attachment.size)} · {attachment.uploaderName ||
                                                t('Deleted user')}
                                        </p>
                                    </div>
                                    <Button variant="ghost" size="sm" asChild>
                                        {#snippet children(buttonProps)}
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
                                                <Download aria-hidden="true" />
                                                {t('Download')}
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
                                </div>
                            {/each}
                        </div>
                    {/if}

                    {#if showAttachmentUpload}
                        <Form
                            {...AttachmentController.storeTodo.form(
                                routeArguments,
                            )}
                            resetOnSuccess
                            onSuccess={() => (showAttachmentUpload = false)}
                            class="space-y-2 {todo.attachments.length > 0
                                ? 'mt-3'
                                : ''}"
                        >
                            {#snippet children({
                                errors,
                                processing,
                                progress,
                            })}
                                <FileDropzone
                                    id="todo-attachment"
                                    error={errors.file}
                                    compact
                                    accept=".pdf,.txt,.csv,.jpg,.jpeg,.png,.webp,.mp4,.webm,.mov,.m4v,.doc,.docx,.odt,.xls,.xlsx,.xlsm,.ods,.ppt,.pptx,.odp,.zip"
                                />
                                {#if progress}
                                    <progress
                                        value={progress.percentage}
                                        max="100"
                                        class="h-2 w-full"
                                        >{progress.percentage}%</progress
                                    >
                                {/if}
                                <div class="flex gap-2">
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                        >{t('Attach file')}</Button
                                    >
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onclick={() =>
                                            (showAttachmentUpload = false)}
                                        >{t('Cancel')}</Button
                                    >
                                </div>
                            {/snippet}
                        </Form>
                    {:else}
                        <button
                            type="button"
                            onclick={() => (showAttachmentUpload = true)}
                            class="block w-full rounded-md px-2 py-1 text-left text-muted-foreground transition hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring {todo
                                .attachments.length > 0
                                ? 'mt-3'
                                : ''}"
                        >
                            {t('Attach file')}
                        </button>
                    {/if}
                </div>
            </div>
        </section>

        <section class="border-t px-5 py-6 sm:px-8" aria-label={t('Comments')}>
            <h2 class="text-base font-semibold">
                {t('Comments')} ({todo.comments.length})
            </h2>

            {#if todo.comments.length === 0}
                <p class="mt-3 text-sm text-muted-foreground">
                    {t('No comments yet')}
                </p>
            {:else}
                <div class="mt-4 space-y-4">
                    {#each todo.comments as comment (comment.id)}
                        <div class="rounded-lg border bg-background p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    {#if editingCommentId === comment.id}
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
                                            onSuccess={() =>
                                                (editingCommentId = null)}
                                            class="w-full space-y-2"
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
                                                    ariaLabel={t('Comment')}
                                                    mentionables={members}
                                                    {attachmentUploadUrl}
                                                />
                                                <InputError
                                                    message={errors.body}
                                                />
                                                <div class="flex gap-2">
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        disabled={processing}
                                                        >{t('Save')}</Button
                                                    >
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={processing}
                                                        onclick={() =>
                                                            (editingCommentId =
                                                                null)}
                                                        >{t('Cancel')}</Button
                                                    >
                                                </div>
                                            {/snippet}
                                        </Form>
                                    {:else}
                                        <RichTextContent
                                            content={comment.body}
                                        />
                                        <p
                                            class="mt-2 text-xs text-muted-foreground"
                                        >
                                            {comment.authorName ||
                                                t('Deleted user')} · {formatDate(
                                                comment.createdAt,
                                            )}
                                        </p>
                                    {/if}
                                </div>

                                {#if comment.canDelete}
                                    <div
                                        class="flex shrink-0 items-start gap-1"
                                    >
                                        {#if editingCommentId !== comment.id}
                                            <button
                                                type="button"
                                                aria-label={t('Edit')}
                                                onclick={() =>
                                                    (editingCommentId =
                                                        comment.id)}
                                                class="flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-muted"
                                            >
                                                <Pencil class="size-3.5" />
                                            </button>
                                        {/if}
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
                {...CommentController.storeTodo.form(routeArguments)}
                resetOnSuccess
                class="mt-5 space-y-2"
            >
                {#snippet children({ errors, processing })}
                    <Label for="todo-comment">{t('Comment')}</Label>
                    <RichTextEditor
                        id="todo-comment"
                        name="body"
                        required
                        ariaLabel={t('Comment')}
                        mentionables={members}
                        {attachmentUploadUrl}
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

<StoredFilePreviewDialog
    {currentTeam}
    {project}
    file={previewFile}
    bind:open={previewOpen}
/>
