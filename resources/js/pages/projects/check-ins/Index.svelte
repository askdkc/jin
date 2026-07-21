<script module lang="ts">
    import CheckInController from '@/actions/App/Http/Controllers/CheckInController';
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
                title: props.currentTeam.menuNames.checkIns,
                href: CheckInController.index([
                    props.currentTeam.slug,
                    props.project.id,
                ]),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import Plus from 'lucide-svelte/icons/plus';
    import AttachmentController from '@/actions/App/Http/Controllers/AttachmentController';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import RichTextContent from '@/components/RichTextContent.svelte';
    import RichTextEditor from '@/components/RichTextEditor.svelte';
    import { Button } from '@/components/ui/button';
    import {
        Dialog,
        DialogClose,
        DialogContent,
        DialogFooter,
        DialogTitle,
    } from '@/components/ui/dialog';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
    import type {
        CheckInPrompt,
        CheckInResponse,
        CheckInSchedule,
        TodoMember,
    } from '@/types';

    let {
        currentTeam,
        project,
        checkIns,
        pendingPrompts,
        responses,
        members,
    }: {
        currentTeam: Team;
        project: ProjectTool;
        checkIns: CheckInSchedule[];
        pendingPrompts: CheckInPrompt[];
        responses: CheckInResponse[];
        members: TodoMember[];
    } = $props();

    const routeArguments = $derived([currentTeam.slug, project.id] as [
        string,
        number,
    ]);
    let createDialogOpen = $state(false);
    let createFrequency = $state<CheckInSchedule['frequency']>('weekly');
    const weekdayOptions = [
        { value: 1, label: 'Mon' },
        { value: 2, label: 'Tue' },
        { value: 3, label: 'Wed' },
        { value: 4, label: 'Thu' },
        { value: 5, label: 'Fri' },
        { value: 6, label: 'Sat' },
        { value: 7, label: 'Sun' },
    ];
    const dateFormatters: Record<string, Intl.DateTimeFormat> = {};
    const dateFormatterFor = (timezone: string): Intl.DateTimeFormat => {
        const cached = dateFormatters[timezone];

        if (cached) {
            return cached;
        }

        // Intl formatter construction is comparatively expensive on response lists.
        const formatter = new Intl.DateTimeFormat('ja-JP', {
            timeZone: timezone,
            dateStyle: 'medium',
            timeStyle: 'short',
        });
        dateFormatters[timezone] = formatter;

        return formatter;
    };

    const formatDate = (
        value: string | null,
        timezone = 'Asia/Tokyo',
    ): string =>
        value ? dateFormatterFor(timezone).format(new Date(value)) : '';
    const frequencyLabel = (checkIn: CheckInSchedule): string =>
        checkIn.frequency === 'daily'
            ? `${t('Daily')}（${checkIn.weekdays
                  .map((weekday) =>
                      t(
                          weekdayOptions.find(
                              (option) => option.value === weekday,
                          )?.label ?? '',
                      ),
                  )
                  .join('・')}）`
            : t(checkIn.frequency === 'weekly' ? 'Weekly' : 'Monthly');
    // Build the lookup once when the response prop changes instead of scanning
    // the entire response list for every check-in rendered below.
    const responsesByCheckIn = $derived.by(() => {
        const grouped: Record<number, CheckInResponse[]> = {};

        for (const response of responses) {
            (grouped[response.checkInId] ??= []).push(response);
        }

        return grouped;
    });
    const memberInitial = (name: string | null): string =>
        name?.trim().slice(0, 1).toLocaleUpperCase('ja-JP') || '?';
</script>

<AppHead title={currentTeam.menuNames.checkIns} />

<div
    class="min-h-full bg-[#f5f7f3] px-0 pb-8 dark:bg-[#0d151b] sm:px-5 sm:pt-2 lg:px-6"
