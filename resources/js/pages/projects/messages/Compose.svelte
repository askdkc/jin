<script module lang="ts">
    import MessageBoardController from '@/actions/App/Http/Controllers/MessageBoardController';
    import { show } from '@/routes/projects';
    import type { ProjectTool, Team } from '@/types';

    export const layout = (props: {
        currentTeam: Team;
        project: ProjectTool;
        post: { id: number } | null;
    }) => ({
        breadcrumbs: [
            {
                title: props.project.name,
                href: show([props.currentTeam.slug, props.project.id]),
            },
            {
                translationKey: 'Message board',
                href: MessageBoardController.index([
                    props.currentTeam.slug,
                    props.project.id,
                ]),
            },
            {
                translationKey: props.post ? 'Edit message' : 'New message',
                href: '#',
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AttachmentController from '@/actions/App/Http/Controllers/AttachmentController';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import RichTextEditor from '@/components/RichTextEditor.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
    import type { TodoMember } from '@/types';

    let {
        currentTeam,
        project,
        post,
        members,
    }: {
        currentTeam: Team;
        project: ProjectTool;
        post: { id: number; title: string; body: string } | null;
        members: TodoMember[];
    } = $props();

    const routeArguments = $derived([currentTeam.slug, project.id] as [
        string,
        number,
    ]);
    const formOptions = $derived(
        post
            ? MessageBoardController.update.form([
                  currentTeam.slug,
                  project.id,
                  post.id,
              ])
            : MessageBoardController.store.form(routeArguments),
    );
</script>

<AppHead title={post ? t('Edit message') : t('New message')} />

<div class="mx-auto w-full max-w-3xl p-4 sm:p-6">
    <Form {...formOptions} class="space-y-4">
        {#snippet children({ errors, processing })}
            <div class="flex items-center justify-between gap-2">
                <h1 class="text-lg font-semibold tracking-tight">
                    {post ? t('Edit message') : t('New message')}
                </h1>
                <div class="flex gap-2">
                    <Button variant="secondary" asChild>
                        {#snippet children(buttonProps)}
                            <Link
                                {...buttonProps}
                                href={post
                                    ? MessageBoardController.show([
                                          currentTeam.slug,
                                          project.id,
                                          post.id,
                                      ])
                                    : MessageBoardController.index(
                                          routeArguments,
                                      )}
                                class={buttonProps.class}
                            >
                                {t('Cancel')}
                            </Link>
                        {/snippet}
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {post ? t('Save changes') : t('Post this message')}
                    </Button>
                </div>
            </div>

            <div class="space-y-4 rounded-lg border bg-card p-4">
                <div>
                    <Label for="message-title" class="sr-only"
                        >{t('Title')}</Label
                    >
                    <Input
                        id="message-title"
                        name="title"
                        value={post?.title}
                        maxlength={255}
                        placeholder={t('Type a title…')}
                        class="h-auto border-none px-0 py-2 text-3xl font-extrabold shadow-none focus-visible:ring-0"
                        required
                    />
                    <InputError message={errors.title} />
                </div>

                <div>
                    <Label for="message-body" class="sr-only">{t('Body')}</Label
                    >
                    <RichTextEditor
                        id="message-body"
                        name="body"
                        value={post?.body}
                        required
                        ariaLabel={t('Body')}
                        mentionables={members}
                        attachmentUploadUrl={AttachmentController.uploadInline.url(
                            routeArguments,
                        )}
                    />
                    <InputError message={errors.body} />
                </div>
            </div>
        {/snippet}
    </Form>
</div>
