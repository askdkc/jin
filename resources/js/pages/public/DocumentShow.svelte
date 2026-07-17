<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import RichTextContent from '@/components/RichTextContent.svelte';
    import { t } from '@/lib/i18n';

    let {
        document,
    }: {
        document: {
            title: string;
            body: string;
            projectName: string;
            updatedAt: string | null;
        };
    } = $props();

    const updatedAt = $derived(
        document.updatedAt
            ? new Intl.DateTimeFormat('ja-JP', {
                  dateStyle: 'long',
                  timeStyle: 'short',
              }).format(new Date(document.updatedAt))
            : null,
    );
</script>

<AppHead title={document.title} />

<main
    class="min-h-dvh bg-[#f4f1e9] px-4 py-8 text-[#20261f] sm:px-6 sm:py-12 dark:bg-[#0f1714] dark:text-[#f6f2e8]"
>
    <article
        class="mx-auto max-w-3xl overflow-hidden rounded-3xl border border-black/10 bg-white shadow-xl shadow-black/5 dark:border-white/10 dark:bg-[#17211d]"
    >
        <header
            class="border-b border-black/10 px-6 py-6 dark:border-white/10 sm:px-10 sm:py-8"
        >
            <div
                class="mb-8 flex items-center gap-3 text-sm font-bold text-muted-foreground"
            >
                <AppLogoIcon class="size-9" />
                <span>{t('陣（JIN）')}</span>
            </div>
            <p class="text-sm font-semibold text-[#9f3a2d] dark:text-[#e4a07f]">
                {document.projectName}
            </p>
            <h1 class="mt-2 text-3xl font-black tracking-tight sm:text-4xl">
                {document.title}
            </h1>
            {#if updatedAt}
                <p class="mt-3 text-xs text-muted-foreground">
                    {t('Updated :date', { date: updatedAt })}
                </p>
            {/if}
        </header>
        <RichTextContent
            content={document.body}
            class="px-6 py-8 text-base leading-8 sm:px-10 sm:py-12"
        />
    </article>
</main>
