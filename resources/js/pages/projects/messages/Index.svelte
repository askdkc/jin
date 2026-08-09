<script module lang="ts">
    import MessageBoardController from '@/actions/App/Http/Controllers/MessageBoardController';
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
                title: 'Message board',
                href: MessageBoardController.index([
                    props.currentTeam.slug,
                    props.project.id,
                ]),
            },
        ],
    });
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Avatar, AvatarFallback } from '@/components/ui/avatar';
    import { Button } from '@/components/ui/button';
    import { Card, CardContent } from '@/components/ui/card';
    import { currentLocale, t } from '@/lib/i18n';
    import { getInitials } from '@/lib/initials';
    import type { MessageBoardPostSummary, Paginated } from '@/types';

    let {
        currentTeam,
        project,
        posts,
    }: {
        currentTeam: Team;
        project: ProjectTool;
        posts: Paginated<MessageBoardPostSummary>;
    } = $props();

    const routeArguments = $derived([currentTeam.slug, project.id] as [
        string,
        number,
    ]);
    const formatDate = (value: string | null): string =>
        value
            ? new Intl.DateTimeFormat(currentLocale(), {
                  dateStyle: 'medium',
                  timeStyle: 'short',
              }).format(new Date(value))
            : '';
    const paginationLabel = (label: string): string => {
        if (label.includes('Previous')) {
            return t('Previous');
        }

        if (label.includes('Next')) {
            return t('Next');
        }

        return label;
    };
</script>

<AppHead title={t('Message board')} />

<div class="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">
            {t('Message board')}
        </h1>
        <p class="text-sm text-muted-foreground">{project.name}</p>
    </div>

    <Button asChild>
        {#snippet children(buttonProps)}
            <Link
                {...buttonProps}
                href={MessageBoardController.create(routeArguments)}
                class={buttonProps.class}
            >
                + {t('New message')}
            </Link>
        {/snippet}
    </Button>

    {#if posts.data.length === 0}
        <Card
            ><CardContent
                class="py-10 text-center text-sm text-muted-foreground"
                >{t('No messages yet')}</CardContent
            ></Card
        >
    {:else}
        <div class="divide-y rounded-lg border bg-card">
            {#each posts.data as post (post.id)}
                <Link
                    href={MessageBoardController.show([
                        currentTeam.slug,
                        project.id,
                        post.id,
                    ])}
                    class="flex items-start gap-3 p-4 hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <Avatar class="size-9 shrink-0">
                        <AvatarFallback
                            class="bg-primary text-xs font-bold text-primary-foreground"
                        >
                            {getInitials(post.authorName || '?')}
                        </AvatarFallback>
                    </Avatar>
                    <div class="min-w-0 flex-1">
                        <p
                            class="flex items-center gap-2 font-semibold break-words"
                        >
                            {post.title}
                            {#if post.pinned}
                                <span class="text-xs font-medium text-primary"
                                    >{t('Pinned')}</span
                                >
                            {/if}
                        </p>
                        <p class="truncate text-sm text-muted-foreground">
                            {post.authorName || t('Deleted user')} · {formatDate(
                                post.publishedAt,
                            )} · {post.snippet}
                        </p>
                    </div>
                    {#if post.commentsCount > 0}
                        <span
                            class="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                        >
                            {post.commentsCount}
                        </span>
                    {/if}
                </Link>
            {/each}
        </div>

        {#if posts.links.length > 3}
            <nav
                class="flex flex-wrap justify-center gap-2"
                aria-label={t('Pagination')}
            >
                {#each posts.links as link, index (index)}
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
                                >
                                    {paginationLabel(link.label)}
                                </Link>
                            {/snippet}
                        </Button>
                    {:else}
                        <Button variant="outline" size="sm" disabled
                            >{paginationLabel(link.label)}</Button
                        >
                    {/if}
                {/each}
            </nav>
        {/if}
    {/if}
</div>
