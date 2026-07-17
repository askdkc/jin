<script lang="ts">
    import CheckCircle from 'lucide-svelte/icons/circle-check';
    import UploadCloud from 'lucide-svelte/icons/cloud-upload';
    import { onMount } from 'svelte';
    import InputError from '@/components/InputError.svelte';
    import { t } from '@/lib/i18n';
    import { cn } from '@/lib/utils';

    let {
        id,
        name = 'file',
        accept,
        required = true,
        error,
        hint,
        compact = false,
    }: {
        id: string;
        name?: string;
        accept: string;
        required?: boolean;
        error?: string;
        hint?: string;
        compact?: boolean;
    } = $props();

    let input: HTMLInputElement;
    let isDragging = $state(false);
    let selectedFile = $state<File | null>(null);
    let mediaPreviewUrl = $state<string | null>(null);

    const revokeMediaPreview = (): void => {
        if (mediaPreviewUrl) {
            URL.revokeObjectURL(mediaPreviewUrl);
            mediaPreviewUrl = null;
        }
    };

    const updateSelection = (file: File | null): void => {
        revokeMediaPreview();
        selectedFile = file;

        if (
            file?.type.startsWith('image/') ||
            file?.type.startsWith('video/')
        ) {
            mediaPreviewUrl = URL.createObjectURL(file);
        }
    };

    const formatSize = (size: number): string => {
        if (size < 1024) {
            return `${size} B`;
        }

        if (size < 1024 * 1024) {
            return `${(size / 1024).toFixed(1)} KB`;
        }

        return `${(size / (1024 * 1024)).toFixed(1)} MB`;
    };

    onMount(() => {
        const form = input.form;
        const clearSelection = (): void => {
            updateSelection(null);
        };

        form?.addEventListener('reset', clearSelection);

        return () => {
            form?.removeEventListener('reset', clearSelection);
            revokeMediaPreview();
        };
    });

    const selectFile = (file: File): void => {
        const transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        updateSelection(file);
    };

    const handleDrop = (event: DragEvent): void => {
        event.preventDefault();
        isDragging = false;

        const file = event.dataTransfer?.files.item(0);

        if (file) {
            selectFile(file);
        }
    };

    const handleDragLeave = (event: DragEvent): void => {
        const dropzone = event.currentTarget as HTMLElement;

        if (!dropzone.contains(event.relatedTarget as Node | null)) {
            isDragging = false;
        }
    };
</script>

<div class="grid gap-2">
    <input
        bind:this={input}
        {id}
        type="file"
        {name}
        {required}
        {accept}
        aria-invalid={Boolean(error)}
        class="hidden"
        onchange={(event) => {
            updateSelection(event.currentTarget.files?.item(0) ?? null);
        }}
    />

    <button
        type="button"
        class={cn(
            'group flex w-full flex-col items-center justify-center rounded-2xl border-2 border-dashed text-center transition-all hover:border-primary/70 hover:bg-primary/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            compact ? 'min-h-28 gap-2 p-3' : 'min-h-52 gap-3 p-6 sm:p-8',
            selectedFile ? 'border-primary/40 bg-primary/5' : 'bg-muted/25',
            isDragging &&
                'scale-[1.01] border-primary bg-primary/10 text-primary shadow-sm',
        )}
        aria-describedby={hint ? `${id}-hint` : undefined}
        data-dragging={isDragging}
        data-file-selected={Boolean(selectedFile)}
        data-test="file-dropzone"
        onclick={() => input.click()}
        ondragenter={(event) => {
            event.preventDefault();
            isDragging = true;
        }}
        ondragover={(event) => {
            event.preventDefault();
            isDragging = true;

            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'copy';
            }
        }}
        ondragleave={handleDragLeave}
        ondrop={handleDrop}
    >
        {#if mediaPreviewUrl && selectedFile?.type.startsWith('image/')}
            <img
                src={mediaPreviewUrl}
                alt={t('Selected image preview')}
                class={cn(
                    'pointer-events-none max-w-full rounded-xl object-contain shadow-sm',
                    compact ? 'max-h-20' : 'max-h-44',
                )}
            />
        {:else if mediaPreviewUrl && selectedFile?.type.startsWith('video/')}
            <video
                src={mediaPreviewUrl}
                class={cn(
                    'pointer-events-none max-w-full rounded-xl object-contain shadow-sm',
                    compact ? 'max-h-20' : 'max-h-44',
                )}
                muted
            >
                <track kind="captions" />
            </video>
        {/if}
        <span
            class={cn(
                'flex items-center justify-center rounded-2xl bg-primary/10 text-primary transition-transform group-hover:scale-105',
                compact ? 'size-10' : 'size-14',
                isDragging && 'bg-primary text-primary-foreground',
            )}
        >
            {#if selectedFile && !isDragging}
                <CheckCircle class={cn('size-5', !compact && 'size-7')} />
            {:else}
                <UploadCloud class={cn('size-5', !compact && 'size-7')} />
            {/if}
        </span>

        {#if selectedFile && !isDragging}
            <span class="max-w-full" aria-live="polite">
                <span class="block text-sm font-semibold sm:text-base">
                    {t('Ready to upload')}
                </span>
                <span
                    class="mt-1 block max-w-full truncate text-sm font-medium text-foreground"
                >
                    {selectedFile.name}
                </span>
                <span class="mt-0.5 block text-xs text-muted-foreground">
                    {formatSize(selectedFile.size)} · {t(
                        'Click or drop another file to replace it',
                    )}
                </span>
            </span>
        {:else}
            <span>
                <span class="block text-sm font-semibold sm:text-base">
                    {isDragging
                        ? t('Drop file here')
                        : t('Drag and drop a file')}
                </span>
                {#if !isDragging}
                    <span
                        class="mt-1 block text-xs text-muted-foreground sm:text-sm"
                    >
                        {t('or click to choose a file')}
                    </span>
                {/if}
            </span>
        {/if}
    </button>

    {#if hint}
        <p id={`${id}-hint`} class="text-xs text-muted-foreground">{hint}</p>
    {/if}
    <InputError message={error} />
</div>
