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
    import { Form } from '@inertiajs/svelte';
    import NotebookPen from 'lucide-svelte/icons/notebook-pen';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import RichTextEditor from '@/components/RichTextEditor.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
    import type { PersonalNote } from '@/types';

    let {
        currentTeam,
        notes,
    }: {
        currentTeam: Team;
        notes: PersonalNote[];
    } = $props();
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
                />
                <InputError message={errors.body} />
            </div>
            <Button type="submit" disabled={processing}>
                {t('Save note')}
            </Button>
        {/snippet}
    </Form>

    <div class="grid gap-4 md:grid-cols-2">
        {#each notes as note (note.id)}
            <article class="rounded-2xl border bg-card p-4 shadow-sm sm:p-5">
                <Form
                    {...PersonalNoteController.update.form([
                        currentTeam.slug,
                        note.id,
                    ])}
                    class="space-y-3"
                >
                    {#snippet children({ errors, processing })}
                        <Input
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
                        />
                        <InputError message={errors.body} />
                        <Button type="submit" size="sm" disabled={processing}>
                            {t('Update')}
                        </Button>
                    {/snippet}
                </Form>
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
</div>
