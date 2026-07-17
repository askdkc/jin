<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import Bell from 'lucide-svelte/icons/bell';
    import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
    import Breadcrumbs from '@/components/Breadcrumbs.svelte';
    import { Button } from '@/components/ui/button';
    import { SidebarTrigger } from '@/components/ui/sidebar';
    import { t } from '@/lib/i18n';
    import type { BreadcrumbItem, Team } from '@/types';

    let {
        breadcrumbs = [],
    }: {
        breadcrumbs?: BreadcrumbItem[];
    } = $props();

    const currentTeam = $derived(page.props.currentTeam as Team | null);
    const unreadCount = $derived(page.props.notificationSummary.unreadCount);
</script>

<header
    class="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
>
    <div class="flex items-center gap-2">
        <SidebarTrigger class="-ml-1" />
        {#if breadcrumbs && breadcrumbs.length > 0}
            <Breadcrumbs {breadcrumbs} />
        {/if}
    </div>
    {#if currentTeam}
        <Button variant="ghost" size="icon" asChild>
            {#snippet children(buttonProps)}
                <Link
                    {...buttonProps}
                    href={NotificationController.index(currentTeam.slug)}
                    class="relative {buttonProps.class}"
                    aria-label={`${t('Notifications')} (${unreadCount})`}
                >
                    <Bell class="size-5" />
                    {#if unreadCount > 0}
                        <span
                            class="absolute -top-1 -right-1 flex min-w-5 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold text-primary-foreground"
                        >
                            {unreadCount > 99 ? '99+' : unreadCount}
                        </span>
                    {/if}
                </Link>
            {/snippet}
        </Button>
    {/if}
</header>
