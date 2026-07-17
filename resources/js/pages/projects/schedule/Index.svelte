<script module lang="ts">
    import ScheduleEventController from '@/actions/App/Http/Controllers/ScheduleEventController';
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
                title: props.currentTeam.menuNames.calendar,
                href: ScheduleEventController.index([
                    props.currentTeam.slug,
                    props.project.id,
                ]),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import Video from 'lucide-svelte/icons/video';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import RichTextContent from '@/components/RichTextContent.svelte';
    import RichTextEditor from '@/components/RichTextEditor.svelte';
    import { Button } from '@/components/ui/button';
    import { Card, CardContent } from '@/components/ui/card';
    import {
        Dialog,
        DialogClose,
        DialogContent,
        DialogFooter,
        DialogTitle,
        DialogTrigger,
    } from '@/components/ui/dialog';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
    import type { ProjectScheduleEvent, ScheduleGridDay } from '@/types';

    type ScheduleView = 'day' | 'week' | 'month' | 'gantt';

    let {
        currentTeam,
        project,
        view,
        date,
        today,
        rangeStart,
        rangeEnd,
        previousDate,
        nextDate,
        month,
        events,
        weeks,
    }: {
        currentTeam: Team;
        project: ProjectTool;
        view: ScheduleView;
        date: string;
        today: string;
        rangeStart: string;
        rangeEnd: string;
        previousDate: string;
        nextDate: string;
        month: string;
        events: ProjectScheduleEvent[];
        weeks: ScheduleGridDay[][] | null;
    } = $props();

    const routeArguments = $derived([currentTeam.slug, project.id] as [
        string,
        number,
    ]);
    const weekdayLabels = [
        t('Sun'),
        t('Mon'),
        t('Tue'),
        t('Wed'),
        t('Thu'),
        t('Fri'),
        t('Sat'),
    ];

    const views: { value: ScheduleView; label: string }[] = [
        { value: 'day', label: 'Day' },
        { value: 'week', label: 'Week' },
        { value: 'month', label: 'Month' },
        { value: 'gantt', label: 'Gantt' },
    ];
    const viewHref = (targetView: ScheduleView, targetDate: string): string =>
        ScheduleEventController.index(routeArguments, {
            query: { view: targetView, date: targetDate },
        }).url;

    const addDays = (value: string, amount: number): string => {
        const [year, monthNumber, day] = value.split('-').map(Number);
        const shifted = new Date(Date.UTC(year, monthNumber - 1, day + amount));

        return `${shifted.getUTCFullYear()}-${String(shifted.getUTCMonth() + 1).padStart(2, '0')}-${String(shifted.getUTCDate()).padStart(2, '0')}`;
    };
    const epochDay = (value: string): number => {
        const [year, monthNumber, day] = value.split('-').map(Number);

        return Date.UTC(year, monthNumber - 1, day) / 86_400_000;
    };
    const weekdayOf = (value: string): number => {
        const [year, monthNumber, day] = value.split('-').map(Number);

        return new Date(Date.UTC(year, monthNumber - 1, day)).getUTCDay();
    };
    const eventsForDate = (value: string): ProjectScheduleEvent[] =>
        events.filter((event) => event.startsAt.slice(0, 10) === value);

    const shortDateFormatter = new Intl.DateTimeFormat('ja-JP', {
        month: 'numeric',
        day: 'numeric',
        weekday: 'short',
    });
    const longDateFormatter = new Intl.DateTimeFormat('ja-JP', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        weekday: 'short',
    });
    const formatShortDate = (value: string): string =>
        shortDateFormatter.format(new Date(`${value}T00:00:00`));
    const formatLongDate = (value: string): string =>
        longDateFormatter.format(new Date(`${value}T00:00:00`));

    const weekDays = $derived.by(() =>
        Array.from({ length: 7 }, (_, index) => {
            const dateKey = addDays(rangeStart, index);

            return { date: dateKey, events: eventsForDate(dateKey) };
        }),
    );
    const ganttWeeks = $derived(
        Array.from({ length: 12 }, (_, index) =>
            addDays(rangeStart, index * 7),
        ),
    );
    const ganttDayCount = $derived(
        epochDay(rangeEnd) - epochDay(rangeStart) + 1,
    );
    const todayInGanttRange = $derived(
        today >= rangeStart && today <= rangeEnd,
    );
    const todayOffset = $derived(
        ((epochDay(today) - epochDay(rangeStart) + 0.5) / ganttDayCount) * 100,
    );
    const ganttGridColumn = (startsAt: string, endsAt: string): string => {
        const firstDay = Math.max(
            0,
            epochDay(startsAt.slice(0, 10)) - epochDay(rangeStart),
        );
        const lastDay = Math.min(
            ganttDayCount - 1,
            epochDay(endsAt.slice(0, 10)) - epochDay(rangeStart),
        );

        return `${firstDay + 1} / ${lastDay + 2}`;
    };

    const scrollToEvent = (id: number): void => {
        requestAnimationFrame(() => {
            document
                .getElementById(`event-${id}`)
                ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    };
    const formatEventDate = (value: string, allDay: boolean): string =>
        new Intl.DateTimeFormat(
            'ja-JP',
            allDay
                ? { dateStyle: 'long' }
                : { dateStyle: 'medium', timeStyle: 'short' },
        ).format(new Date(value));
    const confirmDelete = (): boolean =>
        window.confirm(t('Are you sure you want to delete this?'));
    const recurrenceLabel = (
        recurrence: ProjectScheduleEvent['recurrence'],
    ): string => {
        switch (recurrence) {
            case 'daily':
                return t('Repeats daily');
            case 'weekly':
                return t('Repeats weekly');
            case 'monthly':
                return t('Repeats monthly');
            default:
                return '';
        }
    };
    let repeatValue = $state('');

    let newEventOpen = $state(false);
    let newEventFormKey = $state(0);
    let showLinkField = $state(false);
    let showRepeatField = $state(false);
    let showNotesField = $state(false);
    const handleNewEventOpenChange = (value: boolean): void => {
        newEventOpen = value;

        if (!value) {
            newEventFormKey++;
            showLinkField = false;
            showRepeatField = false;
            showNotesField = false;
            repeatValue = '';
        }
    };

    const monthTitle = $derived(
        new Intl.DateTimeFormat('ja-JP', {
            year: 'numeric',
            month: 'long',
        }).format(new Date(`${month}-01T00:00:00`)),
    );
    const monthAbbrev = (value: string): string =>
        new Intl.DateTimeFormat('ja-JP', { month: 'short' }).format(
            new Date(value),
        );
    const dayNumber = (value: string): number => new Date(value).getDate();
    const weekdayAbbrev = (value: string): string =>
        new Intl.DateTimeFormat('ja-JP', { weekday: 'short' }).format(
            new Date(value),
        );
    const todayKey = (() => {
        const now = new Date();

        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    })();
    const isEventToday = (value: string): boolean =>
        value.slice(0, 10) === todayKey;

    const periodTitle = $derived(
        view === 'day'
            ? formatLongDate(date)
            : view === 'week' || view === 'gantt'
              ? `${formatShortDate(rangeStart)} – ${formatShortDate(rangeEnd)}`
              : monthTitle,
    );
</script>

<AppHead title={currentTeam.menuNames.calendar} />

<div class="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
    <div class="space-y-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                {currentTeam.menuNames.calendar}
            </h1>
            <p class="text-sm text-muted-foreground">{project.name}</p>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex overflow-hidden rounded-md border">
                    {#each views as scheduleView, index (scheduleView.value)}
                        <Link
                            href={viewHref(scheduleView.value, date)}
                            class={`px-3 py-1.5 text-sm font-medium ${index > 0 ? 'border-l' : ''} ${
                                view === scheduleView.value
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-background hover:bg-muted'
                            }`}
                            aria-current={view === scheduleView.value
                                ? 'page'
                                : undefined}
                        >
                            {t(scheduleView.label)}
                        </Link>
                    {/each}
                </div>
                <Dialog
                    open={newEventOpen}
                    onOpenChange={handleNewEventOpenChange}
                >
                    <DialogTrigger asChild>
                        {#snippet children(triggerProps)}
                            <Button onclick={triggerProps.onClick}
                                >+ {t('New event')}</Button
                            >
                        {/snippet}
                    </DialogTrigger>
                    <DialogContent class="max-w-xl">
                        {#key newEventFormKey}
                            <Form
                                {...ScheduleEventController.store.form(
                                    routeArguments,
                                )}
                                onSuccess={() => (newEventOpen = false)}
                                class="space-y-4"
                            >
                                {#snippet children({ errors, processing })}
                                    <DialogTitle>{t('New event')}</DialogTitle>
                                    <div class="grid gap-2">
                                        <Label for="event-title" class="sr-only"
                                            >{t('Title')}</Label
                                        >
                                        <Input
                                            id="event-title"
                                            name="title"
                                            maxlength={255}
                                            placeholder={t(
                                                'Type the name of the event…',
                                            )}
                                            required
                                        />
                                        <InputError message={errors.title} />
                                    </div>
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <div class="grid gap-2">
                                            <Label for="event-start"
                                                >{t('Starts at')}</Label
                                            >
                                            <Input
                                                id="event-start"
                                                type="datetime-local"
                                                name="starts_at"
                                                required
                                            />
                                            <InputError
                                                message={errors.starts_at}
                                            />
                                        </div>
                                        <div class="grid gap-2">
                                            <Label for="event-end"
                                                >{t('Ends at')}</Label
                                            >
                                            <Input
                                                id="event-end"
                                                type="datetime-local"
                                                name="ends_at"
                                                required
                                            />
                                            <InputError
                                                message={errors.ends_at}
                                            />
                                        </div>
                                    </div>
                                    <label
                                        class="flex min-h-11 items-center gap-3 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            name="all_day"
                                            value="1"
                                            class="size-4 accent-primary"
                                        />
                                        {t('All day')}
                                    </label>

                                    <div class="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onclick={() =>
                                                (showLinkField =
                                                    !showLinkField)}
                                            >{t('Link')}</Button
                                        >
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onclick={() =>
                                                (showRepeatField =
                                                    !showRepeatField)}
                                            >{t('Repeat')}</Button
                                        >
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onclick={() =>
                                                (showNotesField =
                                                    !showNotesField)}
                                            >{t('Notes')}</Button
                                        >
                                    </div>

                                    {#if showLinkField}
                                        <div class="grid gap-2">
                                            <Label for="event-meeting-url"
                                                >{t('Meeting URL')}</Label
                                            >
                                            <Input
                                                id="event-meeting-url"
                                                type="url"
                                                name="meeting_url"
                                                maxlength={2048}
                                                placeholder="https://"
                                            />
                                            <InputError
                                                message={errors.meeting_url}
                                            />
                                        </div>
                                    {/if}

                                    {#if showRepeatField}
                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <div class="grid gap-2">
                                                <Label for="event-recurrence"
                                                    >{t('Repeat')}</Label
                                                >
                                                <select
                                                    id="event-recurrence"
                                                    name="recurrence"
                                                    bind:value={repeatValue}
                                                    class="border-input h-9 rounded-md border bg-background px-3 text-sm"
                                                >
                                                    <option value=""
                                                        >{t(
                                                            'Does not repeat',
                                                        )}</option
                                                    >
                                                    <option value="daily"
                                                        >{t('Daily')}</option
                                                    >
                                                    <option value="weekly"
                                                        >{t('Weekly')}</option
                                                    >
                                                    <option value="monthly"
                                                        >{t('Monthly')}</option
                                                    >
                                                </select>
                                                <InputError
                                                    message={errors.recurrence}
                                                />
                                            </div>
                                            {#if repeatValue !== ''}
                                                <div class="grid gap-2">
                                                    <Label
                                                        for="event-recurrence-until"
                                                        >{t(
                                                            'Repeat until',
                                                        )}</Label
                                                    >
                                                    <Input
                                                        id="event-recurrence-until"
                                                        type="date"
                                                        name="recurrence_until"
                                                        required
                                                    />
                                                    <InputError
                                                        message={errors.recurrence_until}
                                                    />
                                                </div>
                                            {/if}
                                        </div>
                                    {/if}

                                    {#if showNotesField}
                                        <div class="grid gap-2">
                                            <Label for="event-details"
                                                >{t('Notes')}</Label
                                            >
                                            <RichTextEditor
                                                id="event-details"
                                                name="details"
                                                ariaLabel={t('Notes')}
                                            />
                                        </div>
                                    {/if}

                                    <DialogFooter class="gap-2">
                                        <DialogClose asChild>
                                            {#snippet children(closeProps)}
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                    onclick={closeProps.onClick}
                                                    >{t('Cancel')}</Button
                                                >
                                            {/snippet}
                                        </DialogClose>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            >{t('Post this event')}</Button
                                        >
                                    </DialogFooter>
                                {/snippet}
                            </Form>
                        {/key}
                    </DialogContent>
                </Dialog>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Link
                    href={viewHref(view, previousDate)}
                    aria-label={t('Previous')}
                    class="inline-flex size-9 items-center justify-center rounded-md border hover:bg-muted"
                    >←</Link
                >
                <Form
                    {...ScheduleEventController.index.form(routeArguments)}
                    class="flex gap-2"
                >
                    {#snippet children({ processing })}
                        <input type="hidden" name="view" value={view} />
                        <div>
                            <Label for="schedule-date" class="sr-only"
                                >{t('Date')}</Label
                            >
                            <Input
                                id="schedule-date"
                                type="date"
                                name="date"
                                value={date}
                                required
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}>{t('Show')}</Button
                        >
                    {/snippet}
                </Form>
                <Link
                    href={viewHref(view, nextDate)}
                    aria-label={t('Next')}
                    class="inline-flex size-9 items-center justify-center rounded-md border hover:bg-muted"
                    >→</Link
                >
            </div>
        </div>
        <h2 class="text-center text-base font-bold sm:text-lg">
            {periodTitle}
        </h2>
    </div>

    {#if view === 'month' && weeks}
        <div class="overflow-hidden rounded-lg border">
            <div class="grid grid-cols-7 border-b bg-muted/40">
                {#each weekdayLabels as label (label)}
                    <div
                        class="p-2 text-center text-xs font-medium text-muted-foreground"
                    >
                        {label}
                    </div>
                {/each}
            </div>
            {#each weeks as week, weekIndex (weekIndex)}
                <div class="grid grid-cols-7 {weekIndex > 0 ? 'border-t' : ''}">
                    {#each week as day (day.date)}
                        <div
                            class="min-h-24 border-l p-1.5 first:border-l-0 sm:min-h-32 sm:p-2 {day.inCurrentMonth
                                ? ''
                                : 'bg-muted/20 text-muted-foreground'}"
                        >
                            <p class="text-xs">
                                {#if day.monthLabel}<span
                                        class="mr-1 rounded bg-muted px-1 py-0.5 font-medium"
                                        >{day.monthLabel}</span
                                    >{/if}<span
                                    class={day.isToday
                                        ? 'rounded-full bg-primary px-1.5 py-0.5 font-semibold text-primary-foreground'
                                        : ''}>{day.day}</span
                                >
                            </p>
                            <div class="mt-1 space-y-1">
                                {#each day.events.slice(0, 3) as event (event.id)}
                                    <button
                                        type="button"
                                        onclick={() => scrollToEvent(event.id)}
                                        class="block w-full truncate rounded bg-primary/10 px-1 py-0.5 text-left text-xs hover:bg-primary/20"
                                        title={event.title}
                                    >
                                        <span class="font-semibold"
                                            >{event.title}</span
                                        >
                                        {#if !event.allDay}<br /><span
                                                class="text-muted-foreground"
                                                >{new Intl.DateTimeFormat(
                                                    'ja-JP',
                                                    { timeStyle: 'short' },
                                                ).format(
                                                    new Date(event.startsAt),
                                                )}</span
                                            >{/if}
                                    </button>
                                {/each}
                                {#if day.events.length > 3}
                                    <Link
                                        href={viewHref('day', day.date)}
                                        class="block text-xs text-muted-foreground hover:underline"
                                    >
                                        {t('+:count more', {
                                            count: String(
                                                day.events.length - 3,
                                            ),
                                        })}
                                    </Link>
                                {/if}
                            </div>
                        </div>
                    {/each}
                </div>
            {/each}
        </div>
    {:else if view === 'week'}
        <div class="overflow-hidden rounded-lg border">
            <div class="grid divide-y md:grid-cols-7 md:divide-x md:divide-y-0">
                {#each weekDays as day (day.date)}
                    <div class="min-h-32 p-2">
                        <div
                            class="mb-2 flex items-center gap-2 md:flex-col md:text-center"
                        >
                            <span
                                class="text-xs font-semibold text-muted-foreground"
                            >
                                {weekdayLabels[weekdayOf(day.date)]}
                            </span>
                            <span
                                class="flex size-7 items-center justify-center rounded-full text-sm font-bold {day.date ===
                                today
                                    ? 'bg-primary text-primary-foreground'
                                    : ''}"
                            >
                                {Number(day.date.slice(8, 10))}
                            </span>
                        </div>
                        <div class="space-y-1">
                            {#each day.events as event (event.id)}
                                <button
                                    type="button"
                                    onclick={() => scrollToEvent(event.id)}
                                    class="block w-full truncate rounded bg-primary/10 px-1 py-0.5 text-left text-xs hover:bg-primary/20"
                                    title={event.title}
                                >
                                    {event.title}
                                </button>
                            {/each}
                        </div>
                    </div>
                {/each}
            </div>
        </div>
    {:else if view === 'gantt'}
        <div class="overflow-x-auto overscroll-x-contain rounded-lg border">
            <div class="min-w-[82rem]">
                <div
                    class="grid grid-cols-12 border-b bg-muted/40 text-xs font-semibold text-muted-foreground"
                >
                    {#each ganttWeeks as weekStart (weekStart)}
                        <div class="border-r px-3 py-3 last:border-r-0">
                            {formatShortDate(weekStart)}
                        </div>
                    {/each}
                </div>
                <div class="relative">
                    {#if todayInGanttRange}
                        <div
                            class="pointer-events-none absolute inset-y-0 z-20 border-l-2 border-primary"
                            style={`left: ${todayOffset}%;`}
                        >
                            <span
                                class="absolute top-2 -translate-x-1/2 whitespace-nowrap rounded-md bg-primary px-2 py-1 text-xs font-bold text-primary-foreground shadow-sm"
                            >
                                {t('Today')} · {formatShortDate(today)}
                            </span>
                        </div>
                    {/if}
                    <div
                        class={`space-y-2 p-4 ${todayInGanttRange ? 'pt-12' : ''}`}
                    >
                        {#each events as event (event.id)}
                            <div
                                class="relative grid min-h-14 grid-cols-[repeat(84,minmax(0,1fr))] overflow-hidden border-y first:border-t-0 last:border-b-0"
                            >
                                <div
                                    class="pointer-events-none absolute inset-0 grid grid-cols-12"
                                    aria-hidden="true"
                                >
                                    {#each ganttWeeks as weekStart (weekStart)}
                                        <span class="border-r last:border-r-0"
                                        ></span>
                                    {/each}
                                </div>
                                <div
                                    class="pointer-events-none absolute inset-0 opacity-30"
                                    style={`background-image: linear-gradient(to right, var(--color-border) 1px, transparent 1px); background-size: calc(100% / ${ganttDayCount}) 100%;`}
                                    aria-hidden="true"
                                ></div>
                                <Link
                                    href={viewHref(
                                        'day',
                                        event.startsAt.slice(0, 10),
                                    )}
                                    class="relative z-10 mx-px my-1 flex min-w-0 items-center overflow-visible rounded-lg border bg-primary/10 px-2 py-2 text-left text-sm font-bold shadow-sm transition hover:bg-primary/20"
                                    style={`grid-column: ${ganttGridColumn(event.startsAt, event.endsAt)};`}
                                    title={event.title}
                                >
                                    <span class="whitespace-nowrap"
                                        >{event.title}</span
                                    >
                                </Link>
                            </div>
                        {/each}
                        {#if events.length === 0}
                            <div
                                class="rounded-lg border border-dashed py-8 text-center text-sm text-muted-foreground"
                            >
                                {t('No scheduled events in this period')}
                            </div>
                        {/if}
                    </div>
                </div>
            </div>
        </div>
    {/if}

    {#if view === 'gantt'}
        <!-- Gantt already renders each event as a timeline bar above. -->
    {:else if events.length === 0}
        <Card
            ><CardContent
                class="py-10 text-center text-sm text-muted-foreground"
                >{t('No events in this period')}</CardContent
            ></Card
        >
    {:else}
        <div class="overflow-hidden rounded-lg border">
            <div class="border-b bg-muted/40 px-4 py-2 text-sm font-semibold">
                {periodTitle}
            </div>
            <div class="divide-y">
                {#each events as event (event.id)}
                    <div id={`event-${event.id}`} class="flex gap-4 p-4">
                        <div
                            class="flex w-14 shrink-0 flex-col items-center overflow-hidden rounded-md border"
                        >
                            <div
                                class="w-full bg-primary py-0.5 text-center text-[10px] font-semibold text-primary-foreground"
                            >
                                {monthAbbrev(event.startsAt)}
                            </div>
                            <div class="py-1 text-lg leading-none font-bold">
                                {dayNumber(event.startsAt)}
                            </div>
                            <div class="pb-1 text-[10px] text-muted-foreground">
                                {weekdayAbbrev(event.startsAt)}
                            </div>
                            {#if isEventToday(event.startsAt)}
                                <div
                                    class="w-full bg-muted py-0.5 text-center text-[9px] font-semibold"
                                >
                                    {t('TODAY')}
                                </div>
                            {/if}
                        </div>
                        <div class="min-w-0 flex-1 space-y-3">
                            <div>
                                <p class="font-semibold break-words">
                                    {event.title}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {formatEventDate(
                                        event.startsAt,
                                        event.allDay,
                                    )}
                                    {#if !event.allDay}– {formatEventDate(
                                            event.endsAt,
                                            false,
                                        )}{/if}
                                </p>
                                {#if event.recurrence}
                                    <p class="text-xs text-muted-foreground">
                                        {recurrenceLabel(event.recurrence)}
                                    </p>
                                {/if}
                            </div>
                            {#if event.details}
                                <RichTextContent
                                    content={event.details}
                                    class="text-sm"
                                />
                            {/if}
                            {#if event.meetingUrl}
                                <Button variant="outline" size="sm" asChild>
                                    {#snippet children(buttonProps)}
                                        <a
                                            {...buttonProps}
                                            href={event.meetingUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class={buttonProps.class}
                                        >
                                            <Video class="size-4" />
                                            {t('Join meeting')}
                                        </a>
                                    {/snippet}
                                </Button>
                            {/if}
                            <p class="text-xs text-muted-foreground">
                                {t('Created by :name', {
                                    name:
                                        event.creatorName || t('Deleted user'),
                                })}
                            </p>

                            {#if event.canEdit}
                                <details class="rounded-lg border p-3">
                                    <summary
                                        class="cursor-pointer text-sm font-medium"
                                        >{t('Edit event')}</summary
                                    >
                                    <Form
                                        {...ScheduleEventController.update.form(
                                            [
                                                currentTeam.slug,
                                                project.id,
                                                event.id,
                                            ],
                                        )}
                                        class="mt-4 space-y-3"
                                    >
                                        {#snippet children({
                                            errors,
                                            processing,
                                        })}
                                            <Input
                                                name="title"
                                                value={event.title}
                                                maxlength={255}
                                                required
                                            />
                                            <InputError
                                                message={errors.title}
                                            />
                                            <RichTextEditor
                                                id={`event-details-${event.id}`}
                                                name="details"
                                                value={event.details}
                                                ariaLabel={t('Details')}
                                            />
                                            <div class="grid gap-2">
                                                <Label
                                                    for={`event-meeting-url-${event.id}`}
                                                    >{t('Meeting URL')}</Label
                                                >
                                                <Input
                                                    id={`event-meeting-url-${event.id}`}
                                                    type="url"
                                                    name="meeting_url"
                                                    maxlength={2048}
                                                    value={event.meetingUrl ||
                                                        ''}
                                                    placeholder="https://"
                                                />
                                                <InputError
                                                    message={errors.meeting_url}
                                                />
                                            </div>
                                            <div
                                                class="grid gap-3 sm:grid-cols-2"
                                            >
                                                <Input
                                                    type="datetime-local"
                                                    name="starts_at"
                                                    value={event.startsAt}
                                                    required
                                                    aria-label={t('Starts at')}
                                                />
                                                <Input
                                                    type="datetime-local"
                                                    name="ends_at"
                                                    value={event.endsAt}
                                                    required
                                                    aria-label={t('Ends at')}
                                                />
                                            </div>
                                            <InputError
                                                message={errors.starts_at ||
                                                    errors.ends_at}
                                            />
                                            <label
                                                class="flex min-h-11 items-center gap-3 text-sm"
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="all_day"
                                                    value="1"
                                                    checked={event.allDay}
                                                    class="size-4 accent-primary"
                                                />
                                                {t('All day')}
                                            </label>
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={processing}
                                                >{t('Save')}</Button
                                            >
                                        {/snippet}
                                    </Form>
                                    <Form
                                        {...ScheduleEventController.destroy.form(
                                            [
                                                currentTeam.slug,
                                                project.id,
                                                event.id,
                                            ],
                                        )}
                                        onBefore={confirmDelete}
                                        class="mt-2"
                                    >
                                        {#snippet children({ processing })}
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
                        </div>
                    </div>
                {/each}
            </div>
        </div>
    {/if}
</div>
