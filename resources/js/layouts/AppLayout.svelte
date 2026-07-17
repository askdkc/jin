<script lang="ts">
    import type { Snippet } from 'svelte';
    import AppLayout from '@/layouts/app/AppHeaderLayout.svelte';
    import type { BreadcrumbItem, HeaderAction } from '@/types';

    let {
        breadcrumbs = [],
        headerActions = [],
        children,
    }: {
        breadcrumbs?: BreadcrumbItem[];
        headerActions?: HeaderAction[];
        children?: Snippet;
    } = $props();
</script>

<AppLayout {breadcrumbs} {headerActions}>
    {@render children?.()}
</AppLayout>
