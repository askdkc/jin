<script module lang="ts">
    import EverythingController from '@/actions/App/Http/Controllers/EverythingController';
    import type { Team } from '@/types';

    export const layout = (props: { currentTeam: Team }) => ({
        breadcrumbs: [
            {
                title: 'Everything',
                href: EverythingController(props.currentTeam.slug),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import FileIcon from 'lucide-svelte/icons/file';
    import FileText from 'lucide-svelte/icons/file-text';
    import LinkIcon from 'lucide-svelte/icons/link';
    import ListTodo from 'lucide-svelte/icons/list-todo';
    import MessageSquare from 'lucide-svelte/icons/message-square';
    import Search from 'lucide-svelte/icons/search';
    import CheckInController from '@/actions/App/Http/Controllers/CheckInController';
    import MessageBoardController from '@/actions/App/Http/Controllers/MessageBoardController';
    import ProjectDocumentController from '@/actions/App/Http/Controllers/ProjectDocumentController';
    import ProjectMaterialController from '@/actions/App/Http/Controllers/ProjectMaterialController';
    import ProjectTodoController from '@/actions/App/Http/Controllers/ProjectTodoController';
    import ScheduleEventController from '@/actions/App/Http/Controllers/ScheduleEventController';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Card, CardContent } from '@/components/ui/card';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
    import type {
        EverythingFilters,
        EverythingItem,
        EverythingTarget,
        EverythingType,
        Paginated,
        ProjectTool,
    } from '@/types';

    let {
        currentTeam,
        results,
        projects,
        filters,
    }: {
        currentTeam: Team;
        results: Paginated<EverythingItem>;
        projects: ProjectTool[];
        filters: EverythingFilters;
    } = $props();

    const typeLabel = (type: EverythingType): string =>
        ({
            message: t('Message'),
            todo: currentTeam.menuNames.todos,
            document: t('Document'),
            file: t('File'),
            link: t('External link'),
            comment: t('Comment'),
        })[type];
    const resultUrl = (
        target: EverythingTarget,
        projectId: number,
    ): ReturnType<typeof MessageBoardController.index> => {
        const args = [currentTeam.slug, projectId] as [string, number];

        return {
            messages: MessageBoardController.index,
            todos: ProjectTodoController.index,
            documents: ProjectDocumentController.index,
            files: ProjectMaterialController.index,
            materials: ProjectMaterialController.index,
            schedule: ScheduleEventController.index,
            check_ins: CheckInController.index,
        }[target](args);
    };
    const formatDate = (value: string): string =>
        new Intl.DateTimeFormat('ja-JP', {
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(new Date(value));
    const paginationLabel = (label: string): string =>
        label.includes('Previous')
            ? t('Previous')
            : label.includes('Next')
              ? t('Next')
              : label;
</script>

<AppHead title={t('Everything')} />

<div class="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight">
            {t('Find everything')}
        </h1>
        <p class="text-sm text-muted-foreground">
            {t(
                'Search messages, to-dos, documents, files, links, and comments',
            )}
        </p>
    </div>

    <Card>
        <CardContent class="pt-6">
            <Form
                {...EverythingController.form(currentTeam.slug)}
                class="grid gap-3 md:grid-cols-[minmax(0,2fr)_1fr_1fr_auto]"
            >
                {#snippet children({ processing })}
                    <div class="grid gap-2">
                        <Label for="everything-query">{t('Search')}</Label>
                        <Input
                            id="everything-query"
                            name="q"
                            type="search"
                            maxlength={100}
                            value={filters.q ?? ''}
                            placeholder={t('Search words')}
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="everything-type">{t('Type')}</Label>
                        <select
                            id="everything-type"
                            name="type"
                            class="border-input h-10 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">{t('All types')}</option>
                            <option
                                value="message"
                                selected={filters.type === 'message'}
                            >
                                {t('Messages')}
                            </option>
                            <option
                                value="todo"
                                selected={filters.type === 'todo'}
                            >
                                {currentTeam.menuNames.todos}
                            </option>
                            <option
                                value="document"
                                selected={filters.type === 'document'}
                            >
                                {t('Documents')}
                            </option>
                            <option
                                value="file"
                                selected={filters.type === 'file'}
                            >
                                {t('Files')}
                            </option>
                            <option
                                value="link"
                                selected={filters.type === 'link'}
                            >
                                {t('External links')}
                            </option>
                            <option
                                value="comment"
                                selected={filters.type === 'comment'}
                            >
                                {t('Comments')}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="everything-project">{t('Project')}</Label>
                        <select
                            id="everything-project"
                            name="project"
                            class="border-input h-10 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">{t('All projects')}</option>
                            {#each projects as project (project.id)}
                                <option
                                    value={project.id}
                                    selected={filters.project === project.id}
                                >
                                    {project.name}
                                </option>
                            {/each}
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <Button type="submit" disabled={processing}>
                            <Search class="size-4" />
                            {t('Search')}
                        </Button>
                        <Button variant="outline" asChild>
                            {#snippet children(buttonProps)}
                                <Link
                                    {...buttonProps}
                                    href={EverythingController(
                                        currentTeam.slug,
                                    )}
                                    class={buttonProps.class}
                                >
                                    {t('Clear')}
                                </Link>
                            {/snippet}
                        </Button>
                    </div>
                {/snippet}
            </Form>
        </CardContent>
    </Card>

    {#if results.data.length === 0}
        <Card>
            <CardContent class="py-12 text-center">
                <Search class="mx-auto mb-3 size-8 text-muted-foreground" />
                <p class="text-sm text-muted-foreground">
                    {t('No matching items')}
                </p>
            </CardContent>
        </Card>
    {:else}
        <div class="space-y-3">
            {#each results.data as item (`${item.type}-${item.id}`)}
                <Card class="min-w-0">
                    <CardContent class="flex min-w-0 items-start gap-3 pt-6">
                        <div
                            class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted"
                        >
                            {#if item.type === 'message' || item.type === 'comment'}
                                <MessageSquare class="size-5" />
                            {:else if item.type === 'todo'}
                                <ListTodo class="size-5" />
                            {:else if item.type === 'file'}
                                <FileIcon class="size-5" />
                            {:else if item.type === 'link'}
                                <LinkIcon class="size-5" />
                            {:else}
                                <FileText class="size-5" />
                            {/if}
                        </div>
                        <div class="min-w-0 flex-1">
                            <Link
                                href={resultUrl(item.target, item.project.id)}
                                class="break-words font-medium hover:underline"
                            >
                                {item.title}
                            </Link>
                            {#if item.snippet}
                                <p
                                    class="mt-1 line-clamp-3 break-words whitespace-pre-wrap text-sm text-muted-foreground"
                                >
                                    {item.snippet}
                                </p>
                            {/if}
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <Badge variant="secondary">
                                    {typeLabel(item.type)}
                                </Badge>
                                <Badge
                                    variant="outline"
                                    class="max-w-full truncate"
                                >
                                    {item.project.name}
                                </Badge>
                                <span class="text-xs text-muted-foreground">
                                    {formatDate(item.updatedAt)}
                                </span>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            {/each}
        </div>

        {#if results.links.length > 3}
            <nav
                class="flex flex-wrap justify-center gap-2"
                aria-label={t('Pagination')}
            >
                {#each results.links as link, index (index)}
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
                        <Button variant="outline" size="sm" disabled>
                            {paginationLabel(link.label)}
                        </Button>
                    {/if}
                {/each}
            </nav>
        {/if}
    {/if}
</div>
