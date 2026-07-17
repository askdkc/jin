<script module lang="ts">
    import { index, show } from '@/routes/projects';
    import type { Project, Team } from '@/types';

    export const layout = (props: { currentTeam: Team; project: Project }) => ({
        breadcrumbs: [
            {
                title: 'Projects',
                href: index(props.currentTeam.slug),
            },
            {
                title: props.project.name,
                href: show([props.currentTeam.slug, props.project.id]),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import Archive from 'lucide-svelte/icons/archive';
    import ArrowRight from 'lucide-svelte/icons/arrow-right';
    import BookOpen from 'lucide-svelte/icons/book-open';
    import CheckCircle2 from 'lucide-svelte/icons/circle-check-big';
    import Clock3 from 'lucide-svelte/icons/clock-3';
    import FileText from 'lucide-svelte/icons/file-text';
    import Link2 from 'lucide-svelte/icons/link-2';
    import MessageCircleQuestion from 'lucide-svelte/icons/message-circle-question';
    import Paperclip from 'lucide-svelte/icons/paperclip';
    import Pencil from 'lucide-svelte/icons/pencil';
    import Settings from 'lucide-svelte/icons/settings';
    import Star from 'lucide-svelte/icons/star';
    import Users from 'lucide-svelte/icons/users';
    import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
    import CardTableController from '@/actions/App/Http/Controllers/CardTableController';
    import ChatController from '@/actions/App/Http/Controllers/ChatController';
    import CheckInController from '@/actions/App/Http/Controllers/CheckInController';
    import MessageBoardController from '@/actions/App/Http/Controllers/MessageBoardController';
    import ProjectFavoriteController from '@/actions/App/Http/Controllers/ProjectFavoriteController';
    import ProjectMaterialController from '@/actions/App/Http/Controllers/ProjectMaterialController';
    import ProjectTimeEntryController from '@/actions/App/Http/Controllers/ProjectTimeEntryController';
    import ProjectTodoController from '@/actions/App/Http/Controllers/ProjectTodoController';
    import ScheduleEventController from '@/actions/App/Http/Controllers/ScheduleEventController';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { activityAction, activityHref } from '@/lib/activity';
    import { t } from '@/lib/i18n';
    import { archive, edit } from '@/routes/projects';
    import type { ProjectHome, ProjectPermissions } from '@/types';

    let {
        currentTeam,
        project,
        home,
        permissions,
    }: {
        currentTeam: Team;
        project: Project;
        home: ProjectHome;
        permissions: ProjectPermissions;
    } = $props();

    const dateFormatter = new Intl.DateTimeFormat('ja-JP', {
        timeZone: 'Asia/Tokyo',
        month: 'numeric',
        day: 'numeric',
    });
    const dateTimeFormatter = new Intl.DateTimeFormat('ja-JP', {
        timeZone: 'Asia/Tokyo',
        month: 'numeric',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
    const monthFormatter = new Intl.DateTimeFormat('ja-JP', {
        timeZone: 'Asia/Tokyo',
        year: 'numeric',
        month: 'long',
    });
    let calendarAnchor = $derived(
        new Date(
            home.schedule.upcoming[0]?.startsAt ??
                project.startsOn ??
                Date.now(),
        ),
    );
    let calendarYear = $derived(calendarAnchor.getFullYear());
    let calendarMonth = $derived(calendarAnchor.getMonth());
    let calendarDays = $derived.by(() => {
        const firstCalendarWeekday = new Date(
            calendarYear,
            calendarMonth,
            1,
        ).getDay();
        const daysInCalendarMonth = new Date(
            calendarYear,
            calendarMonth + 1,
            0,
        ).getDate();

        return Array.from({ length: 42 }, (_, index) => {
            const day = index - firstCalendarWeekday + 1;

            return day > 0 && day <= daysInCalendarMonth ? day : null;
        });
    });
    let eventDays = $derived.by(
        () =>
            new Set(
                home.schedule.upcoming
                    .filter((event) => {
                        const date = new Date(event.startsAt);

                        return (
                            date.getFullYear() === calendarYear &&
                            date.getMonth() === calendarMonth
                        );
                    })
                    .map((event) => new Date(event.startsAt).getDate()),
            ),
    );
    const cardColumnClasses = [
        'border-t-violet-400 bg-violet-50 dark:bg-violet-950/30',
        'border-t-orange-400 bg-orange-50 dark:bg-orange-950/30',
        'border-t-sky-400 bg-sky-50 dark:bg-sky-950/30',
        'border-t-pink-400 bg-pink-50 dark:bg-pink-950/30',
        'border-t-emerald-400 bg-emerald-50 dark:bg-emerald-950/30',
    ];
    const formatDate = (value: string | null, includeTime = false): string => {
        if (!value) {
            return '';
        }

        return (includeTime ? dateTimeFormatter : dateFormatter).format(
            new Date(value),
        );
    };

    const memberInitial = (name: string): string =>
        name.trim().slice(0, 1).toLocaleUpperCase('ja-JP') || '?';

    const archiveProject = (): boolean =>
        window.confirm(t('Are you sure you want to archive this project?'));
</script>

<AppHead title={`${currentTeam.menuNames.projectHome} — ${project.name}`} />

<div
    class="min-h-full bg-[#f5f7f3] px-0 pb-8 dark:bg-[#0d151b] sm:px-5 sm:pt-2 lg:px-6"
>
    <main
        class="mx-auto min-h-full w-full max-w-[1088px] overflow-hidden bg-white text-base [--text-2xl:1.25rem] [--text-3xl:1.5rem] [--text-4xl:2.5rem] [--text-base:0.875rem] [--text-lg:1rem] [--text-sm:0.75rem] [--text-xl:1.125rem] [--text-xs:0.6875rem] shadow-[0_18px_55px_rgba(30,55,80,0.08)] dark:bg-card dark:[--border:#495257] dark:[--card:#1e292f]"
        data-test="project-home-board"
    >
        <div
            class="flex min-h-10 items-center justify-between gap-4 border-b px-5 sm:px-8"
        >
            <div
                class="flex min-w-0 items-center"
                aria-label={t('Project members')}
            >
                <span
                    class="mr-2 flex size-8 shrink-0 items-center justify-center rounded-full border bg-background text-muted-foreground"
                    title={t('Project members')}
                >
                    <Users class="size-4" />
                </span>
                <div class="flex -space-x-2 overflow-hidden py-1">
                    {#each project.members.slice(0, 8) as member, index (member.id)}
                        <span
                            class="flex size-7 shrink-0 items-center justify-center rounded-full border-2 border-white bg-primary text-xs font-bold text-primary-foreground shadow-sm dark:border-card"
                            class:bg-amber-500={index % 5 === 1}
                            class:bg-emerald-600={index % 5 === 2}
                            class:bg-sky-600={index % 5 === 3}
                            class:bg-fuchsia-600={index % 5 === 4}
                            title={member.name}
                        >
                            {memberInitial(member.name)}
                        </span>
                    {/each}
                    {#if project.members.length > 8}
                        <span
                            class="flex size-7 shrink-0 items-center justify-center rounded-full border-2 border-white bg-muted text-xs font-semibold text-muted-foreground dark:border-card"
                        >
                            +{project.members.length - 8}
                        </span>
                    {/if}
                    {#if project.members.length === 0}
                        <span
                            class="self-center pl-2 text-base text-muted-foreground"
                        >
                            {t('No members assigned')}
                        </span>
                    {/if}
                </div>
            </div>

            {#if permissions.canUpdateProject || permissions.canArchiveProject}
                <div class="flex shrink-0 items-center gap-1">
                    {#if permissions.canUpdateProject}
                        <Button variant="ghost" size="icon" asChild>
                            {#snippet children(buttonProps)}
                                <Link
                                    {...buttonProps}
                                    href={edit([currentTeam.slug, project.id])}
                                    class={buttonProps.class}
                                    aria-label={t('Edit')}
                                >
                                    <Pencil class="size-4" />
                                </Link>
                            {/snippet}
                        </Button>
                    {/if}
                    {#if permissions.canArchiveProject}
                        <Form
                            {...archive.form([currentTeam.slug, project.id])}
                            onBefore={archiveProject}
                        >
                            {#snippet children({ processing })}
                                <Button
                                    type="submit"
                                    variant="ghost"
                                    size="icon"
                                    disabled={processing}
                                    aria-label={t('Archive')}
                                >
                                    <Archive class="size-4" />
                                </Button>
                            {/snippet}
                        </Form>
                    {/if}
                </div>
            {/if}
        </div>

        <div class="px-5 py-4 sm:px-9 lg:px-10">
            <header class="mb-7 leading-tight">
                <p
                    class="text-sm font-bold tracking-[0.18em] text-[#ad382a] dark:text-[#ff8c7c]"
                >
                    {currentTeam.menuNames.projectHome}
                </p>
                <div class="mt-1 flex flex-wrap items-center gap-3">
                    <h1
                        class="break-words text-3xl font-black tracking-[-0.04em] text-[#27343c] dark:text-foreground sm:text-4xl"
                    >
                        {project.name}
                    </h1>
                    <Form
                        {...ProjectFavoriteController.form([
                            currentTeam.slug,
                            project.id,
                        ])}
                    >
                        {#snippet children({ processing })}
                            <Button
                                type="submit"
                                variant="ghost"
                                size="icon"
                                disabled={processing}
                                aria-label={project.isFavorite
                                    ? t('Remove from favorites')
                                    : t('Add to favorites')}
                            >
                                <Star
                                    class={`size-7 ${project.isFavorite ? 'fill-amber-400 text-amber-500' : ''}`}
                                />
                            </Button>
                        {/snippet}
                    </Form>
                </div>
                {#if project.description}
                    <p
                        class="mt-2 max-w-3xl whitespace-pre-wrap text-lg leading-5 text-muted-foreground"
                    >
                        {project.description}
                    </p>
                {/if}
                {#if project.startsOn || project.endsOn}
                    <p class="mt-2 text-sm font-medium text-muted-foreground">
                        {project.startsOn || t('Not set')} – {project.endsOn ||
                            t('Not set')}
                    </p>
                {/if}
                {#if home.activity.latest.length > 0}
                    <ol
                        class="mt-3 max-w-2xl space-y-1"
                        data-test="project-recent-activity"
                    >
                        {#each home.activity.latest.slice(0, 3) as activity (activity.id)}
                            <li
                                class="flex min-w-0 items-center gap-2 text-base"
                            >
                                <span
                                    class="flex size-4 shrink-0 items-center justify-center rounded-full bg-violet-500 text-[8px] font-bold text-white"
                                >
                                    {memberInitial(
                                        activity.actorName || t('Deleted user'),
                                    )}
                                </span>
                                <time
                                    datetime={activity.createdAt || ''}
                                    class="shrink-0 text-sm font-bold text-slate-600 dark:text-slate-300"
                                >
                                    {formatDate(activity.createdAt)}
                                </time>
                                <Link
                                    href={activityHref(
                                        currentTeam.slug,
                                        activity,
                                    )}
                                    class="min-w-0 truncate text-slate-700 hover:text-blue-600 dark:text-slate-200 dark:hover:text-blue-400"
                                >
                                    <strong class="font-semibold">
                                        {activity.actorName ||
                                            t('Deleted user')}
                                    </strong>
                                    {activityAction(activity.type)}
                                    <span
                                        class="font-medium text-blue-600 underline underline-offset-2 dark:text-blue-400"
                                    >
                                        {activity.subjectLabel}
                                    </span>
                                </Link>
                            </li>
                        {/each}
                    </ol>
                    <div
                        class="mt-2 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground"
                    >
                        <span class="size-2 rounded-full bg-[#d8c6af]"></span>
                        <span>
                            {t(':count people active in the last 7 days', {
                                count: String(
                                    home.activity.activeLastSevenDays,
                                ),
                            })}
                        </span>
                        <span aria-hidden="true">·</span>
                        <Link
                            href={ActivityController(currentTeam.slug, {
                                query: { project: project.id },
                            })}
                            class="font-medium text-blue-600 underline underline-offset-2 dark:text-blue-400"
                        >
                            {t('View all activity')}
                        </Link>
                    </div>
                {/if}
            </header>

            <div class="grid gap-x-4 gap-y-6 md:grid-cols-2 lg:grid-cols-3">
                {#if project.enabledTools.includes('messages')}
                    <section
                        class="min-w-0 lg:order-1"
                        data-test="project-tool-messages"
                    >
                        <h2
                            class="mb-2 text-xl font-bold text-[#ad382a] dark:text-[#ff8c7c]"
                        >
                            {t('Message board')}
                        </h2>
                        <Link
                            href={MessageBoardController.index([
                                currentTeam.slug,
                                project.id,
                            ])}
                            class="group block h-[20rem] overflow-hidden rounded-lg border border-border bg-white p-4 shadow-[0_1px_2px_rgba(30,45,55,0.04)] transition hover:-translate-y-0.5 hover:border-muted-foreground/60 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring dark:bg-card"
                        >
                            {#if home.messages.latest.length > 0}
                                <div class="flex h-full flex-col">
                                    {#each home.messages.latest as message, index (message.id)}
                                        {#if index === 0}
                                            <article class="border-b pb-4">
                                                <div
                                                    class="flex items-start gap-3"
                                                >
                                                    <span
                                                        class="flex size-8 shrink-0 items-center justify-center rounded-full bg-[#26343d] text-sm font-bold text-white"
                                                    >
                                                        {memberInitial(
                                                            message.authorName ||
                                                                '?',
                                                        )}
                                                    </span>
                                                    <div class="min-w-0">
                                                        <p
                                                            class="truncate text-base font-semibold"
                                                        >
                                                            {message.authorName ||
                                                                t(
                                                                    'Deleted user',
                                                                )}
                                                        </p>
                                                        <time
                                                            class="text-sm text-muted-foreground"
                                                        >
                                                            {formatDate(
                                                                message.publishedAt,
                                                                true,
                                                            )}
                                                        </time>
                                                    </div>
                                                </div>
                                                <h3
                                                    class="mt-3 line-clamp-2 text-base font-bold"
                                                >
                                                    {message.title}
                                                </h3>
                                                <p
                                                    class="mt-1 line-clamp-3 whitespace-pre-wrap text-base leading-5 text-muted-foreground"
                                                >
                                                    {message.body}
                                                </p>
                                            </article>
                                        {:else}
                                            <article
                                                class="flex min-w-0 items-center gap-3 border-b py-3 last:border-0"
                                            >
                                                <span
                                                    class="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-bold"
                                                >
                                                    {memberInitial(
                                                        message.authorName ||
                                                            '?',
                                                    )}
                                                </span>
                                                <div class="min-w-0">
                                                    <h3
                                                        class="truncate text-base font-semibold"
                                                    >
                                                        {message.title}
                                                    </h3>
                                                    <p
                                                        class="truncate text-sm text-muted-foreground"
                                                    >
                                                        {message.authorName ||
                                                            t('Deleted user')}
                                                    </p>
                                                </div>
                                            </article>
                                        {/if}
                                    {/each}
                                    <span
                                        class="mt-auto flex items-center justify-end gap-1 pt-3 text-sm font-semibold text-muted-foreground group-hover:text-foreground"
                                    >
                                        {t(':count messages', {
                                            count: String(home.messages.count),
                                        })}
                                        <ArrowRight class="size-3.5" />
                                    </span>
                                </div>
                            {:else}
                                <div
                                    class="flex h-full flex-col items-center justify-center text-center text-muted-foreground"
                                >
                                    <FileText class="mb-3 size-9" />
                                    <p class="text-base">
                                        {t('No messages yet')}
                                    </p>
                                </div>
                            {/if}
                        </Link>
                    </section>
                {/if}

                {#if project.enabledTools.includes('materials')}
                    <section
                        class="min-w-0 lg:order-3"
                        data-test="project-tool-materials"
                    >
                        <h2
                            class="mb-2 text-xl font-bold text-[#ad382a] dark:text-[#ff8c7c]"
                        >
                            {t('Materials')}
                        </h2>
                        <Link
                            href={ProjectMaterialController.index([
                                currentTeam.slug,
                                project.id,
                            ])}
                            class="group block h-[20rem] overflow-hidden rounded-lg border border-border bg-white p-4 shadow-[0_1px_2px_rgba(30,45,55,0.04)] transition hover:-translate-y-0.5 hover:border-muted-foreground/60 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring dark:bg-card"
                        >
                            {#if home.materials.documents.length > 0 || home.materials.files.length > 0 || home.materials.links.length > 0}
                                <ul class="space-y-3">
                                    {#each home.materials.documents.slice(0, 3) as document (document.id)}
                                        <li
                                            class="flex min-w-0 items-center gap-3"
                                        >
                                            <span
                                                class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-emerald-500 text-white"
                                            >
                                                <BookOpen class="size-4" />
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span
                                                    class="block truncate text-base font-bold"
                                                    >{document.title}</span
                                                >
                                                <span
                                                    class="block text-sm text-muted-foreground"
                                                    >{formatDate(
                                                        document.updatedAt,
                                                    )}</span
                                                >
                                            </span>
                                        </li>
                                    {/each}
                                    {#each home.materials.files.slice(0, 3) as file (file.id)}
                                        <li
                                            class="flex min-w-0 items-center gap-3"
                                        >
                                            <span
                                                class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-blue-500 text-white"
                                            >
                                                <Paperclip class="size-4" />
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span
                                                    class="block truncate text-base font-bold"
                                                    >{file.name}</span
                                                >
                                                <span
                                                    class="block text-sm text-muted-foreground"
                                                    >{formatDate(
                                                        file.createdAt,
                                                    )}</span
                                                >
                                            </span>
                                        </li>
                                    {/each}
                                    {#each home.materials.links.slice(0, 2) as link (link.id)}
                                        <li
                                            class="flex min-w-0 items-center gap-3"
                                        >
                                            <span
                                                class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-orange-500 text-white"
                                            >
                                                <Link2 class="size-4" />
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span
                                                    class="block truncate text-base font-bold"
                                                    >{link.title}</span
                                                >
                                                <span
                                                    class="block text-sm text-muted-foreground"
                                                    >{formatDate(
                                                        link.updatedAt,
                                                    )}</span
                                                >
                                            </span>
                                        </li>
                                    {/each}
                                </ul>
                            {:else}
                                <div
                                    class="flex h-full flex-col items-center justify-center text-center text-muted-foreground"
                                >
                                    <BookOpen class="mb-3 size-9" />
                                    <p class="text-base">
                                        {t('No documents or files yet')}
                                    </p>
                                </div>
                            {/if}
                        </Link>
                    </section>
                {/if}

                {#if project.enabledTools.includes('todos')}
                    <section
                        class="min-w-0 lg:order-2"
                        data-test="project-tool-todos"
                    >
                        <h2
                            class="mb-2 text-xl font-bold text-[#ad382a] dark:text-[#ff8c7c]"
                        >
                            {currentTeam.menuNames.todos}
                        </h2>
                        <Link
                            href={ProjectTodoController.index([
                                currentTeam.slug,
                                project.id,
                            ])}
                            class="group block h-[20rem] overflow-hidden rounded-lg border border-border bg-white p-4 shadow-[0_1px_2px_rgba(30,45,55,0.04)] transition hover:-translate-y-0.5 hover:border-muted-foreground/60 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring dark:bg-card"
                        >
                            <div class="flex h-full flex-col">
                                <div class="mb-5 flex items-center gap-3">
                                    <div
                                        class="size-3 rounded-full bg-cyan-500"
                                    ></div>
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="flex items-center justify-between gap-3 text-base font-bold"
                                        >
                                            <span
                                                >{t(
                                                    ':completed of :total complete',
                                                    {
                                                        completed: String(
                                                            home.todos
                                                                .completed,
                                                        ),
                                                        total: String(
                                                            home.todos.total,
                                                        ),
                                                    },
                                                )}</span
                                            >
                                            {#if home.todos.overdue > 0}
                                                <span
                                                    class="shrink-0 text-sm text-destructive"
                                                    >{t(':count overdue', {
                                                        count: String(
                                                            home.todos.overdue,
                                                        ),
                                                    })}</span
                                                >
                                            {/if}
                                        </div>
                                        <progress
                                            class="mt-2 h-2 w-full accent-cyan-600"
                                            value={home.todos.completed}
                                            max={Math.max(home.todos.total, 1)}
                                            aria-label={`${currentTeam.menuNames.todos}の進捗`}
                                        ></progress>
                                    </div>
                                </div>
                                {#if home.todos.latest.length > 0}
                                    <ul class="space-y-3">
                                        {#each home.todos.latest as todo (todo.id)}
                                            <li
                                                class="flex min-w-0 items-start gap-3"
                                            >
                                                <span
                                                    class="mt-0.5 size-5 shrink-0 rounded border-2 border-[#a8b0b4]"
                                                ></span>
                                                <span class="min-w-0">
                                                    <span
                                                        class="block truncate text-base font-semibold"
                                                        >{todo.title}</span
                                                    >
                                                    <span
                                                        class="block truncate text-sm text-muted-foreground"
                                                    >
                                                        {todo.listTitle}{todo.dueOn
                                                            ? ` · ${formatDate(todo.dueOn)}`
                                                            : ''}
                                                    </span>
                                                </span>
                                            </li>
                                        {/each}
                                    </ul>
                                {:else}
                                    <div
                                        class="flex flex-1 flex-col items-center justify-center text-center text-muted-foreground"
                                    >
                                        <CheckCircle2 class="mb-3 size-9" />
                                        <p class="text-base">
                                            {t('No to-dos yet')}
                                        </p>
                                    </div>
                                {/if}
                            </div>
                        </Link>
                    </section>
                {/if}

                {#if project.enabledTools.includes('chat')}
                    <section
                        class="min-w-0 lg:order-4"
                        data-test="project-tool-chat"
                    >
                        <h2
                            class="mb-2 text-xl font-bold text-[#ad382a] dark:text-[#ff8c7c]"
                        >
                            {currentTeam.menuNames.chat}
                        </h2>
                        <Link
                            href={ChatController.show([
                                currentTeam.slug,
                                project.id,
                            ])}
                            class="group block h-[20rem] overflow-hidden rounded-lg border border-border bg-white p-4 shadow-[0_1px_2px_rgba(30,45,55,0.04)] transition hover:-translate-y-0.5 hover:border-muted-foreground/60 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring dark:bg-card"
                        >
                            {#if home.chat.recent.length > 0}
                                <ul class="space-y-3">
                                    {#each home.chat.recent as message (message.id)}
                                        <li
                                            class="flex min-w-0 items-start gap-3"
                                        >
                                            <span
                                                class="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-bold"
                                            >
                                                {memberInitial(
                                                    message.authorName || '?',
                                                )}
                                            </span>
                                            <div
                                                class="min-w-0 flex-1 rounded-xl bg-[#f5f2ef] px-3 py-2 dark:bg-muted"
                                            >
                                                <p
                                                    class="truncate text-sm font-bold"
                                                >
                                                    {message.authorName ||
                                                        t('Deleted user')}
                                                    <time
                                                        class="ml-1 font-normal text-muted-foreground"
                                                        >{formatDate(
                                                            message.createdAt,
                                                            true,
                                                        )}</time
                                                    >
                                                </p>
                                                <p
                                                    class="mt-0.5 line-clamp-2 whitespace-pre-wrap text-base leading-5"
                                                >
                                                    {message.body}
                                                </p>
                                            </div>
                                        </li>
                                    {/each}
                                </ul>
                            {:else}
                                <div
                                    class="flex h-full flex-col items-center justify-center text-center text-muted-foreground"
                                >
                                    <MessageCircleQuestion
                                        class="mb-3 size-9"
                                    />
                                    <p class="text-base">
                                        {t('No chat messages yet')}
                                    </p>
                                </div>
                            {/if}
                        </Link>
                    </section>
                {/if}

                {#if project.enabledTools.includes('schedule')}
                    <section
                        class="min-w-0 lg:order-5"
                        data-test="project-tool-schedule"
                    >
                        <h2
                            class="mb-2 text-xl font-bold text-[#ad382a] dark:text-[#ff8c7c]"
                        >
                            {currentTeam.menuNames.calendar}
                        </h2>
                        <Link
                            href={ScheduleEventController.index([
                                currentTeam.slug,
                                project.id,
                            ])}
                            class="group block h-[20rem] overflow-hidden rounded-lg border border-border bg-white p-4 shadow-[0_1px_2px_rgba(30,45,55,0.04)] transition hover:-translate-y-0.5 hover:border-muted-foreground/60 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring dark:bg-card"
                        >
                            <h3 class="mb-3 text-center text-base font-black">
                                {monthFormatter.format(calendarAnchor)}
                            </h3>
                            <div
                                class="grid grid-cols-7 border-l border-t text-center text-xs font-bold text-muted-foreground"
                            >
                                {#each ['日', '月', '火', '水', '木', '金', '土'] as weekday (weekday)}
                                    <div class="border-b border-r py-1.5">
                                        {weekday}
                                    </div>
                                {/each}
                                {#each calendarDays as day, index (index)}
                                    <div
                                        class="relative flex h-8 items-center justify-center border-b border-r text-sm"
                                        class:bg-amber-50={day !== null &&
                                            eventDays.has(day)}
                                    >
                                        {day ?? ''}
                                        {#if day !== null && eventDays.has(day)}
                                            <span
                                                class="absolute bottom-1 size-1 rounded-full bg-blue-500"
                                            ></span>
                                        {/if}
                                    </div>
                                {/each}
                            </div>
                            {#if home.schedule.upcoming.length > 0}
                                <ul class="mt-3 space-y-2">
                                    {#each home.schedule.upcoming.slice(0, 2) as event (event.id)}
                                        <li
                                            class="flex min-w-0 items-center gap-2"
                                        >
                                            <span
                                                class="flex size-9 shrink-0 flex-col items-center justify-center rounded-md bg-blue-600 text-xs font-bold leading-none text-white"
                                            >
                                                {formatDate(event.startsAt)}
                                            </span>
                                            <span
                                                class="truncate text-base font-semibold"
                                                >{event.title}</span
                                            >
                                        </li>
                                    {/each}
                                </ul>
                            {:else}
                                <p
                                    class="mt-4 text-center text-base text-muted-foreground"
                                >
                                    {t('No upcoming events')}
                                </p>
                            {/if}
                        </Link>
                    </section>
                {/if}

                {#if project.enabledTools.includes('card_tables')}
                    <section
                        class="min-w-0 lg:order-8"
                        data-test="project-tool-card-table"
                    >
                        <h2
                            class="mb-2 text-xl font-bold text-[#ad382a] dark:text-[#ff8c7c]"
                        >
                            {currentTeam.menuNames.cardTable}
                        </h2>
                        <Link
                            href={CardTableController.index([
                                currentTeam.slug,
                                project.id,
                            ])}
                            class="group block h-[20rem] overflow-hidden rounded-lg border border-border bg-white p-4 shadow-[0_1px_2px_rgba(30,45,55,0.04)] transition hover:-translate-y-0.5 hover:border-muted-foreground/60 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring dark:bg-card"
                        >
                            {#if home.cardTables.preview && home.cardTables.preview.columns.length > 0}
                                <div class="flex h-full flex-col">
                                    <div
                                        class="mb-4 flex items-center justify-between gap-3"
                                    >
                                        <h3
                                            class="truncate text-base font-black uppercase tracking-wide"
                                        >
                                            {home.cardTables.preview.name}
                                        </h3>
                                        <span
                                            class="text-sm text-muted-foreground"
                                        >
                                            {home.cardTables.count}
                                        </span>
                                    </div>
                                    <div
                                        class="flex min-h-0 flex-1 overflow-hidden rounded-xl border"
                                    >
                                        {#each home.cardTables.preview.columns as column, index (column.id)}
                                            <div
                                                class={`flex min-w-0 flex-1 flex-col items-center border-r border-t-4 px-1 py-3 last:border-r-0 ${cardColumnClasses[index % cardColumnClasses.length]}`}
                                            >
                                                <span
                                                    class="text-sm text-muted-foreground"
                                                    >({column.cardCount})</span
                                                >
                                                <span
                                                    class="mt-2 text-sm font-black uppercase tracking-wider [writing-mode:vertical-rl]"
                                                >
                                                    {column.name}
                                                </span>
                                            </div>
                                        {/each}
                                    </div>
                                </div>
                            {:else}
                                <div
                                    class="flex h-full flex-col items-center justify-center text-center text-muted-foreground"
                                >
                                    <FileText class="mb-3 size-9" />
                                    <p class="text-base">
                                        {t('No card tables yet')}
                                    </p>
                                </div>
                            {/if}
                        </Link>
                    </section>
                {/if}

                {#if project.enabledTools.includes('check_ins')}
                    <section
                        class="min-w-0 lg:order-6"
                        data-test="project-tool-check-ins"
                    >
                        <h2
                            class="mb-2 text-xl font-bold text-[#ad382a] dark:text-[#ff8c7c]"
                        >
                            {currentTeam.menuNames.checkIns}
                        </h2>
                        <Link
                            href={CheckInController.index([
                                currentTeam.slug,
                                project.id,
                            ])}
                            class="group flex h-[20rem] flex-col overflow-hidden rounded-lg border border-border bg-white p-4 shadow-[0_1px_2px_rgba(30,45,55,0.04)] transition hover:-translate-y-0.5 hover:border-muted-foreground/60 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring dark:bg-card"
                        >
                            <div
                                class="rounded-lg border bg-[#fbfaf8] px-4 py-3 text-base font-bold dark:bg-muted"
                            >
                                {currentTeam.menuNames.checkIns}
                            </div>
                            <div
                                class="flex flex-1 items-center justify-center gap-3"
                            >
                                <span
                                    class="flex size-11 items-center justify-center rounded-full bg-[#26343d] text-lg font-black text-white"
                                >
                                    Q
                                </span>
                                <div
                                    class="relative max-w-[13rem] rounded-xl border bg-[#fffaf5] p-4 text-base shadow-sm before:absolute before:top-4 before:-left-2 before:size-4 before:rotate-45 before:border-b before:border-l before:bg-[#fffaf5] dark:bg-muted dark:before:bg-muted"
                                >
                                    <p class="font-bold">
                                        {t(':count check-ins', {
                                            count: String(home.checkIns.count),
                                        })}
                                    </p>
                                    <p class="mt-1 text-muted-foreground">
                                        {t(':count awaiting your answer', {
                                            count: String(
                                                home.checkIns.pending,
                                            ),
                                        })}
                                    </p>
                                </div>
                            </div>
                            <span
                                class="flex items-center justify-end gap-1 text-sm font-semibold text-muted-foreground group-hover:text-foreground"
                            >
                                {t('Open check-ins')}
                                <ArrowRight class="size-3.5" />
                            </span>
                        </Link>
                    </section>
                {/if}

                {#if project.enabledTools.includes('timesheets')}
                    <section
                        class="min-w-0 lg:order-7"
                        data-test="project-tool-timesheets"
                    >
                        <h2
                            class="mb-2 text-xl font-bold text-[#ad382a] dark:text-[#ff8c7c]"
                        >
                            {currentTeam.menuNames.timesheets}
                        </h2>
                        <Link
                            href={ProjectTimeEntryController.index([
                                currentTeam.slug,
                                project.id,
                            ])}
                            class="group flex h-[20rem] flex-col overflow-hidden rounded-lg border border-border bg-white p-4 shadow-[0_1px_2px_rgba(30,45,55,0.04)] transition hover:-translate-y-0.5 hover:border-muted-foreground/60 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring dark:bg-card"
                        >
                            <div class="flex items-center gap-3">
                                <span
                                    class="rounded-xl bg-emerald-100 p-3 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
                                >
                                    <Clock3 class="size-6" />
                                </span>
                                <div>
                                    <p class="text-2xl font-black">
                                        {Math.floor(
                                            home.timesheets.totalMinutes / 60,
                                        )}h {home.timesheets.totalMinutes % 60}m
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {t('Total time logged')}
                                    </p>
                                </div>
                            </div>
                            <ul class="mt-6 space-y-3">
                                {#each home.timesheets.latest as entry (entry.id)}
                                    <li class="rounded-xl bg-muted/60 p-3">
                                        <p class="text-base font-semibold">
                                            {entry.userName} · {Math.floor(
                                                entry.minutes / 60,
                                            )}h {entry.minutes % 60}m
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            {entry.workedOn}
                                        </p>
                                    </li>
                                {/each}
                            </ul>
                            <span
                                class="mt-auto flex items-center justify-end gap-1 text-sm font-semibold text-muted-foreground group-hover:text-foreground"
                            >
                                {t('Open timesheets')}
                                <ArrowRight class="size-3.5" />
                            </span>
                        </Link>
                    </section>
                {/if}
            </div>

            {#if permissions.canUpdateProject}
                <div class="mt-9 flex justify-end border-t pt-6">
                    <Button variant="outline" size="lg" asChild>
                        {#snippet children(buttonProps)}
                            <Link
                                {...buttonProps}
                                href={edit([currentTeam.slug, project.id])}
                                class={`${buttonProps.class} w-full rounded-xl bg-white font-bold shadow-sm sm:w-auto dark:bg-card`}
                                data-test="project-tools-edit"
                            >
                                <Settings class="size-5" />
                                {t('Project settings')}
                            </Link>
                        {/snippet}
                    </Button>
                </div>
            {/if}
        </div>
    </main>
</div>
