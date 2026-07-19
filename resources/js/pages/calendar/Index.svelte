<script module lang="ts">
    import WorkspaceCalendarController from '@/actions/App/Http/Controllers/WorkspaceCalendarController';
    import type { Team } from '@/types';

    export const layout = (props: { currentTeam: Team }) => ({
        breadcrumbs: [
            {
                title: props.currentTeam.menuNames.calendar,
                href: WorkspaceCalendarController(props.currentTeam.slug),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import Copy from 'lucide-svelte/icons/copy';
    import ExternalLink from 'lucide-svelte/icons/external-link';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import CalendarSubscriptionController from '@/actions/App/Http/Controllers/CalendarSubscriptionController';
    import ScheduleEventController from '@/actions/App/Http/Controllers/ScheduleEventController';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Card, CardContent } from '@/components/ui/card';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
    import { show as showProject } from '@/routes/projects';
    import type {
        CalendarSubscription,
        WorkspaceCalendarEvent,
        WorkspaceGanttProject,
    } from '@/types';

    type CalendarView = 'day' | 'week' | 'month' | 'gantt';
    type GanttRange =
        'week' | 'month' | 'quarter' | 'half-year' | 'year' | 'custom';
    type CalendarDay = {
        date: string;
        inCurrentMonth: boolean;
        events: WorkspaceCalendarEvent[];
    };

    let {
        currentTeam,
        view,
        date,
        today,
        rangeStart,
        rangeEnd,
        previousDate,
        nextDate,
        ganttRange,
        events,
        ganttProjects,
        subscriptions,
    }: {
        currentTeam: Team;
        view: CalendarView;
        date: string;
        today: string;
        rangeStart: string;
        rangeEnd: string;
        previousDate: string;
        nextDate: string;
        ganttRange: GanttRange;
        events: WorkspaceCalendarEvent[];
        ganttProjects: WorkspaceGanttProject[];
        subscriptions: CalendarSubscription[];
    } = $props();

    let copiedSubscriptionId = $state<number | null>(null);

    const views: { value: CalendarView; label: string }[] = [
        { value: 'day', label: 'Day' },
        { value: 'week', label: 'Week' },
        { value: 'month', label: 'Month' },
        { value: 'gantt', label: 'Gantt' },
    ];
    const ganttRanges: {
        value: Exclude<GanttRange, 'custom'>;
        label: string;
    }[] = [
        { value: 'week', label: 'Week' },
        { value: 'month', label: 'Month' },
        { value: 'quarter', label: 'Quarter' },
        { value: 'half-year', label: 'Half year' },
        { value: 'year', label: 'Year' },
    ];
    const weekdays = ['日', '月', '火', '水', '木', '金', '土'];
    const eventColorClasses = [
        'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:bg-blue-950/50 dark:text-blue-200',
        'border-violet-200 bg-violet-50 text-violet-800 dark:border-violet-900 dark:bg-violet-950/50 dark:text-violet-200',
        'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200',
        'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-200',
    ];
    const monthFormatter = new Intl.DateTimeFormat('ja-JP', {
        year: 'numeric',
        month: 'long',
        timeZone: 'Asia/Tokyo',
    });
    const longDateFormatter = new Intl.DateTimeFormat('ja-JP', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        weekday: 'short',
        timeZone: 'Asia/Tokyo',
    });
    const shortDateFormatter = new Intl.DateTimeFormat('ja-JP', {
        month: 'numeric',
        day: 'numeric',
        weekday: 'short',
        timeZone: 'Asia/Tokyo',
    });
    const timeFormatter = new Intl.DateTimeFormat('ja-JP', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Asia/Tokyo',
    });
    const dateKeyFormatter = new Intl.DateTimeFormat('en-CA', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        timeZone: 'Asia/Tokyo',
    });

    const parseDate = (value: string): Date =>
        new Date(`${value}T00:00:00+09:00`);
    const addDays = (value: string, amount: number): string =>
        new Date(Date.parse(`${value}T00:00:00Z`) + amount * 86_400_000)
            .toISOString()
            .slice(0, 10);
    const startOfNextMonth = (value: string): string => {
        const [year, monthNumber] = value.split('-').map(Number);

        return new Date(Date.UTC(year, monthNumber, 1))
            .toISOString()
            .slice(0, 10);
    };
    const weekday = (value: string): number =>
        new Date(`${value}T00:00:00Z`).getUTCDay();
    const eventsForDate = (value: string): WorkspaceCalendarEvent[] => {
        const dayStart = parseDate(value).getTime();
        const dayEnd = parseDate(addDays(value, 1)).getTime();

        // ponytail: bounded ranges make a linear scan cheap; index by day only if event volume proves otherwise.
        return events.filter((event) => {
            const startsAt = new Date(event.startsAt).getTime();
            const endsAt = new Date(event.endsAt).getTime();

            return startsAt < dayEnd && endsAt > dayStart;
        });
    };
    const formatMonth = (value: string): string =>
        monthFormatter.format(parseDate(value));
    const formatLongDate = (value: string): string =>
        longDateFormatter.format(parseDate(value));
    const formatShortDate = (value: string): string =>
        shortDateFormatter.format(parseDate(value));
    const formatEventTime = (event: WorkspaceCalendarEvent): string =>
        event.allDay
            ? t('All day')
            : `${timeFormatter.format(new Date(event.startsAt))} – ${timeFormatter.format(new Date(event.endsAt))}`;
    const eventColor = (projectId: number): string =>
        eventColorClasses[projectId % eventColorClasses.length] ??
        eventColorClasses[0];
    const dayNumber = (value: string): number =>
        Date.parse(`${value}T00:00:00Z`) / 86_400_000;
    const workspaceCalendarHref = (
        targetView: CalendarView,
        targetDate: string,
    ): string => {
        if (targetView !== 'gantt') {
            return WorkspaceCalendarController(currentTeam.slug, {
                query: { view: targetView, date: targetDate },
            }).url;
        }

        if (ganttRange === 'custom') {
            return WorkspaceCalendarController(currentTeam.slug, {
                query: {
                    view: 'gantt',
                    range: 'custom',
                    start: rangeStart,
                    end: rangeEnd,
                },
            }).url;
        }

        return WorkspaceCalendarController(currentTeam.slug, {
            query: { view: 'gantt', range: ganttRange, date: targetDate },
        }).url;
    };
    const ganttRangeHref = (
        targetRange: Exclude<GanttRange, 'custom'>,
    ): string =>
        WorkspaceCalendarController(currentTeam.slug, {
            query: { view: 'gantt', range: targetRange, date },
        }).url;
    const dateKeyInTokyo = (value: string, end = false): string =>
        dateKeyFormatter.format(
            new Date(new Date(value).getTime() - (end ? 1 : 0)),
        );
    const ganttGridColumn = (startsOn: string, endsOn: string): string => {
        const firstDay = Math.max(
            0,
            dayNumber(startsOn) - dayNumber(rangeStart),
        );
        const lastDay = Math.min(
            ganttDayCount - 1,
            dayNumber(endsOn) - dayNumber(rangeStart),
        );

        return `${firstDay + 1} / ${lastDay + 2}`;
    };
    const centerGanttOnSelectedDate = (node: HTMLDivElement): void => {
        requestAnimationFrame(() => {
            const focus =
                (dayNumber(date) - dayNumber(rangeStart) + 0.5) / ganttDayCount;

            node.scrollLeft = node.scrollWidth * focus - node.clientWidth / 2;
        });
    };

    let periodTitle = $derived(
        view === 'day'
            ? formatLongDate(date)
            : view === 'week' || view === 'gantt'
              ? `${formatShortDate(rangeStart)} – ${formatShortDate(rangeEnd)}`
              : formatMonth(date),
    );
    let ganttDayCount = $derived(
        dayNumber(rangeEnd) - dayNumber(rangeStart) + 1,
    );
    let ganttTicks = $derived.by(() => {
        if (ganttDayCount <= 14) {
            return Array.from({ length: ganttDayCount }, (_, index) =>
                addDays(rangeStart, index),
            );
        }

        if (ganttDayCount <= 120) {
            return Array.from(
                { length: Math.ceil(ganttDayCount / 7) },
                (_, index) => addDays(rangeStart, index * 7),
            );
        }

        const ticks = [rangeStart];
        let cursor = startOfNextMonth(rangeStart);

        while (cursor <= rangeEnd) {
            ticks.push(cursor);
            cursor = startOfNextMonth(cursor);
        }

        return ticks;
    });
    let ganttWeekendBands = $derived.by(() =>
        Array.from({ length: ganttDayCount }, (_, index) =>
            addDays(rangeStart, index),
        ).filter((calendarDate) => {
            const day = weekday(calendarDate);

            return day === 0 || day === 6;
        }),
    );
    let ganttMinWidthRem = $derived(Math.max(48, Math.min(120, ganttDayCount)));
    let ganttGridStepDays = $derived(ganttDayCount > 180 ? 7 : 1);
    const ganttTickOffset = (value: string): number =>
        ((dayNumber(value) - dayNumber(rangeStart)) / ganttDayCount) * 100;
    const ganttMonthFormatter = new Intl.DateTimeFormat('ja-JP', {
        year: 'numeric',
        month: 'short',
        timeZone: 'Asia/Tokyo',
    });
    const formatGanttTick = (value: string): string =>
        ganttDayCount > 120
            ? ganttMonthFormatter.format(parseDate(value))
            : formatShortDate(value);
    const ganttNavigationHref = (targetDate: string): string => {
        if (ganttRange !== 'custom') {
            return workspaceCalendarHref('gantt', targetDate);
        }

        return WorkspaceCalendarController(currentTeam.slug, {
            query: {
                view: 'gantt',
                range: 'custom',
                start: targetDate,
                end: addDays(targetDate, ganttDayCount - 1),
            },
        }).url;
    };
    let todayInGanttRange = $derived(today >= rangeStart && today <= rangeEnd);
    let todayOffset = $derived(
        ((dayNumber(today) - dayNumber(rangeStart) + 0.5) / ganttDayCount) *
            100,
    );
    let weekDays = $derived.by(() =>
        Array.from({ length: 7 }, (_, index): CalendarDay => {
            const calendarDate = addDays(rangeStart, index);

            return {
                date: calendarDate,
                inCurrentMonth: true,
                events: eventsForDate(calendarDate),
            };
        }),
    );
    let monthDays = $derived.by(() => {
        const monthPrefix = date.slice(0, 7);
        const firstOfMonth = `${monthPrefix}-01`;
        const gridStart = addDays(firstOfMonth, -weekday(firstOfMonth));

        return Array.from({ length: 42 }, (_, index): CalendarDay => {
            const calendarDate = addDays(gridStart, index);

            return {
                date: calendarDate,
                inCurrentMonth: calendarDate.startsWith(monthPrefix),
                events: eventsForDate(calendarDate),
            };
        });
    });
    const copySubscriptionUrl = async (
        subscription: CalendarSubscription,
    ): Promise<void> => {
        if (!subscription.url) {
            return;
        }

        await navigator.clipboard.writeText(subscription.url);
        copiedSubscriptionId = subscription.id;
    };
