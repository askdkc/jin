<script lang="ts">
    import AtSign from 'lucide-svelte/icons/at-sign';
    import LoaderCircle from 'lucide-svelte/icons/loader-circle';
    import Paperclip from 'lucide-svelte/icons/paperclip';
    import Send from 'lucide-svelte/icons/send';
    import EmojiPickerButton from '@/components/EmojiPickerButton.svelte';
    import RichTextEditor from '@/components/RichTextEditor.svelte';
    import { Button } from '@/components/ui/button';
    import { t } from '@/lib/i18n';
    import { cn } from '@/lib/utils';

    let {
        id,
        placeholder,
        mentionables,
        attachmentUploadUrl,
        processing,
        onSubmit,
        onAttach,
        attachDisabled = false,
        allowEmpty = false,
        compact = false,
        submitLabel,
    }: {
        id: string;
        placeholder: string;
        mentionables: { id: number; name: string }[];
        attachmentUploadUrl: string;
        processing: boolean;
        onSubmit: (body: string) => Promise<boolean>;
        onAttach?: () => void;
        attachDisabled?: boolean;
        allowEmpty?: boolean;
        compact?: boolean;
        submitLabel: string;
    } = $props();

    let form = $state<HTMLFormElement>();
    let editor = $state<{
        insertText: (text: string) => void;
        openMentionPicker: () => void;
    }>();
    let hasContent = $state(false);
    let expanded = $state(false);
    let attachmentUploading = $state(false);
    const composerProcessing = $derived(processing || attachmentUploading);
    const canSubmit = $derived(
        !composerProcessing && (hasContent || allowEmpty),
    );

    $effect(() => {
        if (allowEmpty) {
            expanded = true;
        }
    });

    const submit = async (event: SubmitEvent): Promise<void> => {
        event.preventDefault();

        if (!form || !canSubmit) {
            return;
        }

        const body = String(new FormData(form).get('body') ?? '').trim();
        const sent = await onSubmit(body);

        if (sent) {
            form.reset();
            hasContent = false;
            expanded = false;

            const focusedElement = document.activeElement;

            if (
                focusedElement instanceof HTMLElement &&
                form.contains(focusedElement)
            ) {
                focusedElement.blur();
            }
        }
    };
</script>

<form
    bind:this={form}
    class={cn(
        'chat-composer overflow-visible rounded-xl border border-input bg-card shadow-sm transition focus-within:border-ring focus-within:ring-2 focus-within:ring-ring',
        '[&_.rich-text-editor-shell]:!border-0 [&_.rich-text-editor-shell]:!shadow-none',
        '[&_trix-editor.rich-text-editor]:transition-[min-height] [&_.rich-text-editor-markdown]:transition-[min-height]',
        expanded
            ? compact
                ? '[&_.rich-text-editor-shell]:!rounded-b-none [&_.rich-text-editor-shell]:!rounded-t-xl [&_trix-editor.rich-text-editor]:!min-h-20 [&_.rich-text-editor-markdown]:!min-h-20'
                : '[&_.rich-text-editor-shell]:!rounded-b-none [&_.rich-text-editor-shell]:!rounded-t-xl [&_trix-editor.rich-text-editor]:!min-h-28 [&_.rich-text-editor-markdown]:!min-h-28'
            : '[&_.rich-text-editor-shell]:!rounded-xl [&_trix-editor.rich-text-editor]:!min-h-12 [&_.rich-text-editor-markdown]:!min-h-12',
    )}
    data-expanded={expanded}
    aria-label={t('Write a message')}
    onpointerdown={() => (expanded = true)}
    onfocusin={() => (expanded = true)}
    onsubmit={submit}
>
    <RichTextEditor
        bind:this={editor}
        {id}
        name="body"
        {placeholder}
        ariaLabel={placeholder}
        {mentionables}
        {attachmentUploadUrl}
        showFileInsert={false}
        onContentChange={(value) => (hasContent = value)}
        onAttachmentUploadChange={(uploading) =>
            (attachmentUploading = uploading)}
        onSubmitShortcut={() => form?.requestSubmit()}
    />

    {#if expanded}
        <div class="flex min-h-9 items-center gap-0.5 border-t px-2 py-1">
            {#if onAttach}
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-7 rounded-full"
                    disabled={attachDisabled}
                    aria-label={t('Attach file')}
                    title={t('Attach file')}
                    onclick={onAttach}
                >
                    <Paperclip class="size-3.5" />
                </Button>
            {/if}

            <EmojiPickerButton
                disabled={composerProcessing}
                onSelect={(emoji) => editor?.insertText(emoji)}
            />

            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="size-7 rounded-full"
                disabled={composerProcessing || mentionables.length === 0}
                aria-label={t('Mention someone')}
                title={t('Mention someone')}
                onclick={() => editor?.openMentionPicker()}
            >
                <AtSign class="size-3.5" />
            </Button>

            <span
                class="ml-auto hidden items-center gap-1 text-xs text-muted-foreground sm:flex"
            >
                <kbd class="rounded border bg-muted px-1 py-0.5 font-sans"
                    >⌘/Ctrl</kbd
                >
                +
                <kbd class="rounded border bg-muted px-1 py-0.5 font-sans"
                    >Enter</kbd
                >
            </span>

            <Button
                type="submit"
                size="icon"
                class="ml-1 size-7 rounded-full"
                disabled={!canSubmit}
                aria-label={submitLabel}
                title={submitLabel}
            >
                {#if composerProcessing}
                    <LoaderCircle class="size-3.5 animate-spin" />
                {:else}
                    <Send class="size-3.5" />
                {/if}
            </Button>
        </div>
    {/if}
</form>
