<script lang="ts">
    import { Form, Link, router } from '@inertiajs/svelte';
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import CheckCircle2 from 'lucide-svelte/icons/check-circle-2';
    import Circle from 'lucide-svelte/icons/circle';
    import Download from 'lucide-svelte/icons/download';
    import FileText from 'lucide-svelte/icons/file-text';
    import Link2 from 'lucide-svelte/icons/link-2';
    import ListTodo from 'lucide-svelte/icons/list-todo';
    import Plus from 'lucide-svelte/icons/plus';
    import Unlink from 'lucide-svelte/icons/unlink';
    import ProjectFileController from '@/actions/App/Http/Controllers/ProjectFileController';
    import ProjectTodoController from '@/actions/App/Http/Controllers/ProjectTodoController';
    import InputError from '@/components/InputError.svelte';
    import ProjectFileThumbnail from '@/components/ProjectFileThumbnail.svelte';
    import StoredFilePreviewDialog, {
        canPreviewStoredFile,
    } from '@/components/StoredFilePreviewDialog.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import {
        Dialog,
        DialogContent,
        DialogDescription,
        DialogFooter,
        DialogTitle,
    } from '@/components/ui/dialog';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Separator } from '@/components/ui/separator';
    import { currentLocale, t } from '@/lib/i18n';
    import type {
        ChatAttachment,
        ChatChannel,
        ChatProject,
        ChatTodo,
        ChatTodoList,
        Team,
        TodoMember,
    } from '@/types';

    let {
        currentTeam,
        project,
        selectedChannel,
        todoLists,
        relatedTodos,
        linkableTodos,
        channelFiles,
        members,
        show = 'all',
    }: {
        currentTeam: Team;
        project: ChatProject;
        selectedChannel: ChatChannel;
        todoLists: ChatTodoList[];
        relatedTodos: ChatTodo[];
        linkableTodos: ChatTodo[];
        channelFiles: ChatAttachment[];
        members: TodoMember[];
        show?: 'all' | 'todos' | 'files';
    } = $props();

    const initialTodoListId = (): number => todoLists[0]?.id ?? 0;

    let createOpen = $state(false);
    let createListId = $state<number>(initialTodoListId());
    let selectedLinkTodoId = $state<number>(0);
    let previewFile = $state<ChatAttachment | null>(null);
    let previewOpen = $state(false);

    const shouldShowTodos = $derived(show === 'all' || show === 'todos');
    const shouldShowFiles = $derived(show === 'all' || show === 'files');
    const incompleteCount = $derived(
        relatedTodos.filter((todo) => !todo.completed).length,
    );
    const formatDate = (value: string | null): string =>
        value
            ? new Intl.DateTimeFormat(currentLocale(), {
                  month: 'short',
                  day: 'numeric',
              }).format(new Date(`${value}T00:00:00`))
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
    const linkSelectedTodo = (): void => {
        const todo = linkableTodos.find(
            (candidate) => candidate.id === selectedLinkTodoId,
        );

        if (!todo) {
            return;
        }

        router.patch(
            ProjectTodoController.updateTodo([
                currentTeam.slug,
                project.id,
                todo.listId,
                todo.id,
            ]).url,
            { chat_channel_id: selectedChannel.id },
            {
                preserveScroll: true,
                onSuccess: () => (selectedLinkTodoId = 0),
            },
        );
    };
    const unlinkTodo = (todo: ChatTodo): void => {
        router.patch(
            ProjectTodoController.updateTodo([
                currentTeam.slug,
                project.id,
                todo.listId,
                todo.id,
            ]).url,
            { chat_channel_id: null },
            { preserveScroll: true },
        );
    };
    const openPreview = (file: ChatAttachment): void => {
        if (!canPreviewStoredFile(file.name)) {
            return;
        }

        previewFile = file;
        previewOpen = true;
    };
</script>

