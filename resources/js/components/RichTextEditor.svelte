<script lang="ts">
    import Table2 from 'lucide-svelte/icons/table-2';
    import { onMount } from 'svelte';
    import 'trix';
    import { t } from '@/lib/i18n';

    type TrixAttachment = object;
    type TrixRect = { left: number; top: number; bottom: number };
    type TrixLocation = { index: number; offset: number };
    type TrixDocumentApi = {
        toString: () => string;
        locationFromPosition: (position: number) => TrixLocation;
        positionFromLocation: (location: TrixLocation) => number;
        getBlockAtIndex: (index: number) => { toString: () => string };
    };
    type TrixEditorApi = {
        insertHTML: (html: string) => void;
        insertAttachment: (attachment: TrixAttachment) => void;
        insertString: (text: string) => void;
        getSelectedRange: () => [number, number];
        setSelectedRange: (range: [number, number] | number) => void;
        getClientRectAtPosition: (position: number) => TrixRect;
        getDocument: () => TrixDocumentApi;
        attributeIsActive: (attribute: string) => boolean;
    };
    type TrixEditorElement = HTMLElement & { editor?: TrixEditorApi };
    type TrixConstructor = {
        Attachment: new (attributes: { content: string }) => TrixAttachment;
    };

    let {
        id,
        name,
        value = '',
        placeholder = '',
        required = false,
        ariaLabel,
        mentionables = [],
        attachmentUploadUrl,
    }: {
        id: string;
        name: string;
        value?: string | null;
        placeholder?: string;
        required?: boolean;
        ariaLabel?: string;
        mentionables?: { id: number; name: string }[];
        attachmentUploadUrl?: string;
    } = $props();

    let editor: TrixEditorElement;
    const inputId = $derived(`${id}-input`);
    let uploadingAttachment = $state(false);

    // ponytail: curated to shiki/bundle/web — anything outside it would render unhighlighted anyway.
    const codeLanguages = [
        'bash',
        'c',
        'common-lisp',
        'cpp',
        'css',
        'emacs-lisp',
        'graphql',
        'html',
        'java',
        'javascript',
        'json',
        'markdown',
        'php',
        'python',
        'sql',
        'svelte',
        'tsx',
        'typescript',
        'vue',
        'xml',
        'yaml',
    ];
    const languageMarker = /^lang:(\S+)\n/;
    let inCodeBlock = $state(false);
    let codeLanguage = $state('');

    const currentCodeBlock = (): { start: number; marker: string } | null => {
        const api = editor?.editor;

        if (!api) {
            return null;
        }

        const [position] = api.getSelectedRange();
        const trixDocument = api.getDocument();
        const { index } = trixDocument.locationFromPosition(position);

        return {
            start: trixDocument.positionFromLocation({ index, offset: 0 }),
            marker:
                trixDocument
                    .getBlockAtIndex(index)
                    .toString()
                    .match(languageMarker)?.[0] ?? '',
        };
    };

    const updateCodeBlockState = (): void => {
        inCodeBlock = editor?.editor?.attributeIsActive('code') ?? false;
        codeLanguage = inCodeBlock
            ? (currentCodeBlock()?.marker.match(languageMarker)?.[1] ?? '')
            : '';
    };

    const setCodeLanguage = (language: string): void => {
        const api = editor.editor;
        const block = currentCodeBlock();

        if (!api || !block) {
            return;
        }

        const marker = language ? `lang:${language}\n` : '';
        api.setSelectedRange([block.start, block.start + block.marker.length]);
        api.insertString(marker);
        api.setSelectedRange(block.start + marker.length);
        editor.focus();
    };

    let mentionQuery = $state<string | null>(null);
    let mentionMatchStart = $state(0);
    let mentionPosition = $state({ left: 0, top: 0 });
    let mentionActiveIndex = $state(0);
    const mentionMatches = $derived.by(() => {
        const query = mentionQuery;

        if (query === null) {
            return [];
        }

        return mentionables
            .filter((user) =>
                user.name.toLowerCase().includes(query.toLowerCase()),
            )
            .slice(0, 5);
    });

    const closeMentions = (): void => {
        mentionQuery = null;
    };

    const selectMention = (user: { id: number; name: string }): void => {
        if (!editor.editor) {
            return;
        }

        const [position] = editor.editor.getSelectedRange();
        editor.editor.setSelectedRange([mentionMatchStart, position]);
        editor.editor.insertString(`@${user.name} `);
        closeMentions();
        editor.focus();
    };

    const updateMentionState = (): void => {
        if (!editor?.editor || mentionables.length === 0) {
            return;
        }

        const range = editor.editor.getSelectedRange();

        if (range[0] !== range[1]) {
            closeMentions();

            return;
        }

        const position = range[0];
        const text = editor.editor.getDocument().toString().slice(0, position);
        const match = text.match(/@([^\s@]*(?:\s[^\s@]*){0,3})$/);

        if (!match) {
            closeMentions();

            return;
        }

        mentionQuery = match[1];
        mentionMatchStart = position - match[0].length;
        mentionActiveIndex = 0;

        const rect = editor.editor.getClientRectAtPosition(mentionMatchStart);
        const editorRect = editor.getBoundingClientRect();
        mentionPosition = {
            left: rect.left - editorRect.left,
            top: rect.bottom - editorRect.top,
        };
    };

    const onMentionKeydown = (event: KeyboardEvent): void => {
        if (mentionQuery === null || mentionMatches.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            mentionActiveIndex =
                (mentionActiveIndex + 1) % mentionMatches.length;
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            mentionActiveIndex =
                (mentionActiveIndex - 1 + mentionMatches.length) %
                mentionMatches.length;
        } else if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            selectMention(mentionMatches[mentionActiveIndex]);
        } else if (event.key === 'Escape') {
            closeMentions();
        }
    };

    const escapeHtml = (text: string): string =>
        text
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

    const inlineMarkdown = (text: string): string =>
        escapeHtml(text)
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/__([^_]+)__/g, '<strong>$1</strong>')
            .replace(/~~([^~]+)~~/g, '<del>$1</del>')
            .replace(/\*([^*]+)\*/g, '<em>$1</em>');

    const tableFromMarkdown = (markdown: string): string | null => {
        const lines = markdown
            .trim()
            .split(/\r?\n/)
            .filter((line) => line.trim() !== '');

        if (
            lines.length < 2 ||
            !lines[0].includes('|') ||
            !/^\s*\|?\s*:?-{3,}/.test(lines[1])
        ) {
            return null;
        }

        const cells = (line: string): string[] =>
            line
                .trim()
                .replace(/^\|/, '')
                .replace(/\|$/, '')
                .split('|')
                .map((cell) => inlineMarkdown(cell.trim()));
        const headers = cells(lines[0]);
        const rows = lines.slice(2).map(cells);

        return `<div class="table-scroll"><table><thead><tr>${headers.map((cell) => `<th>${cell}</th>`).join('')}</tr></thead><tbody>${rows.map((row) => `<tr>${row.map((cell) => `<td>${cell}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
    };

    const markdownToHtml = (markdown: string): string => {
        const lines = markdown.replaceAll('\r\n', '\n').split('\n');
        const html: string[] = [];
        let code: string[] | null = null;
        let codeLanguage = '';
        let list: 'ul' | 'ol' | null = null;

        const closeList = (): void => {
            if (list) {
                html.push(`</${list}>`);
                list = null;
            }
        };

        for (const line of lines) {
            if (line.startsWith('```')) {
                closeList();

                if (code === null) {
                    code = [];
                    codeLanguage = line.slice(3).trim();
                } else {
                    // ponytail: Trix's document model drops unknown HTML attributes on
                    // save (no data-language survives), so the language rides along as a
                    // literal "lang:xxx" first line instead; RichTextContent strips it
                    // back out before highlighting.
                    const marker = codeLanguage
                        ? `lang:${escapeHtml(codeLanguage)}\n`
                        : '';
                    html.push(
                        `<pre><code>${marker}${escapeHtml(code.join('\n'))}</code></pre>`,
                    );
                    code = null;
                }

                continue;
            }

            if (code !== null) {
                code.push(line);
                continue;
            }

            const heading = line.match(/^(#{1,3})\s+(.+)$/);
            const unordered = line.match(/^[-*]\s+(.+)$/);
            const ordered = line.match(/^\d+\.\s+(.+)$/);

            if (heading) {
                closeList();
                const level = Math.min(heading[1].length, 3);
                html.push(
                    `<h${level}>${inlineMarkdown(heading[2])}</h${level}>`,
                );
            } else if (unordered || ordered) {
                const nextList = unordered ? 'ul' : 'ol';

                if (list !== nextList) {
                    closeList();
                    list = nextList;
                    html.push(`<${list}>`);
                }

                html.push(
                    `<li>${inlineMarkdown((unordered || ordered)?.[1] ?? '')}</li>`,
                );
            } else if (line.startsWith('> ')) {
                closeList();
                html.push(
                    `<blockquote>${inlineMarkdown(line.slice(2))}</blockquote>`,
                );
            } else if (line.trim() === '') {
                closeList();
            } else {
                closeList();
                html.push(`<p>${inlineMarkdown(line)}</p>`);
            }
        }

        closeList();

        if (code !== null) {
            const marker = codeLanguage
                ? `lang:${escapeHtml(codeLanguage)}\n`
                : '';
            html.push(
                `<pre><code>${marker}${escapeHtml(code.join('\n'))}</code></pre>`,
            );
        }

        return html.join('');
    };

    const looksLikeMarkdown = (text: string): boolean =>
        /(^|\n)(#{1,3}\s|[-*]\s|\d+\.\s|>\s|```)|\*\*[^*]+\*\*|\|.+\|/.test(
            text,
        );

    const insertTable = (html?: string): void => {
        const content =
            html ??
            '<div class="table-scroll"><table><thead><tr><th>見出し 1</th><th>見出し 2</th></tr></thead><tbody><tr><td>内容</td><td>内容</td></tr></tbody></table></div>';
        const trix = (window as typeof window & { Trix?: TrixConstructor })
            .Trix;

        if (trix && editor.editor) {
            editor.editor.insertAttachment(new trix.Attachment({ content }));
            editor.focus();
        }
    };

    const uploadAttachment = async (file: File): Promise<void> => {
        if (!attachmentUploadUrl) {
            return;
        }

        uploadingAttachment = true;

        try {
            const formData = new FormData();
            formData.append('file', file);
            const csrfToken = document.querySelector<HTMLMetaElement>(
                'meta[name="csrf-token"]',
            )?.content;
            const response = await fetch(attachmentUploadUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                },
                body: formData,
            });

            if (!response.ok) {
                throw new Error(`Attachment upload failed: ${response.status}`);
            }

            const payload = (await response.json()) as {
                name: string;
                mimeType: string | null;
                previewUrl: string;
            };
            const url = escapeHtml(payload.previewUrl);
            const name = escapeHtml(payload.name);
            const html = payload.mimeType?.startsWith('image/')
                ? `<p><a href="${url}"><img src="${url}" alt="${name}"></a></p>`
                : `<p><a href="${url}">📎 ${name}</a></p>`;
            editor.editor?.insertHTML(html);
            editor.focus();
        } catch {
            // ponytail: silent failure just leaves the file unattached; user can retry the drop.
        } finally {
            uploadingAttachment = false;
        }
    };

    onMount(() => {
        const hideBrokenImage = (image: HTMLImageElement): void => {
            const wrapper =
                image.closest('figure') ?? image.closest('a') ?? image;

            if (wrapper instanceof HTMLElement) {
                wrapper.hidden = true;
            }
        };
        const handleImageError = (event: Event): void => {
            if (event.target instanceof HTMLImageElement) {
                hideBrokenImage(event.target);
            }
        };
        const handleFileAccept = (event: Event): void => {
            event.preventDefault();
            const file = (event as Event & { file?: File }).file;

            if (file && attachmentUploadUrl) {
                void uploadAttachment(file);
            }
        };
        const formatMarkdownPaste = (event: ClipboardEvent): void => {
            const plainText = event.clipboardData?.getData('text/plain') ?? '';
            const html = event.clipboardData?.getData('text/html') ?? '';

            if (!plainText || html || !looksLikeMarkdown(plainText)) {
                return;
            }

            event.preventDefault();
            const table = tableFromMarkdown(plainText);

            if (table) {
                insertTable(table);
            } else {
                editor.editor?.insertHTML(markdownToHtml(plainText));
            }
        };

        editor.addEventListener('trix-file-accept', handleFileAccept);
        editor.addEventListener('error', handleImageError, true);
        editor.addEventListener('paste', formatMarkdownPaste);
        editor.addEventListener('trix-change', updateMentionState);
        editor.addEventListener('trix-change', updateCodeBlockState);
        editor.addEventListener('trix-selection-change', updateCodeBlockState);
        editor.addEventListener('keydown', onMentionKeydown);
        editor.addEventListener('blur', closeMentions);
        editor.querySelectorAll<HTMLImageElement>('img').forEach((image) => {
            if (image.complete && image.naturalWidth === 0) {
                hideBrokenImage(image);
            }
        });

        return () => {
            editor.removeEventListener('trix-file-accept', handleFileAccept);
            editor.removeEventListener('error', handleImageError, true);
            editor.removeEventListener('paste', formatMarkdownPaste);
            editor.removeEventListener('trix-change', updateMentionState);
            editor.removeEventListener('trix-change', updateCodeBlockState);
            editor.removeEventListener(
                'trix-selection-change',
                updateCodeBlockState,
            );
            editor.removeEventListener('keydown', onMentionKeydown);
            editor.removeEventListener('blur', closeMentions);
        };
    });