</script>

<AppHead title={currentTeam.menuNames.calendar} />

<div class="mx-auto w-full max-w-[96rem] space-y-6 p-3 sm:p-6 lg:p-8">
    <div
        class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
    >
        <div>
            <h1
                class="text-3xl font-black tracking-tight text-slate-800 dark:text-slate-100"
            >
                {currentTeam.menuNames.calendar}
            </h1>
            <p class="text-sm text-muted-foreground">
                {t('Schedules from all projects you can access')}
            </p>
        </div>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <nav
                class="inline-flex w-fit rounded-lg border bg-muted/30 p-1"
                aria-label={t('Calendar view')}
            >
                {#each views as calendarView (calendarView.value)}
                    <Button
                        variant={view === calendarView.value
                            ? 'default'
                            : 'ghost'}
                        size="sm"
                        asChild
                    >
                        {#snippet children(buttonProps)}
                            <Link
                                {...buttonProps}
                                href={workspaceCalendarHref(
                                    calendarView.value,
                                    date,
                                )}
                                class={buttonProps.class}
                                aria-current={view === calendarView.value
                                    ? 'page'
                                    : undefined}
                            >
                                {t(calendarView.label)}
                            </Link>
                        {/snippet}
                    </Button>
                {/each}
            </nav>

            {#if view === 'gantt'}
                <div class="flex flex-wrap items-end gap-2">
                    <nav
                        class="inline-flex w-fit overflow-hidden rounded-md border bg-muted/20"
                        aria-label={t('Gantt range')}
                    >
                        {#each ganttRanges as rangeOption, index (rangeOption.value)}
                            <Link
                                href={ganttRangeHref(rangeOption.value)}
                                class={`px-2.5 py-1.5 text-xs font-semibold ${index > 0 ? 'border-l' : ''} ${
                                    ganttRange === rangeOption.value
                                        ? 'bg-primary text-primary-foreground'
                                        : 'hover:bg-muted'
                                }`}
                                aria-current={ganttRange === rangeOption.value
                                    ? 'page'
                                    : undefined}
                            >
                                {t(rangeOption.label)}
                            </Link>
                        {/each}
                    </nav>

                    <Form
                        {...WorkspaceCalendarController.form(currentTeam.slug)}
                        class="flex flex-wrap items-end gap-2"
                    >
                        {#snippet children({ errors, processing })}
                            <input type="hidden" name="view" value="gantt" />
                            <input type="hidden" name="range" value="custom" />
                            <div class="grid gap-1">
                                <Label
                                    for="workspace-gantt-start"
                                    class="text-xs"
                                >
                                    {t('Start date')}
                                </Label>
                                <Input
                                    id="workspace-gantt-start"
                                    type="date"
                                    name="start"
                                    value={rangeStart}
                                    required
                                    class="h-8 text-xs"
                                />
                                <InputError message={errors.start} />
                            </div>
                            <div class="grid gap-1">
                                <Label
                                    for="workspace-gantt-end"
                                    class="text-xs"
                                >
                                    {t('End date')}
                                </Label>
                                <Input
                                    id="workspace-gantt-end"
                                    type="date"
                                    name="end"
                                    value={rangeEnd}
                                    required
                                    class="h-8 text-xs"
                                />
                                <InputError message={errors.end} />
                            </div>
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={processing}
                            >
                                {t('Show')}
                            </Button>
                            <p
                                class="w-full text-xs text-muted-foreground sm:w-auto sm:self-center"
                            >
                                {t('Maximum one year')}
                            </p>
                        {/snippet}
                    </Form>
                </div>
            {:else}
                <Form
                    {...WorkspaceCalendarController.form(currentTeam.slug)}
                    class="flex items-end gap-2"
                >
                    {#snippet children({ processing })}
                        <input type="hidden" name="view" value={view} />
                        <div class="min-w-0 flex-1 sm:w-44">
                            <Label
                                for="workspace-calendar-date"
                                class="sr-only"
                            >
                                {t('Date')}
                            </Label>
                            <Input
                                id="workspace-calendar-date"
                                type="date"
                                name="date"
                                value={date}
                                required
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                        >
                            {t('Show')}
                        </Button>
                    {/snippet}
                </Form>
            {/if}
        </div>
    </div>

    <section
        class="overflow-hidden rounded-[1.25rem] border border-slate-200 bg-white shadow-[0_18px_55px_rgba(68,54,49,0.10)] dark:border-slate-800 dark:bg-slate-950"
    >
        <header
            class="flex items-center justify-between gap-3 border-b px-3 py-4 sm:px-5"
        >
            <Button variant="outline" size="sm" asChild>
                {#snippet children(buttonProps)}
                    <Link
                        {...buttonProps}
                        href={view === 'gantt'
                            ? ganttNavigationHref(previousDate)
                            : workspaceCalendarHref(view, previousDate)}
                        class={buttonProps.class}
                        aria-label={t('Previous')}
                    >
                        ←
                    </Link>
                {/snippet}
            </Button>
            <h2 class="text-center text-base font-bold sm:text-lg">
                {periodTitle}
            </h2>
            <Button variant="outline" size="sm" asChild>
                {#snippet children(buttonProps)}
                    <Link
                        {...buttonProps}
                        href={view === 'gantt'
                            ? ganttNavigationHref(nextDate)
                            : workspaceCalendarHref(view, nextDate)}
                        class={buttonProps.class}
                        aria-label={t('Next')}
                    >
                        →
                    </Link>
                {/snippet}
            </Button>
        </header>

        {#if view === 'month'}
            <div class="overflow-x-auto">
                <div class="min-w-[52rem]">
                    <div
                        class="grid grid-cols-7 border-b bg-muted/30 text-center text-xs font-semibold text-muted-foreground"
                    >
                        {#each weekdays as weekdayLabel (weekdayLabel)}
                            <div class="border-r px-2 py-2 last:border-r-0">
                                {weekdayLabel}
                            </div>
                        {/each}
                    </div>
                    <div class="grid grid-cols-7">
                        {#each monthDays as calendarDay (calendarDay.date)}
                            <div
                                class={`min-h-32 border-b border-r p-2 last:border-r-0 ${calendarDay.inCurrentMonth ? '' : 'bg-muted/20'}`}
                            >
                                <time
                                    datetime={calendarDay.date}
                                    class="ml-auto flex size-7 items-center justify-center rounded-full text-xs font-bold"
                                    class:bg-amber-100={calendarDay.date ===
                                        today}
                                    class:text-amber-950={calendarDay.date ===
                                        today}
                                    class:text-muted-foreground={!calendarDay.inCurrentMonth}
                                >
                                    {Number(calendarDay.date.slice(8, 10))}
                                </time>
                                <div class="mt-1 space-y-1">
                                    {#each calendarDay.events.slice(0, 3) as event (event.id)}
                                        <Link
                                            href={ScheduleEventController.index(
                                                [
                                                    currentTeam.slug,
                                                    event.project.id,
                                                ],
                                                {
                                                    query: {
                                                        month: calendarDay.date.slice(
                                                            0,
                                                            7,
                                                        ),
                                                    },
                                                },
                                            )}
                                            class={`block truncate rounded-md border px-2 py-1 text-xs font-semibold transition hover:brightness-95 ${eventColor(event.project.id)}`}
                                            title={`${event.title} · ${event.project.name}`}
                                        >
                                            {#if !event.allDay}
                                                <span class="mr-1 font-normal">
                                                    {timeFormatter.format(
                                                        new Date(
                                                            event.startsAt,
                                                        ),
                                                    )}
                                                </span>
                                            {/if}
                                            {event.title}
                                        </Link>
                                    {/each}
                                    {#if calendarDay.events.length > 3}
                                        <p
                                            class="px-1 text-xs text-muted-foreground"
                                        >
                                            +{calendarDay.events.length - 3}
                                        </p>
                                    {/if}
                                </div>
                            </div>
                        {/each}
                    </div>
                </div>
            </div>
        {:else if view === 'week'}
            <div class="grid divide-y md:grid-cols-7 md:divide-x md:divide-y-0">
                {#each weekDays as calendarDay (calendarDay.date)}
                    <div class="min-h-44 p-3 md:min-w-0">
                        <div
                            class="mb-3 flex items-center gap-2 md:flex-col md:text-center"
                        >
                            <span
                                class="text-xs font-semibold text-muted-foreground"
                            >
                                {weekdays[weekday(calendarDay.date)]}
                            </span>
                            <time
                                datetime={calendarDay.date}
                                class="flex size-8 items-center justify-center rounded-full text-sm font-bold"
                                class:bg-amber-100={calendarDay.date === today}
                                class:text-amber-950={calendarDay.date ===
                                    today}
                            >
                                {Number(calendarDay.date.slice(8, 10))}
                            </time>
                        </div>
                        <div class="space-y-2">
                            {#each calendarDay.events as event (event.id)}
                                <Link
                                    href={ScheduleEventController.index(
                                        [currentTeam.slug, event.project.id],
                                        {
                                            query: {
                                                month: calendarDay.date.slice(
                                                    0,
                                                    7,
                                                ),
                                            },
                                        },
                                    )}
                                    class={`block rounded-lg border p-2 text-xs transition hover:brightness-95 ${eventColor(event.project.id)}`}
                                >
                                    <span class="block font-bold">
                                        {event.title}
                                    </span>
                                    <span class="mt-1 block opacity-75">
                                        {formatEventTime(event)}
                                    </span>
                                    <span
                                        class="mt-1 block truncate opacity-75"
                                    >
                                        {event.project.name}
                                    </span>
                                </Link>
                            {/each}
                            {#if calendarDay.events.length === 0}
                                <p
                                    class="py-4 text-center text-xs text-muted-foreground"
                                >
                                    —
                                </p>
                            {/if}
                        </div>
                    </div>
                {/each}
            </div>
        {:else if view === 'gantt'}
            <div
                class="overflow-x-auto overscroll-x-contain bg-white dark:bg-slate-950"
                aria-label={t('Gantt')}
                use:centerGanttOnSelectedDate
            >
                <div
                    class="relative"
                    style={`min-width: ${ganttMinWidthRem}rem;`}
                >
                    {#if todayInGanttRange}
                        <div
                            class="pointer-events-none absolute inset-y-0 z-30 border-l-2 border-blue-500"
                            style={`left: ${todayOffset}%;`}
                            aria-label={t('Today')}
                        >
                            <span
                                class="absolute top-2 -translate-x-1/2 whitespace-nowrap rounded-md bg-blue-600 px-2.5 py-1 text-xs font-bold text-white shadow-sm"
                            >
                                {t('Today')} · {formatShortDate(today)}
                            </span>
                        </div>
                    {/if}

                    <div
                        class="relative h-12 overflow-hidden border-b border-slate-200 bg-slate-50 text-xs font-semibold text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400"
                    >
                        {#each ganttWeekendBands as weekend (weekend)}
                            <span
                                class="absolute inset-y-0 bg-slate-200/55 dark:bg-slate-800/70"
                                style={`left: ${ganttTickOffset(weekend)}%; width: ${100 / ganttDayCount}%;`}
                                aria-hidden="true"
                            ></span>
                        {/each}
                        {#each ganttTicks as tick (tick)}
                            <time
                                datetime={tick}
                                class="absolute inset-y-0 z-10 border-l border-slate-300 px-2 py-3.5 whitespace-nowrap dark:border-slate-700"
                                style={`left: ${ganttTickOffset(tick)}%;`}
                            >
                                {formatGanttTick(tick)}
                            </time>
                        {/each}
                    </div>

                    <div class="relative">
                        <div class="space-y-8 px-4 pt-6 pb-7">
                            <section class="space-y-3">
                                <div>
                                    <h3 class="text-sm font-bold">
                                        {t('Project periods')}
                                    </h3>
                                    <p class="text-xs text-muted-foreground">
                                        {t(
                                            'Projects require start and end dates to appear here.',
                                        )}
                                    </p>
                                </div>

                                {#each ganttProjects as project (project.id)}
                                    <div
                                        class="relative grid min-h-20 overflow-hidden border-y border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950"
                                        style={`grid-template-columns: repeat(${ganttDayCount}, minmax(0, 1fr));`}
                                    >
                                        <div
                                            class="pointer-events-none absolute inset-0"
                                            aria-hidden="true"
                                        >
                                            {#each ganttWeekendBands as weekend (weekend)}
                                                <span
                                                    class="absolute inset-y-0 bg-slate-100 dark:bg-slate-900"
                                                    style={`left: ${ganttTickOffset(weekend)}%; width: ${100 / ganttDayCount}%;`}
                                                ></span>
                                            {/each}
                                        </div>
                                        <div
                                            class="pointer-events-none absolute inset-0"
                                            aria-hidden="true"
                                        >
                                            {#each ganttTicks as tick (tick)}
                                                <span
                                                    class="absolute inset-y-0 border-l border-slate-200/70 dark:border-slate-800"
                                                    style={`left: ${ganttTickOffset(tick)}%;`}
                                                ></span>
                                            {/each}
                                        </div>
                                        <div
                                            class="pointer-events-none absolute inset-0 opacity-50"
                                            style={`background-image: linear-gradient(to right, var(--color-border) 1px, transparent 1px); background-size: calc(100% / ${ganttDayCount} * ${ganttGridStepDays}) 100%;`}
                                            aria-hidden="true"
                                        ></div>
                                        <Link
                                            href={showProject([
                                                currentTeam.slug,
                                                project.id,
                                            ])}
                                            class="relative z-10 m-2 flex min-w-0 flex-col items-start justify-center overflow-hidden rounded-lg border border-sky-200 bg-sky-100 px-3 py-2 text-sky-950 shadow-sm transition hover:-translate-y-0.5 hover:border-sky-300 dark:border-sky-800 dark:bg-sky-950 dark:text-sky-100"
                                            style={`grid-column: ${ganttGridColumn(project.startsOn, project.endsOn)};`}
                                            title={`${project.name} · ${formatLongDate(project.startsOn)} – ${formatLongDate(project.endsOn)}`}
                                        >
                                            <span
                                                class="w-full truncate font-bold"
                                            >
                                                {project.name}
                                            </span>
                                            <span
                                                class="w-full truncate text-xs font-medium text-sky-700 dark:text-sky-300"
                                            >
                                                {formatShortDate(
                                                    project.startsOn,
                                                )} – {formatShortDate(
                                                    project.endsOn,
                                                )}
                                            </span>
                                        </Link>
                                    </div>
                                {/each}

                                {#if ganttProjects.length === 0}
                                    <div
                                        class="rounded-xl border border-dashed py-8 text-center text-sm text-muted-foreground"
                                    >
                                        {t('No dated projects in this period')}
                                    </div>
                                {/if}
                            </section>

                            <section class="space-y-3">
                                <h3 class="text-sm font-bold">
                                    {t('Scheduled events')}
                                </h3>

                                {#each events as event (event.id)}
                                    <div
                                        class="relative grid min-h-14 overflow-hidden border-y border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950"
                                        style={`grid-template-columns: repeat(${ganttDayCount}, minmax(0, 1fr));`}
                                    >
                                        <div
                                            class="pointer-events-none absolute inset-0"
                                            aria-hidden="true"
                                        >
                                            {#each ganttWeekendBands as weekend (weekend)}
                                                <span
                                                    class="absolute inset-y-0 bg-slate-100 dark:bg-slate-900"
                                                    style={`left: ${ganttTickOffset(weekend)}%; width: ${100 / ganttDayCount}%;`}
                                                ></span>
                                            {/each}
                                        </div>
                                        <div
                                            class="pointer-events-none absolute inset-0"
                                            aria-hidden="true"
                                        >
                                            {#each ganttTicks as tick (tick)}
                                                <span
                                                    class="absolute inset-y-0 border-l border-slate-200/70 dark:border-slate-800"
                                                    style={`left: ${ganttTickOffset(tick)}%;`}
                                                ></span>
                                            {/each}
                                        </div>
                                        <div
                                            class="pointer-events-none absolute inset-0 opacity-50"
                                            style={`background-image: linear-gradient(to right, var(--color-border) 1px, transparent 1px); background-size: calc(100% / ${ganttDayCount} * ${ganttGridStepDays}) 100%;`}
                                            aria-hidden="true"
                                        ></div>
                                        <Link
                                            href={ScheduleEventController.index(
                                                [
                                                    currentTeam.slug,
                                                    event.project.id,
                                                ],
                                                {
                                                    query: {
                                                        month: dateKeyInTokyo(
                                                            event.startsAt,
                                                        ).slice(0, 7),
                                                    },
                                                },
                                            )}
                                            class="relative z-10 mx-px my-1 flex min-w-0 items-center overflow-visible rounded-lg border border-sky-200 bg-sky-100 px-2 py-2 text-sm font-bold text-sky-950 shadow-sm transition hover:border-sky-300 hover:brightness-95 dark:border-sky-800 dark:bg-sky-950 dark:text-sky-100"
                                            style={`grid-column: ${ganttGridColumn(dateKeyInTokyo(event.startsAt), dateKeyInTokyo(event.endsAt, true))};`}
                                            title={`${event.title} · ${event.project.name}`}
                                        >
                                            <span class="whitespace-nowrap">
                                                {event.title} · {event.project
                                                    .name}
                                            </span>
                                        </Link>
                                    </div>
                                {/each}

                                {#if events.length === 0}
                                    <div
                                        class="rounded-xl border border-dashed py-8 text-center text-sm text-muted-foreground"
                                    >
                                        {t(
                                            'No scheduled events in this period',
                                        )}
                                    </div>
                                {/if}
                            </section>
                        </div>
                    </div>
                </div>
            </div>
        {:else}
            <div class="divide-y">
                {#each events as event (event.id)}
                    <Link
                        href={ScheduleEventController.index(
                            [currentTeam.slug, event.project.id],
                            { query: { month: date.slice(0, 7) } },
                        )}
                        class="group grid gap-3 px-4 py-4 transition hover:bg-muted/40 sm:grid-cols-[8rem_1fr] sm:px-6"
                    >
                        <time class="text-sm font-bold text-muted-foreground">
                            {formatEventTime(event)}
                        </time>
                        <span class="min-w-0">
                            <span class="block font-bold group-hover:underline">
                                {event.title}
                            </span>
                            <span
                                class="mt-1 block truncate text-sm text-muted-foreground"
                            >
                                {event.project.name}
                            </span>
                        </span>
                    </Link>
                {/each}
                {#if events.length === 0}
                    <div class="py-16 text-center text-muted-foreground">
                        <CalendarDays class="mx-auto mb-3 size-8" />
                        <p class="text-sm">{t('No events in this period')}</p>
                    </div>
                {/if}
            </div>
        {/if}
    </section>

    <Card>
        <CardContent class="space-y-5 pt-6">
            <div>
                <h2 class="font-semibold">{t('Calendar subscriptions')}</h2>
                <p class="text-sm text-muted-foreground">
                    {t(
                        'Subscribe from an external calendar with a read-only URL.',
                    )}
                </p>
            </div>

            <Form
                {...CalendarSubscriptionController.store.form(currentTeam.slug)}
                class="flex flex-col gap-3 sm:flex-row"
            >
                {#snippet children({ errors, processing })}
                    <div class="min-w-0 flex-1">
                        <Label for="subscription-name" class="sr-only">
                            {t('Subscription name')}
                        </Label>
                        <Input
                            id="subscription-name"
                            name="name"
                            placeholder={t('Subscription name')}
                            maxlength={100}
                            required
                        />
                        {#if errors.name}
                            <p class="mt-1 text-sm text-destructive">
                                {errors.name}
                            </p>
                        {/if}
                    </div>
                    <Button
                        type="submit"
                        disabled={processing}
                        class="w-full sm:w-auto"
                    >
                        {t('Create subscription')}
                    </Button>
                {/snippet}
            </Form>

            {#if subscriptions.length > 0}
                <div class="space-y-3">
                    {#each subscriptions as subscription (subscription.id)}
                        <div class="space-y-3 rounded-lg border p-3">
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <p class="font-medium">{subscription.name}</p>
                                {#if subscription.revoked}
                                    <Badge variant="secondary">
                                        {t('Revoked')}
                                    </Badge>
                                {/if}
                            </div>

                            {#if subscription.url}
                                <div class="flex min-w-0 gap-2">
                                    <Input
                                        value={subscription.url}
                                        readonly
                                        aria-label={t('Subscription URL')}
                                        class="min-w-0 font-mono text-xs"
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        aria-label={t('Copy subscription URL')}
                                        onclick={() =>
                                            copySubscriptionUrl(subscription)}
                                    >
                                        <Copy class="size-4" />
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        asChild
                                    >
                                        {#snippet children(buttonProps)}
                                            <a
                                                {...buttonProps}
                                                href={subscription.url}
                                                class={buttonProps.class}
                                                aria-label={t(
                                                    'Open subscription URL',
                                                )}
                                            >
                                                <ExternalLink class="size-4" />
                                            </a>
                                        {/snippet}
                                    </Button>
                                </div>
                                {#if copiedSubscriptionId === subscription.id}
                                    <p class="text-xs text-muted-foreground">
                                        {t('Copied')}
                                    </p>
                                {/if}

                                <Form
                                    {...CalendarSubscriptionController.destroy.form(
                                        [currentTeam.slug, subscription.id],
                                    )}
                                    class="flex justify-end"
                                >
                                    {#snippet children({ processing })}
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            <Trash2 class="size-4" />
                                            {t('Revoke')}
                                        </Button>
                                    {/snippet}
                                </Form>
                            {/if}
                        </div>
                    {/each}
                </div>
            {:else}
                <p class="text-sm text-muted-foreground">
                    {t('No calendar subscriptions')}
                </p>
            {/if}
        </CardContent>
    </Card>
</div>
