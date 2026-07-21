<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import ja from 'emoji-picker-element/i18n/ja.js';
    import type Picker from 'emoji-picker-element/picker';
    import type { EmojiClickEvent } from 'emoji-picker-element/shared';
    import emojiDataSource from 'emoji-picker-element-data/ja/cldr-native/data.json?url';
    import { tick } from 'svelte';
    import { t } from '@/lib/i18n';
    import { themeState } from '@/lib/theme.svelte';
    import type { ProjectReaction } from '@/types';

    let {
        action,
        reactions,
    }: {
        action: string;
        reactions: ProjectReaction[];
    } = $props();

    const quickReactions = ['👍', '👏', '👋', '❤️', '😂', '😊', '🎉'];
    const { resolvedAppearance } = themeState();
    let menuOpen = $state(false);
    let moreOpen = $state(false);
    let customOpen = $state(false);
    let customReaction = $state('');
    let pickerHost = $state<HTMLDivElement>();
    let picker = $state<Picker>();
    let emojiSubmitButton = $state<HTMLButtonElement>();

    const selectEmoji = async (event: EmojiClickEvent): Promise<void> => {
        if (!event.detail.unicode) {
            return;
        }

        customReaction = event.detail.unicode;
        await tick();
        emojiSubmitButton?.click();
    };

    const toggleFullPicker = async (): Promise<void> => {
        moreOpen = !moreOpen;

        if (!moreOpen) {
            return;
        }

        await tick();

        if (!picker) {
            const { Picker: EmojiPicker } =
                await import('emoji-picker-element');

            picker = new EmojiPicker({
                dataSource: emojiDataSource,
                emojiVersion: 17,
                i18n: ja,
                locale: 'ja',
            });
            picker.style.width = 'min(22rem, calc(100vw - 2rem))';
            picker.style.height = 'min(25rem, calc(100vh - 8rem))';
            picker.style.setProperty('--border-radius', '0.75rem');
            picker.addEventListener('emoji-click', selectEmoji);
        }

        // emoji-picker-element is a third-party custom element created at runtime.
        // eslint-disable-next-line svelte/no-dom-manipulating
        pickerHost?.append(picker);
    };

    $effect(() => {
        picker?.classList.toggle('dark', resolvedAppearance() === 'dark');
    });

    const close = (): void => {
        menuOpen = false;
        moreOpen = false;
        customOpen = false;
        customReaction = '';
    };
</script>

<div class="flex flex-wrap items-center gap-2">
    {#each reactions as reaction (reaction.reaction)}
        <Form {action} method="post">
            {#snippet children({ processing })}
                <input
                    type="hidden"
                    name="reaction"
                    value={reaction.reaction}
                />
                <button
                    type="submit"
                    disabled={processing}
                    aria-pressed={reaction.reacted}
                    title={reaction.names.join(', ')}
                    class={[
                        'inline-flex h-8 items-center gap-1 rounded-full border px-2.5 text-sm shadow-sm transition hover:-translate-y-0.5 disabled:pointer-events-none disabled:opacity-50',
                        reaction.reacted
                            ? 'border-primary bg-primary/10'
                            : 'border-border bg-background hover:bg-muted',
                    ]}
                >
                    <span>{reaction.reaction}</span>
                    {#if reaction.count > 1}
                        <span
                            class="text-xs font-semibold text-muted-foreground"
                            >{reaction.count}</span
                        >
                    {/if}
                </button>
            {/snippet}
        </Form>
    {/each}

    <div class="relative">
        {#if menuOpen}
            {#if customOpen}
                <Form
                    {action}
                    method="post"
                    onSuccess={close}
                    class="relative flex w-72 items-center gap-1 rounded-full border bg-popover p-1.5 text-popover-foreground shadow-xl"
                >
                    {#snippet children({ errors, processing })}
                        <input
                            name="reaction"
                            maxlength={20}
                            required
                            bind:value={customReaction}
                            aria-label={t('Write a short reaction')}
                            placeholder={t('Write a short reaction…')}
                            class="min-w-0 flex-1 bg-transparent px-2 text-sm outline-none placeholder:text-muted-foreground"
                        />
                        <button
                            type="submit"
                            disabled={processing}
                            aria-label={t('Save')}
                            class="flex size-7 items-center justify-center rounded-full text-emerald-600 hover:bg-emerald-500/10 disabled:opacity-50"
                        >
                            ✓
                        </button>
                        <button
                            type="button"
                            aria-label={t('Cancel')}
                            onclick={close}
                            class="flex size-7 items-center justify-center rounded-full text-destructive hover:bg-destructive/10"
                        >
                            ×
                        </button>
                        {#if errors.reaction}
                            <span
                                class="absolute top-full left-2 mt-1 text-xs text-destructive"
                                >{errors.reaction}</span
                            >
                        {/if}
                    {/snippet}
                </Form>
            {:else}
                <div
                    class="flex items-center gap-0.5 rounded-full border bg-popover p-1.5 text-popover-foreground shadow-xl"
                >
                    <button
                        type="button"
                        aria-label={t('Write a short reaction')}
                        onclick={() => (customOpen = true)}
                        class="flex size-8 items-center justify-center rounded-full text-lg hover:bg-muted"
                    >
                        <span aria-hidden="true">💬</span>
                    </button>
                    {#each quickReactions as reaction (reaction)}
                        <Form {action} method="post" onSuccess={close}>
                            {#snippet children({ processing })}
                                <input
                                    type="hidden"
                                    name="reaction"
                                    value={reaction}
                                />
                                <button
                                    type="submit"
                                    disabled={processing}
                                    aria-label={`${t('React with')} ${reaction}`}
                                    class="flex size-8 items-center justify-center rounded-full text-xl transition hover:scale-110 hover:bg-muted disabled:opacity-50"
                                >
                                    {reaction}
                                </button>
                            {/snippet}
                        </Form>
                    {/each}
                    <button
                        type="button"
                        aria-label={t('More reactions')}
                        aria-expanded={moreOpen}
                        onclick={toggleFullPicker}
                        class="flex size-8 items-center justify-center rounded-full text-xl font-bold hover:bg-muted"
                    >
                        …
                    </button>
                </div>

                {#if moreOpen}
                    <div
                        class="absolute bottom-full left-0 z-50 mb-2 overflow-hidden rounded-xl bg-popover shadow-xl"
                    >
                        <div bind:this={pickerHost}></div>
                    </div>

                    <Form
                        {action}
                        method="post"
                        onSuccess={close}
                        class="hidden"
                    >
                        {#snippet children({ processing })}
                            <input
                                type="hidden"
                                name="reaction"
                                value={customReaction}
                            />
                            <button
                                bind:this={emojiSubmitButton}
                                type="submit"
                                disabled={processing}
                                tabindex="-1"
                                aria-hidden="true"
                            ></button>
                        {/snippet}
                    </Form>
                {/if}
            {/if}
        {:else}
            <button
                type="button"
                aria-label={t('Add a reaction')}
                aria-expanded={menuOpen}
                onclick={() => (menuOpen = true)}
                class="flex size-8 items-center justify-center rounded-full border bg-background text-sm shadow-sm transition hover:-translate-y-0.5 hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <span aria-hidden="true">🚀</span>
            </button>
        {/if}
    </div>
</div>