</script>

<div class="mb-2 flex items-center justify-between gap-2">
    <span class="text-xs text-muted-foreground">
        {t('Markdown supported')}
        {#if attachmentUploadUrl}
            · {t('Drop files to attach')}
        {/if}
        {#if uploadingAttachment}
            · {t('Uploading')}
        {/if}
    </span>
    <div class="flex flex-wrap justify-end gap-2">
        {#if inCodeBlock}
            <select
                class="min-h-9 rounded-md border bg-background px-2 text-xs font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                aria-label={t('Code block language')}
                value={codeLanguage}
                onchange={(event) => setCodeLanguage(event.currentTarget.value)}
            >
                <option value="">{t('Plain text')}</option>
                {#if codeLanguage && !codeLanguages.includes(codeLanguage)}
                    <option value={codeLanguage}>{codeLanguage}</option>
                {/if}
                {#each codeLanguages as language (language)}
                    <option value={language}>{language}</option>
                {/each}
            </select>
        {/if}
        <button
            type="button"
            class="inline-flex min-h-9 items-center gap-2 rounded-md border bg-background px-3 text-xs font-semibold hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            onclick={() => insertTable()}
        >
            <Table2 class="size-4" />
            {t('Insert table')}
        </button>
    </div>
</div>
<input id={inputId} type="hidden" {name} value={value ?? ''} />
<div class="relative">
    <trix-editor
        bind:this={editor}
        {id}
        input={inputId}
        {placeholder}
        aria-label={ariaLabel}
        aria-required={required}
        class="trix-content rich-text-editor"
    ></trix-editor>
    {#if mentionQuery !== null && mentionMatches.length > 0}
        <ul
            class="absolute z-10 w-56 overflow-hidden rounded-md border bg-popover py-1 shadow-md"
            style={`left: ${mentionPosition.left}px; top: ${mentionPosition.top}px;`}
        >
            {#each mentionMatches as user, index (user.id)}
                <li>
                    <button
                        type="button"
                        class="block w-full px-3 py-1.5 text-left text-sm {index ===
                        mentionActiveIndex
                            ? 'bg-muted'
                            : ''}"
                        onmousedown={(event) => event.preventDefault()}
                        onclick={() => selectMention(user)}
                    >
                        {user.name}
                    </button>
                </li>
            {/each}
        </ul>
    {/if}
</div>
