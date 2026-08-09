<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import ArrowRight from 'lucide-svelte/icons/arrow-right';
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import ChartColumn from 'lucide-svelte/icons/chart-column';
    import CheckCircle from 'lucide-svelte/icons/circle-check';
    import FileText from 'lucide-svelte/icons/file-text';
    import FolderKanban from 'lucide-svelte/icons/folder-kanban';
    import MessageCircle from 'lucide-svelte/icons/message-circle';
    import ShieldCheck from 'lucide-svelte/icons/shield-check';
    import AppHead from '@/components/AppHead.svelte';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import LanguageSwitcher from '@/components/LanguageSwitcher.svelte';
    import { t } from '@/lib/i18n';
    import { toUrl } from '@/lib/utils';
    import { dashboard, login } from '@/routes';
    import type { Team } from '@/types';

    const auth = $derived(page.props.auth);
    const currentTeam = $derived(page.props.currentTeam as Team | null);
    const canOpenJin = $derived(Boolean(auth.user && currentTeam));
    const primaryHref = $derived(
        canOpenJin && currentTeam ? dashboard(currentTeam.slug) : login(),
    );
    const primaryLabel = $derived(canOpenJin ? t('Open JIN') : t('Log in'));

    const features = [
        {
            icon: FolderKanban,
            label: t('Stay aligned'),
            title: t('Your project hub and recent team activity'),
            description: t(
                'Bring boards, tasks, schedules, materials, and conversations together in one workspace. Follow the flow of work from recent team activity.',
            ),
        },
        {
            icon: CheckCircle,
            label: t('Move work forward'),
            title: t('Tasks, planning boards, and retrospectives'),
            description: t(
                'Make owners and deadlines clear, then organize progress in both lists and cards. Regular check-ins help prevent missed reports.',
            ),
        },
        {
            icon: CalendarDays,
            label: t('See the schedule'),
            title: t('Schedules and lineup'),
            description: t(
                'Switch between day, week, month, and Gantt views to see schedules and project timelines on one shared time axis.',
            ),
        },
        {
            icon: MessageCircle,
            label: t('Keep decisions moving'),
            title: t('Conversations, comments, and notifications'),
            description: t(
                'With chat, comments, and notifications powered by Laravel Reverb, teams can discuss and decide together in the moment.',
            ),
        },
        {
            icon: FileText,
            label: t('Preserve knowledge'),
            title: t('Materials library'),
            description: t(
                'Share files by drag and drop. Preview PDF, Office, and CSV files in place without downloading them.',
            ),
        },
        {
            icon: ChartColumn,
            label: t('See the whole picture'),
            title: t('Status, workload, and administration'),
            description: t(
                'Use cross-project reports and time entries to see the whole picture. Administrators can manage project and user permissions in one place.',
            ),
        },
    ];

    const screens = [
        {
            image: '/images/landing/project.webp?v=20260724-dummy',
            eyebrow: 'PROJECT HOME',
            title: t('Everything you need, in one place'),
            description: t(
                'When you open a project, recent activity and each tool’s status appear on one screen. No need to jump between services to find information.',
            ),
            points: [
                t('See up to three recent activities'),
                t('Review the main tools in one list'),
                t('Same information structure on mobile'),
            ],
            imageOrder: '',
        },
        {
            image: '/images/landing/calendar.webp?v=20260724-dummy',
            eyebrow: 'CALENDAR & LINEUP',
            title: t('From today’s schedule to months ahead'),
            description: t(
                'Switch between day, week, month, and Gantt views. See not only individual events but also periods when projects overlap.',
            ),
            points: [
                t('Day, week, month, and Gantt views'),
                t('Across all projects'),
                t('Subscribe from external calendars'),
            ],
            imageOrder: 'lg:order-2',
        },
        {
            image: '/images/landing/materials.webp?v=20260724-dummy',
            eyebrow: 'MATERIALS',
            title: t('Drop in your files. Find them effortlessly.'),
            description: t(
                'Drop files into the materials library, choose a destination, and sharing is done. Supported documents can be previewed right in the browser.',
            ),
            points: [
                t('Drag-and-drop support'),
                t('Organize with folders'),
                t('Preview PDF, Office, and CSV files'),
            ],
            imageOrder: '',
        },
    ];
