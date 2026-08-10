<script module lang="ts">
    import ScheduleEventController from '@/actions/App/Http/Controllers/ScheduleEventController';
    import { show as showProject } from '@/routes/projects';
    import type { ProjectTool, Team } from '@/types';

    export const layout = (props: {
        currentTeam: Team;
        project: ProjectTool;
        event: { id: number; title: string };
    }) => ({
        breadcrumbs: [
            {
                title: props.project.name,
                href: showProject([props.currentTeam.slug, props.project.id]),
            },
            {
                title: props.currentTeam.menuNames.calendar,
                href: ScheduleEventController.index([
                    props.currentTeam.slug,
                    props.project.id,
                ]),
            },
            {
                title: props.event.title,
                href: ScheduleEventController.show([
                    props.currentTeam.slug,
                    props.project.id,
                    props.event.id,
                ]),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import Video from 'lucide-svelte/icons/video';
    import AttachmentController from '@/actions/App/Http/Controllers/AttachmentController';
    import CommentController from '@/actions/App/Http/Controllers/CommentController';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import ReactionPicker from '@/components/ReactionPicker.svelte';
    import RichTextContent from '@/components/RichTextContent.svelte';
    import RichTextEditor from '@/components/RichTextEditor.svelte';
    import { Button } from '@/components/ui/button';
    import { Label } from '@/components/ui/label';
    import { submitFormById } from '@/lib/form-submit';
    import { currentLocale, t } from '@/lib/i18n';
    import type { ProjectScheduleEventDetail, TodoMember } from '@/types';

    let {
        currentTeam,
        project,
        event,
        members,
    }: {
        currentTeam: Team;
        project: ProjectTool;
        event: ProjectScheduleEventDetail;
        members: TodoMember[];
    } = $props();

    const routeArguments = $derived([
        currentTeam.slug,
        project.id,
        event.id,
    ] as [string, number, number]);
    let commentComposerOpen = $state(false);

    const monthFormatter = new Intl.DateTimeFormat(currentLocale(), {
        month: 'short',
    });
    const dayFormatter = new Intl.DateTimeFormat(currentLocale(), {
        day: 'numeric',
    });
    const dateTimeFormatter = new Intl.DateTimeFormat(currentLocale(), {
        dateStyle: 'long',
        timeStyle: 'short',
    });
    const allDayFormatter = new Intl.DateTimeFormat(currentLocale(), {
        dateStyle: 'long',
    });
    const commentDateFormatter = new Intl.DateTimeFormat(currentLocale(), {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
    const formatWhen = (): string => {
        const start = new Date(event.startsAt);
        const end = new Date(event.endsAt);

        if (event.allDay) {
            return `${allDayFormatter.format(start)} · ${t('All day')}`;
        }

        return `${dateTimeFormatter.format(start)} – ${dateTimeFormatter.format(end)}`;
    };
</script>

<AppHead title={event.title} />

<main class="mx-auto w-full max-w-5xl px-4 py-6 sm:px-6 sm:py-8">
    <article class="overflow-hidden rounded-xl border bg-card">
        <header class="px-5 pt-5 pb-6 sm:px-8">
            <nav
                class="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-1 text-sm text-muted-foreground"
                aria-label={t('Breadcrumb')}
            >
                <Link
                    href={showProject([currentTeam.slug, project.id])}
                    class="truncate font-semibold text-foreground hover:underline"
                    >{project.name}</Link
                >
                <span aria-hidden="true">‹</span>
                <Link
                    href={ScheduleEventController.index([
                        currentTeam.slug,
                        project.id,
                    ])}
                    class="hover:underline"
                    >{currentTeam.menuNames.calendar}</Link
                >
            </nav>

            <div class="mt-8 flex items-start gap-5">
                <div
                    class="flex w-16 shrink-0 flex-col items-center overflow-hidden rounded-md border border-primary"
                    aria-hidden="true"
                >
                    <div
                        class="w-full bg-primary py-1 text-center text-sm font-semibold text-primary-foreground"
                    >
                        {monthFormatter.format(new Date(event.startsAt))}
                    </div>
                    <div class="py-1.5 text-2xl leading-none font-bold">
                        {dayFormatter.format(new Date(event.startsAt))}
                    </div>
                </div>
                <div class="min-w-0">
                    <h1 class="text-3xl font-bold tracking-tight break-words">
                        {event.title}
                    </h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        {t('Created by :name', {
                            name: event.creatorName || t('Deleted user'),
                        })}
                    </p>
                </div>
            </div>
        </header>

        <section class="space-y-5 border-t px-5 py-6 sm:px-8">
            <div class="grid gap-2 sm:grid-cols-[5rem_1fr]">
                <h2 class="font-semibold">{t('When')}</h2>
                <p>{formatWhen()}</p>
            </div>
            <div class="grid gap-2 sm:grid-cols-[5rem_1fr]">
                <h2 class="font-semibold">{t('With')}</h2>
                <p>{event.creatorName || t('Deleted user')}</p>
            </div>
            {#if event.details}
                <div class="grid gap-2 sm:grid-cols-[5rem_1fr]">
                    <h2 class="font-semibold">{t('Notes')}</h2>
                    <RichTextContent content={event.details} />
                </div>
            {/if}

            <div class="flex flex-wrap items-center gap-2 sm:pl-[6rem]">
                {#if event.meetingUrl}
                    <Button variant="outline" size="sm" asChild>
                        {#snippet children(buttonProps)}
                            <a
                                {...buttonProps}
                                href={event.meetingUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                class={buttonProps.class}
                            >
                                <Video aria-hidden="true" />
                                {t('Join meeting')}
                            </a>
                        {/snippet}
                    </Button>
                {/if}

                <ReactionPicker
                    action={ScheduleEventController.toggleReaction.url(
                        routeArguments,
                    )}
                    reactions={event.reactions}
                />
            </div>
        </section>

        <section class="border-t px-5 py-6 sm:px-8" aria-label={t('Comments')}>
            <h2 class="text-base font-semibold">
                {t('Comments')} ({event.comments.length})
            </h2>

            {#if event.comments.length === 0}
                <p class="mt-3 text-sm text-muted-foreground">
                    {t('No comments yet')}
                </p>
            {:else}
                <div class="mt-4 space-y-4">
                    {#each event.comments as comment (comment.id)}
                        <div class="rounded-lg border bg-background p-4">
                            <RichTextContent content={comment.body} />
                            <p class="mt-2 text-xs text-muted-foreground">
                                {comment.authorName || t('Deleted user')}
                                {#if comment.createdAt}
                                    · {commentDateFormatter.format(
                                        new Date(comment.createdAt),
                                    )}
                                {/if}
                            </p>
                        </div>
                    {/each}
                </div>
            {/if}

            <div class="mt-5">
                {#if commentComposerOpen}
                    <Form
                        {...CommentController.storeScheduleEvent.form(
                            routeArguments,
                        )}
                        id="schedule-event-comment-form"
                        resetOnSuccess
                        onSuccess={() => (commentComposerOpen = false)}
                        class="space-y-2"
                    >
                        {#snippet children({ errors, processing })}
                            <Label for="schedule-event-comment"
                                >{t('Comment')}</Label
                            >
                            <RichTextEditor
                                id="schedule-event-comment"
                                name="body"
                                required
                                ariaLabel={t('Comment')}
                                mentionables={members}
                                attachmentUploadUrl={AttachmentController.uploadInline.url(
                                    [currentTeam.slug, project.id],
                                )}
                                onSubmitShortcut={() =>
                                    !processing &&
                                    submitFormById(
                                        'schedule-event-comment-form',
                                    )}
                            />
                            <InputError message={errors.body} />
                            <div class="flex gap-2">
                                <Button type="submit" disabled={processing}
                                    >{t('Add comment')}</Button
                                >
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={processing}
                                    onclick={() =>
                                        (commentComposerOpen = false)}
                                    >{t('Cancel')}</Button
                                >
                            </div>
                        {/snippet}
                    </Form>
                {:else}
                    <button
                        type="button"
                        aria-expanded="false"
                        aria-controls="schedule-event-comment-form"
                        onclick={() => (commentComposerOpen = true)}
                        class="flex min-h-12 w-full items-center rounded-lg px-4 text-left font-medium text-muted-foreground outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        {t('Comment on this')}
                    </button>
                {/if}
            </div>
        </section>
    </article>
</main>
