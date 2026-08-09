<script module lang="ts">
    import ProjectDocumentController from '@/actions/App/Http/Controllers/ProjectDocumentController';
    import ProjectMaterialController from '@/actions/App/Http/Controllers/ProjectMaterialController';
    import { show } from '@/routes/projects';
    import type { ProjectTool, Team } from '@/types';

    export const layout = (props: {
        currentTeam: Team;
        project: ProjectTool;
    }) => ({
        breadcrumbs: [
            {
                title: props.project.name,
                href: show([props.currentTeam.slug, props.project.id]),
            },
            {
                translationKey: 'Materials',
                href: ProjectMaterialController.index([
                    props.currentTeam.slug,
                    props.project.id,
                ]),
            },
            {
                translationKey: 'Documents',
                href: ProjectDocumentController.index([
                    props.currentTeam.slug,
                    props.project.id,
                ]),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import ExternalLink from 'lucide-svelte/icons/external-link';
    import Globe2 from 'lucide-svelte/icons/globe-2';
    import AttachmentController from '@/actions/App/Http/Controllers/AttachmentController';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import RichTextContent from '@/components/RichTextContent.svelte';
    import RichTextEditor from '@/components/RichTextEditor.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import {
        Card,
        CardContent,
        CardHeader,
        CardTitle,
    } from '@/components/ui/card';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { currentLocale, t } from '@/lib/i18n';
    import type { Paginated, ProjectDocument, TodoMember } from '@/types';

    let {
        currentTeam,
        project,
        documents,
        folders,
    }: {
        currentTeam: Team;
        project: ProjectTool;
        documents: Paginated<ProjectDocument>;
        folders: TodoMember[];
    } = $props();

    const routeArguments = $derived([currentTeam.slug, project.id] as [
        string,
        number,
    ]);
    // Trix is expensive to mount, so keep one document editor active at a time.
    let editingDocumentId = $state<number | null>(null);
    const toggleDocumentEditor = (documentId: number): void => {
        const opening = editingDocumentId !== documentId;
        editingDocumentId = opening ? documentId : null;

        if (opening) {
            // Focus the first field after Svelte mounts the selected editor.
            requestAnimationFrame(() =>
                document
                    .getElementById(`document-title-${documentId}`)
                    ?.focus(),
            );
        }
    };
    const closeDocumentEditor = (documentId: number): void => {
        editingDocumentId = null;
        requestAnimationFrame(() =>
            document
                .getElementById(`document-edit-toggle-${documentId}`)
                ?.focus(),
        );
    };
    const formatDate = (value: string | null): string =>
        value
            ? new Intl.DateTimeFormat(currentLocale(), {
                  dateStyle: 'medium',
                  timeStyle: 'short',
              }).format(new Date(value))
            : '';
    // Paginator labels are localized HTML entities on the server, so positions are reliable.
    const paginationLabel = (
        label: string,
        index: number,
        lastIndex: number,
    ): string =>
        index === 0 ? t('Previous') : index === lastIndex ? t('Next') : label;
    const hasPaginationNavigation = $derived(
        documents.links.some((link) => link.url !== null && !link.active),
    );
    const confirmDelete = (): boolean =>
        window.confirm(t('Are you sure you want to delete this?'));
</script>

<AppHead title={t('Documents')} />

<div class="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">{t('Documents')}</h1>
        <p class="text-sm text-muted-foreground">{project.name}</p>
    </div>

    <Card>
        <CardHeader><CardTitle>{t('New document')}</CardTitle></CardHeader>
        <CardContent>
            <Form
                {...ProjectDocumentController.store.form(routeArguments)}
                resetOnSuccess
                class="space-y-4"
            >
                {#snippet children({ errors, processing })}
                    <div class="grid gap-2">
                        <Label for="document-title">{t('Title')}</Label>
                        <Input
                            id="document-title"
                            name="title"
                            maxlength={255}
                            required
                        />
                        <InputError message={errors.title} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="document-body">{t('Body')}</Label>
                        <RichTextEditor
                            id="document-body"
                            name="body"
                            required
                            ariaLabel={t('Body')}
                            attachmentUploadUrl={AttachmentController.uploadInline.url(
                                routeArguments,
                            )}
                        />
                        <InputError message={errors.body} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="document-folder">{t('Folder')}</Label>
                        <select
                            id="document-folder"
                            name="material_folder_id"
                            class="border-input h-10 w-full rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">{t('No folder')}</option>
                            {#each folders as folder (folder.id)}
                                <option value={folder.id}>{folder.name}</option>
                            {/each}
                        </select>
                        <InputError message={errors.material_folder_id} />
                    </div>
                    <Button type="submit" disabled={processing}
                        >{t('Create document')}</Button
                    >
                {/snippet}
            </Form>
        </CardContent>
    </Card>

    {#if documents.data.length === 0}
        <Card
            ><CardContent
                class="py-10 text-center text-sm text-muted-foreground"
                >{t('No documents yet')}</CardContent
            ></Card
        >
    {:else}
        <div class="grid gap-5 md:grid-cols-2">
            {#each documents.data as document (document.id)}
                <Card class="min-w-0">
                    <CardHeader class="gap-2">
                        <CardTitle class="break-words"
                            >{document.title}</CardTitle
                        >
                        <p class="text-xs text-muted-foreground">
                            {document.creatorName || t('Deleted user')} · {formatDate(
                                document.updatedAt,
                            )}
                        </p>
                        {#if document.folderName}
                            <Badge variant="outline"
                                >{document.folderName}</Badge
                            >
                        {/if}
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <RichTextContent
                            content={document.body}
                            class="text-sm leading-6"
                        />

                        {#if document.canEdit}
                            <div
                                class="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/30 p-3"
                            >
                                <Form
                                    {...ProjectDocumentController.togglePublicLink.form(
                                        [
                                            currentTeam.slug,
                                            project.id,
                                            document.id,
                                        ],
                                    )}
                                >
                                    {#snippet children({ processing })}
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant={document.publicUrl
                                                ? 'destructive'
                                                : 'outline'}
                                            disabled={processing}
                                        >
                                            <Globe2 class="size-4" />
                                            {document.publicUrl
                                                ? t('Stop public sharing')
                                                : t('Create public link')}
                                        </Button>
                                    {/snippet}
                                </Form>
                                {#if document.publicUrl}
                                    <Button variant="outline" size="sm" asChild>
                                        {#snippet children(buttonProps)}
                                            <a
                                                {...buttonProps}
                                                href={document.publicUrl}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class={buttonProps.class}
                                            >
                                                <ExternalLink class="size-4" />
                                                {t('Open public page')}
                                            </a>
                                        {/snippet}
                                    </Button>
                                {/if}
                            </div>
                        {/if}

                        {#if document.canEdit}
                            <Button
                                id={`document-edit-toggle-${document.id}`}
                                type="button"
                                variant="outline"
                                size="sm"
                                aria-expanded={editingDocumentId ===
                                    document.id}
                                aria-controls={editingDocumentId === document.id
                                    ? `document-editor-${document.id}`
                                    : undefined}
                                disabled={editingDocumentId !== null &&
                                    editingDocumentId !== document.id}
                                onclick={() =>
                                    toggleDocumentEditor(document.id)}
                            >
                                {editingDocumentId === document.id
                                    ? t('Cancel')
                                    : t('Edit document')}
                            </Button>

                            {#if editingDocumentId === document.id}
                                <div
                                    id={`document-editor-${document.id}`}
                                    class="rounded-lg border p-3"
                                >
                                    <Form
                                        {...ProjectDocumentController.update.form(
                                            [
                                                currentTeam.slug,
                                                project.id,
                                                document.id,
                                            ],
                                        )}
                                        onSuccess={() =>
                                            closeDocumentEditor(document.id)}
                                        class="space-y-3"
                                    >
                                        {#snippet children({
                                            errors,
                                            processing,
                                        })}
                                            <Input
                                                id={`document-title-${document.id}`}
                                                name="title"
                                                value={document.title}
                                                maxlength={255}
                                                required
                                                aria-label={t('Title')}
                                            />
                                            <InputError
                                                message={errors.title}
                                            />
                                            <RichTextEditor
                                                id={`document-body-${document.id}`}
                                                name="body"
                                                value={document.body}
                                                required
                                                ariaLabel={t('Body')}
                                                attachmentUploadUrl={AttachmentController.uploadInline.url(
                                                    routeArguments,
                                                )}
                                            />
                                            <InputError message={errors.body} />
                                            <select
                                                name="material_folder_id"
                                                class="border-input h-10 w-full rounded-md border bg-background px-3 text-sm"
                                                aria-label={t('Folder')}
                                            >
                                                <option value=""
                                                    >{t('No folder')}</option
                                                >
                                                {#each folders as folder (folder.id)}
                                                    <option
                                                        value={folder.id}
                                                        selected={folder.id ===
                                                            document.folderId}
                                                    >
                                                        {folder.name}
                                                    </option>
                                                {/each}
                                            </select>
                                            <InputError
                                                message={errors.material_folder_id}
                                            />
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={processing}
                                                >{t('Save')}</Button
                                            >
                                        {/snippet}
                                    </Form>
                                    <Form
                                        {...ProjectDocumentController.destroy.form(
                                            [
                                                currentTeam.slug,
                                                project.id,
                                                document.id,
                                            ],
                                        )}
                                        onBefore={confirmDelete}
                                        class="mt-2"
                                    >
                                        {#snippet children({ processing })}
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                size="sm"
                                                disabled={processing}
                                                >{t('Delete')}</Button
                                            >
                                        {/snippet}
                                    </Form>
                                </div>
                            {/if}
                        {/if}
                    </CardContent>
                </Card>
            {/each}
        </div>
    {/if}

    {#if hasPaginationNavigation}
        <nav
            class="flex flex-wrap justify-center gap-2"
            aria-label={t('Pagination')}
        >
            {#each documents.links as link, index (index)}
                {@const url = link.url}
                {#if url}
                    <Button
                        variant={link.active ? 'default' : 'outline'}
                        size="sm"
                        asChild
                    >
                        {#snippet children(buttonProps)}
                            <Link
                                {...buttonProps}
                                href={url}
                                preserveScroll
                                class={buttonProps.class}
                                aria-current={link.active ? 'page' : undefined}
                            >
                                {paginationLabel(
                                    link.label,
                                    index,
                                    documents.links.length - 1,
                                )}
                            </Link>
                        {/snippet}
                    </Button>
                {:else}
                    <Button variant="outline" size="sm" disabled>
                        {paginationLabel(
                            link.label,
                            index,
                            documents.links.length - 1,
                        )}
                    </Button>
                {/if}
            {/each}
        </nav>
    {/if}
</div>
