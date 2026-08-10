<script lang="ts">
    import type { ReloadOptions } from '@inertiajs/core';
    import { Form, Link, page, router, WhenVisible } from '@inertiajs/svelte';
    import ArrowLeft from 'lucide-svelte/icons/arrow-left';
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import ChartColumn from 'lucide-svelte/icons/chart-column';
    import ChartGantt from 'lucide-svelte/icons/chart-gantt';
    import ChevronDown from 'lucide-svelte/icons/chevron-down';
    import FolderKanban from 'lucide-svelte/icons/folder-kanban';
    import History from 'lucide-svelte/icons/history';
    import House from 'lucide-svelte/icons/house';
    import LayoutGrid from 'lucide-svelte/icons/layout-grid';
    import Menu from 'lucide-svelte/icons/menu';
    import NotebookPen from 'lucide-svelte/icons/notebook-pen';
    import Search from 'lucide-svelte/icons/search';
    import UserRound from 'lucide-svelte/icons/user-round';
    import X from 'lucide-svelte/icons/x';
    import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
    import AdminlandController from '@/actions/App/Http/Controllers/AdminlandController';
    import EverythingController from '@/actions/App/Http/Controllers/EverythingController';
    import LineupController from '@/actions/App/Http/Controllers/LineupController';
    import PersonalNoteController from '@/actions/App/Http/Controllers/PersonalNoteController';
    import ReportsController from '@/actions/App/Http/Controllers/ReportsController';
    import WorkspaceCalendarController from '@/actions/App/Http/Controllers/WorkspaceCalendarController';
    import AppLogo from '@/components/AppLogo.svelte';
    import Breadcrumbs from '@/components/Breadcrumbs.svelte';
    import TeamSwitcher from '@/components/TeamSwitcher.svelte';
    import {
        Avatar,
        AvatarFallback,
        AvatarImage,
    } from '@/components/ui/avatar';
    import { Button } from '@/components/ui/button';
    import {
        DropdownMenu,
        DropdownMenuContent,
        DropdownMenuTrigger,
    } from '@/components/ui/dropdown-menu';
    import {
        Sheet,
        SheetContent,
        SheetHeader,
        SheetTitle,
        SheetTrigger,
    } from '@/components/ui/sheet';
    import UserMenuContent from '@/components/UserMenuContent.svelte';
    import { currentUrlState } from '@/lib/currentUrl.svelte';
    import { currentLocale, t } from '@/lib/i18n';
    import { getInitials } from '@/lib/initials';
    import { toUrl } from '@/lib/utils';
    import { dashboard } from '@/routes';
    import { index as projectIndex } from '@/routes/projects';
    import { show as projectShow } from '@/routes/projects';
    import type { BreadcrumbItem, HeaderAction, NavItem, Team } from '@/types';

    let {
        breadcrumbs = [],
        headerActions = [],
    }: {
        breadcrumbs?: BreadcrumbItem[];
        headerActions?: HeaderAction[];
    } = $props();

    const user = $derived(page.props.auth.user);
    const currentTeam = $derived(page.props.currentTeam as Team | null);
    const navigationProjects = $derived(page.props.navigationProjects);
    const navigationPeopleLoaded = $derived(
        page.props.navigationPeople !== undefined,
    );
    const navigationPeople = $derived(
        (page.props.navigationPeople ?? []).slice(0, 50),
    );
    const url = currentUrlState();
    const dashboardUrl = $derived(
        currentTeam ? dashboard(currentTeam.slug) : '/',
    );
    const adminlandUrl = $derived(
        currentTeam ? AdminlandController(currentTeam.slug) : '/',
    );
    const isProjectPage = $derived(
        page.component === 'projects/Show' ||
            (page.component.startsWith('projects/') &&
                page.component !== 'projects/Index' &&
                page.component !== 'projects/Form'),
    );
    // On a project feature sub-page every layout puts the project top at breadcrumbs[0].
    const projectTop = $derived(
        isProjectPage &&
            page.component !== 'projects/Show' &&
            breadcrumbs.length > 0
            ? breadcrumbs[0]
            : null,
    );
    const hasBottomNavigation = $derived(
        url.isCurrentUrl(dashboardUrl, url.currentUrl) ||
            url.isCurrentUrl(adminlandUrl, url.currentUrl) ||
            isProjectPage,
    );
    let jumpOpen = $state(false);
    let jumpQuery = $state('');
    let jumpInput = $state<HTMLInputElement>();
    let shiftHeld = $state(false);
    let navigationPeopleLoadState = $state<'idle' | 'loading' | 'error'>(
        'idle',
    );
    const filteredProjects = $derived(
        navigationProjects.filter((project) =>
            project.name
                .toLocaleLowerCase(currentLocale())
                .includes(jumpQuery.trim().toLocaleLowerCase(currentLocale())),
        ),
    );
    const filteredPeople = $derived(
        navigationPeople.filter((person) =>
            person.name
                .toLocaleLowerCase(currentLocale())
                .includes(jumpQuery.trim().toLocaleLowerCase(currentLocale())),
        ),
    );

    const failNavigationPeopleLoad = (): void => {
        navigationPeopleLoadState = 'error';
    };
    const settleNavigationPeopleLoad = (): void => {
        navigationPeopleLoadState = navigationPeopleLoaded ? 'idle' : 'error';
    };
    const navigationPeopleLoadParams: ReloadOptions = {
        onStart: () => {
            navigationPeopleLoadState = 'loading';
        },
        onSuccess: () => {
            navigationPeopleLoadState = 'idle';
        },
        // A partial reload can retain unrelated validation errors and invoke onError.
        onError: settleNavigationPeopleLoad,
        onHttpException: failNavigationPeopleLoad,
        onNetworkError: () => {
            failNavigationPeopleLoad();

            return false;
        },
        onCancel: settleNavigationPeopleLoad,
    };
    const retryNavigationPeople = (): void => {
        router.reload({
            only: ['navigationPeople'],
            preserveErrors: true,
            ...navigationPeopleLoadParams,
        });
    };

    const openJump = (): void => {
        if (!currentTeam) {
            return;
        }

        jumpOpen = true;
        requestAnimationFrame(() => jumpInput?.focus());
    };
    const closeJump = (): void => {
        jumpOpen = false;
        jumpQuery = '';
    };
    const handleShortcut = (event: KeyboardEvent): void => {
        shiftHeld = event.shiftKey || event.key === 'Shift';

        if (event.key === 'Escape') {
            closeJump();

            return;
        }

        const target = event.target;
        const isEditing =
            target instanceof HTMLElement &&
            (target.matches('input, textarea, select') ||
                target.isContentEditable);

        // Global shortcuts must not consume ordinary uppercase input in editors.
        if (isEditing) {
            return;
        }

        if (event.shiftKey && event.key.toLocaleLowerCase() === 'j') {
            event.preventDefault();
            openJump();
        } else if (event.shiftKey && event.key.toLocaleLowerCase() === 's') {
            event.preventDefault();
            window.dispatchEvent(new CustomEvent('jin:new-for-you'));
        } else if (event.shiftKey && !jumpOpen) {
            const item = mainNavItems.find(
                (navItem) => navItem.shortcut === event.key.toLocaleLowerCase(),
            );

            if (item) {
                event.preventDefault();
                router.visit(toUrl(item.href));
            }
        }
    };
    const handleKeyup = (event: KeyboardEvent): void => {
        if (event.key === 'Shift') {
            shiftHeld = false;
        }
    };

    const mainNavItems = $derived<(NavItem & { shortcut: string })[]>([
        {
            title: t('My work'),
            href: dashboardUrl,
            icon: LayoutGrid,
            shortcut: 'h',
        },
        {
            title: t('Projects'),
            href: currentTeam ? projectIndex(currentTeam.slug) : '/',
            icon: FolderKanban,
            shortcut: 'p',
        },
        {
            title: t('Activity'),
            href: currentTeam ? ActivityController(currentTeam.slug) : '/',
            icon: History,
            shortcut: 'a',
        },
        {
            title: currentTeam?.menuNames.calendar ?? t('Calendar'),
            href: currentTeam
                ? WorkspaceCalendarController(currentTeam.slug)
                : '/',
            icon: CalendarDays,
            shortcut: 'c',
        },
        {
            title: t('Everything'),
            href: currentTeam ? EverythingController(currentTeam.slug) : '/',
            icon: Search,
            shortcut: 'e',
        },
        {
            title: t('My notes'),
            href: currentTeam
                ? PersonalNoteController.index(currentTeam.slug)
                : '/',
            icon: NotebookPen,
            shortcut: 'n',
        },
        {
            title: currentTeam?.menuNames.reports ?? t('Reports'),
            href: currentTeam ? ReportsController(currentTeam.slug) : '/',
            icon: ChartColumn,
            shortcut: 'r',
        },
        {
            title: currentTeam?.menuNames.lineup ?? t('Lineup'),
            href: currentTeam ? LineupController(currentTeam.slug) : '/',
            icon: ChartGantt,
            shortcut: 'l',
        },
    ]);
    const jumpNavItems = $derived(
        [History, CalendarDays, ChartColumn, Search]
            .map((icon) => mainNavItems.find((item) => item.icon === icon))
            .filter(
                (item): item is NavItem & { shortcut: string } =>
                    item !== undefined,
            ),
    );

    const navClasses = (item: NavItem): string =>
        `flex items-center gap-2 rounded-full px-3 py-2 text-sm font-semibold whitespace-nowrap transition ${
            url.isCurrentUrl(item.href, url.currentUrl)
                ? 'bg-[#e4f1e6] text-[#205c3b]'
                : 'text-slate-600 hover:bg-white hover:text-slate-950 dark:text-slate-300 dark:hover:bg-slate-800'
        }`;
