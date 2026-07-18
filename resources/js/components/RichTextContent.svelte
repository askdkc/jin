<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import StoredFilePreviewDialog, {
        canPreviewStoredFile,
    } from '@/components/StoredFilePreviewDialog.svelte';
    import type { Team } from '@/types';

    let {
        content,
        class: className = '',
    }: {
        content: string | null | undefined;
        class?: string;
    } = $props();

    const currentTeam = $derived(page.props.currentTeam as Team | null);

    let previewOpen = $state(false);
    let previewProjectId = $state<number | null>(null);
    let previewFile = $state<{ id: number; name: string } | null>(null);

    const filePattern = /\/projects\/(\d+)\/files\/(\d+)\/preview/;

    const handleClick = (event: MouseEvent): void => {
        if (!currentTeam) {
            return;
        }

        const anchor = (event.target as HTMLElement).closest('a');
        const match = anchor?.getAttribute('href')?.match(filePattern);

        if (!anchor || !match) {
            return;
        }

        const isImage = anchor.querySelector('img') !== null;
        const name = isImage
            ? 'image.jpg'
            : (anchor.textContent ?? '').replace(/^\s*📎\s*/, '').trim();

        if (!isImage && !canPreviewStoredFile(name)) {
            return;
        }

        event.preventDefault();
        previewProjectId = Number(match[1]);
        previewFile = { id: Number(match[2]), name: name || 'file' };
        previewOpen = true;
    };

    let containerEl = $state<HTMLDivElement>();

    // Trix strips unknown HTML attributes on save, so a pasted ```lang fence rides
    // along as a literal "lang:xxx" first line instead (see RichTextEditor.svelte).
    const languageMarker = /^lang:(\S+)\n/;

    // Grammars missing from shiki/bundle/web, loaded on demand into the shared
    // singleton highlighter (the codeToHtml shorthand rejects non-bundle names).
    const extraLanguages: Record<string, () => Promise<{ default: unknown }>> =
        {
            'common-lisp': () => import('@shikijs/langs/common-lisp'),
            lisp: () => import('@shikijs/langs/common-lisp'),
            'emacs-lisp': () => import('@shikijs/langs/emacs-lisp'),
            elisp: () => import('@shikijs/langs/emacs-lisp'),
        };

    const highlightCodeBlocks = async (
        blocks: HTMLPreElement[],
    ): Promise<void> => {
        const {
            codeToHtml,
            bundledLanguages,
            bundledLanguagesAlias,
            getSingletonHighlighter,
        } = await import('shiki/bundle/web');
        const themes = {
            light: 'github-light',
            dark: 'github-dark',
        } as const;

        for (const block of blocks) {
            const text = block.textContent ?? '';
            const match = text.match(languageMarker);
            const requested = match?.[1].toLowerCase() ?? '';
            const lang =
                requested in bundledLanguages ||
                requested in bundledLanguagesAlias ||
                requested in extraLanguages
                    ? requested
                    : 'text';
            const code = match ? text.slice(match[0].length) : text;

            try {
                let html: string;

                if (lang in extraLanguages) {
                    const highlighter = await getSingletonHighlighter({
                        themes: Object.values(themes),
                    });
                    await highlighter.loadLanguage(
                        (await extraLanguages[lang]()).default as Parameters<
                            typeof highlighter.loadLanguage
                        >[0],
                    );
                    html = highlighter.codeToHtml(code, {
                        lang,
                        themes,
                        defaultColor: false,
                    });
                } else {
                    html = await codeToHtml(code, {
                        lang,
                        themes,
                        defaultColor: false,
                    });
                }

                const template = document.createElement('template');
                template.innerHTML = html;
                const highlighted = template.content.firstElementChild;

                if (!highlighted || !block.isConnected) {
                    continue;
                }

                highlighted.setAttribute('data-shiki', '');

                if (lang !== 'text') {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'shiki-block';
                    wrapper.setAttribute('data-shiki', '');
                    const badge = document.createElement('span');
                    badge.className = 'shiki-language-badge';
                    badge.textContent = lang;
                    wrapper.append(badge, highlighted);
                    block.replaceWith(wrapper);
                } else {
                    block.replaceWith(highlighted);
                }
            } catch {
                // ponytail: leave the block as plain monospace text if highlighting fails
                // (e.g. offline and the language chunk can't be fetched).
            }
        }
    };

    $effect(() => {
        void content;

        const blocks = containerEl
            ? [
                  ...containerEl.querySelectorAll<HTMLPreElement>(
                      'pre:not([data-shiki])',
                  ),
              ]
            : [];

        if (blocks.length > 0) {
            void highlightCodeBlocks(blocks);
        }
    });
</script>

<div
    bind:this={containerEl}
    class={`trix-content rich-text-content ${className}`}
    onclick={handleClick}
>
    <!-- eslint-disable-next-line svelte/no-at-html-tags -- server-side rich-text casts sanitize this HTML. -->
    {@html content ?? ''}
</div>

{#if currentTeam && previewProjectId}
    <StoredFilePreviewDialog
        {currentTeam}
        project={{ id: previewProjectId, name: '' }}
        file={previewFile}
        bind:open={previewOpen}
    />
{/if}
