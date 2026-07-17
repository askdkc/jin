<script module lang="ts">
    import CheckInController from '@/actions/App/Http/Controllers/CheckInController';
    import { show as showProject } from '@/routes/projects';
    import type { ProjectTool, Team } from '@/types';

    export const layout = (props: {
        currentTeam: Team;
        project: ProjectTool;
        checkIn: { id: number; question: string };
    }) => ({
        breadcrumbs: [
            {
                title: props.project.name,
                href: showProject([props.currentTeam.slug, props.project.id]),
            },
            {
                title: props.currentTeam.menuNames.checkIns,
                href: CheckInController.index([
                    props.currentTeam.slug,
                    props.project.id,
                ]),
            },
            {
                title: props.checkIn.question,
                href: CheckInController.show([
                    props.currentTeam.slug,
                    props.project.id,
                    props.checkIn.id,
                ]),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import Bookmark from 'lucide-svelte/icons/bookmark';
    import Pause from 'lucide-svelte/icons/pause';
    import Pencil from 'lucide-svelte/icons/pencil';
    import Play from 'lucide-svelte/icons/play';
    import Trash2 from 'lucide-svelte/icons/trash-2';
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
        CheckInDetail,
        CheckInPrompt,
        CheckInResponse,
        TodoMember,
    } from '@/types';

    let {
        currentTeam,
        project,
        checkIn,
        pendingPrompt,
        responses,
        members,
    }: {
        currentTeam: Team;
        project: ProjectTool;
        checkIn: CheckInDetail;
        pendingPrompt: CheckInPrompt | null;
        responses: CheckInResponse[];
        members: TodoMember[];
    } = $props();

    const routeArguments = $derived([
        currentTeam.slug,
        project.id,
        checkIn.id,
    ] as [string, number, number]);
    let editDialogOpen = $state(false);
    let editFrequency = $state<CheckInDetail['frequency']>('weekly');
    const weekdayOptions = [
        { value: 1, label: 'Mon' },
        { value: 2, label: 'Tue' },
        { value: 3, label: 'Wed' },
        { value: 4, label: 'Thu' },
        { value: 5, label: 'Fri' },
        { value: 6, label: 'Sat' },
        { value: 7, label: 'Sun' },
    ];

    const confirmDelete = (): boolean =>
        window.confirm(t('Are you sure you want to delete this?'));
    const openEditDialog = (): void => {
        editFrequency = checkIn.frequency;
        editDialogOpen = true;
    };
    const formatDate = (
        value: string | null,
        timezone = 'Asia/Tokyo',
    ): string =>
        value
            ? new Intl.DateTimeFormat('ja-JP', {
                  timeZone: timezone,
                  dateStyle: 'medium',
                  timeStyle: 'short',
              }).format(new Date(value))
            : '';
    const frequencyLabel = (schedule: CheckInDetail): string =>
        schedule.frequency === 'daily'
            ? `${t('Daily')}（${schedule.weekdays
                  .map((weekday) =>
                      t(
                          weekdayOptions.find(
                              (option) => option.value === weekday,
                          )?.label ?? '',
                      ),
                  )
                  .join('・')}）`
            : t(schedule.frequency === 'weekly' ? 'Weekly' : 'Monthly');
    const memberInitial = (name: string | null): string =>
        name?.trim().slice(0, 1).toLocaleUpperCase('ja-JP') || '?';
</script>

<AppHead title={checkIn.question} />

<div
    class="min-h-full bg-[#f5f7f3] px-0 pb-8 dark:bg-[#0d151b] sm:px-5 sm:pt-2 lg:px-6"
>
    <main
        class="mx-auto min-h-[calc(100dvh-7rem)] w-full max-w-[1088px] overflow-hidden bg-card shadow-[0_18px_55px_rgba(30,55,80,0.08)]"
    >
        <div
            class="flex min-h-12 flex-wrap items-center justify-between gap-3 border-b px-5 py-2 sm:px-8"
        >
            <div class="flex min-w-0 items-center gap-2 text-sm">
                <Link
                    href={showProject([currentTeam.slug, project.id])}
                    class="truncate font-bold hover:underline"
                >
                    {project.name}
                </Link>
                <span aria-hidden="true">‹</span>
                <Link
                    href={CheckInController.index([
                        currentTeam.slug,
                        project.id,
                    ])}
                    class="text-muted-foreground hover:text-foreground hover:underline"
                >
                    {currentTeam.menuNames.checkIns}
                </Link>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-1">
                <Form
                    {...CheckInController.toggleBookmark.form(routeArguments)}
                >
                    {#snippet children({ processing })}
                        <Button
                            type="submit"
                            variant="ghost"
                            size="sm"
                            disabled={processing}
                            aria-label={checkIn.isBookmarked
                                ? t('Remove bookmark')
                                : t('Bookmark')}
                        >
                            <Bookmark
                                class={`size-4 ${checkIn.isBookmarked ? 'fill-current' : ''}`}
                                aria-hidden="true"
                            />
                            {checkIn.isBookmarked
                                ? t('Remove bookmark')
                                : t('Bookmark')}
                        </Button>
                    {/snippet}
                </Form>

                {#if checkIn.canManage}
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onclick={openEditDialog}
                    >
                        <Pencil aria-hidden="true" />
                        {t('Edit')}
                    </Button>
                {/if}

                {#if checkIn.canDelete}
                    <Form
                        {...CheckInController.destroy.form(routeArguments)}
                        onBefore={confirmDelete}
                    >
                        {#snippet children({ processing })}
                            <Button
                                type="submit"
                                variant="ghost"
                                size="sm"
                                class="text-destructive hover:text-destructive"
                                disabled={processing}
                            >
                                <Trash2 aria-hidden="true" />
                                {t('Delete')}
                            </Button>
                        {/snippet}
                    </Form>
                {/if}
            </div>
        </div>

        <div class="px-5 py-7 sm:px-9 sm:py-10 lg:px-10">
            <header>
                <h1
                    class="max-w-4xl break-words text-3xl font-bold tracking-tight sm:text-4xl"
                >
                    {checkIn.question}
                </h1>

                <div
                    class="mt-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
                >
                    <span
                        class="size-2 shrink-0 rounded-full bg-sky-400"
                        aria-hidden="true"
                    ></span>
                    <span class="font-semibold text-foreground">
                        {checkIn.paused ? t('Paused') : frequencyLabel(checkIn)}
                    </span>
                    <span aria-hidden="true">·</span>
                    <span>
                        {t('Next question')}:
                        {formatDate(checkIn.nextRunAt, checkIn.timezone)}
                    </span>
                    <span aria-hidden="true">·</span>
                    <span>
                        {t('People to ask')}:
                        {checkIn.targetNames.join('、')}
                    </span>
                </div>

                {#if checkIn.creatorName}
                    <p class="mt-1 text-xs text-muted-foreground">
                        {t('Created by')}: {checkIn.creatorName}
                    </p>
                {/if}

                {#if checkIn.canManage}
                    <div class="mt-4 flex flex-wrap gap-2">
                        <Form
                            {...CheckInController.togglePause.form(
                                routeArguments,
                            )}
                        >
                            {#snippet children({ processing })}
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                    disabled={processing}
                                >
                                    {#if checkIn.paused}
                                        <Play aria-hidden="true" />
                                        {t('Resume')}
                                    {:else}
                                        <Pause aria-hidden="true" />
                                        {t('Pause')}
                                    {/if}
                                </Button>
                            {/snippet}
                        </Form>
                        <Button
                            type="button"
                            variant="link"
                            size="sm"
                            onclick={openEditDialog}
                        >
                            {t('Change question settings')}
                        </Button>
                    </div>
                {/if}
            </header>

            {#if pendingPrompt}
                <section
                    class="mt-8 border-t pt-8"
                    aria-labelledby="check-in-answer"
                >
                    <h2 id="check-in-answer" class="text-lg font-bold">
                        {t('Your answer')}
                    </h2>
                    <Form
                        {...CheckInController.answer.form([
                            currentTeam.slug,
                            project.id,
                            checkIn.id,
                            pendingPrompt.id,
                        ])}
                        class="mt-4 grid gap-3"
                    >
                        {#snippet children({ errors, processing })}
                            <Label
                                for={`check-in-answer-${pendingPrompt.id}`}
                                class="sr-only"
                            >
                                {t('Answer')}
                            </Label>
                            <RichTextEditor
                                id={`check-in-answer-${pendingPrompt.id}`}
                                name="answer"
                                required
                                placeholder={t('Write your answer')}
                                ariaLabel={t('Answer')}
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
                </section>
            {/if}

            <section
                class="mt-10 border-t pt-8"
                aria-labelledby="check-in-responses"
            >
                <h2 id="check-in-responses" class="text-xl font-bold">
                    {t('Responses')}
                </h2>

                {#if responses.length === 0}
                    <p class="mt-6 text-sm text-muted-foreground">
                        {t('No answers yet')}
                    </p>
                {:else}
                    <div class="mt-6 grid gap-6">
                        {#each responses as response (response.id)}
                            <article class="flex min-w-0 gap-3">
                                <span
                                    class="flex size-10 shrink-0 items-center justify-center rounded-full bg-muted font-bold text-foreground"
                                    aria-hidden="true"
                                >
                                    {memberInitial(response.userName)}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm">
                                        <span class="font-bold">
                                            {response.userName ||
                                                t('Deleted user')}
                                        </span>
                                        <span class="text-muted-foreground">
                                            · {formatDate(response.answeredAt)}
                                        </span>
                                    </p>
                                    <div
                                        class="mt-2 rounded-xl border bg-background/40 p-4"
                                    >
                                        <RichTextContent
                                            content={response.answer}
                                            class="break-words text-sm leading-6"
                                        />
                                    </div>
                                </div>
                            </article>
                        {/each}
                    </div>
                {/if}
            </section>
        </div>
    </main>
</div>

<Dialog bind:open={editDialogOpen}>
    <DialogContent class="max-h-[calc(100dvh-2rem)] max-w-2xl overflow-y-auto">
        <DialogTitle>{t('Question settings')}</DialogTitle>
        <Form
            {...CheckInController.update.form(routeArguments)}
            onSuccess={() => (editDialogOpen = false)}
            class="mt-5 grid gap-5"
        >
            {#snippet children({ errors, processing })}
                <div class="grid gap-2">
                    <Label for="check-in-question">{t('Question')}</Label>
                    <Input
                        id="check-in-question"
                        name="question"
                        value={checkIn.question}
                        maxlength={255}
                        required
                    />
                    <InputError message={errors.question} />
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="check-in-frequency">{t('Frequency')}</Label>
                        <select
                            id="check-in-frequency"
                            name="frequency"
                            bind:value={editFrequency}
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
                            value={checkIn.nextRunAtLocal}
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
                            <option
                                value="Asia/Tokyo"
                                selected={checkIn.timezone === 'Asia/Tokyo'}
                                >Asia/Tokyo</option
                            >
                            <option
                                value="UTC"
                                selected={checkIn.timezone === 'UTC'}
                                >UTC</option
                            >
                            {#if !['Asia/Tokyo', 'UTC'].includes(checkIn.timezone)}
                                <option value={checkIn.timezone} selected>
                                    {checkIn.timezone}
                                </option>
                            {/if}
                        </select>
                        <InputError message={errors.timezone} />
                    </div>
                </div>

                {#if editFrequency === 'daily'}
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
                                        checked={checkIn.weekdays.length === 0
                                            ? weekday.value <= 5
                                            : checkIn.weekdays.includes(
                                                  weekday.value,
                                              )}
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
                                    checked={checkIn.targetIds.includes(
                                        member.id,
                                    )}
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
                        {t('Save changes')}
                    </Button>
                </DialogFooter>
            {/snippet}
        </Form>
    </DialogContent>
</Dialog>
