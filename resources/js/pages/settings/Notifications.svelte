<script module lang="ts">
    import { edit } from '@/routes/notification-settings';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Notification settings',
                href: edit(),
            },
        ],
    };
</script>

<script lang="ts">
    import { useForm } from '@inertiajs/svelte';
    import Bell from 'lucide-svelte/icons/bell';
    import NotificationSettingsController from '@/actions/App/Http/Controllers/Settings/NotificationSettingsController';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';

    type NotificationPreferences = {
        enabled: boolean;
        scope: 'everything' | 'mentions';
        event_reminders: boolean;
        assignment_reminders: boolean;
        in_app: boolean;
        email: boolean;
        show_unread_count: boolean;
        schedule: 'always' | 'work_hours';
        work_days: number[];
        work_start: string;
        work_end: string;
        timezone: string;
    };

    let {
        preferences,
        timezones,
    }: {
        preferences: NotificationPreferences;
        timezones: string[];
    } = $props();

    const weekdays = [
        { value: 1, label: 'Mon' },
        { value: 2, label: 'Tue' },
        { value: 3, label: 'Wed' },
        { value: 4, label: 'Thu' },
        { value: 5, label: 'Fri' },
        { value: 6, label: 'Sat' },
        { value: 7, label: 'Sun' },
    ];

    // The form needs a stable initial snapshot; later edits belong to the form.
    // svelte-ignore state_referenced_locally
    const form = useForm({ ...preferences });

    function submit(event: SubmitEvent) {
        event.preventDefault();
        form.patch(NotificationSettingsController.update.url(), {
            preserveScroll: true,
        });
    }
</script>

<AppHead title={t('Notification settings')} />

<h1 class="sr-only">{t('Notification settings')}</h1>

