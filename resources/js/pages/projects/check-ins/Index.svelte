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
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import RichTextContent from '@/components/RichTextContent.svelte';
    import RichTextEditor from '@/components/RichTextEditor.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import {
        Card,
        CardContent,
        CardHeader,
        CardTitle,
    } from '@/components/ui/card';
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
    const confirmDelete = (event: SubmitEvent): void => {
        if (!window.confirm(t('Are you sure you want to delete this?'))) {
            event.preventDefault();
        }
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
    const frequencyLabel = (frequency: CheckInSchedule['frequency']): string =>
        t(
            frequency === 'daily'
                ? 'Daily'
                : frequency === 'weekly'
                  ? 'Weekly'
                  : 'Monthly',
        );
</script>

<AppHead title={currentTeam.menuNames.checkIns} />

<div class="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">
            {currentTeam.menuNames.checkIns}
        </h1>
        <p class="text-sm text-muted-foreground">{project.name}</p>
    </div>

    <Card>
        <CardHeader>
            <CardTitle>{t('Create a recurring question')}</CardTitle>
        </CardHeader>
        <CardContent>
            <Form
                {...CheckInController.store.form(routeArguments)}
                resetOnSuccess
                class="space-y-4"
            >
                {#snippet children({ errors, processing })}
                    <div>
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
                        <div>
                            <Label for="check-in-frequency"
                                >{t('Frequency')}</Label
                            >
                            <select
                                id="check-in-frequency"
                                name="frequency"
                                required
                                class="border-input h-10 w-full rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="daily">{t('Daily')}</option>
                                <option value="weekly" selected
                                    >{t('Weekly')}</option
                                >
                                <option value="monthly">{t('Monthly')}</option>
                            </select>
                            <InputError message={errors.frequency} />
                        </div>
                        <div>
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
                        <div>
                            <Label for="check-in-timezone"
                                >{t('Timezone')}</Label
                            >
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

                    <fieldset class="space-y-2">
                        <legend class="text-sm font-medium"
                            >{t('People to ask')}</legend
                        >
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
                                    <span class="min-w-0 truncate"
                                        >{member.name}</span
                                    >
                                </label>
                            {/each}
                        </div>
                        <InputError message={errors.target_user_ids} />
                    </fieldset>

                    <Button type="submit" disabled={processing}>
                        {t('Create check-in')}
                    </Button>
                {/snippet}
            </Form>
        </CardContent>
    </Card>

    <section class="space-y-3">
        <div>
            <h2 class="text-lg font-semibold">{t('Awaiting your answer')}</h2>
            <p class="text-sm text-muted-foreground">
                {t('Answer the latest questions assigned to you.')}
            </p>
        </div>

        {#if pendingPrompts.length === 0}
            <Card>
                <CardContent
                    class="py-8 text-center text-sm text-muted-foreground"
                >
                    {t('No unanswered check-ins')}
                </CardContent>
            </Card>
        {:else}
            <div class="grid gap-4 lg:grid-cols-2">
                {#each pendingPrompts as prompt (prompt.id)}
                    <Card>
                        <CardHeader>
                            <CardTitle class="break-words text-base">
                                {prompt.question}
                            </CardTitle>
                            <p class="text-xs text-muted-foreground">
                                {formatDate(prompt.scheduledFor)}
                            </p>
                        </CardHeader>
                        <CardContent>
                            <Form
                                {...CheckInController.answer.form([
                                    currentTeam.slug,
                                    project.id,
                                    prompt.checkInId,
                                    prompt.id,
                                ])}
                                class="space-y-3"
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
                                    />
                                    <InputError message={errors.answer} />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        {t('Submit answer')}
                                    </Button>
                                {/snippet}
                            </Form>
                        </CardContent>
                    </Card>
                {/each}
            </div>
        {/if}
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-semibold">{t('Recurring questions')}</h2>
        {#if checkIns.length === 0}
            <Card>
                <CardContent
                    class="py-8 text-center text-sm text-muted-foreground"
                >
                    {t('No check-ins yet')}
                </CardContent>
            </Card>
        {:else}
            <div class="grid gap-4 lg:grid-cols-2">
                {#each checkIns as checkIn (checkIn.id)}
                    <Card class={checkIn.paused ? 'opacity-75' : ''}>
                        <CardHeader>
                            <div
                                class="flex min-w-0 items-start justify-between gap-3"
                            >
                                <CardTitle class="break-words text-base">
                                    {checkIn.question}
                                </CardTitle>
                                <Badge
                                    variant={checkIn.paused
                                        ? 'outline'
                                        : 'secondary'}
                                >
                                    {checkIn.paused
                                        ? t('Paused')
                                        : frequencyLabel(checkIn.frequency)}
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <dl class="space-y-2 text-sm">
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        {t('Next question')}
                                    </dt>
                                    <dd>
                                        {formatDate(
                                            checkIn.nextRunAt,
                                            checkIn.timezone,
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        {t('People to ask')}
                                    </dt>
                                    <dd class="break-words">
                                        {checkIn.targetNames.join('、')}
                                    </dd>
                                </div>
                            </dl>
                            <Badge variant="outline">
                                {t(':count unanswered', {
                                    count: String(checkIn.pendingCount),
                                })}
                            </Badge>

                            {#if checkIn.canManage || checkIn.canDelete}
                                <div class="flex flex-wrap gap-2 border-t pt-3">
                                    {#if checkIn.canManage}
                                        <Form
                                            {...CheckInController.togglePause.form(
                                                [
                                                    currentTeam.slug,
                                                    project.id,
                                                    checkIn.id,
                                                ],
                                            )}
                                        >
                                            {#snippet children({ processing })}
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    {checkIn.paused
                                                        ? t('Resume')
                                                        : t('Pause')}
                                                </Button>
                                            {/snippet}
                                        </Form>
                                    {/if}
                                    {#if checkIn.canDelete}
                                        <Form
                                            {...CheckInController.destroy.form([
                                                currentTeam.slug,
                                                project.id,
                                                checkIn.id,
                                            ])}
                                            onsubmit={confirmDelete}
                                        >
                                            {#snippet children({ processing })}
                                                <Button
                                                    type="submit"
                                                    variant="destructive"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    {t('Delete')}
                                                </Button>
                                            {/snippet}
                                        </Form>
                                    {/if}
                                </div>
                            {/if}
                        </CardContent>
                    </Card>
                {/each}
            </div>
        {/if}
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-semibold">{t('Past answers')}</h2>
        {#if responses.length === 0}
            <Card>
                <CardContent
                    class="py-8 text-center text-sm text-muted-foreground"
                >
                    {t('No answers yet')}
                </CardContent>
            </Card>
        {:else}
            <div class="space-y-3">
                {#each responses as response (response.id)}
                    <Card>
                        <CardContent class="space-y-2 pt-6">
                            <p class="break-words text-sm font-semibold">
                                {response.question}
                            </p>
                            <RichTextContent
                                content={response.answer}
                                class="text-sm leading-6"
                            />
                            <p class="text-xs text-muted-foreground">
                                {response.userName || t('Deleted user')} · {formatDate(
                                    response.answeredAt,
                                )}
                            </p>
                        </CardContent>
                    </Card>
                {/each}
            </div>
        {/if}
    </section>
</div>
