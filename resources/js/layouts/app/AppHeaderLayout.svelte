<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import type { Snippet } from 'svelte';
    import AppContent from '@/components/AppContent.svelte';
    import AppHeader from '@/components/AppHeader.svelte';
    import AppShell from '@/components/AppShell.svelte';
    import { Toaster } from '@/components/ui/sonner';
    import WorkspaceBottomNav from '@/components/WorkspaceBottomNav.svelte';
    import type { BreadcrumbItem, HeaderAction, Team } from '@/types';

    let {
        breadcrumbs = [],
        headerActions = [],
        children,
    }: {
        breadcrumbs?: BreadcrumbItem[];
        headerActions?: HeaderAction[];
        children?: Snippet;
    } = $props();

    const currentTeam = $derived(page.props.currentTeam as Team | null);
</script>

<AppShell variant="header">
    <AppHeader {breadcrumbs} {headerActions} />
    <AppContent variant="header" class={currentTeam ? 'pb-12' : ''}>
        {@render children?.()}
    </AppContent>
    {#if currentTeam}
        {#key currentTeam.id}
            <WorkspaceBottomNav {currentTeam} />
        {/key}
    {/if}
    <Toaster />
</AppShell>