<div class="space-y-8">
    <Heading
        variant="small"
        title={t('Notification settings')}
        description={t('Choose what reaches you and when')}
    />

    <Alert class="bg-muted/40 py-5">
        <Bell />
        <AlertTitle>
            {form.enabled
                ? t('Notifications are on')
                : t('Notifications are off')}
        </AlertTitle>
        <AlertDescription>
            <p>
                {form.enabled
                    ? t('You will receive notifications using the channels selected below.')
                    : t('Mentions, activity notifications, and reminder notifications are paused.')}
            </p>
            <Button
                type="button"
                variant="link"
                class="h-auto px-0"
                onclick={() => (form.enabled = !form.enabled)}
            >
                {form.enabled
                    ? t('Turn notifications off')
                    : t('Turn notifications on')}
            </Button>
        </AlertDescription>
    </Alert>

    <form class="space-y-10" onsubmit={submit}>
        <fieldset class="space-y-4">
            <legend class="section-heading">{t('What?')}</legend>

            <label class="option-row">
                <input
                    type="radio"
                    bind:group={form.scope}
                    value="everything"
                />
                <span>
                    <span class="option-title">{t('Notify me about everything')}</span>
                    <span class="option-description">
                        {t('Includes comments, assignments, completions, and mentions.')}
                    </span>
                </span>
            </label>

            <label class="option-row">
                <input
                    type="radio"
                    bind:group={form.scope}
                    value="mentions"
                />
                <span>
                    <span class="option-title">
                        {t('Only notify me about direct messages and mentions')}
                    </span>
                    <span class="option-description">
                        {t('Other activity remains available in each project.')}
                    </span>
                </span>
            </label>
            <InputError message={form.errors.scope} />

            <div class="space-y-3 pt-2">
                <h3 class="font-semibold">{t('Reminders')}</h3>
                <label class="option-row">
                    <input
                        type="checkbox"
                        bind:checked={form.event_reminders}
                    />
                    <span>
                        <span class="option-title">
                            {t('Show upcoming event reminders')}
                        </span>
                        <span class="option-description">
                            {t('Shows a reminder in the workspace bar shortly before an event starts.')}
                        </span>
                    </span>
                </label>
                <label class="option-row">
                    <input
                        type="checkbox"
                        bind:checked={form.assignment_reminders}
                    />
                    <span>
                        <span class="option-title">
                            {t('Notify me about upcoming assignments')}
                        </span>
                        <span class="option-description">
                            {t('Sends an in-app or email reminder when an assigned to-do is due.')}
                        </span>
                    </span>
                </label>
            </div>
        </fieldset>

        <fieldset class="space-y-4">
            <legend class="section-heading">{t('How?')}</legend>

            <label class="option-row">
                <input type="checkbox" bind:checked={form.email} />
                <span>
                    <span class="option-title">{t('Send me email notifications')}</span>
                    <span class="option-description">
                        {t('Email follows the work-hours schedule below.')}
                    </span>
                </span>
            </label>

            <label class="option-row">
                <input type="checkbox" bind:checked={form.in_app} />
                <span>
                    <span class="option-title">{t('Keep notifications in the app')}</span>
                    <span class="option-description">
                        {t('The in-app inbox stays available outside work hours.')}
                    </span>
                </span>
            </label>

            <label class="option-row">
                <input
                    type="checkbox"
                    bind:checked={form.show_unread_count}
                />
                <span>
                    <span class="option-title">{t('Show the number of unread items')}</span>
                    <span class="option-description">
                        {t('Display an unread badge in the header and sidebar.')}
                    </span>
                </span>
            </label>
        </fieldset>

        <fieldset class="space-y-4">
            <legend class="section-heading">{t('When?')}</legend>

            <label class="option-row">
                <input
                    type="radio"
                    bind:group={form.schedule}
                    value="always"
                />
                <span class="option-title">{t('Always')}</span>
            </label>

            <label class="option-row">
                <input
                    type="radio"
                    bind:group={form.schedule}
                    value="work_hours"
                />
                <span>
                    <span class="option-title">{t('Only during my work hours')}</span>
                    <span class="option-description">
                        {t('Outside these hours, email is not sent while in-app notifications remain available.')}
                    </span>
                </span>
            </label>

            {#if form.schedule === 'work_hours'}
                <div class="ml-7 grid gap-5 rounded-lg border bg-muted/30 p-4">
                    <div class="grid gap-2">
                        <Label>{t('Work days')}</Label>
                        <div class="flex flex-wrap gap-3">
                            {#each weekdays as day (day.value)}
                                <label class="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        bind:group={form.work_days}
                                        value={day.value}
                                    />
                                    {t(day.label)}
                                </label>
                            {/each}
                        </div>
                        <InputError message={form.errors.work_days} />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="work-start">{t('Start time')}</Label>
                            <Input
                                id="work-start"
                                type="time"
                                bind:value={form.work_start}
                            />
                            <InputError message={form.errors.work_start} />
                        </div>
                        <div class="grid gap-2">
                            <Label for="work-end">{t('End time')}</Label>
                            <Input
                                id="work-end"
                                type="time"
                                bind:value={form.work_end}
                            />
                            <InputError message={form.errors.work_end} />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="timezone">{t('Time zone')}</Label>
                        <select
                            id="timezone"
                            bind:value={form.timezone}
                            class="border-input bg-background focus-visible:border-ring focus-visible:ring-ring/50 h-9 w-full rounded-md border px-3 text-sm outline-none focus-visible:ring-[3px]"
                        >
                            {#each timezones as timezone (timezone)}
                                <option value={timezone}>{timezone}</option>
                            {/each}
                        </select>
                        <InputError message={form.errors.timezone} />
                    </div>
                </div>
            {/if}
        </fieldset>

        <Button type="submit" disabled={form.processing}>
            {form.processing ? t('Saving...') : t('Save my settings')}
        </Button>
    </form>
</div>

<style>
    :global(.section-heading) {
        display: flex;
        width: 100%;
        align-items: center;
        gap: 0.75rem;
        font-size: 1.125rem;
        font-weight: 700;
    }

    :global(.section-heading)::after {
        height: 1px;
        flex: 1;
        content: '';
        background: var(--border);
    }

    :global(.option-row) {
        display: flex;
        cursor: pointer;
        align-items: flex-start;
        gap: 0.75rem;
    }

    :global(.option-row input) {
        margin-top: 0.2rem;
        height: 1rem;
        width: 1rem;
        accent-color: var(--primary);
    }

    :global(.option-title),
    :global(.option-description) {
        display: block;
    }

    :global(.option-title) {
        font-size: 0.875rem;
        font-weight: 500;
    }

    :global(.option-description) {
        margin-top: 0.125rem;
        font-size: 0.8125rem;
        line-height: 1.4;
        color: var(--muted-foreground);
    }
</style>
