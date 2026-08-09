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
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import CheckCircle2 from 'lucide-svelte/icons/circle-check-big';
    import FileText from 'lucide-svelte/icons/file-text';
    import History from 'lucide-svelte/icons/history';
    import MessageCircle from 'lucide-svelte/icons/message-circle';
    import Search from 'lucide-svelte/icons/search';
    import AppHead from '@/components/AppHead.svelte';
    import { Avatar, AvatarFallback } from '@/components/ui/avatar';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { activityAction, activityHref } from '@/lib/activity';
    import { currentLocale, t } from '@/lib/i18n';
    import { getInitials } from '@/lib/initials';
    import { show as showProject } from '@/routes/projects';
    import type {
        ActivityFilters,
        ActivityItem,
        ActivityPerson,
        ProjectTool,
    } from '@/types';

    type ActivePerson = {
        key: string;
        name: string;
    };
    type ProjectActivityGroup = {
        key: number;
        project: ProjectTool;
        activities: ActivityItem[];
    };
    type ActivityGroup = {
        key: string;
        label: string;
        activePeople: ActivePerson[];
        projects: ProjectActivityGroup[];
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

    const dateKeyFormatter = new Intl.DateTimeFormat(currentLocale(), {
        timeZone: 'Asia/Tokyo',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });
    const dateLabelFormatter = new Intl.DateTimeFormat(currentLocale(), {
        timeZone: 'Asia/Tokyo',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        weekday: 'long',
    });
    const timeFormatter = new Intl.DateTimeFormat(currentLocale(), {
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

        return grouped.map((group) => {
            const activePeople: ActivePerson[] = [];
            const projectGroups: ProjectActivityGroup[] = [];

            for (const activity of group.activities) {
                const actorKey = String(activity.actorId ?? 'deleted-user');

                if (!activePeople.some((person) => person.key === actorKey)) {
                    activePeople.push({
                        key: actorKey,
                        name: activity.actorName || t('Deleted user'),
                    });
                }

                const projectGroup = projectGroups.find(
                    (candidate) => candidate.key === activity.project.id,
                );

                if (projectGroup) {
                    projectGroup.activities.push(activity);
                } else {
                    projectGroups.push({
                        key: activity.project.id,
                        project: activity.project,
                        activities: [activity],
                    });
                }
            }

            return {
                key: group.key,
                label: formatGroupLabel(group.date, group.key),
                activePeople,
                projects: projectGroups,
            };
        });
    };
    const hasFilters = (): boolean =>
        Boolean(filters.q || filters.project || filters.user);
</script>

<AppHead title={t('Latest activity')} />

<div
    class="min-h-[calc(100vh-7rem)] bg-slate-100/70 px-3 py-5 sm:px-6 sm:py-8 dark:bg-slate-950/50"
>
    <main
        class="mx-auto w-full max-w-6xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[0_16px_48px_rgba(15,23,42,0.07)] dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/25"
    >
        <header
            class="border-b border-slate-200 px-5 py-6 sm:px-10 sm:py-8 dark:border-slate-700"
        >
            <div>
                <p
                    class="text-sm font-semibold text-emerald-700 dark:text-emerald-300"
                >
                    {currentTeam.name}
                </p>
                <h1
                    class="mt-1 text-3xl font-black tracking-[-0.035em] text-slate-900 sm:text-4xl dark:text-slate-50"
                >
                    {t('Latest activity')}
                </h1>
                <p
                    class="mt-2 max-w-2xl text-sm leading-6 text-slate-500 dark:text-slate-400"
                >
                    {t(
                        'See what everyone is working on and join the conversation.',
                    )}
                </p>
            </div>

            <form
                onsubmit={submitFilters}
                class="mt-5 flex flex-col gap-2.5 sm:flex-row sm:flex-wrap sm:items-center"
            >
                <div
                    class="inline-flex h-9 w-fit items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-800 shadow-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                >
                    <History class="size-4" />
                    {t('Timeline')}
                </div>

                <label class="sr-only" for="activity-project">
                    {t('All projects')}
                </label>
                <select
                    id="activity-project"
                    name="project"
                    onchange={submitSelect}
                    class="h-9 min-w-40 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 shadow-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-600/20 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
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
                    class="h-9 min-w-36 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 shadow-sm outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-600/20 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
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

                <div class="relative min-w-0 flex-1 sm:min-w-48 sm:max-w-xs">
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
                        class="h-9 rounded-lg border-slate-300 bg-white pl-9 dark:border-slate-600 dark:bg-slate-800"
                    />
                </div>
                <Button
                    type="submit"
                    disabled={processing}
                    size="sm"
                    class="h-9 rounded-lg px-4"
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
            <div class="space-y-10 px-5 py-7 sm:px-10 sm:py-9">
                {#each getActivityGroups() as group (group.key)}
                    <section aria-labelledby={`activity-day-${group.key}`}>
                        <div class="flex flex-wrap items-center gap-3">
                            <h2
                                id={`activity-day-${group.key}`}
                                class="w-fit rounded-md bg-slate-900 px-3 py-2 text-sm font-black tracking-wide text-white dark:bg-slate-100 dark:text-slate-900"
                            >
                                {group.label}
                            </h2>
                            <span
                                class="hidden h-px min-w-8 flex-1 bg-slate-200 sm:block dark:bg-slate-700"
                                aria-hidden="true"
                            ></span>
                            <div class="ml-auto flex items-center gap-2">
                                <span
                                    class="text-sm font-medium text-slate-500 dark:text-slate-300"
                                >
                                    {t(':count people active', {
                                        count: String(
                                            group.activePeople.length,
                                        ),
                                    })}
                                </span>
                                <div
                                    class="flex -space-x-2"
                                    aria-label={t(':count people active', {
                                        count: String(
                                            group.activePeople.length,
                                        ),
                                    })}
                                >
                                    {#each group.activePeople.slice(0, 8) as person (person.key)}
                                        <span title={person.name}>
                                            <Avatar
                                                class="size-7 border-2 border-white dark:border-slate-900"
                                            >
                                                <AvatarFallback
                                                    class="bg-slate-700 text-[0.58rem] font-bold text-white dark:bg-slate-200 dark:text-slate-900"
                                                >
                                                    {getInitials(person.name)}
                                                </AvatarFallback>
                                            </Avatar>
                                        </span>
                                    {/each}
                                    {#if group.activePeople.length > 8}
                                        <span
                                            class="flex size-7 items-center justify-center rounded-full border-2 border-white bg-slate-200 text-[0.6rem] font-bold text-slate-700 dark:border-slate-900 dark:bg-slate-700 dark:text-slate-100"
                                        >
                                            +{group.activePeople.length - 8}
                                        </span>
                                    {/if}
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 space-y-7">
                            {#each group.projects as projectGroup (projectGroup.key)}
                                <section
                                    aria-labelledby={`activity-project-${group.key}-${projectGroup.key}`}
                                >
                                    <h3
                                        id={`activity-project-${group.key}-${projectGroup.key}`}
                                    >
                                        <Link
                                            href={showProject([
                                                currentTeam.slug,
                                                projectGroup.project.id,
                                            ])}
                                            class="inline-flex max-w-full items-center rounded-full bg-slate-800 px-3 py-1.5 text-sm font-bold text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                                        >
                                            <span class="truncate">
                                                {projectGroup.project.name}
                                            </span>
                                        </Link>
                                    </h3>

                                    <ol
                                        class="mt-2 divide-y divide-slate-200 border-b border-slate-200 dark:divide-slate-700 dark:border-slate-700"
                                    >
                                        {#each projectGroup.activities as activity (activity.id)}
                                            <li
                                                class="grid grid-cols-[2rem_minmax(0,1fr)] gap-3 py-3"
                                            >
                                                <span
                                                    class="flex size-8 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300"
                                                >
                                                    {#if activity.target === 'messages'}
                                                        <MessageCircle
                                                            class="size-4"
                                                        />
                                                    {:else if activity.target === 'todos'}
                                                        <CheckCircle2
                                                            class="size-4"
                                                        />
                                                    {:else if activity.target === 'schedule'}
                                                        <CalendarDays
                                                            class="size-4"
                                                        />
                                                    {:else if activity.target === 'documents' || activity.target === 'files' || activity.target === 'materials'}
                                                        <FileText
                                                            class="size-4"
                                                        />
                                                    {:else}
                                                        <History
                                                            class="size-4"
                                                        />
                                                    {/if}
                                                </span>

                                                <article class="min-w-0">
                                                    <div
                                                        class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1"
                                                    >
                                                        <Avatar
                                                            class="size-5 border border-slate-200 dark:border-slate-700"
                                                        >
                                                            <AvatarFallback
                                                                class="bg-slate-200 text-[0.5rem] font-bold text-slate-700 dark:bg-slate-700 dark:text-slate-100"
                                                            >
                                                                {getInitials(
                                                                    activity.actorName ||
                                                                        t(
                                                                            'Deleted user',
                                                                        ),
                                                                )}
                                                            </AvatarFallback>
                                                        </Avatar>
                                                        <p
                                                            class="min-w-0 text-sm leading-5 text-slate-600 dark:text-slate-300"
                                                        >
                                                            <strong
                                                                class="font-bold text-slate-900 dark:text-slate-100"
                                                            >
                                                                {activity.actorName ||
                                                                    t(
                                                                        'Deleted user',
                                                                    )}
                                                            </strong>
                                                            {activityAction(
                                                                activity.type,
                                                            )}
                                                        </p>
                                                        <time
                                                            datetime={activity.createdAt ||
                                                                undefined}
                                                            class="ml-auto text-xs tabular-nums text-slate-400"
                                                        >
                                                            {formatTime(
                                                                activity.createdAt,
                                                            )}
                                                        </time>
                                                    </div>
                                                    <Link
                                                        href={activityHref(
                                                            currentTeam.slug,
                                                            activity,
                                                        )}
                                                        class="mt-0.5 block break-words text-[0.95rem] font-semibold text-sky-700 hover:underline dark:text-sky-400"
                                                    >
                                                        {activity.subjectLabel}
                                                    </Link>
                                                    {#if canComment(activity)}
                                                        <Link
                                                            href={activityHref(
                                                                currentTeam.slug,
                                                                activity,
                                                            )}
                                                            class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-slate-500 hover:text-sky-700 dark:text-slate-400 dark:hover:text-sky-400"
                                                        >
                                                            <MessageCircle
                                                                class="size-3"
                                                            />
                                                            {t(
                                                                'View and comment',
                                                            )}
                                                        </Link>
                                                    {/if}
                                                </article>
                                            </li>
                                        {/each}
                                    </ol>
                                </section>
                            {/each}
                        </div>
                    </section>
                {/each}
            </div>
        {/if}
    </main>
</div>
