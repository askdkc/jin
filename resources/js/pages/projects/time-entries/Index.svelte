<script module lang="ts">
    import ProjectTimeEntryController from '@/actions/App/Http/Controllers/ProjectTimeEntryController';
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
                title: props.currentTeam.menuNames.timesheets,
                href: ProjectTimeEntryController.index([
                    props.currentTeam.slug,
                    props.project.id,
                ]),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import Clock3 from 'lucide-svelte/icons/clock-3';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
    import type { Paginated, ProjectTimeEntry } from '@/types';

    let {
        currentTeam,
        project,
        entries,
        todos,
        totalMinutes,
    }: {
        currentTeam: Team;
        project: ProjectTool;
        entries: Paginated<ProjectTimeEntry>;
        todos: ProjectTool[];
        totalMinutes: number;
    } = $props();

    const today = new Intl.DateTimeFormat('sv-SE', {
        timeZone: 'Asia/Tokyo',
    }).format(new Date());
    const formatDuration = (minutes: number): string =>
        `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
    // Paginator labels are localized HTML entities on the server, so positions are reliable.
    const paginationLabel = (
        label: string,
        index: number,
        lastIndex: number,
    ): string =>
        index === 0 ? t('Previous') : index === lastIndex ? t('Next') : label;
    const hasPaginationNavigation = $derived(
        entries.links.some((link) => link.url !== null && !link.active),
    );
</script>

<AppHead title={currentTeam.menuNames.timesheets} />

<div class="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm text-muted-foreground">{project.name}</p>
            <h1 class="text-3xl font-black tracking-tight">
                {currentTeam.menuNames.timesheets}
            </h1>
        </div>
        <div
            class="rounded-2xl bg-emerald-100 px-5 py-3 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100"
        >
            <p class="text-xs font-semibold">{t('Total time logged')}</p>
            <p class="text-2xl font-black">{formatDuration(totalMinutes)}</p>
        </div>
    </header>

    <Form
        {...ProjectTimeEntryController.store.form([
            currentTeam.slug,
            project.id,
        ])}
        resetOnSuccess
        class="grid gap-4 rounded-2xl border bg-card p-4 shadow-sm sm:grid-cols-2 sm:p-6"
    >
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="worked_on">{t('Date')}</Label>
                <Input
                    id="worked_on"
                    name="worked_on"
                    type="date"
                    value={today}
                    required
                />
                <InputError message={errors.worked_on} />
            </div>
            <div class="grid gap-2">
                <Label for="minutes">{t('Minutes')}</Label>
                <Input
                    id="minutes"
                    name="minutes"
                    type="number"
                    min="1"
                    max="1440"
                    value="60"
                    required
                />
                <InputError message={errors.minutes} />
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <Label for="todo_id">{currentTeam.menuNames.todos}</Label>
                <select
                    id="todo_id"
                    name="todo_id"
                    class="border-input focus-visible:border-ring focus-visible:ring-ring/50 h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                >
                    <option value=""
                        >{currentTeam.menuNames.todos}に関連付けない</option
                    >
                    {#each todos as todo (todo.id)}
                        <option value={todo.id}>{todo.name}</option>
                    {/each}
                </select>
                <InputError message={errors.todo_id} />
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <Label for="notes">{t('Notes')}</Label>
                <textarea
                    id="notes"
                    name="notes"
                    rows="3"
                    maxlength="5000"
                    class="border-input focus-visible:border-ring focus-visible:ring-ring/50 rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                ></textarea>
                <InputError message={errors.notes} />
            </div>
            <div class="sm:col-span-2 sm:text-right">
                <Button type="submit" disabled={processing}>
                    <Clock3 class="size-4" />
                    {t('Log time')}
                </Button>
            </div>
        {/snippet}
    </Form>

    {#if entries.data.length === 0}
        <div
            class="rounded-2xl border border-dashed p-10 text-center text-muted-foreground"
        >
            {t('No time entries yet')}
        </div>
    {:else}
        <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            {#each entries.data as entry (entry.id)}
                <article
                    class="flex flex-col gap-3 border-b p-4 last:border-0 sm:flex-row sm:items-center"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-bold">
                            {entry.userName} · {formatDuration(entry.minutes)}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {entry.workedOn}{entry.todoTitle
                                ? ` · ${entry.todoTitle}`
                                : ''}
                        </p>
                        {#if entry.notes}
                            <p class="mt-1 whitespace-pre-wrap text-sm">
                                {entry.notes}
                            </p>
                        {/if}
                    </div>
                    {#if entry.canDelete}
                        <Form
                            {...ProjectTimeEntryController.destroy.form([
                                currentTeam.slug,
                                project.id,
                                entry.id,
                            ])}
                        >
                            {#snippet children({ processing })}
                                <Button
                                    type="submit"
                                    variant="ghost"
                                    size="icon"
                                    disabled={processing}
                                    aria-label={t('Delete')}
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            {/snippet}
                        </Form>
                    {/if}
                </article>
            {/each}
        </div>
    {/if}

    {#if hasPaginationNavigation}
        <nav
            class="flex flex-wrap justify-center gap-2"
            aria-label={t('Pagination')}
        >
            {#each entries.links as link, index (index)}
                {@const url = link.url}
                {#if url}
                    <Button
                        variant={link.active ? 'default' : 'outline'}
                        size="sm"
                        asChild
                    >
                        {#snippet children(buttonProps)}
                            <Link
                                {...buttonProps}
                                href={url}
                                preserveScroll
                                class={buttonProps.class}
                                aria-current={link.active ? 'page' : undefined}
                            >
                                {paginationLabel(
                                    link.label,
                                    index,
                                    entries.links.length - 1,
                                )}
                            </Link>
                        {/snippet}
                    </Button>
                {:else}
                    <Button variant="outline" size="sm" disabled>
                        {paginationLabel(
                            link.label,
                            index,
                            entries.links.length - 1,
                        )}
                    </Button>
                {/if}
            {/each}
        </nav>
    {/if}

    <Button variant="outline" asChild>
        {#snippet children(buttonProps)}
            <Link
                {...buttonProps}
                href={show([currentTeam.slug, project.id])}
                class={buttonProps.class}>{t('Back to project')}</Link
            >
        {/snippet}
    </Button>
</div>
