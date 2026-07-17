<script module lang="ts">
    import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
    import type { Team } from '@/types';

    export const layout = (props: { currentTeam: Team }) => ({
        breadcrumbs: [
            {
                title: 'Activity',
                href: ActivityController(props.currentTeam.slug),
            },
        ],
    });
</script>

<script lang="ts">
    import { Link, router } from '@inertiajs/svelte';
    import ArrowRight from 'lucide-svelte/icons/arrow-right';
    import History from 'lucide-svelte/icons/history';
    import MessageCircle from 'lucide-svelte/icons/message-circle';
    import Search from 'lucide-svelte/icons/search';
    import Users from 'lucide-svelte/icons/users';
    import AppHead from '@/components/AppHead.svelte';
    import { Avatar, AvatarFallback } from '@/components/ui/avatar';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { activityAction, activityHref } from '@/lib/activity';
    import { t } from '@/lib/i18n';
    import { getInitials } from '@/lib/initials';
    import type {
        ActivityFilters,
        ActivityItem,
        ActivityPerson,
        ProjectTool,
    } from '@/types';

    type ActivityGroup = {
        key: string;
        label: string;
        activePeople: number;
        activities: ActivityItem[];
    };

    let {
        currentTeam,
        activities = [],
        projects = [],
        people = [],
        filters = { q: null, project: null, user: null },
    }: {
        currentTeam: Team;
        activities?: ActivityItem[];
        projects?: ProjectTool[];
        people?: ActivityPerson[];
        filters?: ActivityFilters;
    } = $props();
    let processing = $state(false);

    const dateKeyFormatter = new Intl.DateTimeFormat('ja-JP', {
        timeZone: 'Asia/Tokyo',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });
    const dateLabelFormatter = new Intl.DateTimeFormat('ja-JP', {
        timeZone: 'Asia/Tokyo',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        weekday: 'long',
    });
    const timeFormatter = new Intl.DateTimeFormat('ja-JP', {
        timeZone: 'Asia/Tokyo',
        hour: '2-digit',
        minute: '2-digit',
    });
    const dateKey = (date: Date): string => dateKeyFormatter.format(date);
    const todayKey = dateKey(new Date());
    const yesterdayKey = dateKey(new Date(Date.now() - 86_400_000));
    const formatGroupLabel = (date: Date, key: string): string => {
        if (key === todayKey) {
            return t('Today');
        }

        if (key === yesterdayKey) {
            return t('Yesterday');
        }

        return dateLabelFormatter.format(date);
    };
    const formatTime = (value: string | null): string =>
        value ? timeFormatter.format(new Date(value)) : '';
    const submitSelect = (event: Event): void => {
        (event.currentTarget as HTMLSelectElement).form?.requestSubmit();
    };
    const visitFilters = (data: Record<string, string>): void => {
        router.get(ActivityController(currentTeam.slug).url, data, {
            preserveState: true,
            replace: true,
            onStart: () => (processing = true),
            onFinish: () => (processing = false),
        });
    };
    const submitFilters = (event: SubmitEvent): void => {
        event.preventDefault();

        const formData = new FormData(event.currentTarget as HTMLFormElement);

        visitFilters({
            project: String(formData.get('project') ?? ''),
            user: String(formData.get('user') ?? ''),
            q: String(formData.get('q') ?? ''),
        });
    };
    const canComment = (activity: ActivityItem): boolean =>
        activity.target === 'messages' || activity.target === 'todos';

    const getActivityGroups = (): ActivityGroup[] => {
        const grouped: Array<{
            key: string;
            date: Date;
            activities: ActivityItem[];
        }> = [];

        for (const activity of activities) {
            const date = activity.createdAt
                ? new Date(activity.createdAt)
                : new Date(0);
            const key = dateKey(date);
            const currentGroup = grouped.at(-1);

            if (currentGroup?.key === key) {
                currentGroup.activities.push(activity);
            } else {
                grouped.push({ key, date, activities: [activity] });
            }
        }

        return grouped.map((group) => ({
            key: group.key,
            label: formatGroupLabel(group.date, group.key),
            activePeople: new Set(
                group.activities.map(
                    (activity) => activity.actorId ?? 'deleted-user',
                ),
            ).size,
            activities: group.activities,
        }));
    };
    const hasFilters = (): boolean =>
        Boolean(filters.q || filters.project || filters.user);
</script>

<AppHead title={t('Latest activity')} />

<div
    class="min-h-[calc(100vh-7rem)] bg-[#f2f7fc] px-3 py-5 sm:px-6 sm:py-8 dark:bg-[#07161d]"
