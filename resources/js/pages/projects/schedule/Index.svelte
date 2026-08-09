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
    import { onMount } from 'svelte';
    import AttachmentController from '@/actions/App/Http/Controllers/AttachmentController';
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
    } from '@/components/ui/dialog';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { currentLocale, t } from '@/lib/i18n';
    import {
        defaultScheduleEventTimes,
        halfHourOptions,
        scheduleEventDaySegment,
        scheduleEventEnd,
        scheduleEventLastDisplayDate,
    } from '@/lib/schedule-event-time';
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
        ganttUsesProjectDates,
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
        ganttUsesProjectDates: boolean;
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
        events.filter(
            (event) =>
                scheduleEventDaySegment(event.startsAt, event.endsAt, value) !==
                null,
        );

    const shortDateFormatter = new Intl.DateTimeFormat(currentLocale(), {
        month: 'numeric',
        day: 'numeric',
        weekday: 'short',
    });
    const longDateFormatter = new Intl.DateTimeFormat(currentLocale(), {
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
    const timelineDays = $derived(
        view === 'day' ? [{ date, events: eventsForDate(date) }] : weekDays,
    );
    const ganttDayCount = $derived(
        epochDay(rangeEnd) - epochDay(rangeStart) + 1,
    );
    const startOfNextMonth = (value: string): string => {
        const [year, monthNumber] = value.split('-').map(Number);

        return new Date(Date.UTC(year, monthNumber, 1))
            .toISOString()
            .slice(0, 10);
    };
    const ganttTicks = $derived.by(() => {
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
    const ganttMinWidthRem = $derived(
        Math.max(48, Math.min(120, ganttDayCount)),
    );
    const ganttGridStepDays = $derived(ganttDayCount > 180 ? 7 : 1);
    const ganttTickOffset = (value: string): number =>
        ((epochDay(value) - epochDay(rangeStart)) / ganttDayCount) * 100;
    const ganttMonthFormatter = new Intl.DateTimeFormat(currentLocale(), {
        year: 'numeric',
        month: 'short',
    });
    const formatGanttTick = (value: string): string =>
        ganttDayCount > 120
            ? ganttMonthFormatter.format(new Date(`${value}T00:00:00`))
            : formatShortDate(value);
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
            epochDay(scheduleEventLastDisplayDate(endsAt)) -
                epochDay(rangeStart),
        );

        return `${firstDay + 1} / ${lastDay + 2}`;
    };

    const formatEventDate = (value: string, allDay: boolean): string =>
        new Intl.DateTimeFormat(
            currentLocale(),
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
    let newEventStartDate = $state('');
    let newEventStartTime = $state('');
    let newEventEndDate = $state('');
    let newEventEndTime = $state('');
    let showLinkField = $state(false);
    let showRepeatField = $state(false);
    let showNotesField = $state(false);
    const setNewEventEndFromStart = (): void => {
        if (!newEventStartDate || !newEventStartTime) {
            return;
        }

        const end = scheduleEventEnd(newEventStartDate, newEventStartTime);

        if (!end) {
            return;
        }

        newEventEndDate = end.endDate;
        newEventEndTime = end.endTime;
    };
    const openNewEventForDate = (selectedDate: string): void => {
        const times = defaultScheduleEventTimes(selectedDate);

        newEventStartDate = times.startDate;
        newEventStartTime = times.startTime;
        newEventEndDate = times.endDate;
        newEventEndTime = times.endTime;
        newEventOpen = true;
    };
    const openNewEventAt = (selectedDate: string, startTime: string): void => {
        const end = scheduleEventEnd(selectedDate, startTime);

        if (!end) {
            return;
        }

        newEventStartDate = selectedDate;
        newEventStartTime = startTime;
        newEventEndDate = end.endDate;
        newEventEndTime = end.endTime;
        newEventOpen = true;
    };
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
        new Intl.DateTimeFormat(currentLocale(), {
            year: 'numeric',
            month: 'long',
        }).format(new Date(`${month}-01T00:00:00`)),
    );
    const monthAbbrev = (value: string): string =>
        new Intl.DateTimeFormat(currentLocale(), { month: 'short' }).format(
            new Date(value),
        );
    const dayNumber = (value: string): number => new Date(value).getDate();
    const weekdayAbbrev = (value: string): string =>
        new Intl.DateTimeFormat(currentLocale(), { weekday: 'short' }).format(
            new Date(value),
        );
    const hourHeight = 64;
    const timelineHeight = hourHeight * 24;
    let currentNow = $state(new Date());
    const currentDateKey = $derived(
        `${currentNow.getFullYear()}-${String(currentNow.getMonth() + 1).padStart(2, '0')}-${String(currentNow.getDate()).padStart(2, '0')}`,
    );
    const currentMinute = $derived(
        currentNow.getHours() * 60 +
            currentNow.getMinutes() +
            currentNow.getSeconds() / 60,
    );
    const currentTimeTop = $derived((currentMinute / 60) * hourHeight);
    const currentTimeLabel = $derived(
        `${String(currentNow.getHours()).padStart(2, '0')}:${String(currentNow.getMinutes()).padStart(2, '0')}`,
    );
    const eventStyle = (
        event: ProjectScheduleEvent,
        displayDate: string,
    ): string => {
        const segment = scheduleEventDaySegment(
            event.startsAt,
            event.endsAt,
            displayDate,
        );

        if (!segment) {
            return '';
        }

        const top = (segment.startMinute / 60) * hourHeight;
        const height = Math.min(
            timelineHeight - top,
            Math.max(
                24,
                ((segment.endMinute - segment.startMinute) / 60) * hourHeight,
            ),
        );

        return `top: ${top}px; height: ${height}px;`;
    };
    const eventTimeLabel = (
        event: ProjectScheduleEvent,
        displayDate: string,
    ): string => {
        const segment = scheduleEventDaySegment(
            event.startsAt,
            event.endsAt,
            displayDate,
        );
        const formatMinute = (value: number): string =>
            `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;

        return segment
            ? `${formatMinute(segment.startMinute)}–${formatMinute(segment.endMinute)}`
            : '';
    };
    const scrollTimelineToCurrentTime = (
        node: HTMLElement,
        rangeKey: string,
    ) => {
        let activeRangeKey = rangeKey;
        const scroll = (): void => {
            requestAnimationFrame(() => {
                const now = new Date();
                const minutes =
                    now.getHours() * 60 +
                    now.getMinutes() +
                    now.getSeconds() / 60;

                node.scrollTop = Math.max(0, (minutes / 60) * hourHeight - 12);
            });
        };

        scroll();

        return {
            update(nextRangeKey: string): void {
                if (nextRangeKey === activeRangeKey) {
                    return;
                }

                activeRangeKey = nextRangeKey;
                scroll();
            },
        };
    };

    onMount(() => {
        const timer = window.setInterval(() => {
            currentNow = new Date();
        }, 60_000);

        return () => window.clearInterval(timer);
    });

    const isEventToday = (value: string): boolean =>
        value.slice(0, 10) === currentDateKey;

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
                    <Button onclick={() => openNewEventForDate(date)}
                        >+ {t('New event')}</Button
                    >
                    <DialogContent class="max-w-xl">
                        {#key newEventFormKey}
                            <Form
                                {...ScheduleEventController.store.form(
                                    routeArguments,
                                )}
                                onSuccess={() =>
                                    handleNewEventOpenChange(false)}
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
                                            <Label for="event-start-date"
                                                >{t('Starts at')}</Label
                                            >
                                            <input
                                                name="starts_at"
                                                type="hidden"
                                                value={`${newEventStartDate}T${newEventStartTime}`}
                                            />
                                            <div
                                                class="grid grid-cols-[minmax(0,1fr)_7rem] gap-2"
                                            >
                                                <Input
                                                    id="event-start-date"
                                                    type="date"
                                                    bind:value={
                                                        newEventStartDate
                                                    }
                                                    onchange={setNewEventEndFromStart}
                                                    required
                                                />
                                                <Input
                                                    id="event-start-time"
                                                    type="time"
                                                    list="schedule-half-hour-options"
                                                    bind:value={
                                                        newEventStartTime
                                                    }
                                                    onchange={setNewEventEndFromStart}
                                                    aria-label={t('Starts at')}
                                                    required
                                                />
                                            </div>
                                            <InputError
                                                message={errors.starts_at}
                                            />
                                        </div>
                                        <div class="grid gap-2">
                                            <Label for="event-end-date"
                                                >{t('Ends at')}</Label
                                            >
                                            <input
                                                name="ends_at"
                                                type="hidden"
                                                value={`${newEventEndDate}T${newEventEndTime}`}
                                            />
                                            <div
                                                class="grid grid-cols-[minmax(0,1fr)_7rem] gap-2"
                                            >
                                                <Input
                                                    id="event-end-date"
                                                    type="date"
                                                    bind:value={newEventEndDate}
                                                    required
                                                />
                                                <Input
                                                    id="event-end-time"
                                                    type="time"
                                                    list="schedule-half-hour-options"
                                                    bind:value={newEventEndTime}
                                                    aria-label={t('Ends at')}
                                                    required
                                                />
                                            </div>
                                            <InputError
                                                message={errors.ends_at}
                                            />
                                        </div>
                                        <datalist
                                            id="schedule-half-hour-options"
                                        >
                                            {#each halfHourOptions as time (time)}
                                                <option value={time}></option>
                                            {/each}
                                        </datalist>
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
                                                attachmentUploadUrl={AttachmentController.uploadInline.url(
                                                    routeArguments,
                                                )}
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
            {#if view !== 'gantt' || !ganttUsesProjectDates}
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
            {/if}
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
                            class="relative min-h-24 border-l p-1.5 first:border-l-0 sm:min-h-32 sm:p-2 {day.inCurrentMonth
                                ? ''
                                : 'bg-muted/20 text-muted-foreground'}"
                        >
                            <button
                                type="button"
                                onclick={() => openNewEventForDate(day.date)}
                                class="absolute inset-0 z-0 cursor-pointer text-left outline-none hover:bg-muted/30 focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-inset"
                                aria-label={`${formatLongDate(day.date)} ${t('New event')}`}
                            ></button>
                            <p
                                class="pointer-events-none relative z-10 text-xs"
                            >
                                {#if day.monthLabel}<span
                                        class="mr-1 rounded bg-muted px-1 py-0.5 font-medium"
                                        >{day.monthLabel}</span
                                    >{/if}<span
                                    class={day.isToday
                                        ? 'rounded-full bg-primary px-1.5 py-0.5 font-semibold text-primary-foreground'
                                        : ''}>{day.day}</span
                                >
                            </p>
                            <div
                                class="pointer-events-none relative z-10 mt-1 space-y-1"
                            >
                                {#each day.events.slice(0, 3) as event (event.id)}
                                    {@const segment = scheduleEventDaySegment(
                                        event.startsAt,
                                        event.endsAt,
                                        day.date,
                                    )}
                                    <Link
                                        href={ScheduleEventController.show([
                                            currentTeam.slug,
                                            project.id,
                                            event.id,
                                        ])}
                                        class="pointer-events-auto block w-full truncate rounded bg-primary/10 px-1 py-0.5 text-left text-xs hover:bg-primary/20"
                                        title={event.title}
                                    >
                                        <span class="font-semibold"
                                            >{event.title}</span
                                        >
                                        {#if !event.allDay && segment?.startsOnDate}<br
                                            /><span
                                                class="text-muted-foreground"
                                                >{new Intl.DateTimeFormat(
                                                    currentLocale(),
                                                    { timeStyle: 'short' },
                                                ).format(
                                                    new Date(event.startsAt),
                                                )}</span
                                            >{/if}
                                    </Link>
                                {/each}
                                {#if day.events.length > 3}
                                    <Link
                                        href={viewHref('day', day.date)}
                                        class="pointer-events-auto block text-xs text-muted-foreground hover:underline"
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
    {:else if view === 'day' || view === 'week'}
        <div class="overflow-hidden rounded-lg border">
            <div
                use:scrollTimelineToCurrentTime={`${view}:${rangeStart}:${rangeEnd}`}
                class="h-[calc(100vh-18rem)] min-h-96 max-h-[42rem] overflow-auto overscroll-contain"
            >
                <div class={view === 'week' ? 'min-w-4xl' : 'min-w-md'}>
                    <div
                        class="sticky top-0 z-40 grid border-b bg-background shadow-sm"
                        style={`grid-template-columns: 4rem repeat(${timelineDays.length}, minmax(0, 1fr));`}
                    >
                        <div
                            class="sticky left-0 z-50 border-r bg-muted/60"
                            aria-hidden="true"
                        ></div>
                        {#each timelineDays as day (day.date)}
                            <div
                                class="min-w-0 border-r bg-muted/40 px-1 py-2 text-center last:border-r-0"
                            >
                                <button
                                    type="button"
                                    onclick={() =>
                                        openNewEventForDate(day.date)}
                                    class="mx-auto flex items-center justify-center gap-1 rounded-md px-2 py-1 text-sm font-semibold hover:bg-muted focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                                >
                                    <span class="text-muted-foreground"
                                        >{weekdayLabels[
                                            weekdayOf(day.date)
                                        ]}</span
                                    >
                                    <span
                                        class="flex size-7 items-center justify-center rounded-full {day.date ===
                                        currentDateKey
                                            ? 'bg-primary text-primary-foreground'
                                            : ''}"
                                    >
                                        {Number(day.date.slice(8, 10))}
                                    </span>
                                </button>
                                {#each day.events.filter((event) => event.allDay) as event (event.id)}
                                    <Link
                                        href={ScheduleEventController.show([
                                            currentTeam.slug,
                                            project.id,
                                            event.id,
                                        ])}
                                        class="mt-1 block w-full truncate rounded bg-primary/15 px-1 py-0.5 text-left text-xs font-medium hover:bg-primary/25"
                                        title={event.title}
                                    >
                                        {event.title}
                                    </Link>
                                {/each}
                            </div>
                        {/each}
                    </div>

                    <div
                        class="relative grid pb-5"
                        style={`grid-template-columns: 4rem repeat(${timelineDays.length}, minmax(0, 1fr));`}
                    >
                        <div
                            class="sticky left-0 z-20 border-r bg-background"
                            style={`height: ${timelineHeight}px;`}
                        >
                            {#each halfHourOptions.filter( (time) => time.endsWith(':00') ) as time (time)}
                                <span
                                    class="absolute right-2 text-[11px] leading-none text-muted-foreground"
                                    style={`top: ${Number(time.slice(0, 2)) * hourHeight + 4}px;`}
                                >
                                    {time}
                                </span>
                            {/each}
                            <span
                                class="absolute right-2 text-[11px] leading-none text-muted-foreground"
                                style={`top: ${timelineHeight + 4}px;`}
                            >
                                24:00
                            </span>
                        </div>

                        {#each timelineDays as day (day.date)}
                            <div
                                class="relative min-w-0 overflow-hidden border-r last:border-r-0"
                                style={`height: ${timelineHeight}px;`}
                            >
                                {#each halfHourOptions as time, slotIndex (time)}
                                    <button
                                        type="button"
                                        onclick={() =>
                                            openNewEventAt(day.date, time)}
                                        class={`block h-8 w-full border-t text-left outline-none hover:bg-primary/5 focus-visible:bg-primary/10 focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-inset ${slotIndex % 2 === 0 ? 'border-border' : 'border-dashed border-border/60'}`}
                                        aria-label={`${formatLongDate(day.date)} ${time} ${t('New event')}`}
                                    ></button>
                                {/each}

                                {#each day.events.filter((event) => !event.allDay) as event (event.id)}
                                    <Link
                                        href={ScheduleEventController.show([
                                            currentTeam.slug,
                                            project.id,
                                            event.id,
                                        ])}
                                        class="absolute inset-x-1 z-10 overflow-hidden rounded-md border border-primary/40 bg-primary/15 px-1.5 py-1 text-left text-xs shadow-sm hover:bg-primary/25 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                                        style={eventStyle(event, day.date)}
                                        title={event.title}
                                    >
                                        <span
                                            class="block truncate font-semibold"
                                            >{event.title}</span
                                        >
                                        <span
                                            class="block truncate text-[10px] text-muted-foreground"
                                            >{eventTimeLabel(
                                                event,
                                                day.date,
                                            )}</span
                                        >
                                    </Link>
                                {/each}
                            </div>
                        {/each}

                        <div
                            class="pointer-events-none absolute right-0 left-0 z-30 border-t-2 border-emerald-500"
                            style={`top: ${currentTimeTop}px;`}
                            aria-label={`${t('Today')} ${currentTimeLabel}`}
                        >
                            <span
                                class="sticky left-0 inline-flex w-16 -translate-y-1/2 justify-center rounded-r-full bg-emerald-500 px-1 py-0.5 text-[10px] font-bold text-white shadow-sm"
                            >
                                {currentTimeLabel}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    {:else if view === 'gantt'}
        <div class="overflow-x-auto overscroll-x-contain rounded-lg border">
            <div style={`min-width: ${ganttMinWidthRem}rem;`}>
                <div
                    class="relative h-11 overflow-hidden border-b bg-muted/40 text-xs font-semibold text-muted-foreground"
                >
                    {#each ganttTicks as tick (tick)}
                        <time
                            datetime={tick}
                            class="absolute inset-y-0 border-l px-2 py-3 whitespace-nowrap"
                            style={`left: ${ganttTickOffset(tick)}%;`}
                        >
                            {formatGanttTick(tick)}
                        </time>
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
                                class="relative grid min-h-14 overflow-hidden border-y first:border-t-0 last:border-b-0"
                                style={`grid-template-columns: repeat(${ganttDayCount}, minmax(0, 1fr));`}
                            >
                                <div
                                    class="pointer-events-none absolute inset-0"
                                    aria-hidden="true"
                                >
                                    {#each ganttTicks as tick (tick)}
                                        <span
                                            class="absolute inset-y-0 border-l"
                                            style={`left: ${ganttTickOffset(tick)}%;`}
                                        ></span>
                                    {/each}
                                </div>
                                <div
                                    class="pointer-events-none absolute inset-0 opacity-30"
                                    style={`background-image: linear-gradient(to right, var(--color-border) 1px, transparent 1px); background-size: calc(100% / ${ganttDayCount} * ${ganttGridStepDays}) 100%;`}
                                    aria-hidden="true"
                                ></div>
                                <Link
                                    href={ScheduleEventController.show([
                                        currentTeam.slug,
                                        project.id,
                                        event.id,
                                    ])}
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
                                                attachmentUploadUrl={AttachmentController.uploadInline.url(
                                                    routeArguments,
                                                )}
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