<div class="flex min-h-0 flex-1 flex-col overflow-y-auto bg-card">
    {#if shouldShowTodos}
        <section class="flex flex-col gap-3 p-4" aria-label={t('To-dos')}>
            <header class="flex items-center gap-2">
                <ListTodo class="size-4 text-muted-foreground" />
                <h2 class="text-sm font-bold">{t('To-dos')}</h2>
                <Badge variant="secondary" class="ml-auto">
                    {t(':count incomplete', {
                        count: String(incompleteCount),
                    })}
                </Badge>
            </header>

            {#if relatedTodos.length === 0}
                <p
                    class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground"
                >
                    {t('No to-dos yet')}
                </p>
            {:else}
                <div class="flex flex-col gap-2">
                    {#each relatedTodos as todo (todo.id)}
                        <div
                            class="group relative rounded-lg border bg-background hover:bg-muted/40"
                        >
                            <Link
                                href={ProjectTodoController.show([
                                    currentTeam.slug,
                                    project.id,
                                    todo.listId,
                                    todo.id,
                                ])}
                                class="flex gap-2.5 p-3 pr-10"
                                prefetch
                            >
                                {#if todo.completed}
                                    <CheckCircle2
                                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                    />
                                {:else}
                                    <Circle
                                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                    />
                                {/if}
                                <span class="min-w-0 flex-1">
                                    <span
                                        class:line-through={todo.completed}
                                        class="block break-words text-sm font-medium"
                                    >
                                        {todo.title}
                                    </span>
                                    <span
                                        class="mt-1 flex flex-wrap gap-x-2 text-xs text-muted-foreground"
                                    >
                                        <span>{todo.listTitle}</span>
                                        {#if todo.assigneeName}
                                            <span>{todo.assigneeName}</span>
                                        {/if}
                                        {#if todo.dueOn}
                                            <span
                                                class="inline-flex items-center gap-1"
                                            >
                                                <CalendarDays class="size-3" />
                                                {formatDate(todo.dueOn)}
                                            </span>
                                        {/if}
                                    </span>
                                </span>
                            </Link>
                            {#if todo.canEdit}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="absolute top-2 right-2 size-7 opacity-0 group-focus-within:opacity-100 group-hover:opacity-100"
                                    aria-label={t('Remove')}
                                    title={t('Remove')}
                                    onclick={() => unlinkTodo(todo)}
                                >
                                    <Unlink />
                                </Button>
                            {/if}
                        </div>
                    {/each}
                </div>
            {/if}

            {#if linkableTodos.length > 0}
                <div class="flex gap-2">
                    <label class="sr-only" for="chat-link-todo">
                        {t('To-do')}
                    </label>
                    <select
                        id="chat-link-todo"
                        class="h-9 min-w-0 flex-1 rounded-md border border-input bg-background px-2 text-sm"
                        value={selectedLinkTodoId}
                        onchange={(event) =>
                            (selectedLinkTodoId = Number(
                                event.currentTarget.value,
                            ))}
                    >
                        <option value={0}>{t('Add this to-do')}</option>
                        {#each linkableTodos as todo (todo.id)}
                            <option value={todo.id}>
                                {todo.listTitle} · {todo.title}
                            </option>
                        {/each}
                    </select>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        disabled={selectedLinkTodoId === 0}
                        aria-label={t('Link')}
                        title={t('Link')}
                        onclick={linkSelectedTodo}
                    >
                        <Link2 />
                    </Button>
                </div>
            {/if}

            <Button
                type="button"
                disabled={todoLists.length === 0}
                onclick={() => (createOpen = true)}
            >
                <Plus data-icon="inline-start" />
                {t('Add a to-do')}
            </Button>

            {#if todoLists.length === 0}
                <Link
                    href={ProjectTodoController.index([
                        currentTeam.slug,
                        project.id,
                    ])}
                    class="text-center text-xs text-primary hover:underline"
                >
                    {t('New to-do list')}
                </Link>
            {/if}
        </section>
    {/if}

    {#if shouldShowTodos && shouldShowFiles}
        <Separator />
    {/if}

    {#if shouldShowFiles}
        <section class="flex flex-col gap-3 p-4" aria-label={t('Files')}>
            <header class="flex items-center gap-2">
                <FileText class="size-4 text-muted-foreground" />
                <h2 class="text-sm font-bold">{t('Files')}</h2>
                <Badge variant="secondary" class="ml-auto">
                    {channelFiles.length}
                </Badge>
            </header>

            {#if channelFiles.length === 0}
                <p
                    class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground"
                >
                    {t('No files yet')}
                </p>
            {:else}
                <div class="flex flex-col gap-2">
                    {#each channelFiles as file (file.id)}
                        <div
                            class="flex items-center gap-2 rounded-lg border bg-background p-2.5"
                        >
                            <ProjectFileThumbnail
                                teamSlug={currentTeam.slug}
                                projectId={project.id}
                                fileId={file.id}
                                mimeType={file.mimeType}
                                thumbnailUrl={null}
                                variant="summary"
                            />
                            <div class="min-w-0 flex-1">
                                {#if canPreviewStoredFile(file.name)}
                                    <button
                                        type="button"
                                        class="block w-full truncate text-left text-sm font-medium hover:underline"
                                        onclick={() => openPreview(file)}
                                    >
                                        {file.name}
                                    </button>
                                {:else}
                                    <a
                                        href={ProjectFileController.download.url(
                                            [
                                                currentTeam.slug,
                                                project.id,
                                                file.id,
                                            ],
                                        )}
                                        class="block truncate text-sm font-medium hover:underline"
                                    >
                                        {file.name}
                                    </a>
                                {/if}
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {formatSize(file.size)}
                                    {#if file.uploaderName}
                                        · {file.uploaderName}
                                    {/if}
                                </p>
                            </div>
                            <Button asChild variant="ghost" size="icon">
                                {#snippet children({
                                    class: className,
                                    ...rest
                                })}
                                    <a
                                        href={ProjectFileController.download.url(
                                            [
                                                currentTeam.slug,
                                                project.id,
                                                file.id,
                                            ],
                                        )}
                                        class={className}
                                        aria-label={t('Download')}
                                        title={t('Download')}
                                        {...rest}
                                    >
                                        <Download />
                                    </a>
                                {/snippet}
                            </Button>
                        </div>
                    {/each}
                </div>
            {/if}
        </section>
    {/if}
</div>

<Dialog bind:open={createOpen}>
    <DialogContent class="sm:max-w-lg">
        <DialogTitle>{t('Add a to-do')}</DialogTitle>
        <DialogDescription>
            #{selectedChannel.name}
        </DialogDescription>

        {#if createListId > 0}
            <Form
                {...ProjectTodoController.storeTodo.form([
                    currentTeam.slug,
                    project.id,
                    createListId,
                ])}
                resetOnSuccess
                onSuccess={() => (createOpen = false)}
                class="mt-4 flex flex-col gap-4"
            >
                {#snippet children({ errors, processing })}
                    <input
                        type="hidden"
                        name="chat_channel_id"
                        value={selectedChannel.id}
                    />

                    <div class="flex flex-col gap-1.5">
                        <Label for="chat-todo-list">{t('To-dos')}</Label>
                        <select
                            id="chat-todo-list"
                            class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                            value={createListId}
                            onchange={(event) =>
                                (createListId = Number(
                                    event.currentTarget.value,
                                ))}
                        >
                            {#each todoLists as list (list.id)}
                                <option value={list.id}>{list.title}</option>
                            {/each}
                        </select>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label for="chat-todo-title">{t('To-do title')}</Label>
                        <Input
                            id="chat-todo-title"
                            name="title"
                            maxlength={255}
                            required
                            autofocus
                            placeholder={t('Describe this to-do...')}
                            aria-invalid={Boolean(errors.title)}
                        />
                        <InputError message={errors.title} />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <Label for="chat-todo-assignee">
                                {t('Assignee')}
                            </Label>
                            <select
                                id="chat-todo-assignee"
                                name="assignee_id"
                                class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="">{t('Unassigned')}</option>
                                {#each members as member (member.id)}
                                    <option value={member.id}>
                                        {member.name}
                                    </option>
                                {/each}
                            </select>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="chat-todo-due">{t('Due date')}</Label>
                            <Input
                                id="chat-todo-due"
                                name="due_on"
                                type="date"
                                aria-invalid={Boolean(errors.due_on)}
                            />
                        </div>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <Label for="chat-todo-details">{t('Notes')}</Label>
                        <textarea
                            id="chat-todo-details"
                            name="details"
                            rows="4"
                            class="min-h-24 resize-y rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        ></textarea>
                    </div>

                    <InputError
                        message={errors.assignee_id ||
                            errors.due_on ||
                            errors.details ||
                            errors.chat_channel_id}
                    />

                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onclick={() => (createOpen = false)}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button type="submit" disabled={processing}>
                            <Plus data-icon="inline-start" />
                            {t('Add a to-do')}
                        </Button>
                    </DialogFooter>
                {/snippet}
            </Form>
        {/if}
    </DialogContent>
</Dialog>

<StoredFilePreviewDialog
    {currentTeam}
    {project}
    file={previewFile}
    bind:open={previewOpen}
/>