</script>

<AppHead title="JIN（陣）">
    <meta
        name="description"
        content={t(
            'JIN is an internal workspace that brings projects, schedules, conversations, and materials together.',
        )}
    />
    <meta name="theme-color" content="#071017" />
</AppHead>

<div
    class="min-h-screen overflow-x-hidden bg-[#071017] text-slate-100 selection:bg-[#b44332] selection:text-white"
>
    <a
        href="#main-content"
        class="sr-only z-[100] rounded-md bg-white px-4 py-2 text-slate-950 focus:not-sr-only focus:fixed focus:top-4 focus:left-4"
    >
        {t('Skip to content')}
    </a>

    <header
        class="sticky top-0 z-50 border-b border-white/10 bg-[#071017]/85 backdrop-blur-xl"
    >
        <div
            class="mx-auto flex h-18 max-w-7xl items-center justify-between gap-4 px-5 sm:px-8 lg:px-10"
        >
            <a
                href="#main-content"
                class="flex items-center gap-3 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-amber-300"
                aria-label={t('JIN home')}
            >
                <AppLogoIcon class="size-11 shrink-0" />
                <span class="leading-none">
                    <span
                        class="block text-lg font-black tracking-[0.16em] text-white"
                        >陣（JIN）</span
                    >
                    <span
                        class="mt-1 block text-[10px] font-semibold tracking-[0.22em] text-amber-200/70"
                        >{t('TEAM WORKSPACE')}</span
                    >
                </span>
            </a>

            <nav
                class="hidden items-center gap-8 text-sm font-medium text-slate-300 md:flex"
                aria-label={t('In-page navigation')}
            >
                <a
                    href="#features"
                    class="rounded-sm outline-none transition-colors hover:text-white focus-visible:ring-2 focus-visible:ring-amber-300 motion-safe:duration-200"
                    >{t('Features')}</a
                >
                <a
                    href="#workflow"
                    class="rounded-sm outline-none transition-colors hover:text-white focus-visible:ring-2 focus-visible:ring-amber-300 motion-safe:duration-200"
                    >{t('Easy to use')}</a
                >
                <a
                    href="#screens"
                    class="rounded-sm outline-none transition-colors hover:text-white focus-visible:ring-2 focus-visible:ring-amber-300 motion-safe:duration-200"
                    >{t('Screens')}</a
                >
            </nav>

            <div class="flex shrink-0 items-center gap-2">
                <LanguageSwitcher dark compact />
                <Link
                    href={toUrl(primaryHref)}
                    prefetch
                    data-test="welcome-primary-action"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-amber-300/50 bg-amber-200 px-5 text-sm font-bold text-[#142029] shadow-[0_8px_24px_rgba(218,176,77,0.16)] outline-none transition hover:-translate-y-0.5 hover:bg-amber-100 focus-visible:ring-2 focus-visible:ring-amber-300 focus-visible:ring-offset-2 focus-visible:ring-offset-[#071017] motion-safe:duration-200"
                >
                    {primaryLabel}
                    <ArrowRight class="size-4" aria-hidden="true" />
                </Link>
            </div>
        </div>
    </header>

    <main id="main-content">
        <section
            class="relative border-b border-white/10 bg-[radial-gradient(circle_at_12%_8%,rgba(177,62,45,0.24),transparent_28%),radial-gradient(circle_at_86%_20%,rgba(218,176,77,0.14),transparent_26%)]"
            aria-labelledby="hero-heading"
        >
            <div
                class="pointer-events-none absolute inset-0 opacity-20 [background-image:linear-gradient(rgba(255,255,255,0.04)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,0.04)_1px,transparent_1px)] [background-size:72px_72px]"
                aria-hidden="true"
            ></div>
            <div
                class="relative mx-auto grid max-w-7xl gap-14 px-5 pt-20 pb-16 sm:px-8 sm:pt-28 sm:pb-24 lg:grid-cols-[0.82fr_1.18fr] lg:items-center lg:px-10 lg:pt-32"
            >
                <div>
                    <p
                        class="mb-6 inline-flex items-center gap-2 rounded-full border border-[#d7b45d]/30 bg-[#d7b45d]/8 px-4 py-2 text-xs font-bold tracking-[0.18em] text-amber-200"
                    >
                        <span class="size-1.5 rounded-full bg-[#c24f3a]"></span>
                        {t('One workspace for internal work')}
                    </p>
                    <h1
                        id="hero-heading"
                        class="max-w-3xl text-4xl leading-[1.12] font-black tracking-[-0.045em] text-balance text-white sm:text-6xl lg:text-7xl"
                    >
                        {t("Bring your team's work")}<br />{t(
                            'into one JIN workspace',
                        )}
                    </h1>
                    <p
                        class="mt-7 max-w-xl text-base leading-8 text-pretty text-slate-300 sm:text-lg"
                    >
                        {t(
                            'Bring projects, schedules, conversations, and materials together. JIN is an internal workspace where multiple teams can collaborate from the same view.',
                        )}
                    </p>
                    <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                        <Link
                            href={toUrl(primaryHref)}
                            prefetch
                            class="inline-flex min-h-12 items-center justify-center gap-2 rounded-full bg-[#b44332] px-6 text-sm font-bold text-white shadow-[0_14px_40px_rgba(180,67,50,0.28)] outline-none transition hover:-translate-y-0.5 hover:bg-[#c24f3a] focus-visible:ring-2 focus-visible:ring-[#e07b69] focus-visible:ring-offset-2 focus-visible:ring-offset-[#071017] motion-safe:duration-200"
                        >
                            {primaryLabel}
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </Link>
                        <a
                            href="#features"
                            class="inline-flex min-h-12 items-center justify-center rounded-full border border-white/15 bg-white/5 px-6 text-sm font-bold text-slate-100 outline-none transition hover:border-white/30 hover:bg-white/10 focus-visible:ring-2 focus-visible:ring-amber-300 motion-safe:duration-200"
                        >
                            {t('See what you can do')}
                        </a>
                    </div>
                    <dl
                        class="mt-11 grid max-w-xl grid-cols-1 gap-5 border-t border-white/10 pt-6 text-sm sm:grid-cols-3"
                    >
                        <div>
                            <dt class="font-bold text-white">
                                {t('Easy to find')}
                            </dt>
                            <dd class="mt-1 text-slate-400">
                                {t('One project screen')}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-bold text-white">
                                {t('Easy to follow')}
                            </dt>
                            <dd class="mt-1 text-slate-400">
                                {t(
                                    'Activity and conversations in chronological order',
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-bold text-white">
                                {t('Work anywhere')}
                            </dt>
                            <dd class="mt-1 text-slate-400">
                                {t('Works on smartphones too')}
                            </dd>
                        </div>
                    </dl>
                </div>

                <figure class="relative mx-auto w-full max-w-[780px]">
                    <div
                        class="absolute -inset-5 rounded-[2rem] bg-[linear-gradient(135deg,rgba(180,67,50,0.22),rgba(215,180,93,0.12),transparent)] blur-2xl"
                        aria-hidden="true"
                    ></div>
                    <div
                        class="relative overflow-hidden rounded-2xl border border-white/15 bg-[#0d1820] p-2 shadow-[0_30px_90px_rgba(0,0,0,0.45)] sm:p-3"
                    >
                        <div
                            class="mb-2 flex items-center gap-1.5 px-2 py-1 sm:mb-3"
                            aria-hidden="true"
                        >
                            <span class="size-2 rounded-full bg-[#b44332]"
                            ></span>
                            <span class="size-2 rounded-full bg-[#d7b45d]"
                            ></span>
                            <span class="size-2 rounded-full bg-emerald-600"
                            ></span>
                            <span
                                class="ml-2 text-[10px] font-semibold tracking-[0.16em] text-slate-500"
                                >{t('JIN / DASHBOARD')}</span
                            >
                        </div>
                        <img
                            src="/images/landing/dashboard.webp?v=20260724-dummy"
                            alt={t(
                                'JIN dashboard with projects and recent updates on one screen',
                            )}
                            width="1440"
                            height="900"
                            fetchpriority="high"
                            decoding="async"
                            class="aspect-[8/5] w-full rounded-lg object-cover object-top"
                        />
                    </div>
                    <figcaption class="sr-only">
                        {t('JIN dashboard using anonymous demo data')}
                    </figcaption>
                </figure>
            </div>
        </section>

        <section
            id="features"
            class="scroll-mt-24 border-b border-white/10 bg-[#0a141c] py-20 sm:py-28"
            aria-labelledby="features-heading"
        >
            <div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10">
                <div class="max-w-3xl">
                    <p
                        class="text-xs font-bold tracking-[0.22em] text-[#d7b45d]"
                    >
                        {t('ONE PLACE, ONE TEAM')}
                    </p>
                    <h2
                        id="features-heading"
                        class="mt-4 text-3xl font-black tracking-[-0.035em] text-white sm:text-5xl"
                    >
                        {t('All the information you need, together')}<br />{t(
                            'Decide clearly and move quickly',
                        )}
                    </h2>
                    <p
                        class="mt-6 max-w-2xl text-base leading-8 text-slate-400"
                    >
                        {t(
                            'The tools you need are organized by project, and updates reach the whole team. People always know where to look next.',
                        )}
                    </p>
                </div>

                <div class="mt-12 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {#each features as feature (feature.title)}
                        {@const Icon = feature.icon}
                        <article
                            class="group rounded-2xl border border-white/10 bg-white/[0.035] p-6 transition hover:-translate-y-1 hover:border-[#d7b45d]/35 hover:bg-white/[0.06] motion-safe:duration-300 sm:p-7"
                        >
                            <div
                                class="flex size-11 items-center justify-center rounded-xl border border-[#d7b45d]/25 bg-[#d7b45d]/10 text-amber-200"
                            >
                                <Icon class="size-5" aria-hidden="true" />
                            </div>
                            <p
                                class="mt-6 text-xs font-bold tracking-[0.16em] text-[#c96b5c]"
                            >
                                {feature.label}
                            </p>
                            <h3 class="mt-2 text-xl font-black text-white">
                                {feature.title}
                            </h3>
                            <p class="mt-4 text-sm leading-7 text-slate-400">
                                {feature.description}
                            </p>
                        </article>
                    {/each}
                </div>
            </div>
        </section>

        <section
            id="workflow"
            class="scroll-mt-24 border-b border-white/10 bg-[#071017] py-20 sm:py-28"
            aria-labelledby="workflow-heading"
        >
            <div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10">
                <div
                    class="grid gap-12 lg:grid-cols-[0.72fr_1.28fr] lg:items-start"
                >
                    <div class="lg:sticky lg:top-28">
                        <p
                            class="text-xs font-bold tracking-[0.22em] text-[#d7b45d]"
                        >
                            {t('SIMPLE WORKFLOW')}
                        </p>
                        <h2
                            id="workflow-heading"
                            class="mt-4 text-3xl font-black tracking-[-0.035em] text-white sm:text-5xl"
                        >
                            {t('Three simple steps')}<br />{t(
                                'That is all it takes',
                            )}
                        </h2>
                        <p
                            class="mt-6 max-w-lg text-base leading-8 text-slate-400"
                        >
                            {t(
                                'Open a project and start working without learning complicated settings.',
                            )}
                        </p>
                    </div>

                    <ol class="grid gap-4">
                        <li
                            class="grid gap-5 rounded-2xl border border-white/10 bg-[#0d1921] p-6 sm:grid-cols-[72px_1fr] sm:p-8"
                        >
                            <span
                                class="text-4xl font-black tracking-[-0.06em] text-[#b44332]"
                                >01</span
                            >
                            <div>
                                <h3 class="text-xl font-black text-white">
                                    {t('Open a project')}
                                </h3>
                                <p class="mt-3 leading-7 text-slate-400">
                                    {t(
                                        'Members, recent activity, and the tools you use are gathered on the first screen.',
                                    )}
                                </p>
                            </div>
                        </li>
                        <li
                            class="grid gap-5 rounded-2xl border border-white/10 bg-[#0d1921] p-6 sm:grid-cols-[72px_1fr] sm:p-8"
                        >
                            <span
                                class="text-4xl font-black tracking-[-0.06em] text-[#d7b45d]"
                                >02</span
                            >
                            <div>
                                <h3 class="text-xl font-black text-white">
                                    {t('Record your work')}
                                </h3>
                                <p class="mt-3 leading-7 text-slate-400">
                                    {t(
                                        'Choose the right place from tasks, schedules, boards, and materials, then record the owner and deadline.',
                                    )}
                                </p>
                            </div>
                        </li>
                        <li
                            class="grid gap-5 rounded-2xl border border-white/10 bg-[#0d1921] p-6 sm:grid-cols-[72px_1fr] sm:p-8"
                        >
                            <span
                                class="text-4xl font-black tracking-[-0.06em] text-emerald-500"
                                >03</span
                            >
                            <div>
                                <h3 class="text-xl font-black text-white">
                                    {t('See updates and take the next step')}
                                </h3>
                                <p class="mt-3 leading-7 text-slate-400">
                                    {t(
                                        'Follow changes through recent activity and notifications, then use comments or chat to make the next decision.',
                                    )}
                                </p>
                            </div>
                        </li>
                    </ol>
                </div>
            </div>
        </section>

        <section
            id="screens"
            class="scroll-mt-24 border-b border-white/10 bg-[#0a141c] py-20 sm:py-28"
            aria-labelledby="screens-heading"
        >
            <div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10">
                <div class="mx-auto max-w-3xl text-center">
                    <p
                        class="text-xs font-bold tracking-[0.22em] text-[#d7b45d]"
                    >
                        {t('ACTUAL SCREENS')}
                    </p>
                    <h2
                        id="screens-heading"
                        class="mt-4 text-3xl font-black tracking-[-0.035em] text-white sm:text-5xl"
                    >
                        {t('See the screens you use every day')}
                    </h2>
                    <p class="mt-6 text-base leading-8 text-slate-400">
                        {t(
                            'These are real JIN screens using anonymous demo data. Find the same information easily on desktop or mobile.',
                        )}
                    </p>
                </div>

                <div class="mt-16 grid gap-20 sm:mt-20 sm:gap-28">
                    {#each screens as screen (screen.title)}
                        <article
                            class="grid items-center gap-8 lg:grid-cols-[1.12fr_0.88fr] lg:gap-14"
                        >
                            <figure class={`min-w-0 ${screen.imageOrder}`}>
                                <div
                                    class="overflow-hidden rounded-2xl border border-white/15 bg-[#071017] p-2 shadow-[0_24px_70px_rgba(0,0,0,0.35)] sm:p-3"
                                >
                                    <img
                                        src={screen.image}
                                        alt={screen.title}
                                        width="1440"
                                        height="900"
                                        loading="lazy"
                                        decoding="async"
                                        class="aspect-[8/5] w-full rounded-lg object-cover object-top"
                                    />
                                </div>
                                <figcaption class="sr-only">
                                    {t(
                                        'JIN :title screen using anonymous demo data',
                                        {
                                            title: screen.title,
                                        },
                                    )}
                                </figcaption>
                            </figure>
                            <div>
                                <p
                                    class="text-xs font-bold tracking-[0.2em] text-[#c96b5c]"
                                >
                                    {screen.eyebrow}
                                </p>
                                <h3
                                    class="mt-4 text-2xl leading-tight font-black tracking-[-0.025em] text-white sm:text-4xl"
                                >
                                    {screen.title}
                                </h3>
                                <p class="mt-5 leading-8 text-slate-400">
                                    {screen.description}
                                </p>
                                <ul class="mt-6 grid gap-3">
                                    {#each screen.points as point (point)}
                                        <li
                                            class="flex items-center gap-3 text-sm font-medium text-slate-200"
                                        >
                                            <span
                                                class="flex size-5 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-400"
                                            >
                                                <CheckCircle
                                                    class="size-3.5"
                                                    aria-hidden="true"
                                                />
                                            </span>
                                            {point}
                                        </li>
                                    {/each}
                                </ul>
                            </div>
                        </article>
                    {/each}
                </div>
            </div>
        </section>

        <section
            class="bg-[#071017] py-20 sm:py-28"
            aria-labelledby="access-heading"
        >
            <div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10">
                <div
                    class="relative overflow-hidden rounded-3xl border border-[#d7b45d]/20 bg-[linear-gradient(120deg,rgba(180,67,50,0.16),rgba(215,180,93,0.08),rgba(255,255,255,0.025))] p-7 sm:p-12 lg:p-16"
                >
                    <div
                        class="pointer-events-none absolute top-0 right-0 size-72 translate-x-1/3 -translate-y-1/3 rounded-full border border-[#d7b45d]/15"
                        aria-hidden="true"
                    ></div>
                    <div
                        class="relative grid gap-10 lg:grid-cols-[0.82fr_1.18fr] lg:items-center"
                    >
                        <div
                            class="flex size-16 items-center justify-center rounded-2xl border border-[#d7b45d]/25 bg-[#d7b45d]/10 text-amber-200"
                        >
                            <ShieldCheck class="size-8" aria-hidden="true" />
                        </div>
                        <div>
                            <p
                                class="text-xs font-bold tracking-[0.2em] text-[#d7b45d]"
                            >
                                {t('ACCESS CONTROL')}
                            </p>
                            <h2
                                id="access-heading"
                                class="mt-4 text-3xl font-black tracking-[-0.035em] text-white sm:text-5xl"
                            >
                                {t('The right people, in the right workspace')}
                            </h2>
                            <p class="mt-6 max-w-3xl leading-8 text-slate-300">
                                {t(
                                    'Manage projects and users from administration, set visibility by project and user, and keep internal information inside your organization with an on-premises deployment.',
                                )}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    class="mt-20 flex flex-col items-start justify-between gap-8 border-t border-white/10 pt-10 sm:flex-row sm:items-center"
                >
                    <div>
                        <p class="text-2xl font-black text-white">
                            {t('Ready when you are? Open JIN')}
                        </p>
                        <p class="mt-2 text-sm text-slate-400">
                            {t('Log in with your usual account.')}
                        </p>
                    </div>
                    <Link
                        href={toUrl(primaryHref)}
                        prefetch
                        class="inline-flex min-h-12 items-center justify-center gap-2 rounded-full bg-[#b44332] px-7 text-sm font-bold text-white outline-none transition hover:-translate-y-0.5 hover:bg-[#c24f3a] focus-visible:ring-2 focus-visible:ring-[#e07b69] focus-visible:ring-offset-2 focus-visible:ring-offset-[#071017] motion-safe:duration-200"
                    >
                        {primaryLabel}
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-white/10 bg-[#050c11]">
        <div
            class="mx-auto flex max-w-7xl flex-col gap-5 px-5 py-8 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-8 lg:px-10"
        >
            <div class="flex items-center gap-3">
                <AppLogoIcon class="size-9" />
                <span class="font-bold tracking-[0.16em] text-slate-300"
                    >JIN</span
                >
            </div>
            <p>
                {t("Bring your team's work")}
                {t('into one JIN workspace')}
            </p>
        </div>
    </footer>
</div>
