<script lang="ts">
    import Download from 'lucide-svelte/icons/download';
    import X from 'lucide-svelte/icons/x';
    import ProjectFileController from '@/actions/App/Http/Controllers/ProjectFileController';
    import OnlyOfficePreview from '@/components/OnlyOfficePreview.svelte';
    import { Button } from '@/components/ui/button';
    import {
        Dialog,
        DialogContent,
        DialogDescription,
        DialogTitle,
    } from '@/components/ui/dialog';
    import { t } from '@/lib/i18n';
    import type { ProjectTool, Team } from '@/types';

    type PreviewFile = {
        id: number;
        name: string;
        mimeType?: string | null;
    };

    let {
        currentTeam,
        project,
        file,
        configUrl,
        open = $bindable(false),
    }: {
        currentTeam: Team;
        project: ProjectTool;
        file: PreviewFile | null;
        configUrl: string;
        open?: boolean;
    } = $props();

    const handleOpenChange = (isOpen: boolean): void => {
        open = isOpen;
    };
</script>

<Dialog {open} onOpenChange={handleOpenChange}>
    <DialogContent
        class="flex h-[calc(100dvh-1rem)] w-[calc(100vw-1rem)] max-w-[96rem] flex-col gap-0 overflow-hidden rounded-2xl p-0 sm:h-[calc(100dvh-3rem)] sm:w-[calc(100vw-3rem)]"
    >
        <header class="flex items-start gap-3 border-b px-4 py-3 sm:px-5">
            <div class="min-w-0 flex-1">
                <DialogTitle class="truncate text-base sm:text-lg">
                    {file?.name ?? t('Preview')}
                </DialogTitle>
                <DialogDescription class="mt-1 text-xs sm:text-sm">
                    {t('This Office file is shown in read-only mode.')}
                </DialogDescription>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                {#if file}
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={t('Download')}
                        asChild
                    >
                        {#snippet children(buttonProps)}
                            <a
                                {...buttonProps}
                                href={ProjectFileController.download.url([
                                    currentTeam.slug,
                                    project.id,
                                    file.id,
                                ])}
                                class={buttonProps.class}
                            >
                                <Download class="size-5" />
                            </a>
                        {/snippet}
                    </Button>
                {/if}
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={t('Close')}
                    onclick={() => handleOpenChange(false)}
                >
                    <X class="size-5" />
                </Button>
            </div>
        </header>

        <OnlyOfficePreview
            {currentTeam}
            {project}
            {file}
            {configUrl}
            active={open}
            class="min-h-[70dvh] flex-1"
        />
    </DialogContent>
</Dialog>
