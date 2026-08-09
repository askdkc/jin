<script module lang="ts">
    import ReportsController from '@/actions/App/Http/Controllers/ReportsController';
    import type { Team } from '@/types';

    export const layout = (props: { currentTeam: Team }) => ({
        breadcrumbs: [
            {
                title: props.currentTeam.menuNames.reports,
                href: ReportsController(props.currentTeam.slug),
            },
        ],
    });
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import ChartGantt from 'lucide-svelte/icons/chart-gantt';
    import CheckCircle from 'lucide-svelte/icons/circle-check';
    import CircleUserRound from 'lucide-svelte/icons/circle-user-round';
    import ClipboardCheck from 'lucide-svelte/icons/clipboard-check';
    import Gauge from 'lucide-svelte/icons/gauge';
    import History from 'lucide-svelte/icons/history';
    import ListTodo from 'lucide-svelte/icons/list-todo';
    import Search from 'lucide-svelte/icons/search';
    import AlertTriangle from 'lucide-svelte/icons/triangle-alert';
    import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
    import EverythingController from '@/actions/App/Http/Controllers/EverythingController';
    import LineupController from '@/actions/App/Http/Controllers/LineupController';
    import ProjectTodoController from '@/actions/App/Http/Controllers/ProjectTodoController';
    import WorkspaceCalendarController from '@/actions/App/Http/Controllers/WorkspaceCalendarController';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import {
        Card,
        CardContent,
        CardHeader,
        CardTitle,
    } from '@/components/ui/card';
    import { currentLocale, t } from '@/lib/i18n';
    import type {
        NavItem,
        ReportSummary,
        ReportTodo,
        WorkloadItem,
    } from '@/types';

    type OverviewItem = {
        title: string;
        description: string;
        href: string;
        anchor: boolean;
        icon: NonNullable<NavItem['icon']>;
        tone: string;
    };

    let {
        currentTeam,
        summary,
        overdue,
        unassigned,
        workload,
    }: {
        currentTeam: Team;
        summary: ReportSummary;
        overdue: ReportTodo[];
        unassigned: ReportTodo[];
        workload: WorkloadItem[];
    } = $props();

    const maxWorkload = $derived(
        Math.max(...workload.map((item) => item.incompleteCount), 1),
    );
    const overviewItems = $derived<OverviewItem[]>([
        {
            title: currentTeam.menuNames.lineup,
            description: t('See projects on a timeline'),
            href: LineupController.url(currentTeam.slug),
            anchor: false,
            icon: ChartGantt,
            tone: 'bg-pink-500',
        },
        {
            title: t('Project overview'),
            description: t('See overall project status'),
            href: '#report-summary',
            anchor: true,
            icon: Gauge,
            tone: 'bg-blue-500',
        },
        {
            title: currentTeam.menuNames.calendar,
            description: t('See events across projects'),
            href: WorkspaceCalendarController.url(currentTeam.slug),
            anchor: false,
            icon: CalendarDays,
            tone: 'bg-lime-600',
        },
        {
            title: t('Recent activity'),
            description: t('See what everyone has been working on'),
            href: ActivityController.url(currentTeam.slug),
            anchor: false,
            icon: History,
            tone: 'bg-violet-500',
        },
        {
            title: t('Workload by person'),
            description: t('Compare assigned work'),
            href: '#workload',
            anchor: true,
            icon: CircleUserRound,
            tone: 'bg-orange-500',
        },
        {
            title: t('Completed tasks'),
            description: t('Review recently completed work'),
            href: '#report-summary',
            anchor: true,
            icon: CheckCircle,
            tone: 'bg-emerald-500',
        },
        {
            title: t('Overdue to-dos'),
            description: t('See what is running late'),
            href: '#overdue',
            anchor: true,
            icon: AlertTriangle,
            tone: 'bg-red-500',
        },
        {
            title: t('Unassigned work'),
            description: t('Find work without an assignee'),
            href: '#unassigned',
            anchor: true,
            icon: ClipboardCheck,
            tone: 'bg-amber-500',
        },
        {
            title: t('Everything overview'),
            description: t('Search all project content'),
            href: EverythingController.url(currentTeam.slug),
            anchor: false,
            icon: Search,
            tone: 'bg-teal-500',
        },
    ]);
    const overviewCardClass =
        'group flex min-h-56 flex-col items-center justify-center gap-4 rounded-2xl border bg-card p-6 text-center shadow-sm transition hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:min-h-64';
    const formatDate = (value: string | null): string =>
        value
            ? new Intl.DateTimeFormat(currentLocale(), {
                  dateStyle: 'medium',
              }).format(new Date(`${value}T00:00:00+09:00`))
            : t('No due date');
</script>