>
    <main
        class="mx-auto w-full max-w-6xl overflow-hidden rounded-[1.75rem] border border-slate-200/90 bg-white shadow-[0_22px_65px_rgba(15,23,42,0.1)] dark:border-slate-700/80 dark:bg-[#14242c] dark:shadow-black/25"
    >
        <header
            class="border-b border-slate-200 px-5 py-7 sm:px-10 sm:py-10 dark:border-slate-700"
        >
            <div class="flex items-start gap-4">
                <span
                    class="mt-1 flex size-11 shrink-0 items-center justify-center rounded-full bg-[#dceee1] text-[#246447] dark:bg-[#25453a] dark:text-[#9dd6b3]"
                >
                    <History class="size-5" />
                </span>
                <div>
                    <p
                        class="text-sm font-semibold text-[#347552] dark:text-[#9dd6b3]"
                    >
                        {currentTeam.name}
                    </p>
                    <h1
                        class="mt-1 text-3xl font-black tracking-[-0.035em] text-slate-900 sm:text-5xl dark:text-slate-50"
                    >
                        {t('Latest activity')}
                    </h1>
                    <p
                        class="mt-3 max-w-2xl text-sm leading-6 text-slate-600 sm:text-base dark:text-slate-300"
                    >
                        {t(
                            'See what everyone is working on and join the conversation.',
                        )}
                    </p>
                </div>
            </div>

            <form
                onsubmit={submitFilters}
                class="mt-7 flex flex-col gap-3 lg:flex-row lg:items-center"
            >
                <div
                    class="inline-flex w-fit rounded-xl border border-slate-300 bg-slate-100 p-1 dark:border-slate-600 dark:bg-[#0e1e26]"
                >
                    <span
                        class="rounded-lg bg-white px-4 py-2 text-sm font-bold text-slate-900 shadow-sm dark:bg-[#243740] dark:text-white"
                    >
                        {t('Timeline')}
                    </span>
                </div>

                <label class="sr-only" for="activity-project">
                    {t('All projects')}
                </label>
                <select
                    id="activity-project"
                    name="project"
                    onchange={submitSelect}
                    class="h-11 min-w-44 rounded-xl border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-800 shadow-sm outline-none focus:border-[#4d8f6c] focus:ring-2 focus:ring-[#4d8f6c]/20 dark:border-slate-600 dark:bg-[#0e1e26] dark:text-slate-100"
                >
                    <option value="">{t('All projects')}</option>
                    {#each projects as project (project.id)}
                        <option
                            value={project.id}
                            selected={filters.project === project.id}
                        >
                            {project.name}
                        </option>
                    {/each}
                </select>

                <label class="sr-only" for="activity-user">
                    {t('Everyone')}
                </label>
                <select
                    id="activity-user"
                    name="user"
                    onchange={submitSelect}
                    class="h-11 min-w-40 rounded-xl border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-800 shadow-sm outline-none focus:border-[#4d8f6c] focus:ring-2 focus:ring-[#4d8f6c]/20 dark:border-slate-600 dark:bg-[#0e1e26] dark:text-slate-100"
                >
                    <option value="">{t('Everyone')}</option>
                    {#each people as person (person.id)}
                        <option
                            value={person.id}
                            selected={filters.user === person.id}
                        >
                            {person.name}
                        </option>
                    {/each}
                </select>

                <div class="relative min-w-0 flex-1 lg:max-w-xs">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400"
                    />
                    <Input
                        id="activity-search"
                        name="q"
                        type="search"
                        maxlength={100}
                        value={filters.q ?? ''}
                        placeholder={t('Search activity')}
                        class="h-11 rounded-xl border-slate-300 bg-white pl-9 dark:border-slate-600 dark:bg-[#0e1e26]"
                    />
                </div>
                <Button
                    type="submit"
                    disabled={processing}
                    class="h-11 rounded-xl px-5"
                >
                    {t('Filter')}
                </Button>
                {#if hasFilters()}
                    <button
                        type="button"
                        onclick={() => visitFilters({})}
                        class="px-2 text-center text-sm font-semibold text-slate-600 underline decoration-slate-300 underline-offset-4 hover:text-slate-950 dark:text-slate-300 dark:hover:text-white"
                    >
                        {t('Clear')}
                    </button>
                {/if}
            </form>
        </header>

        {#if getActivityGroups().length === 0}
            <div
                class="flex min-h-80 flex-col items-center justify-center px-6 py-16 text-center"
            >
                <History class="mb-4 size-11 text-slate-400" />
                <h2 class="text-lg font-bold text-slate-900 dark:text-slate-50">
                    {hasFilters()
                        ? t('No activity matches these filters')
                        : t('No activity yet')}
                </h2>
                <p
                    class="mt-2 max-w-md text-sm text-slate-500 dark:text-slate-400"
                >
                    {t('Project updates will appear here.')}
                </p>
            </div>
        {:else}
            <div class="space-y-10 px-5 py-8 sm:px-10 sm:py-10">
                {#each getActivityGroups() as group (group.key)}
                    <section aria-labelledby={`activity-day-${group.key}`}>
                        <div
                            class="grid items-center gap-3 sm:grid-cols-[auto_minmax(2rem,1fr)_auto]"
                        >
                            <h2
                                id={`activity-day-${group.key}`}
                                class="w-fit rounded-lg bg-slate-900 px-4 py-2 text-sm font-black tracking-wide text-white sm:text-base dark:bg-slate-100 dark:text-slate-900"
                            >
                                {group.label}
                            </h2>
                            <span
                                class="hidden h-px bg-slate-200 sm:block dark:bg-slate-700"
                                aria-hidden="true"
                            ></span>
                            <span
                                class="flex items-center gap-2 text-sm font-semibold text-slate-500 dark:text-slate-300"
                            >
                                <Users class="size-4" />
                                {t(':count people active', {
                                    count: String(group.activePeople),
                                })}
                            </span>
                        </div>

                        <ol class="mt-6 space-y-5">
                            {#each group.activities as activity, index (activity.id)}
                                <li
                                    class="grid grid-cols-[3.5rem_2.5rem_minmax(0,1fr)] items-start gap-2 sm:grid-cols-[4.5rem_3rem_minmax(0,1fr)] sm:gap-3"
                                >
                                    <time
                                        datetime={activity.createdAt || ''}
                                        class="pt-2 text-right text-xs font-semibold tabular-nums text-slate-500 sm:text-sm dark:text-slate-400"
                                    >
                                        {formatTime(activity.createdAt)}
                                    </time>
                                    <div class="relative flex justify-center">
                                        <Avatar
                                            class="z-10 size-10 border-2 border-white shadow-sm dark:border-[#14242c]"
                                        >
                                            <AvatarFallback
                                                class="bg-[#5f63db] text-xs font-bold text-white"
                                            >
                                                {getInitials(
                                                    activity.actorName ||
                                                        t('Deleted user'),
                                                )}
                                            </AvatarFallback>
                                        </Avatar>
                                        {#if index < group.activities.length - 1}
                                            <span
                                                class="absolute top-10 bottom-[-1.5rem] left-1/2 w-px -translate-x-1/2 bg-slate-200 dark:bg-slate-700"
                                                aria-hidden="true"
                                            ></span>
                                        {/if}
                                    </div>

                                    <article
                                        class="min-w-0 rounded-2xl border border-transparent px-3 py-2 transition hover:border-slate-200 hover:bg-slate-50 dark:hover:border-slate-700 dark:hover:bg-[#1a2d36]"
                                    >
                                        <Link
                                            href={activityHref(
                                                currentTeam.slug,
                                                activity,
                                            )}
                                            class="group block focus-visible:rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4d8f6c]"
                                        >
                                            <span
                                                class="flex min-w-0 items-start justify-between gap-3"
                                            >
                                                <span class="min-w-0">
                                                    <span
                                                        class="block text-sm leading-6 text-slate-600 dark:text-slate-300"
                                                    >
                                                        <strong
                                                            class="font-extrabold text-slate-900 dark:text-white"
                                                        >
                                                            {activity.actorName ||
                                                                t(
                                                                    'Deleted user',
                                                                )}
                                                        </strong>
                                                        <span>
                                                            {activityAction(
                                                                activity.type,
                                                            )}
                                                        </span>
                                                    </span>
                                                    <span
                                                        class="mt-0.5 block break-words text-base font-bold text-[#2176c7] underline decoration-[#2176c7]/35 underline-offset-4 group-hover:decoration-[#2176c7] dark:text-[#6db8ff]"
                                                    >
                                                        {activity.subjectLabel}
                                                    </span>
                                                </span>
                                                <ArrowRight
                                                    class="mt-2 size-4 shrink-0 text-slate-400 transition group-hover:translate-x-0.5"
                                                />
                                            </span>
                                        </Link>
                                        <div
                                            class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs"
                                        >
                                            <span
                                                class="rounded-full bg-[#e5f1e8] px-2.5 py-1 font-semibold text-[#286345] dark:bg-[#244438] dark:text-[#a6ddbb]"
                                            >
                                                {activity.project.name}
                                            </span>
                                            {#if canComment(activity)}
                                                <Link
                                                    href={activityHref(
                                                        currentTeam.slug,
                                                        activity,
                                                    )}
                                                    class="inline-flex items-center gap-1.5 font-bold text-slate-600 hover:text-[#2176c7] dark:text-slate-300 dark:hover:text-[#6db8ff]"
                                                >
                                                    <MessageCircle
                                                        class="size-3.5"
                                                    />
                                                    {t('View and comment')}
                                                </Link>
                                            {/if}
                                        </div>
                                    </article>
                                </li>
                            {/each}
                        </ol>
                    </section>
                {/each}
            </div>
        {/if}
    </main>
</div>
