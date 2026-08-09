<script module lang="ts">
    import LineupController from '@/actions/App/Http/Controllers/LineupController';
    import type { Team } from '@/types';

    export const layout = (props: { currentTeam: Team }) => ({
        breadcrumbs: [
            {
                title: props.currentTeam.menuNames.lineup,
                href: LineupController(props.currentTeam.slug),
            },
        ],
    });
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import ChartGantt from 'lucide-svelte/icons/chart-gantt';
    import ScheduleEventController from '@/actions/App/Http/Controllers/ScheduleEventController';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import {
        Card,
        CardContent,
        CardHeader,
        CardTitle,
    } from '@/components/ui/card';
    import { currentLocale, t } from '@/lib/i18n';
    import { show } from '@/routes/projects';
    import type { LineupProject } from '@/types';

    let {
        currentTeam,
        projects,
    }: {
        currentTeam: Team;
        projects: LineupProject[];
    } = $props();

    const formatDate = (value: string | null): string =>
        value
            ? new Intl.DateTimeFormat(currentLocale(), {
                  dateStyle: 'medium',
              }).format(new Date(`${value}T00:00:00+09:00`))
            : t('Not set');
    const formatEventDate = (value: string, allDay: boolean): string =>
        new Intl.DateTimeFormat(
            currentLocale(),
            allDay
                ? { dateStyle: 'medium' }
                : { dateStyle: 'medium', timeStyle: 'short' },
        ).format(new Date(value));
</script>

<AppHead title={currentTeam.menuNames.lineup} />

<div class="mx-auto w-full max-w-6xl space-y-6 p-4 sm:p-6">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">
            {currentTeam.menuNames.lineup}
        </h1>
        <p class="text-sm text-muted-foreground">
            {t('See active projects, dates, progress, and the next event')}
        </p>
    </div>

    {#if projects.length === 0}
        <Card>
            <CardContent class="py-12 text-center">
                <ChartGantt class="mx-auto mb-3 size-8 text-muted-foreground" />
                <p class="text-sm text-muted-foreground">
                    {t('No active projects')}
                </p>
            </CardContent>
        </Card>
    {:else}
        <div class="space-y-4">
            {#each projects as project (project.id)}
                <Card class="min-w-0">
                    <CardHeader class="gap-2">
                        <div
                            class="flex min-w-0 flex-wrap items-start justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <CardTitle>
                                    <Link
                                        href={show([
                                            currentTeam.slug,
                                            project.id,
                                        ])}
                                        class="break-words hover:underline"
                                    >
                                        {project.name}
                                    </Link>
                                </CardTitle>
                                {#if project.description}
                                    <p
                                        class="mt-1 line-clamp-2 break-words text-sm text-muted-foreground"
                                    >
                                        {project.description}
                                    </p>
                                {/if}
                            </div>
                            <Badge variant="outline">
                                {formatDate(project.startsOn)} – {formatDate(
                                    project.endsOn,
                                )}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="space-y-2">
                            <div class="flex justify-between gap-3 text-sm">
                                <span>
                                    {t(':name progress', {
                                        name: currentTeam.menuNames.todos,
                                    })}
                                </span>
                                <span class="text-muted-foreground">
                                    {project.todoCompleted}/{project.todoTotal}
                                </span>
                            </div>
                            <progress
                                value={project.todoCompleted}
                                max={Math.max(project.todoTotal, 1)}
                                class="h-2 w-full accent-primary"
                            ></progress>
                            {#if project.todoOverdue > 0}
                                <Badge variant="destructive">
                                    {t(':count overdue', {
                                        count: String(project.todoOverdue),
                                    })}
                                </Badge>
                            {/if}
                        </div>

                        {#if project.nextEvent}
                            <Link
                                href={ScheduleEventController.index([
                                    currentTeam.slug,
                                    project.id,
                                ])}
                                class="flex min-w-0 items-start gap-3 rounded-lg border p-3 hover:bg-muted/50"
                            >
                                <CalendarDays
                                    class="mt-0.5 size-4 shrink-0 text-primary"
                                />
                                <span class="min-w-0">
                                    <span
                                        class="block break-words text-sm font-medium"
                                    >
                                        {project.nextEvent.title}
                                    </span>
                                    <span
                                        class="block text-xs text-muted-foreground"
                                    >
                                        {formatEventDate(
                                            project.nextEvent.startsAt,
                                            project.nextEvent.allDay,
                                        )}
                                    </span>
                                </span>
                            </Link>
                        {:else}
                            <p class="text-sm text-muted-foreground">
                                {t('No upcoming events')}
                            </p>
                        {/if}
                    </CardContent>
                </Card>
            {/each}
        </div>
    {/if}
</div>
