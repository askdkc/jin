<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import BookOpen from 'lucide-svelte/icons/book-open';
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import ChartColumn from 'lucide-svelte/icons/chart-column';
    import ChartGantt from 'lucide-svelte/icons/chart-gantt';
    import FolderGit2 from 'lucide-svelte/icons/folder-git-2';
    import FolderKanban from 'lucide-svelte/icons/folder-kanban';
    import History from 'lucide-svelte/icons/history';
    import LayoutGrid from 'lucide-svelte/icons/layout-grid';
    import Search from 'lucide-svelte/icons/search';
    import type { Snippet } from 'svelte';
    import ActivityController from '@/actions/App/Http/Controllers/ActivityController';
    import EverythingController from '@/actions/App/Http/Controllers/EverythingController';
    import LineupController from '@/actions/App/Http/Controllers/LineupController';
    import ReportsController from '@/actions/App/Http/Controllers/ReportsController';
    import WorkspaceCalendarController from '@/actions/App/Http/Controllers/WorkspaceCalendarController';
    import AppLogo from '@/components/AppLogo.svelte';
    import NavFooter from '@/components/NavFooter.svelte';
    import NavMain from '@/components/NavMain.svelte';
    import NavUser from '@/components/NavUser.svelte';
    import TeamSwitcher from '@/components/TeamSwitcher.svelte';
    import {
        Sidebar,
        SidebarContent,
        SidebarFooter,
        SidebarHeader,
        SidebarMenu,
        SidebarMenuButton,
        SidebarMenuItem,
    } from '@/components/ui/sidebar';
    import { t } from '@/lib/i18n';
    import { dashboard } from '@/routes';
    import { index as projectIndex } from '@/routes/projects';
    import type { NavItem, Team } from '@/types';

    let {
        children,
    }: {
        children?: Snippet;
    } = $props();

    const currentTeam = $derived(page.props.currentTeam as Team | null);
    const dashboardUrl = $derived(
        currentTeam ? dashboard(currentTeam.slug) : '/',
    );
    const projectsUrl = $derived(
        currentTeam ? projectIndex(currentTeam.slug) : '/',
    );
    const activityUrl = $derived(
        currentTeam ? ActivityController(currentTeam.slug) : '/',
    );
    const calendarUrl = $derived(
        currentTeam ? WorkspaceCalendarController(currentTeam.slug) : '/',
    );
    const everythingUrl = $derived(
        currentTeam ? EverythingController(currentTeam.slug) : '/',
    );
    const reportsUrl = $derived(
        currentTeam ? ReportsController(currentTeam.slug) : '/',
    );
    const lineupUrl = $derived(
        currentTeam ? LineupController(currentTeam.slug) : '/',
    );

    const mainNavItems = $derived<NavItem[]>([
        {
            title: t('My work'),
            href: dashboardUrl,
            icon: LayoutGrid,
        },
        {
            title: t('Projects'),
            href: projectsUrl,
            icon: FolderKanban,
        },
        {
            title: t('Activity'),
            href: activityUrl,
            icon: History,
        },
        {
            title: currentTeam?.menuNames.calendar ?? '行程',
            href: calendarUrl,
            icon: CalendarDays,
        },
        {
            title: t('Everything'),
            href: everythingUrl,
            icon: Search,
        },
        {
            title: currentTeam?.menuNames.reports ?? '戦況',
            href: reportsUrl,
            icon: ChartColumn,
        },
        {
            title: currentTeam?.menuNames.lineup ?? '布陣',
            href: lineupUrl,
            icon: ChartGantt,
        },
    ]);

    const footerNavItems: NavItem[] = [
        {
            title: t('Repository'),
            href: 'https://github.com/laravel/svelte-starter-kit',
            icon: FolderGit2,
        },
        {
            title: t('Documentation'),
            href: 'https://laravel.com/docs/starter-kits#svelte',
            icon: BookOpen,
        },
    ];
</script>

<Sidebar collapsible="icon" variant="inset">
    <SidebarHeader>
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton size="lg" asChild>
                    {#snippet children(props)}
                        <Link
                            {...props}
                            href={dashboardUrl}
                            class={props.class}
                        >
                            <AppLogo />
                        </Link>
                    {/snippet}
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
        <SidebarMenu>
            <SidebarMenuItem>
                <TeamSwitcher />
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarHeader>

    <SidebarContent>
        <NavMain items={mainNavItems} />
    </SidebarContent>

    <SidebarFooter>
        <NavFooter items={footerNavItems} />
        <NavUser />
    </SidebarFooter>
</Sidebar>
{@render children?.()}