</script>

<svelte:window
    onkeydown={handleShortcut}
    onkeyup={handleKeyup}
    onblur={() => (shiftHeld = false)}
/>

<header
    class="sticky top-0 z-40 border-b border-slate-200/80 bg-[#f2f7fc]/95 shadow-sm backdrop-blur dark:border-[#0d151b] dark:bg-[#0d151b]"
>
    {#if currentTeam && projectTop}
        <Link
            href={projectTop.href}
            title={projectTop.title}
            class="absolute top-0 left-2 z-10 hidden h-12 max-w-[16rem] items-center gap-1.5 rounded-md px-2 text-sm font-semibold text-slate-700 transition hover:bg-white/70 hover:text-slate-950 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:flex dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white"
        >
            <ArrowLeft class="size-3.5 shrink-0" />
            <span class="truncate">{projectTop.title}</span>
        </Link>
    {:else if currentTeam && isProjectPage}
        <Link
            href={dashboardUrl}
            class="absolute top-0 left-2 z-10 hidden h-12 items-center gap-1.5 rounded-md px-2 text-sm font-semibold text-slate-700 transition hover:bg-white/70 hover:text-slate-950 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:flex dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white"
        >
            <House class="size-3.5" />
            {t('Home')}
        </Link>
    {/if}

    <div
        class="relative mx-auto flex h-12 w-full max-w-[96rem] items-center justify-between px-4 sm:px-6"
    >
        <div class="md:hidden">
            <Sheet>
                <SheetTrigger asChild>
                    {#snippet children(props)}
                        <Button
                            variant="ghost"
                            size="icon"
                            onclick={props.onclick}
                            aria-expanded={props['aria-expanded']}
                        >
                            <Menu class="size-5" />
                            <span class="sr-only">{t('Navigation menu')}</span>
                        </Button>
                    {/snippet}
                </SheetTrigger>
                <SheetContent side="left" class="w-[19rem] p-5">
                    <SheetHeader class="text-left">
                        <SheetTitle><AppLogo /></SheetTitle>
                    </SheetHeader>
                    <nav class="mt-6 grid gap-1">
                        {#each mainNavItems as item (toUrl(item.href))}
                            <Link
                                href={toUrl(item.href)}
                                class={navClasses(item)}
                            >
                                {#if item.icon}
                                    <item.icon class="size-4" />
                                {/if}
                                {item.title}
                            </Link>
                        {/each}
                    </nav>
                </SheetContent>
            </Sheet>
        </div>

        {#if currentTeam}
            <button
                type="button"
                class="absolute left-1/2 flex -translate-x-1/2 items-center gap-1 rounded-full px-2 py-1 outline-none transition hover:bg-white/70 focus-visible:ring-2 focus-visible:ring-ring dark:hover:bg-slate-800"
                onclick={openJump}
                aria-label={t('JIN menu')}
                aria-haspopup="dialog"
                aria-expanded={jumpOpen}
                aria-controls="global-navigation-menu"
            >
                <AppLogo />
                <ChevronDown
                    class={jumpOpen
                        ? 'size-4 rotate-180 transition-transform'
                        : 'size-4 transition-transform'}
                />
            </button>
        {:else}
            <Link
                href={dashboardUrl}
                class="absolute left-1/2 -translate-x-1/2"
                aria-label={t('JIN')}
            >
                <AppLogo />
            </Link>
        {/if}

        <div class="ml-auto flex items-center gap-1">
            {#if currentTeam}
                <Button
                    variant="ghost"
                    size="sm"
                    class="hidden gap-2 lg:flex"
                    onclick={openJump}
                >
                    <Search class="size-4" />
                    {t('Search or jump')}
                    <kbd class="rounded border px-1.5 py-0.5 text-[10px]"
                        >⇧J</kbd
                    >
                </Button>
            {/if}

            {#if user}
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        {#snippet children(props)}
                            <Button
                                variant="ghost"
                                size="icon"
                                class="rounded-full"
                                onclick={props.onclick}
                                aria-expanded={props['aria-expanded']}
                                data-state={props['data-state']}
                            >
                                <Avatar class="size-8">
                                    {#if user.avatar}
                                        <AvatarImage
                                            src={user.avatar}
                                            alt={user.name}
                                        />
                                    {/if}
                                    <AvatarFallback
                                        class="bg-[#dce9df] text-[#205c3b]"
                                    >
                                        {getInitials(user.name)}
                                    </AvatarFallback>
                                </Avatar>
                            </Button>
                        {/snippet}
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <UserMenuContent {user} />
                    </DropdownMenuContent>
                </DropdownMenu>
            {/if}

            <div class="hidden sm:block">
                <TeamSwitcher inHeader={true} />
            </div>
        </div>
    </div>

    {#if !hasBottomNavigation}
        <nav
            class="hidden border-t border-slate-200/70 bg-white/80 md:block dark:border-slate-800 dark:bg-slate-900/80"
        >
            <div
                class="mx-auto flex max-w-[96rem] items-center justify-center gap-1 overflow-x-auto px-4 py-2"
            >
                {#each mainNavItems as item (toUrl(item.href))}
                    <Link href={toUrl(item.href)} class={navClasses(item)}>
                        {#if item.icon}
                            <item.icon class="size-4" />
                        {/if}
                        {item.title}
                        {#if shiftHeld}
                            <kbd
                                class="rounded border border-current/30 px-1 text-[10px] uppercase opacity-70"
                                >{item.shortcut}</kbd
                            >
                        {/if}
                    </Link>
                {/each}
            </div>
        </nav>
    {/if}

    {#if breadcrumbs.length > 1 && !hasBottomNavigation}
        <div
            class="border-t border-slate-200/70 bg-white dark:border-slate-800 dark:bg-slate-900"
        >
            <div
                class="mx-auto flex h-11 max-w-[96rem] items-center justify-between gap-4 px-4 sm:px-6"
            >
                <Breadcrumbs {breadcrumbs} />
                {#if headerActions.length > 0}
                    <div class="flex shrink-0 items-center gap-4 text-sm">
                        {#each headerActions as action (action.label)}
                            {#if action.type === 'link'}
                                <Link
                                    href={action.href}
                                    class={action.variant === 'destructive'
                                        ? 'font-medium text-red-600 hover:underline dark:text-red-400'
                                        : 'font-medium text-primary hover:underline'}
                                >
                                    {t(action.label)}
                                </Link>
                            {:else}
                                <Form
                                    action={action.action}
                                    method={action.method}
                                    onBefore={() =>
                                        !action.confirmMessage ||
                                        window.confirm(
                                            t(action.confirmMessage),
                                        )}
                                >
                                    {#snippet children({ processing })}
                                        <button
                                            type="submit"
                                            disabled={processing}
                                            class={action.variant ===
                                            'destructive'
                                                ? 'font-medium text-red-600 hover:underline disabled:opacity-50 dark:text-red-400'
                                                : 'font-medium text-primary hover:underline disabled:opacity-50'}
                                        >
                                            {t(action.label)}
                                        </button>
                                    {/snippet}
                                </Form>
                            {/if}
                        {/each}
                    </div>
                {/if}
            </div>
        </div>
    {/if}
</header>

{#if jumpOpen && currentTeam}
    <div
        class="fixed inset-0 z-[60] bg-slate-950/60 p-4 pt-[12vh] backdrop-blur-sm"
        role="presentation"
        onclick={(event) => {
            if (event.currentTarget === event.target) {
                closeJump();
            }
        }}
    >
        <div
            id="global-navigation-menu"
            class="mx-auto w-full max-w-xl overflow-hidden rounded-2xl border bg-background shadow-2xl"
            role="dialog"
            aria-modal="true"
            aria-label={t('Search or jump')}
        >
            <nav
                class="grid grid-cols-2 gap-2 border-b p-3 sm:grid-cols-4"
                aria-label={t('Primary navigation')}
            >
                {#each jumpNavItems as item (toUrl(item.href))}
                    <Link
                        href={toUrl(item.href)}
                        class="flex min-w-0 flex-col items-center gap-2 rounded-xl bg-muted/60 px-2 py-3 text-center text-sm font-semibold transition hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        onclick={closeJump}
                    >
                        {#if item.icon}
                            <item.icon class="size-6" />
                        {/if}
                        <span class="truncate">{item.title}</span>
                    </Link>
                {/each}
            </nav>
            <div class="flex items-center gap-2 border-b p-3">
                <Search class="size-5 text-muted-foreground" />
                <input
                    bind:this={jumpInput}
                    bind:value={jumpQuery}
                    form="global-everything-search-form"
                    name="q"
                    type="search"
                    maxlength={100}
                    placeholder={t('Search projects, people, or everything')}
                    class="min-w-0 flex-1 bg-transparent px-2 py-2 text-base outline-none"
                />
                <Button
                    variant="ghost"
                    size="icon"
                    onclick={closeJump}
                    aria-label={t('Close')}
                >
                    <X class="size-5" />
                </Button>
            </div>
            <div class="max-h-[45vh] overflow-y-auto p-2">
                <WhenVisible
                    data="navigationPeople"
                    params={navigationPeopleLoadParams}
                    always
                >
                    {#snippet fallback()}
                        <p
                            class="px-3 py-3 text-sm text-muted-foreground"
                            role="status"
                        >
                            {t('Loading')}
                        </p>
                    {/snippet}
                    {#if navigationPeopleLoadState === 'error'}
                        <div class="space-y-2 px-3 py-3 text-sm" role="alert">
                            <p class="text-destructive">
                                {t('Could not load this panel.')}
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onclick={retryNavigationPeople}
                            >
                                {t('Retry')}
                            </Button>
                        </div>
                    {/if}
                    {#if navigationPeopleLoaded && filteredPeople.length > 0}
                        <p
                            class="px-3 pt-2 pb-1 text-xs font-bold uppercase tracking-wider text-muted-foreground"
                        >
                            {t('People')}
                        </p>
                        {#each filteredPeople as person (person.id)}
                            <Link
                                href={ActivityController(
                                    {
                                        current_team: currentTeam.slug,
                                    },
                                    { query: { user: person.id } },
                                )}
                                class="flex items-center gap-3 rounded-xl px-3 py-2 font-semibold hover:bg-muted"
                                onclick={closeJump}
                            >
                                <UserRound class="size-4 text-primary" />
                                {person.name}
                            </Link>
                        {/each}
                    {:else if navigationPeopleLoadState === 'loading'}
                        <p
                            class="px-3 py-3 text-sm text-muted-foreground"
                            role="status"
                        >
                            {t('Loading')}
                        </p>
                    {/if}
                </WhenVisible>
                <p
                    class="px-3 pt-3 pb-1 text-xs font-bold uppercase tracking-wider text-muted-foreground"
                >
                    {t('Projects')}
                </p>
                {#each filteredProjects as project (project.id)}
                    <Link
                        href={projectShow([currentTeam.slug, project.id])}
                        class="flex items-center gap-3 rounded-xl px-3 py-3 font-semibold hover:bg-muted"
                        onclick={closeJump}
                    >
                        <FolderKanban class="size-4 text-primary" />
                        {project.name}
                    </Link>
                {/each}
                {#if navigationPeopleLoaded && filteredProjects.length === 0 && filteredPeople.length === 0}
                    <p
                        class="px-3 py-6 text-center text-sm text-muted-foreground"
                    >
                        {t('No matching projects')}
                    </p>
                {/if}
            </div>
            <Form
                {...EverythingController.form(currentTeam.slug)}
                id="global-everything-search-form"
                class="flex items-center gap-2 border-t p-3"
                onSuccess={closeJump}
            >
                <p
                    class="min-w-0 flex-1 truncate text-sm text-muted-foreground"
                >
                    {t('Search all content')}
                </p>
                <Button type="submit" size="sm">{t('Search all')}</Button>
            </Form>
        </div>
    </div>
{/if}
