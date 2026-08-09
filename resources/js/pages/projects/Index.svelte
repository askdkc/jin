<script module lang="ts">
    import { index } from '@/routes/projects';
    import type { Team } from '@/types';

    export const layout = (props: { currentTeam: Team }) => ({
        breadcrumbs: [
            {
                translationKey: 'Projects',
                href: index(props.currentTeam.slug),
            },
        ],
    });
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import FolderKanban from 'lucide-svelte/icons/folder-kanban';
    import Plus from 'lucide-svelte/icons/plus';
    import Users from 'lucide-svelte/icons/users';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import {
        Card,
        CardContent,
        CardDescription,
        CardHeader,
        CardTitle,
    } from '@/components/ui/card';
    import { t } from '@/lib/i18n';
    import { create, show } from '@/routes/projects';
    import type { ProjectPermissions, ProjectSummary } from '@/types';

    let {
        currentTeam,
        projects,
        permissions,
    }: {
        currentTeam: Team;
        projects: ProjectSummary[];
        permissions: ProjectPermissions;
    } = $props();

    const dateLabel = (project: ProjectSummary): string => {
        if (!project.startsOn && !project.endsOn) {
            return t('No dates set');
        }

        return `${project.startsOn ?? t('Not set')} – ${project.endsOn ?? t('Not set')}`;
    };
</script>

<AppHead title={t('Projects')} />

<div class="space-y-6 p-4 sm:p-6">
    <div
        class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
    >
        <Heading
            title={t('Projects')}
            description={t('Organize work with your team')}
        />

        {#if permissions.canCreateProject}
            <Button asChild class="w-full sm:w-auto">
                {#snippet children(buttonProps)}
                    <Link
                        {...buttonProps}
                        href={create(currentTeam.slug)}
                        class={buttonProps.class}
                    >
                        <Plus class="size-4" />
                        {t('New project')}
                    </Link>
                {/snippet}
            </Button>
        {/if}
    </div>

    {#if projects.length > 0}
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {#each projects as project (project.id)}
                <Card class="h-full transition-colors hover:border-primary/40">
                    <CardHeader>
                        <CardTitle class="text-lg">
                            <div class="flex flex-wrap items-center gap-2">
                                <Link
                                    href={show([currentTeam.slug, project.id])}
                                    class="hover:underline"
                                >
                                    {project.name}
                                </Link>
                                {#if project.allowsAllClients}
                                    <Badge variant="outline">
                                        {t('All clients')}
                                    </Badge>
                                {/if}
                            </div>
                        </CardTitle>
                        <CardDescription class="line-clamp-3 min-h-10">
                            {project.description || t('No description')}
                        </CardDescription>
                    </CardHeader>

                    <CardContent class="mt-auto space-y-2 text-sm">
                        <div
                            class="flex items-center gap-2 text-muted-foreground"
                        >
                            <CalendarDays class="size-4 shrink-0" />
                            <span>{dateLabel(project)}</span>
                        </div>
                        <div
                            class="flex items-center gap-2 text-muted-foreground"
                        >
                            <Users class="size-4 shrink-0" />
                            <span
                                >{t(':count members', {
                                    count: String(project.memberCount),
                                })}</span
                            >
                        </div>
                    </CardContent>
                </Card>
            {/each}
        </div>
    {:else}
        <div
            class="flex min-h-64 flex-col items-center justify-center rounded-xl border border-dashed px-6 py-12 text-center"
        >
            <FolderKanban class="mb-4 size-10 text-muted-foreground" />
            <h2 class="text-lg font-semibold">{t('No projects yet')}</h2>
            <p class="mt-1 max-w-md text-sm text-muted-foreground">
                {t(
                    'Create a project to organize messages, tasks, files, and chat.',
                )}
            </p>
        </div>
    {/if}
</div>
