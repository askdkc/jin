<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import {
        Breadcrumb,
        BreadcrumbItem,
        BreadcrumbLink,
        BreadcrumbList,
        BreadcrumbPage,
        BreadcrumbSeparator,
    } from '@/components/ui/breadcrumb';
    import { t } from '@/lib/i18n';
    import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

    let {
        breadcrumbs = [],
    }: {
        breadcrumbs: BreadcrumbItemType[];
    } = $props();

    const breadcrumbLabel = (item: BreadcrumbItemType): string =>
        typeof item.translationKey === 'string'
            ? t(item.translationKey)
            : (item.title ?? '');
</script>

<Breadcrumb>
    <BreadcrumbList>
        {#each breadcrumbs as item, index (item.href)}
            <BreadcrumbItem>
                {#if index === breadcrumbs.length - 1}
                    <BreadcrumbPage>{breadcrumbLabel(item)}</BreadcrumbPage>
                {:else}
                    <BreadcrumbLink asChild>
                        {#snippet children(props)}
                            <Link href={item.href} class={props.class}>
                                {breadcrumbLabel(item)}
                            </Link>
                        {/snippet}
                    </BreadcrumbLink>
                {/if}
            </BreadcrumbItem>
            {#if index !== breadcrumbs.length - 1}
                <BreadcrumbSeparator />
            {/if}
        {/each}
    </BreadcrumbList>
</Breadcrumb>