>
    <main
        class="mx-auto min-h-[calc(100dvh-7rem)] w-full max-w-[1088px] overflow-hidden bg-card shadow-[0_18px_55px_rgba(30,55,80,0.08)]"
    >
        <div class="flex min-h-10 items-center border-b px-5 sm:px-8">
            <Link
                href={show([currentTeam.slug, project.id])}
                class="text-sm font-bold hover:underline"
            >
                {project.name}
            </Link>
        </div>

        <div class="px-5 py-6 sm:px-9 sm:py-8 lg:px-10">
            <header>
                <h1 class="text-3xl font-bold tracking-tight">
                    {currentTeam.menuNames.checkIns}
                </h1>
                <Button
                    type="button"
                    size="sm"
                    class="mt-4"
                    onclick={() => (createDialogOpen = true)}
                >
                    <Plus data-icon="inline-start" />
                    {t('New question')}
                </Button>
            </header>

            {#if pendingPrompts.length > 0}
                <section class="mt-8" aria-labelledby="pending-check-ins">
                    <div class="mb-3">
                        <h2
                            id="pending-check-ins"
                            class="text-lg font-semibold"
                        >
                            {t('Awaiting your answer')}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {t('Answer the latest questions assigned to you.')}
                        </p>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        {#each pendingPrompts as prompt (prompt.id)}
                            <article class="rounded-xl border p-5">
                                <h3 class="break-words text-base font-bold">
                                    {prompt.question}
                                </h3>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {formatDate(prompt.scheduledFor)}
                                </p>
                                <Form
                                    {...CheckInController.answer.form([
                                        currentTeam.slug,
                                        project.id,
                                        prompt.checkInId,
                                        prompt.id,
                                    ])}
                                    class="mt-4 grid gap-3"
                                >
                                    {#snippet children({ errors, processing })}
                                        <Label
                                            for={`check-in-answer-${prompt.id}`}
                                            class="sr-only"
                                        >
                                            {t('Answer')}
                                        </Label>
                                        <RichTextEditor
                                            id={`check-in-answer-${prompt.id}`}
                                            name="answer"
                                            required
                                            placeholder={t('Write your answer')}
                                            ariaLabel={t('Answer')}
                                            attachmentUploadUrl={AttachmentController.uploadInline.url(
                                                routeArguments,
                                            )}
                                        />
                                        <InputError message={errors.answer} />
                                        <Button
                                            type="submit"
                                            size="sm"
                                            class="justify-self-start"
                                            disabled={processing}
                                        >
                                            {t('Submit answer')}
                                        </Button>
                                    {/snippet}
                                </Form>
                            </article>
                        {/each}
                    </div>
                </section>
            {/if}

            <section class="mt-8" aria-labelledby="recurring-check-ins">
                <h2 id="recurring-check-ins" class="sr-only">
                    {t('Recurring questions')}
                </h2>

                {#if checkIns.length === 0}
                    <p
                        class="rounded-xl border border-dashed px-5 py-10 text-center text-sm text-muted-foreground"
                    >
                        {t('No check-ins yet')}
                    </p>
                {:else}
                    <div class="grid gap-4">
                        {#each checkIns as checkIn (checkIn.id)}
                            {@const checkInResponses =
                                responsesByCheckIn[checkIn.id] ?? []}
                            <article
                                class="rounded-xl border p-5 sm:p-6"
                                class:opacity-75={checkIn.paused}
                            >
                                <div
                                    class="flex flex-wrap items-start justify-between gap-3"
                                >
                                    <div class="min-w-0">
                                        <h3>
                                            <Link
                                                href={CheckInController.show([
                                                    currentTeam.slug,
                                                    project.id,
                                                    checkIn.id,
                                                ])}
                                                class="break-words text-xl font-bold tracking-tight hover:underline focus-visible:rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2"
                                            >
                                                {checkIn.question}
                                            </Link>
                                        </h3>
                                        <p
                                            class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
                                        >
                                            <span
                                                class="size-2 shrink-0 rounded-full bg-sky-400"
                                                aria-hidden="true"
                                            ></span>
                                            <span
                                                class="font-semibold text-foreground"
                                            >
                                                {checkIn.paused
                                                    ? t('Paused')
                                                    : frequencyLabel(checkIn)}
                                            </span>
                                            <span aria-hidden="true">·</span>
                                            <span>
                                                {t('Next question')}:
                                                {formatDate(
                                                    checkIn.nextRunAt,
                                                    checkIn.timezone,
                                                )}
                                            </span>
                                            {#if checkIn.pendingCount > 0}
                                                <span aria-hidden="true">·</span
                                                >
                                                <span>
                                                    {t(':count unanswered', {
                                                        count: String(
                                                            checkIn.pendingCount,
                                                        ),
                                                    })}
                                                </span>
                                            {/if}
                                        </p>
                                        <p
                                            class="mt-1 break-words text-xs text-muted-foreground"
                                        >
                                            {t('People to ask')}:
                                            {checkIn.targetNames.join('、')}
                                        </p>
                                    </div>
                                </div>

                                {#if checkInResponses.length > 0}
                                    <div
                                        class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3"
                                    >
                                        {#each checkInResponses as response (response.id)}
                                            <div
                                                class="flex min-w-0 flex-col rounded-lg border bg-background/40 p-4"
                                            >
                                                <RichTextContent
                                                    content={response.answer}
                                                    class="min-h-10 break-words text-sm leading-6"
                                                />
                                                <div
                                                    class="mt-4 flex items-center gap-2 text-xs text-muted-foreground"
                                                >
                                                    <span
                                                        class="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted font-bold text-foreground"
                                                        aria-hidden="true"
                                                    >
                                                        {memberInitial(
                                                            response.userName,
                                                        )}
                                                    </span>
                                                    <span class="min-w-0">
                                                        <span
                                                            class="block truncate font-semibold text-foreground"
                                                        >
                                                            {response.userName ||
                                                                t(
                                                                    'Deleted user',
                                                                )}
                                                        </span>
                                                        <span>
                                                            {formatDate(
                                                                response.answeredAt,
                                                            )}
                                                        </span>
                                                    </span>
                                                </div>
                                            </div>
                                        {/each}
                                    </div>
                                {/if}
                            </article>
                        {/each}
                    </div>
                {/if}
            </section>
        </div>
    </main>
</div>

<Dialog bind:open={createDialogOpen}>
    <DialogContent class="max-h-[calc(100dvh-2rem)] max-w-2xl overflow-y-auto">
        <DialogTitle>{t('Create a recurring question')}</DialogTitle>
        <Form
            {...CheckInController.store.form(routeArguments)}
            resetOnSuccess
            onSuccess={() => (createDialogOpen = false)}
            class="mt-5 grid gap-5"
        >
            {#snippet children({ errors, processing })}
                <div class="grid gap-2">
                    <Label for="check-in-question">{t('Question')}</Label>
                    <Input
                        id="check-in-question"
                        name="question"
                        maxlength={255}
                        required
                        placeholder={t('What did you work on?')}
                    />
                    <InputError message={errors.question} />
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="check-in-frequency">{t('Frequency')}</Label>
                        <select
                            id="check-in-frequency"
                            name="frequency"
                            bind:value={createFrequency}
                            required
                            class="border-input h-10 w-full rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="daily">{t('Daily')}</option>
                            <option value="weekly">{t('Weekly')}</option>
                            <option value="monthly">{t('Monthly')}</option>
                        </select>
                        <InputError message={errors.frequency} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="check-in-next-run"
                            >{t('First question at')}</Label
                        >
                        <Input
                            id="check-in-next-run"
                            type="datetime-local"
                            name="next_run_at"
                            required
                        />
                        <InputError message={errors.next_run_at} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="check-in-timezone">{t('Timezone')}</Label>
                        <select
                            id="check-in-timezone"
                            name="timezone"
                            required
                            class="border-input h-10 w-full rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="Asia/Tokyo" selected
                                >Asia/Tokyo</option
                            >
                            <option value="UTC">UTC</option>
                        </select>
                        <InputError message={errors.timezone} />
                    </div>
                </div>

                {#if createFrequency === 'daily'}
                    <fieldset class="grid gap-2">
                        <legend class="text-sm font-medium">
                            {t('Days of week')}
                        </legend>
                        <div class="flex flex-wrap gap-2">
                            {#each weekdayOptions as weekday (weekday.value)}
                                <label
                                    class="flex items-center gap-2 rounded-md border px-3 py-2 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        name="weekdays[]"
                                        value={weekday.value}
                                        checked={weekday.value <= 5}
                                        class="size-4"
                                    />
                                    {t(weekday.label)}
                                </label>
                            {/each}
                        </div>
                        <InputError message={errors.weekdays} />
                    </fieldset>
                {/if}

                <fieldset class="grid gap-2">
                    <legend class="text-sm font-medium">
                        {t('People to ask')}
                    </legend>
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {#each members as member (member.id)}
                            <label
                                class="flex min-w-0 items-center gap-2 rounded-md border p-3 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    name="target_user_ids[]"
                                    value={member.id}
                                    class="size-4"
                                />
                                <span class="min-w-0 truncate">
                                    {member.name}
                                </span>
                            </label>
                        {/each}
                    </div>
                    <InputError message={errors.target_user_ids} />
                </fieldset>

                <DialogFooter>
                    <DialogClose asChild>
                        {#snippet children(closeProps)}
                            <Button
                                type="button"
                                variant="outline"
                                onclick={closeProps.onClick}
                            >
                                {t('Cancel')}
                            </Button>
                        {/snippet}
                    </DialogClose>
                    <Button type="submit" disabled={processing}>
                        {t('Create check-in')}
                    </Button>
                </DialogFooter>
            {/snippet}
        </Form>
    </DialogContent>
</Dialog>
