<script module lang="ts">
    import { dashboard } from '@/routes';
    import type { Team } from '@/types';

    export const layout = (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                translationKey: 'Dashboard',
                href: props.currentTeam
                    ? dashboard(props.currentTeam.slug)
                    : '/',
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link, page } from '@inertiajs/svelte';
    import ArrowRight from 'lucide-svelte/icons/arrow-right';
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import ClipboardCheck from 'lucide-svelte/icons/clipboard-check';
    import FolderKanban from 'lucide-svelte/icons/folder-kanban';
    import Grid2X2 from 'lucide-svelte/icons/grid-2x2';
    import History from 'lucide-svelte/icons/history';
    import KeyRound from 'lucide-svelte/icons/key-round';
    import List from 'lucide-svelte/icons/list';
    import PanelRightClose from 'lucide-svelte/icons/panel-right-close';
    import PanelRightOpen from 'lucide-svelte/icons/panel-right-open';
    import Plus from 'lucide-svelte/icons/plus';
    import Star from 'lucide-svelte/icons/star';
    import UserPlus from 'lucide-svelte/icons/user-plus';
    import { onMount } from 'svelte';
    import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
    import AdminlandController from '@/actions/App/Http/Controllers/AdminlandController';
    import ProjectFavoriteController from '@/actions/App/Http/Controllers/ProjectFavoriteController';
    import ProjectTodoController from '@/actions/App/Http/Controllers/ProjectTodoController';
    import AppHead from '@/components/AppHead.svelte';
    import InviteMemberModal from '@/components/InviteMemberModal.svelte';
    import PendingInvitationsModal from '@/components/PendingInvitationsModal.svelte';
    import { Avatar, AvatarFallback } from '@/components/ui/avatar';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { activityAction, activityHref } from '@/lib/activity';
    import { dashboardActivityPanel } from '@/lib/dashboard-activity-panel.svelte';
    import { currentLocale, t } from '@/lib/i18n';
    import { getInitials } from '@/lib/initials';
    import { cn } from '@/lib/utils';
    import { create, show } from '@/routes/projects';
    import type {
        ActivityItem,
        DashboardInvitation,
        DashboardMyWork,
        DashboardProject,
        DashboardTodo,
        ProjectFolder,
        RoleOption,
    } from '@/types';

    type WorkGroup = {
        key: string;
        title: string;
        description: string;
        todos: DashboardTodo[];
        overdue?: boolean;
    };

    let {
        currentTeam,
        pendingInvitations,
        myWork,
        myProjects,
        projectFolders,
        recentActivities,
        availableRoles,
        permissions,
    }: {
        currentTeam: Team;
        pendingInvitations: DashboardInvitation[];
        myWork: DashboardMyWork;
        myProjects: DashboardProject[];
        projectFolders: ProjectFolder[];
        recentActivities: ActivityItem[];
        availableRoles: RoleOption[];
        permissions: {
            canCreateProject: boolean;
            canInviteMembers: boolean;
            canManageTeam: boolean;
        };
    } = $props();

    let inviteDialogOpen = $state(false);

    const workGroups = $derived<WorkGroup[]>([
        {
            key: 'overdue',
            title: t('Overdue'),
            description: t('Needs attention'),
            todos: myWork.overdue,
            overdue: true,
        },
        {
            key: 'today',
            title: t('Today'),
            description: t('Due today'),
            todos: myWork.today,
        },
        {
            key: 'this-week',
            title: t('This week'),
            description: t('Due by Sunday'),
            todos: myWork.thisWeek,
        },
        {
            key: 'later',
            title: t('Later'),
            description: t('Future or no due date'),
            todos: myWork.later,
        },
    ]);
    const totalTasks = $derived(
        workGroups.reduce((total, group) => total + group.todos.length, 0),
    );
    const projectGroups = $derived.by(() => {
        const groups = projectFolders.map((folder) => ({
            id: folder.id as number | null,
            name: folder.name,
            projects: myProjects.filter(
                (project) => project.folder?.id === folder.id,
            ),
        }));
        const unfiled = myProjects.filter((project) => !project.folder);

        if (unfiled.length > 0) {
            groups.push({
                id: null,
                name: t('Other projects'),
                projects: unfiled,
            });
        }

        return groups.filter((group) => group.projects.length > 0);
    });
    const dateFormatter = new Intl.DateTimeFormat(currentLocale(), {
        timeZone: 'Asia/Tokyo',
        month: 'numeric',
        day: 'numeric',
    });
    const activityDateFormatter = new Intl.DateTimeFormat(currentLocale(), {
        timeZone: 'Asia/Tokyo',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

    const formatDate = (value: string): string =>
        dateFormatter.format(new Date(`${value}T00:00:00+09:00`));
    const formatActivityDate = (value: string | null): string =>
        value ? activityDateFormatter.format(new Date(value)) : '';
    let projectView = $state<'grid' | 'list'>('list');

    onMount(() => {
        dashboardActivityPanel.hide();
        projectView =
            window.localStorage.getItem('jin:dashboard-project-view') === 'grid'
                ? 'grid'
                : 'list';
    });

    const setProjectView = (view: 'grid' | 'list'): void => {
        projectView = view;
        window.localStorage.setItem('jin:dashboard-project-view', view);
    };
</script>

<AppHead title={t('Dashboard')} />

{#if pendingInvitations.length > 0}
    <PendingInvitationsModal invitations={pendingInvitations} />
{/if}

{#if permissions.canInviteMembers}
    <InviteMemberModal
        team={currentTeam}
        {availableRoles}
        bind:open={inviteDialogOpen}
    />
{/if}

<div
    class="min-h-[calc(100dvh-6rem)] bg-[#f5f7f3] text-slate-800 dark:bg-[#07151d] dark:text-slate-100"
>
    <main
        class="mx-auto w-full max-w-[96rem] px-4 py-6 sm:px-6 sm:py-8 lg:flex lg:min-h-[calc(100dvh-6rem)] lg:flex-col lg:gap-4 lg:pb-3 xl:pt-10"
    >
        <div
            class="grid min-w-0 gap-6 motion-reduce:transition-none lg:grid-cols-[15rem_minmax(0,1fr)_var(--dashboard-activity-width)] lg:items-start lg:transition-[grid-template-columns] lg:duration-300 lg:ease-in-out xl:gap-8"
            style:--dashboard-activity-width={dashboardActivityPanel.open
                ? 'clamp(13.6rem, 20.8vw, 16.8rem)'
                : '3rem'}
        >
            <aside class="flex flex-col gap-3 lg:sticky lg:top-24 lg:gap-2">
                <h1 class="text-xl font-bold tracking-tight">
                    {t('Welcome, :name', {
                        name: page.props.auth.user?.name ?? '',
                    })}
                </h1>

                <div class="grid gap-2 lg:gap-1.5">
                    {#if permissions.canCreateProject}
                        <Button
                            variant="outline"
                            asChild
                            class="h-auto w-full justify-start rounded-xl bg-card px-3 py-2"
                        >
                            {#snippet children(buttonProps)}
                                <Link
                                    {...buttonProps}
                                    href={create(currentTeam.slug)}
                                    class={buttonProps.class}
                                >
                                    <Plus class="size-4 text-blue-500" />
                                    {t('New project')}
                                </Link>
                            {/snippet}
                        </Button>
                    {/if}

                    {#if permissions.canInviteMembers}
                        <Button
                            type="button"
                            variant="outline"
                            class="h-auto w-full justify-start rounded-xl bg-card px-3 py-2"
                            onclick={() => (inviteDialogOpen = true)}
                            data-test="dashboard-invite-member-button"
                        >
                            <UserPlus data-icon="inline-start" />
                            {t('Invite users')}
                        </Button>
                    {/if}

                    {#if permissions.canManageTeam}
                        <Button
                            variant="outline"
                            asChild
                            class="h-auto w-full justify-start rounded-xl bg-card px-3 py-2"
                        >
                            {#snippet children(buttonProps)}
                                <Link
                                    {...buttonProps}
                                    href={AdminlandController(currentTeam.slug)}
                                    class={buttonProps.class}
                                >
                                    <KeyRound class="size-4 text-blue-500" />
                                    {currentTeam.menuNames.adminland}
                                </Link>
                            {/snippet}
                        </Button>
                    {/if}
                </div>
            </aside>

            <section
                class="min-w-0 rounded-[1.75rem] border border-slate-200/80 bg-white/70 p-3 shadow-sm backdrop-blur sm:p-4 dark:border-slate-700/80 dark:bg-[#101e26]"
                aria-labelledby="dashboard-projects-heading"
            >
                <div
                    class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div>
                        <p
                            class="text-xs font-semibold uppercase tracking-[0.18em] text-muted-foreground"
                        >
                            {t('Dashboard')}
                        </p>
                        <h2
                            id="dashboard-projects-heading"
                            class="mt-1 text-xl font-bold tracking-tight sm:text-2xl"
                        >
                            {currentTeam.name}
                        </h2>
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-1">
                        <div
                            class="flex rounded-lg border bg-card p-1"
                            aria-label={t('Project display')}
                        >
                            <button
                                type="button"
                                class={projectView === 'grid'
                                    ? 'rounded-md bg-muted p-1.5'
                                    : 'rounded-md p-1.5 hover:bg-muted'}
                                aria-pressed={projectView === 'grid'}
                                aria-label={t('Two column view')}
                                onclick={() => setProjectView('grid')}
                                ><Grid2X2 class="size-4" /></button
                            >
                            <button
                                type="button"
                                class={projectView === 'list'
                                    ? 'rounded-md bg-muted p-1.5'
                                    : 'rounded-md p-1.5 hover:bg-muted'}
                                aria-pressed={projectView === 'list'}
                                aria-label={t('Single column view')}
                                onclick={() => setProjectView('list')}
                                ><List class="size-4" /></button
                            >
                        </div>
                    </div>
                </div>

                {#if myProjects.length === 0}
                    <div
                        class="flex min-h-64 flex-col items-center justify-center rounded-2xl border border-dashed bg-card px-6 py-12 text-center"
                    >
                        <FolderKanban
                            class="mb-4 size-10 text-muted-foreground"
                        />
                        <p class="font-semibold">{t('No active projects')}</p>
                    </div>
                {:else}
                    <div class="flex flex-col gap-4">
                        {#each projectGroups as group (group.id ?? 'other')}
                            <section class="flex flex-col gap-2">
                                <h3
                                    class="flex items-center gap-2 px-1 text-sm font-bold text-muted-foreground"
                                >
                                    <FolderKanban class="size-4" />
                                    {group.name}
                                </h3>
                                <div
                                    class={projectView === 'grid'
                                        ? 'grid gap-3 md:grid-cols-2'
                                        : 'grid gap-3'}
                                >
                                    {#each group.projects as project (project.id)}
                                        <div class="relative">
                                            <Link
                                                href={show([
                                                    currentTeam.slug,
                                                    project.id,
                                                ])}
                                                prefetch
                                                class="group flex min-h-32 min-w-0 flex-col rounded-2xl border border-slate-200 bg-card p-3 shadow-xs transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring dark:border-slate-600 dark:bg-[#192932] sm:p-4"
                                            >
                                                <span
                                                    class="flex min-w-0 items-start justify-between gap-4 pr-10"
                                                >
                                                    <span class="min-w-0">
                                                        <span
                                                            class="block break-words text-lg font-bold tracking-tight sm:text-xl"
                                                        >
                                                            {project.name}
                                                        </span>
                                                        <span
                                                            class="mt-1 line-clamp-2 block text-sm leading-5 text-muted-foreground"
                                                        >
                                                            {project.description ||
                                                                t(
                                                                    'No description',
                                                                )}
                                                        </span>
                                                    </span>
                                                    <ArrowRight
                                                        class="mt-1 size-5 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-1"
                                                    />
                                                </span>

                                                <span
                                                    class="mt-auto flex items-end justify-between gap-4 pt-3"
                                                >
                                                    <span
                                                        class="flex -space-x-2"
                                                        aria-label={t(
                                                            'Team members',
                                                        )}
                                                    >
                                                        {#each project.members as member (member.id)}
                                                            <Avatar
                                                                class="size-8 border-2 border-card dark:border-[#192932]"
                                                            >
                                                                <AvatarFallback
                                                                    class="bg-primary/15 text-xs font-bold text-primary"
                                                                >
                                                                    {getInitials(
                                                                        member.name,
                                                                    )}
                                                                </AvatarFallback>
                                                            </Avatar>
                                                        {/each}
                                                    </span>
                                                    {#if project.endsOn}
                                                        <span
                                                            class="flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground"
                                                        >
                                                            <CalendarDays
                                                                class="size-3.5"
                                                            />
                                                            {formatDate(
                                                                project.endsOn,
                                                            )}
                                                        </span>
                                                    {/if}
                                                </span>
                                            </Link>
                                            <Form
                                                {...ProjectFavoriteController.form(
                                                    [
                                                        currentTeam.slug,
                                                        project.id,
                                                    ],
                                                )}
                                                class="absolute top-3 right-3"
                                            >
                                                {#snippet children({
                                                    processing,
                                                })}
                                                    <Button
                                                        type="submit"
                                                        variant="ghost"
                                                        size="icon"
                                                        disabled={processing}
                                                        aria-label={project.isFavorite
                                                            ? t(
                                                                  'Remove from favorites',
                                                              )
                                                            : t(
                                                                  'Add to favorites',
                                                              )}
                                                    >
                                                        <Star
                                                            class={`size-5 ${project.isFavorite ? 'fill-amber-400 text-amber-500' : ''}`}
                                                        />
                                                    </Button>
                                                {/snippet}
                                            </Form>
                                        </div>
                                    {/each}
                                </div>
                            </section>
                        {/each}
                    </div>
                {/if}
            </section>

            <aside
                id="dashboard-recent-activity"
                class={cn(
                    'relative min-w-0 lg:sticky lg:top-24 lg:self-stretch lg:overflow-hidden',
                    !dashboardActivityPanel.open &&
                        'lg:border-l lg:border-border lg:pl-2',
                )}
                data-state={dashboardActivityPanel.open ? 'open' : 'closed'}
                onmouseenter={() => dashboardActivityPanel.show()}
                onmouseleave={() => dashboardActivityPanel.hide()}
            >
                <div
                    class={cn(
                        'lg:flex lg:min-h-0 lg:w-full lg:min-w-0 lg:flex-col lg:transition-[height,opacity,transform,visibility] lg:duration-200 lg:ease-out motion-reduce:transition-none',
                        dashboardActivityPanel.open
                            ? 'lg:visible lg:max-h-[calc(100dvh-9rem)] lg:translate-x-0 lg:opacity-100'
                            : 'lg:invisible lg:h-0 lg:translate-x-3 lg:opacity-0',
                    )}
                >
                    <div
                        class="mb-4 flex items-center justify-between gap-3 lg:shrink-0"
                    >
                        <h2 class="text-lg font-bold">
                            {t('Recent activity')}
                        </h2>
                        <div class="flex items-center gap-1">
                            <Link
                                href={ActivityController(currentTeam.slug)}
                                class="shrink-0 text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground"
                            >
                                {t('View all')}
                            </Link>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="hidden lg:inline-flex"
                                aria-label={t('Collapse column')}
                                aria-controls="dashboard-recent-activity"
                                aria-expanded={dashboardActivityPanel.open}
                                onclick={() => dashboardActivityPanel.hide()}
                            >
                                <PanelRightClose />
                            </Button>
                        </div>
                    </div>

                    <div
                        class="lg:min-h-0 lg:flex-1 lg:overflow-y-auto lg:overscroll-contain lg:pr-2"
                    >
                        {#if recentActivities.length === 0}
                            <div
                                class="rounded-2xl border border-dashed bg-card/70 px-5 py-10 text-center"
                            >
                                <History
                                    class="mx-auto mb-3 size-8 text-muted-foreground"
                                />
                                <p class="text-sm text-muted-foreground">
                                    {t('No activity yet')}
                                </p>
                            </div>
                        {:else}
                            <ol class="ml-4 border-l border-border">
                                {#each recentActivities as activity (activity.id)}
                                    <li class="relative pb-5 pl-6 last:pb-0">
                                        <Avatar
                                            class="absolute top-0 -left-4 size-8 border-2 border-[#f5f7f3] dark:border-[#07151d]"
                                        >
                                            <AvatarFallback
                                                class="bg-violet-500 text-[10px] font-bold text-white"
                                            >
                                                {getInitials(
                                                    activity.actorName ||
                                                        t('Deleted user'),
                                                )}
                                            </AvatarFallback>
                                        </Avatar>
                                        <Link
                                            href={activityHref(
                                                currentTeam.slug,
                                                activity,
                                            )}
                                            class="block rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        >
                                            <time
                                                datetime={activity.createdAt ||
                                                    ''}
                                                class="text-xs text-muted-foreground"
                                            >
                                                {formatActivityDate(
                                                    activity.createdAt,
                                                )}
                                            </time>
                                            <p class="mt-1 text-sm leading-5">
                                                <strong class="font-semibold">
                                                    {activity.actorName ||
                                                        t('Deleted user')}
                                                </strong>
                                                {activityAction(activity.type)}
                                            </p>
                                            <p
                                                class="mt-0.5 break-words text-sm font-medium text-blue-600 underline-offset-4 hover:underline dark:text-blue-400"
                                            >
                                                {activity.subjectLabel}
                                            </p>
                                            <p
                                                class="mt-1 truncate text-xs text-muted-foreground"
                                            >
                                                {activity.project.name}
                                            </p>
                                        </Link>
                                    </li>
                                {/each}
                            </ol>
                        {/if}
                    </div>
                </div>

                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    class={cn(
                        'absolute top-0 right-0 hidden',
                        !dashboardActivityPanel.open && 'lg:inline-flex',
                    )}
                    aria-label={t('Expand column')}
                    aria-controls="dashboard-recent-activity"
                    aria-expanded={dashboardActivityPanel.open}
                    onclick={() => dashboardActivityPanel.show()}
                >
                    <PanelRightOpen />
                </Button>
            </aside>
        </div>

        <section
            id="my-tasks"
            class="mt-4 scroll-mt-24 rounded-2xl border bg-card/90 p-3 shadow-sm sm:p-4 lg:mt-auto"
            aria-labelledby="my-tasks-heading"
        >
            <div
                class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h2
                        id="my-tasks-heading"
                        class="flex items-center gap-2 text-lg font-bold"
                    >
                        <ClipboardCheck class="size-4 text-primary" />
                        {t('My work')}
                    </h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {t(
                            'Everything assigned to you across active projects.',
                        )}
                    </p>
                </div>
                <Badge variant="secondary" class="text-xs">
                    {totalTasks}
                    {t('Open tasks')}
                </Badge>
            </div>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                {#each workGroups as group (group.key)}
                    <article class="overflow-hidden rounded-xl border">
                        <header
                            class="flex items-start justify-between gap-2 border-b bg-muted/30 px-3 py-2"
                        >
                            <div>
                                <h3 class="text-sm font-semibold">
                                    {group.title}
                                </h3>
                                <p
                                    class="text-[11px] leading-4 text-muted-foreground"
                                >
                                    {group.description}
                                </p>
                            </div>
                            <Badge
                                variant={group.overdue && group.todos.length > 0
                                    ? 'destructive'
                                    : 'secondary'}
                                class="text-[11px]"
                            >
                                {group.todos.length}
                            </Badge>
                        </header>

                        {#if group.todos.length === 0}
                            <p
                                class="px-3 py-5 text-center text-xs text-muted-foreground"
                            >
                                {t('No tasks in this section')}
                            </p>
                        {:else}
                            <ul class="divide-y">
                                {#each group.todos as todo (todo.id)}
                                    <li>
                                        <Link
                                            href={ProjectTodoController.index([
                                                currentTeam.slug,
                                                todo.project.id,
                                            ])}
                                            class="group flex min-w-0 items-center gap-2 px-3 py-2 transition-colors hover:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                                        >
                                            <span class="min-w-0 flex-1">
                                                <span
                                                    class="block break-words text-xs font-medium"
                                                >
                                                    {todo.title}
                                                </span>
                                                <span
                                                    class="mt-0.5 block truncate text-[11px] text-muted-foreground"
                                                >
                                                    {todo.project.name} · {todo.dueOn
                                                        ? formatDate(todo.dueOn)
                                                        : t('No due date')}
                                                </span>
                                            </span>
                                            <ArrowRight
                                                class="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                                            />
                                        </Link>
                                    </li>
                                {/each}
                            </ul>
                        {/if}
                    </article>
                {/each}
            </div>
        </section>
    </main>
</div>
