<script module lang="ts">
    type OnlyOfficeEditor = {
        destroyEditor(): void;
    };

    type OnlyOfficeApi = {
        DocEditor: new (
            elementId: string,
            config: Record<string, unknown>,
        ) => OnlyOfficeEditor;
    };

    type OnlyOfficeWindow = Window &
        typeof globalThis & {
            DocsAPI?: OnlyOfficeApi;
        };

    let onlyOfficeApiPromise: Promise<void> | null = null;
    let onlyOfficeApiSource = '';

    const onlyOfficeWindow = (): OnlyOfficeWindow => window as OnlyOfficeWindow;

    const apiSource = (documentServerUrl: string): string =>
        `${documentServerUrl.replace(/\/+$/, '')}/web-apps/apps/api/documents/api.js`;

    const loadOnlyOfficeApi = async (
        documentServerUrl: string,
    ): Promise<void> => {
        if (onlyOfficeWindow().DocsAPI?.DocEditor) {
            return;
        }

        const source = apiSource(documentServerUrl);

        if (onlyOfficeApiPromise && onlyOfficeApiSource === source) {
            return onlyOfficeApiPromise;
        }

        if (onlyOfficeApiPromise && onlyOfficeApiSource !== source) {
            throw new Error('ONLYOFFICE API source changed.');
        }

        onlyOfficeApiSource = source;
        onlyOfficeApiPromise = new Promise<void>((resolve, reject) => {
            const existing = Array.from(document.scripts).find(
                (candidate) => candidate.src === source,
            );
            const script = existing ?? document.createElement('script');
            let timeoutId = 0;
            let settled = false;

            const finish = (error?: Error): void => {
                if (settled) {
                    return;
                }

                settled = true;
                window.clearTimeout(timeoutId);
                script.removeEventListener('load', handleLoad);
                script.removeEventListener('error', handleError);

                if (error) {
                    if (script.dataset.onlyofficeApi === source) {
                        script.remove();
                    }

                    reject(error);
                } else {
                    resolve();
                }
            };
            const handleLoad = (): void => {
                if (onlyOfficeWindow().DocsAPI?.DocEditor) {
                    finish();
                } else {
                    finish(new Error('ONLYOFFICE API is unavailable.'));
                }
            };
            const handleError = (): void =>
                finish(new Error('ONLYOFFICE API failed to load.'));

            script.addEventListener('load', handleLoad, { once: true });
            script.addEventListener('error', handleError, { once: true });
            timeoutId = window.setTimeout(
                () => finish(new Error('ONLYOFFICE API load timed out.')),
                30_000,
            );

            if (!existing) {
                script.src = source;
                script.async = true;
                script.dataset.onlyofficeApi = source;
                document.head.append(script);
            }
        });

        try {
            await onlyOfficeApiPromise;
        } catch (error) {
            onlyOfficeApiPromise = null;
            onlyOfficeApiSource = '';

            throw error;
        }
    };
</script>