{#snippet overviewCardContent(item: OverviewItem)}
    {@const Icon = item.icon}
    <span
        class={`flex size-16 items-center justify-center rounded-full text-white shadow-sm transition-transform group-hover:scale-105 ${item.tone}`}
    >
        <Icon class="size-8" />
    </span>
    <span>
        <span class="block text-xl font-bold tracking-tight">
            {item.title}
        </span>
        <span
            class="mt-1 block text-sm leading-relaxed text-muted-foreground sm:text-base"
        >
            {item.description}
        </span>
    </span>
{/snippet}

<AppHead title={currentTeam.menuNames.reports} />

<div class="mx-auto w-full max-w-6xl space-y-6 p-4 sm:p-6">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">
            {currentTeam.menuNames.reports}
        </h1>
        <p class="text-sm text-muted-foreground">
            {t('Choose a view across all accessible projects')}
        </p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {#each overviewItems as item (item.title)}
            {#if item.anchor}
                <a href={item.href} class={overviewCardClass}>
                    {@render overviewCardContent(item)}
                </a>
            {:else}
                <Link href={item.href} prefetch class={overviewCardClass}>
                    {@render overviewCardContent(item)}
                </Link>
            {/if}
        {/each}
    </div>

    <div class="border-t pt-6">
        <h2 class="text-xl font-semibold tracking-tight">
            {t('Report details')}
        </h2>
        <p class="text-sm text-muted-foreground">
            {t('See overdue, unassigned, completed, and workload totals')}
        </p>
    </div>

    <div
        id="report-summary"
        class="grid scroll-mt-20 grid-cols-2 gap-3 lg:grid-cols-4"
    >
        <Card>
            <CardContent class="pt-6">
                <ListTodo class="mb-3 size-5 text-primary" />
                <p class="text-2xl font-semibold">{summary.incomplete}</p>
                <p class="text-sm text-muted-foreground">{t('Incomplete')}</p>
            </CardContent>
        </Card>
        <Card>
            <CardContent class="pt-6">
                <AlertTriangle class="mb-3 size-5 text-destructive" />
                <p class="text-2xl font-semibold">{summary.overdue}</p>
                <p class="text-sm text-muted-foreground">{t('Overdue')}</p>
            </CardContent>
        </Card>
        <Card>
            <CardContent class="pt-6">
                <CircleUserRound class="mb-3 size-5 text-primary" />
                <p class="text-2xl font-semibold">{summary.unassigned}</p>
                <p class="text-sm text-muted-foreground">{t('Unassigned')}</p>
            </CardContent>
        </Card>
        <Card>
            <CardContent class="pt-6">
                <CheckCircle class="mb-3 size-5 text-primary" />
                <p class="text-2xl font-semibold">
                    {summary.completedLast30Days}
                </p>
                <p class="text-sm text-muted-foreground">
                    {t('Completed in 30 days')}
                </p>
            </CardContent>
        </Card>
    </div>

    <div id="workload" class="scroll-mt-20">
        <Card>
            <CardHeader><CardTitle>{t('Workload')}</CardTitle></CardHeader>
            <CardContent>
                {#if workload.length === 0}
                    <p class="text-sm text-muted-foreground">
                        {t('No assigned work')}
                    </p>
                {:else}
                    <div class="space-y-4">
                        {#each workload as item (item.userId)}
                            <div
                                class="grid gap-2 sm:grid-cols-[minmax(8rem,12rem)_1fr_auto] sm:items-center"
                            >
                                <span class="truncate text-sm font-medium"
                                    >{item.name}</span
                                >
                                <progress
                                    value={item.incompleteCount}
                                    max={maxWorkload}
                                    class="h-2 w-full accent-primary"
                                ></progress>
                                <Badge variant="secondary">
                                    {t(':count incomplete', {
                                        count: String(item.incompleteCount),
                                    })}
                                </Badge>
                            </div>
                        {/each}
                    </div>
                {/if}
            </CardContent>
        </Card>
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        <div id="overdue" class="scroll-mt-20">
            <Card>
                <CardHeader
                    ><CardTitle>{t('Overdue to-dos')}</CardTitle></CardHeader
                >
                <CardContent>
                    {#if overdue.length === 0}
                        <p class="text-sm text-muted-foreground">
                            {t('No overdue to-dos')}
                        </p>
                    {:else}
                        <ul class="space-y-3">
                            {#each overdue as todo (todo.id)}
                                <li class="min-w-0 rounded-lg border p-3">
                                    <Link
                                        href={ProjectTodoController.index([
                                            currentTeam.slug,
                                            todo.project.id,
                                        ])}
                                        class="break-words text-sm font-medium hover:underline"
                                    >
                                        {todo.title}
                                    </Link>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {todo.project.name} · {formatDate(
                                            todo.dueOn,
                                        )}
                                    </p>
                                </li>
                            {/each}
                        </ul>
                    {/if}
                </CardContent>
            </Card>
        </div>

        <div id="unassigned" class="scroll-mt-20">
            <Card>
                <CardHeader
                    ><CardTitle>{t('Unassigned to-dos')}</CardTitle></CardHeader
                >
                <CardContent>
                    {#if unassigned.length === 0}
                        <p class="text-sm text-muted-foreground">
                            {t('No unassigned to-dos')}
                        </p>
                    {:else}
                        <ul class="space-y-3">
                            {#each unassigned as todo (todo.id)}
                                <li class="min-w-0 rounded-lg border p-3">
                                    <Link
                                        href={ProjectTodoController.index([
                                            currentTeam.slug,
                                            todo.project.id,
                                        ])}
                                        class="break-words text-sm font-medium hover:underline"
                                    >
                                        {todo.title}
                                    </Link>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {todo.project.name} · {formatDate(
                                            todo.dueOn,
                                        )}
                                    </p>
                                </li>
                            {/each}
                        </ul>
                    {/if}
                </CardContent>
            </Card>
        </div>
    </div>
</div>
