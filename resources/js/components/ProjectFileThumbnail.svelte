<script lang="ts">
    import FileIcon from 'lucide-svelte/icons/file';
    import Paperclip from 'lucide-svelte/icons/paperclip';
    import ProjectFileController from '@/actions/App/Http/Controllers/ProjectFileController';
    import { nextFileThumbnailSource } from '@/lib/file-thumbnail';

    let {
        teamSlug,
        projectId,
        fileId,
        mimeType,
        thumbnailUrl,
        variant,
    }: {
        teamSlug: string;
        projectId: number;
        fileId: number;
        mimeType: string | null;
        thumbnailUrl: string | null;
        variant: 'index' | 'result' | 'summary';
    } = $props();

    let failedUrls = $state<string[]>([]);
    const originalPreviewUrl = $derived(
        mimeType?.startsWith('image/')
            ? ProjectFileController.preview.url([teamSlug, projectId, fileId])
            : null,
    );
    const source = $derived(
        nextFileThumbnailSource({
            mimeType,
            thumbnailUrl,
            originalPreviewUrl,
            failedUrls,
        }),
    );
    const containerClass = $derived(
        variant === 'result'
            ? 'inline-flex max-w-full items-center justify-center overflow-hidden rounded-lg'
            : variant === 'index'
              ? 'flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-md'
              : 'flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-md',
    );
    const imageClass = $derived(
        variant === 'result'
            ? 'h-auto max-h-56 w-auto max-w-72 object-contain'
            : 'size-full object-contain',
    );
    const fallbackContainerClass = $derived(
        variant === 'result' ? `${containerClass} size-40` : containerClass,
    );

    function handleError(event: Event): void {
        const failedUrl = (event.currentTarget as HTMLImageElement).dataset
            .thumbnailUrl;

        if (failedUrl && !failedUrls.includes(failedUrl)) {
            failedUrls = [...failedUrls, failedUrl];
        }
    }
</script>

{#if source}
    <span
        class={`${containerClass} border border-border bg-white dark:bg-muted`}
    >
        <img
            src={source.url}
            data-thumbnail-url={source.url}
            alt=""
            loading="lazy"
            decoding="async"
            class={imageClass}
            onerror={handleError}
        />
    </span>
{:else}
    <span class={`${fallbackContainerClass} bg-muted text-muted-foreground`}>
        {#if variant === 'result'}
            <FileIcon class="size-12" aria-hidden="true" />
        {:else if variant === 'index'}
            <FileIcon class="size-7" aria-hidden="true" />
        {:else}
            <Paperclip class="size-5" aria-hidden="true" />
        {/if}
    </span>
{/if}
