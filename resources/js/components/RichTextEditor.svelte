<script lang="ts">
    import { ToggleGroup } from 'bits-ui';
    import ImagePlus from 'lucide-svelte/icons/image-plus';
    import Paperclip from 'lucide-svelte/icons/paperclip';
    import Table2 from 'lucide-svelte/icons/table-2';
    import { Marked, Renderer } from 'marked';
    import { onMount } from 'svelte';
    import Trix from 'trix';
    import { t } from '@/lib/i18n';

    for (let level = 2; level <= 6; level++) {
        Trix.config.blockAttributes[`heading${level}`] = {
            tagName: `h${level}`,
            terminal: true,
            breakOnReturn: true,
            group: false,
        };
    }

    type TrixAttachment = object;
    type TrixRect = { left: number; top: number; bottom: number };
    type TrixLocation = { index: number; offset: number };
    type TrixDocumentApi = {
        getStringAtRange: (range: [number, number]) => string;
        locationFromPosition: (position: number) => TrixLocation;
        positionFromLocation: (location: TrixLocation) => number;
        getBlockAtIndex: (index: number) => { toString: () => string };
    };
    type TrixEditorApi = {
        loadHTML: (html: string) => void;
        insertHTML: (html: string) => void;
        insertAttachment: (attachment: TrixAttachment) => void;
        insertString: (text: string) => void;
        getSelectedRange: () => [number, number];
        setSelectedRange: (range: [number, number] | number) => void;
        getClientRectAtPosition: (position: number) => TrixRect;
        getDocument: () => TrixDocumentApi;
        attributeIsActive: (attribute: string) => boolean;
    };
    type TrixEditorElement = HTMLElement & {
        editor?: TrixEditorApi;
        value: string;
    };
    type TrixConstructor = {
        Attachment: new (attributes: { content: string }) => TrixAttachment;
    };
    type EditorMode = 'rich' | 'markdown';

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
    let hiddenInput: HTMLInputElement;
    let imageInput = $state<HTMLInputElement>();
    let fileInput = $state<HTMLInputElement>();
    const inputId = $derived(`${id}-input`);
    let mode = $state<EditorMode>('rich');
    let markdownValue = $state('');
    let markdownDirty = false;
    let uploadingAttachment = $state(false);
    let attachmentUploadError = $state('');

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
        const trixDocument = editor.editor.getDocument();
        const { index } = trixDocument.locationFromPosition(position);
        const blockStart = trixDocument.positionFromLocation({
            index,
            offset: 0,
        });
        // A mention cannot cross a block boundary, so avoid copying the whole document per keystroke.
        const text = trixDocument.getStringAtRange([blockStart, position]);
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

    const safeLink = (url: string): boolean =>
        !/^[a-z][a-z\d+.-]*:/i.test(url) || /^(https?:|mailto:)/i.test(url);
    const markdownRenderer = new Renderer();

    markdownRenderer.html = ({ text }): string => escapeHtml(text);
    markdownRenderer.code = ({ text, lang }): string => {
        // Trix drops unknown HTML attributes, so retain the code language in a
        // first-line marker that RichTextContent removes before highlighting.
        const language = lang?.trim().split(/\s+/)[0] ?? '';
        const marker = language ? `lang:${escapeHtml(language)}\n` : '';

        return `<pre><code>${marker}${escapeHtml(text)}</code></pre>`;
    };
    markdownRenderer.link = ({ href, tokens }): string => {
        const label = markdownRenderer.parser.parseInline(tokens);

        return safeLink(href)
            ? `<a href="${escapeHtml(href)}">${label}</a>`
            : label;
    };
    markdownRenderer.image = ({ href, text }): string =>
        /^\/(?!\/)/.test(href)
            ? `<img src="${escapeHtml(href)}" alt="${escapeHtml(text)}">`
            : escapeHtml(text);

    const markdownParser = new Marked({
        async: false,
        breaks: false,
        gfm: true,
        renderer: markdownRenderer,
    });
    const markdownToHtml = (markdown: string): string =>
        markdownParser.parse(markdown, { async: false });
    const inlineMarkdown = (markdown: string): string =>
        markdownParser.parseInline(markdown, { async: false });

    const syncMarkdownToInput = (): string => {
        if (!markdownDirty) {
            return hiddenInput.value;
        }

        // Laravel stores rich HTML; defer the O(document) conversion to mode/submit boundaries.
        const html = markdownToHtml(markdownValue);
        hiddenInput.value = html;
        markdownDirty = false;

        return html;
    };

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

    const htmlToMarkdown = (html: string): string => {
        const document = new DOMParser().parseFromString(html, 'text/html');
        const escapeText = (text: string): string =>
            text.replace(/([\\`*_[\]~])/g, '\\$1');
        const children = (node: Node): string =>
            Array.from(node.childNodes, renderNode).join('');
        const list = (element: Element, depth = 0): string => {
            const ordered = element.tagName === 'OL';
            const start = Number(element.getAttribute('start') ?? 1);

            return Array.from(element.children)
                .filter((child) => child.tagName === 'LI')
                .map((item, index) => {
                    const nestedLists = Array.from(item.children).filter(
                        (child) =>
                            child.tagName === 'UL' || child.tagName === 'OL',
                    );
                    const nestedSet = new Set(nestedLists);
                    const body = Array.from(item.childNodes)
                        .filter(
                            (child) =>
                                !(child instanceof Element) ||
                                !nestedSet.has(child),
                        )
                        .map(renderNode)
                        .join('')
                        .replace(/\s*\n+\s*/g, ' ')
                        .trim();
                    const prefix = ordered ? `${start + index}. ` : '- ';
                    const nested = nestedLists
                        .map((child) => list(child, depth + 1))
                        .join('\n');

                    return `${'    '.repeat(depth)}${prefix}${body}${nested ? `\n${nested}` : ''}`;
                })
                .join('\n');
        };
        const table = (element: Element): string => {
            const rows = Array.from(element.querySelectorAll('tr')).map((row) =>
                Array.from(row.querySelectorAll('th, td')).map((cell) =>
                    children(cell)
                        .replace(/\s*\n+\s*/g, ' ')
                        .trim()
                        .replaceAll('|', '\\|'),
                ),
            );

            if (rows.length === 0 || rows[0].length === 0) {
                return '';
            }

            return [
                `| ${rows[0].join(' | ')} |`,
                `| ${rows[0].map(() => '---').join(' | ')} |`,
                ...rows.slice(1).map((row) => `| ${row.join(' | ')} |`),
            ].join('\n');
        };
        const block = (element: Element): string =>
            children(element)
                .trim()
                .replace(/^(\s*)(#{1,3}\s|[-*]\s|\d+\.\s|>\s|```)/, '$1\\$2');

        function renderNode(node: Node): string {
            if (node.nodeType === Node.TEXT_NODE) {
                return escapeText(node.textContent ?? '');
            }

            if (!(node instanceof Element)) {
                return '';
            }

            const content = children(node);
            const tag = node.tagName;

            if (tag === 'BR') {
                return '\n';
            }

            if (tag === 'STRONG' || tag === 'B') {
                return `**${content}**`;
            }

            if (tag === 'EM' || tag === 'I') {
                return `*${content}*`;
            }

            if (tag === 'DEL' || tag === 'S') {
                return `~~${content}~~`;
            }

            if (tag === 'CODE' && node.parentElement?.tagName !== 'PRE') {
                return `\`${node.textContent?.replaceAll('`', '\\`') ?? ''}\``;
            }

            if (tag === 'PRE') {
                const raw = node.textContent ?? '';
                const marker = raw.match(languageMarker);
                const language = marker?.[1] ?? '';
                const code = marker ? raw.slice(marker[0].length) : raw;

                return `\`\`\`${language}\n${code.replace(/\n$/, '')}\n\`\`\`\n\n`;
            }

            if (tag === 'A') {
                const href = node.getAttribute('href');

                return href ? `[${content}](${href})` : content;
            }

            if (tag === 'IMG') {
                const src = node.getAttribute('src');

                return src
                    ? `![${escapeText(node.getAttribute('alt') ?? '')}](${src})`
                    : '';
            }

            if (/^H[1-6]$/.test(tag)) {
                return `${'#'.repeat(Number(tag[1]))} ${content.trim()}\n\n`;
            }

            if (tag === 'UL' || tag === 'OL') {
                return `${list(node)}\n\n`;
            }

            if (tag === 'BLOCKQUOTE') {
                return `${content
                    .trim()
                    .split('\n')
                    .map((line) => `> ${line}`)
                    .join('\n')}\n\n`;
            }

            if (tag === 'TABLE') {
                return `${table(node)}\n\n`;
            }

            if (tag === 'P' || tag === 'DIV') {
                return `${block(node)}\n\n`;
            }

            if (tag === 'FIGCAPTION') {
                return '';
            }

            return content;
        }

        return children(document.body)
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    };

    const setMode = (nextMode: string): void => {
        if (
            (nextMode !== 'rich' && nextMode !== 'markdown') ||
            nextMode === mode
        ) {
            return;
        }

        if (nextMode === 'markdown') {
            markdownValue = htmlToMarkdown(hiddenInput.value);
            markdownDirty = false;
        } else if (markdownDirty) {
            const html = syncMarkdownToInput();
            editor.editor?.loadHTML(html);
        }

        mode = nextMode;
        closeMentions();
    };

    const updateMarkdown = (event: Event): void => {
        markdownValue = (event.currentTarget as HTMLTextAreaElement).value;
        markdownDirty = true;
    };

    const looksLikeMarkdown = (text: string): boolean =>
        /(^|\n)(#{1,3}\s|[-*]\s|\d+\.\s|>\s|```)|\*\*[^*]+\*\*|\|.+\|/.test(
            text,
        );

    const insertTable = (html?: string): void => {
        const content =
            html ??
            '<div class="table-scroll"><table><thead><tr><th>' +
                t('Heading 1') +
                '</th><th>' +
                t('Heading 2') +
                '</th></tr></thead><tbody><tr><td>' +
                t('Content') +
                '</td><td>' +
                t('Content') +
                '</td></tr></tbody></table></div>';
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
        attachmentUploadError = '';

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
                inline: boolean;
            };
            const url = escapeHtml(payload.previewUrl);
            const name = escapeHtml(payload.name);
            const html =
                payload.inline === true
                    ? `<p><a href="${url}"><img src="${url}" alt="${name}"></a></p>`
                    : `<p><a href="${url}">📎 ${name}</a></p>`;
            editor.editor?.insertHTML(html);
            editor.focus();
        } catch {
            attachmentUploadError = t(
                'Attachment upload failed. Please try again.',
            );
        } finally {
            uploadingAttachment = false;
        }
    };

    const openImageChooser = (): void => {
        imageInput?.click();
    };

    const openFileChooser = (): void => {
        fileInput?.click();
    };

    const handleImageSelection = (event: Event): void => {
        const input = event.currentTarget as HTMLInputElement;
        const file = input.files?.[0];
        input.value = '';

        if (file) {
            void uploadAttachment(file);
        }
    };

    onMount(() => {
        const form = hiddenInput.form;
        const syncMarkdownBeforeSubmit = (): void => {
            if (mode === 'markdown') {
                syncMarkdownToInput();
            }
        };
        const resetEditor = (): void => {
            queueMicrotask(() => {
                const resetValue = hiddenInput.value;

                markdownValue = htmlToMarkdown(resetValue);
                markdownDirty = false;
                editor.editor?.loadHTML(resetValue);
                closeMentions();
            });
        };
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

        form?.addEventListener('reset', resetEditor);
        form?.addEventListener('submit', syncMarkdownBeforeSubmit, true);
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
            form?.removeEventListener('reset', resetEditor);
            form?.removeEventListener('submit', syncMarkdownBeforeSubmit, true);
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

<input
    bind:this={hiddenInput}
    id={inputId}
    type="hidden"
    {name}
    value={value ?? ''}
/>
<div class="rich-text-editor-shell relative" data-mode={mode}>
    <trix-editor
        bind:this={editor}
        id={mode === 'rich' ? id : `${id}-rich`}
        input={inputId}
        {placeholder}
        aria-label={ariaLabel}
        aria-required={required}
        aria-hidden={mode === 'markdown'}
        class="trix-content rich-text-editor"
    ></trix-editor>
    {#if mode === 'rich'}
        <div class="rich-text-editor-insert-tools">
            {#if attachmentUploadUrl}
                <input
                    bind:this={imageInput}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    hidden
                    disabled={uploadingAttachment}
                    onchange={handleImageSelection}
                />
                <button
                    type="button"
                    aria-label={t('Insert image')}
                    title={t('Insert image')}
                    onclick={openImageChooser}
                    disabled={uploadingAttachment}
                >
                    <ImagePlus class="size-4" />
                    <span class="sr-only">{t('Insert image')}</span>
                </button>
                <input
                    bind:this={fileInput}
                    type="file"
                    hidden
                    disabled={uploadingAttachment}
                    onchange={handleImageSelection}
                />
                <button
                    type="button"
                    aria-label={t('Insert file')}
                    title={t('Insert file')}
                    onclick={openFileChooser}
                    disabled={uploadingAttachment}
                >
                    <Paperclip class="size-4" />
                    <span class="sr-only">{t('Insert file')}</span>
                </button>
            {/if}
        </div>
    {/if}
    <div class="rich-text-editor-mode-tools">
        {#if mode === 'rich'}
            <button
                type="button"
                aria-label={t('Insert table')}
                title={t('Insert table')}
                onclick={() => insertTable()}
            >
                <Table2 class="size-4" />
                <span class="sr-only">{t('Insert table')}</span>
            </button>
        {/if}
        {#if mode === 'rich' && inCodeBlock}
            <select
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
        <ToggleGroup.Root
            type="single"
            value={mode}
            onValueChange={setMode}
            aria-label={t('Editor mode')}
            class="inline-flex rounded-sm bg-muted p-px"
        >
            <ToggleGroup.Item
                value="rich"
                class="min-h-6 rounded-sm px-1.5 text-xs font-semibold text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring data-[state=on]:bg-background data-[state=on]:text-foreground data-[state=on]:shadow-sm"
            >
                {t('Rich text')}
            </ToggleGroup.Item>
            <ToggleGroup.Item
                value="markdown"
                class="min-h-6 rounded-sm px-1.5 text-xs font-semibold text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring data-[state=on]:bg-background data-[state=on]:text-foreground data-[state=on]:shadow-sm"
            >
                Markdown
            </ToggleGroup.Item>
        </ToggleGroup.Root>
        <span class="sr-only" aria-live="polite">
            {uploadingAttachment ? t('Uploading') : ''}
        </span>
    </div>
    {#if mode === 'markdown'}
        <textarea
            {id}
            value={markdownValue}
            {placeholder}
            {required}
            aria-label={ariaLabel}
            oninput={updateMarkdown}
            class="rich-text-editor-markdown min-h-32 w-full resize-y bg-card px-3 py-3 font-mono text-sm text-foreground outline-none placeholder:text-muted-foreground"
        ></textarea>
    {/if}
    {#if mode === 'rich' && mentionQuery !== null && mentionMatches.length > 0}
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
{#if attachmentUploadError}
    <p class="mt-2 text-sm text-destructive" aria-live="polite">
        {attachmentUploadError}
    </p>
{/if}