<script lang="ts">
    import Download from 'lucide-svelte/icons/download';
    import FileText from 'lucide-svelte/icons/file-text';
    import LoaderCircle from 'lucide-svelte/icons/loader-circle';
    import { onDestroy, tick } from 'svelte';
    import ProjectFileController from '@/actions/App/Http/Controllers/ProjectFileController';
    import { Button } from '@/components/ui/button';
    import { t } from '@/lib/i18n';
    import type { ProjectTool, Team } from '@/types';

    type PreviewFile = {
        id: number;
        name: string;
        mimeType?: string | null;
    };

    type OnlyOfficePayload = {
        documentServerUrl: string;
        config: Record<string, unknown>;
    };

    let {
        currentTeam,
        project,
        file,
        configUrl,
        active = true,
        class: className = 'h-full min-h-[70dvh] w-full',
    }: {
        currentTeam: Team;
        project: ProjectTool;
        file: PreviewFile | null;
        configUrl: string;
        active?: boolean;
        class?: string;
    } = $props();

    const componentId = $props.id();
    const previewHostId = $derived(
        `${componentId}-onlyoffice-preview-${file?.id ?? 'empty'}`,
    );
    let previewHost = $state<HTMLDivElement>();
    let previewLoading = $state(false);
    let previewError = $state('');
    let previewRequest: AbortController | null = null;
    let previewEditor: OnlyOfficeEditor | null = null;
    let readyTimeout = 0;
    let loadedFileId: number | null = null;
    let generation = 0;

    const cleanupPreview = (): void => {
        generation += 1;
        previewRequest?.abort();
        previewRequest = null;

        if (typeof window !== 'undefined') {
            window.clearTimeout(readyTimeout);
        }

        readyTimeout = 0;

        if (previewEditor) {
            try {
                previewEditor.destroyEditor();
            } catch {
                // The iframe may already have removed itself.
            }
        }

        previewEditor = null;
        // eslint-disable-next-line svelte/no-dom-manipulating
        previewHost?.replaceChildren();
        previewLoading = false;
        loadedFileId = null;
    };

    const failPreview = (requestGeneration: number): void => {
        if (generation !== requestGeneration) {
            return;
        }

        if (typeof window !== 'undefined') {
            window.clearTimeout(readyTimeout);
        }

        readyTimeout = 0;
        previewLoading = false;
        previewError = t('Could not load this file preview.');
    };

    const isPayload = (value: unknown): value is OnlyOfficePayload => {
        if (!value || typeof value !== 'object') {
            return false;
        }

        const payload = value as Partial<OnlyOfficePayload>;

        return (
            typeof payload.documentServerUrl === 'string' &&
            payload.documentServerUrl.length > 0 &&
            !!payload.config &&
            typeof payload.config === 'object'
        );
    };

    const loadPreview = async (previewFile: PreviewFile): Promise<void> => {
        cleanupPreview();
        const requestGeneration = generation;
        loadedFileId = previewFile.id;
        previewLoading = true;
        previewError = '';
        const request = new AbortController();
        previewRequest = request;

        await tick();

        try {
            const response = await fetch(configUrl, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: request.signal,
            });

            if (!response.ok) {
                throw new Error(`ONLYOFFICE config failed: ${response.status}`);
            }

            const payload: unknown = await response.json();

            if (!isPayload(payload)) {
                throw new Error('ONLYOFFICE config is invalid.');
            }

            const publicUrl = new URL(
                payload.documentServerUrl,
                window.location.origin,
            );

            if (!['http:', 'https:'].includes(publicUrl.protocol)) {
                throw new Error('ONLYOFFICE public URL is invalid.');
            }

            if (
                window.location.protocol === 'https:' &&
                publicUrl.protocol !== 'https:'
            ) {
                throw new Error(
                    'ONLYOFFICE would be blocked as mixed content.',
                );
            }

            await loadOnlyOfficeApi(publicUrl.toString());

            if (
                request.signal.aborted ||
                generation !== requestGeneration ||
                !previewHost
            ) {
                return;
            }

            const DocsAPI = onlyOfficeWindow().DocsAPI;

            if (!DocsAPI?.DocEditor) {
                throw new Error('ONLYOFFICE API is unavailable.');
            }

            const editorConfig: Record<string, unknown> = {
                ...payload.config,
                events: {
                    onAppReady: () => {
                        if (generation !== requestGeneration) {
                            return;
                        }

                        window.clearTimeout(readyTimeout);
                        readyTimeout = 0;
                        previewLoading = false;
                    },
                    onError: () => failPreview(requestGeneration),
                },
            };

            previewEditor = new DocsAPI.DocEditor(previewHostId, editorConfig);
            readyTimeout = window.setTimeout(
                () => failPreview(requestGeneration),
                60_000,
            );
        } catch (error) {
            if (!(
                error instanceof DOMException && error.name === 'AbortError'
            )) {
                failPreview(requestGeneration);
            }
        } finally {
            if (previewRequest === request) {
                previewRequest = null;
            }
        }
    };

    $effect(() => {
        if (active && file && loadedFileId !== file.id) {
            void loadPreview(file);

            return;
        }

        if (!active && loadedFileId !== null) {
            cleanupPreview();
            previewError = '';
        }
    });

    onDestroy(cleanupPreview);
</script>

<div class={`relative overflow-hidden bg-slate-100 ${className}`}>
    <div
        id={previewHostId}
        bind:this={previewHost}
        class="absolute inset-0"
        aria-label={t('Document preview')}
    ></div>

    {#if previewLoading}
        <div
            class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 bg-background"
            role="status"
        >
            <LoaderCircle class="size-8 animate-spin text-primary" />
            <p class="text-sm font-medium">
                {t('Preparing preview...')}
            </p>
        </div>
    {:else if previewError}
        <div
            class="absolute inset-0 z-10 flex items-center justify-center bg-background p-6 text-center"
            role="alert"
        >
            <div>
                <FileText class="mx-auto size-10 text-muted-foreground" />
                <p class="mt-3 font-semibold">{previewError}</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {t('Download the file to open it locally.')}
                </p>
                <div class="mt-4 flex flex-wrap justify-center gap-2">
                    {#if file}
                        <Button variant="outline" asChild>
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
                                    <Download aria-hidden="true" />
                                    {t('Download')}
                                </a>
                            {/snippet}
                        </Button>
                    {/if}
                </div>
            </div>
        </div>
    {/if}
</div>
