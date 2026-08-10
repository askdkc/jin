<script lang="ts">
    import type Picker from 'emoji-picker-element/picker';
    import type { EmojiClickEvent } from 'emoji-picker-element/shared';
    import Smile from 'lucide-svelte/icons/smile';
    import { onDestroy, tick } from 'svelte';
    import { Button } from '@/components/ui/button';
    import { currentLocale, t } from '@/lib/i18n';
    import { loadEmojiPickerLocalization } from '@/lib/locale-integrations';
    import { themeState } from '@/lib/theme.svelte';

    let {
        onSelect,
        disabled = false,
    }: {
        onSelect: (emoji: string) => void;
        disabled?: boolean;
    } = $props();

    const quickEmoji = ['👍', '👏', '👋', '❤️', '😂', '😊', '🎉'];
    const { resolvedAppearance } = themeState();
    let open = $state(false);
    let fullPickerOpen = $state(false);
    let pickerHost = $state<HTMLDivElement>();
    let picker = $state<Picker>();
    let pickerLocale = $state<string>();
    let pickerLoadId = 0;
    let destroyed = false;

    const destroyPicker = (): void => {
        if (!picker) {
            return;
        }

        picker.removeEventListener('emoji-click', handleEmojiClick);
        picker.remove();
        picker = undefined;
        pickerLocale = undefined;
    };

    const close = (): void => {
        pickerLoadId += 1;
        open = false;
        fullPickerOpen = false;
    };

    const select = (emoji: string): void => {
        onSelect(emoji);
        close();
    };

    const handleEmojiClick = (event: EmojiClickEvent): void => {
        if (event.detail.unicode) {
            select(event.detail.unicode);
        }
    };

    const openFullPicker = async (): Promise<void> => {
        fullPickerOpen = true;
        const loadId = ++pickerLoadId;
        await tick();

        const requestedLocale = currentLocale() ?? 'en';

        if (picker && pickerLocale !== requestedLocale) {
            destroyPicker();
        }

        if (!picker) {
            const [{ Picker: EmojiPicker }, localization] = await Promise.all([
                import('emoji-picker-element'),
                loadEmojiPickerLocalization(requestedLocale),
            ]);

            if (
                destroyed ||
                loadId !== pickerLoadId ||
                !open ||
                !fullPickerOpen ||
                requestedLocale !== (currentLocale() ?? 'en')
            ) {
                return;
            }

            picker = new EmojiPicker({
                dataSource: localization.dataSource,
                emojiVersion: 17,
                i18n: localization.i18n,
                locale: localization.locale,
            });
            pickerLocale = requestedLocale;
            picker.style.width = 'min(20rem, calc(100vw - 2rem))';
            picker.style.height = 'min(20rem, calc(100vh - 8rem))';
            picker.style.setProperty('--border-radius', '0.75rem');
            picker.addEventListener('emoji-click', handleEmojiClick);
        }

        // emoji-picker-element is a third-party custom element created at runtime.
        // eslint-disable-next-line svelte/no-dom-manipulating
        pickerHost?.append(picker);
    };

    const handleKeydown = (event: KeyboardEvent): void => {
        if (event.key === 'Escape' && open) {
            close();
        }
    };

    $effect(() => {
        const locale = currentLocale() ?? 'en';

        if (picker && pickerLocale !== locale) {
            destroyPicker();
        }
    });

    $effect(() => {
        picker?.classList.toggle('dark', resolvedAppearance() === 'dark');
    });

    onDestroy(() => {
        destroyed = true;
        pickerLoadId += 1;
        destroyPicker();
    });
</script>

<svelte:window onkeydown={handleKeydown} />

<div class="relative">
    <Button
        type="button"
        variant="ghost"
        size="icon"
        class="size-7 rounded-full"
        {disabled}
        aria-label={t('Insert emoji')}
        title={t('Insert emoji')}
        aria-expanded={open}
        onclick={() => {
            open = !open;
            fullPickerOpen = false;
        }}
    >
        <Smile class="size-3.5" />
    </Button>

    {#if open}
        <div
            class="absolute bottom-full left-0 z-50 mb-2 overflow-hidden rounded-xl border bg-popover text-popover-foreground shadow-xl"
        >
            {#if fullPickerOpen}
                <div bind:this={pickerHost}></div>
            {:else}
                <div class="flex items-center gap-0.5 p-1.5">
                    {#each quickEmoji as emoji (emoji)}
                        <button
                            type="button"
                            class="flex size-9 items-center justify-center rounded-lg text-xl transition hover:scale-110 hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            aria-label={`${t('Insert emoji')} ${emoji}`}
                            onmousedown={(event) => event.preventDefault()}
                            onclick={() => select(emoji)}
                        >
                            {emoji}
                        </button>
                    {/each}
                    <button
                        type="button"
                        class="flex size-9 items-center justify-center rounded-lg text-lg font-bold hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        aria-label={t('More reactions')}
                        onclick={() => void openFullPicker()}
                    >
                        …
                    </button>
                </div>
            {/if}
        </div>
    {/if}
</div>
