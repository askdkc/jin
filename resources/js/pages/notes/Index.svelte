<script module lang="ts">
    import PersonalNoteController from '@/actions/App/Http/Controllers/PersonalNoteController';
    import type { Team } from '@/types';

    export const layout = (props: { currentTeam: Team }) => ({
        breadcrumbs: [
            {
                title: 'My notes',
                href: PersonalNoteController.index(props.currentTeam.slug),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import NotebookPen from 'lucide-svelte/icons/notebook-pen';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import PersonalNoteAttachmentController from '@/actions/App/Http/Controllers/PersonalNoteAttachmentController';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import RichTextContent from '@/components/RichTextContent.svelte';
    import RichTextEditor from '@/components/RichTextEditor.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
    import type { Paginated, PersonalNote } from '@/types';

    let {
        currentTeam,
        notes,
    }: {
        currentTeam: Team;
        notes: Paginated<PersonalNote>;
    } = $props();

    // Trix is expensive to mount, so only the selected existing note gets an editor.
    let editingNoteId = $state<number | null>(null);
    const toggleNoteEditor = (noteId: number): void => {
        const opening = editingNoteId !== noteId;
        editingNoteId = opening ? noteId : null;

        if (opening) {
            // Focus the first field after Svelte mounts the selected editor.
            requestAnimationFrame(() =>
                document.getElementById(`note-title-${noteId}`)?.focus(),
            );
        }
    };
    const closeNoteEditor = (noteId: number): void => {
        editingNoteId = null;
        requestAnimationFrame(() =>
            document.getElementById(`note-edit-toggle-${noteId}`)?.focus(),
        );
    };
    // Paginator labels are localized HTML entities on the server, so positions are reliable.
    const paginationLabel = (
        label: string,
        index: number,
        lastIndex: number,
    ): string =>
        index === 0 ? t('Previous') : index === lastIndex ? t('Next') : label;
    const hasPaginationNavigation = $derived(
        notes.links.some((link) => link.url !== null && !link.active),
    );
</script>

<AppHead title={t('My notes')} />

<div class="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
    <header>
        <h1 class="flex items-center gap-3 text-3xl font-black tracking-tight">
            <NotebookPen class="size-7 text-primary" />
            {t('My notes')}
        </h1>
        <p class="mt-1 text-sm text-muted-foreground">
            {t('Private notes visible only to you.')}
        </p>
    </header>

    <Form
        {...PersonalNoteController.store.form(currentTeam.slug)}
        resetOnSuccess
        class="space-y-4 rounded-2xl border bg-card p-4 shadow-sm sm:p-6"
    >
        {#snippet children({ errors, processing })}
            <input type="hidden" name="return_to_notes_index" value="1" />
            <div class="grid gap-2">
                <Label for="new-note-title">{t('Title')}</Label>
                <Input
                    id="new-note-title"
                    name="title"
                    maxlength={255}
                    required
                />
                <InputError message={errors.title} />
            </div>
            <div class="grid gap-2">
                <Label for="new-note-body">{t('Note')}</Label>
                <RichTextEditor
                    id="new-note-body"
                    name="body"
                    required
                    ariaLabel={t('Note')}
                    attachmentUploadUrl={PersonalNoteAttachmentController.upload.url(
                        currentTeam.slug,
                    )}
                />
                <InputError message={errors.body} />
            </div>
            <Button type="submit" disabled={processing}>
                {t('Save note')}
            </Button>
        {/snippet}
    </Form>

    <div class="grid gap-4 md:grid-cols-2">
        {#each notes.data as note (note.id)}
            <article class="rounded-2xl border bg-card p-4 shadow-sm sm:p-5">
                {#if editingNoteId === note.id}
                    <Form
                        id={`note-editor-${note.id}`}
                        {...PersonalNoteController.update.form([
                            currentTeam.slug,
                            note.id,
                        ])}
                        onSuccess={() => closeNoteEditor(note.id)}
                        class="space-y-3"
                    >
                        {#snippet children({ errors, processing })}
                            <Input
                                id={`note-title-${note.id}`}
                                name="title"
                                value={note.title}
                                maxlength={255}
                                required
                                aria-label={t('Title')}
                            />
                            <InputError message={errors.title} />
                            <RichTextEditor
                                id={`note-body-${note.id}`}
                                name="body"
                                value={note.body}
                                required
                                ariaLabel={t('Note')}
                                attachmentUploadUrl={PersonalNoteAttachmentController.upload.url(
                                    currentTeam.slug,
                                )}
                            />
                            <InputError message={errors.body} />
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}
                                >
                                    {t('Update')}
                                </Button>
                            </div>
                        {/snippet}
                    </Form>
                {:else}
                    <div class="space-y-3">
                        <h2 class="break-words text-lg font-semibold">
                            {note.title}
                        </h2>
                        <RichTextContent
                            content={note.body}
                            class="text-sm leading-6"
                        />
                    </div>
                {/if}
                <Button
                    id={`note-edit-toggle-${note.id}`}
                    type="button"
                    variant="outline"
                    size="sm"
                    class="mt-3"
                    aria-expanded={editingNoteId === note.id}
                    aria-controls={editingNoteId === note.id
                        ? `note-editor-${note.id}`
                        : undefined}
                    disabled={editingNoteId !== null &&
                        editingNoteId !== note.id}
                    onclick={() => toggleNoteEditor(note.id)}
                >
                    {editingNoteId === note.id ? t('Cancel') : t('Edit')}
                </Button>
                <Form
                    {...PersonalNoteController.destroy.form([
                        currentTeam.slug,
                        note.id,
                    ])}
                    class="mt-3 border-t pt-3 text-right"
                >
                    {#snippet children({ processing })}
                        <Button
                            type="submit"
                            variant="ghost"
                            size="sm"
                            disabled={processing}
                        >
                            <Trash2 class="size-4" />
                            {t('Delete')}
                        </Button>
                    {/snippet}
                </Form>
            </article>
        {/each}
    </div>

    {#if hasPaginationNavigation}
        <nav
            class="flex flex-wrap justify-center gap-2"
            aria-label={t('Pagination')}
        >
            {#each notes.links as link, index (index)}
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
                                    notes.links.length - 1,
                                )}
                            </Link>
                        {/snippet}
                    </Button>
                {:else}
                    <Button variant="outline" size="sm" disabled>
                        {paginationLabel(
                            link.label,
                            index,
                            notes.links.length - 1,
                        )}
                    </Button>
                {/if}
            {/each}
        </nav>
    {/if}
</div>
