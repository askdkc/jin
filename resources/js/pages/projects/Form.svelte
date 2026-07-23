<script module lang="ts">
    import { edit, index } from '@/routes/projects';
    import type { Project, Team } from '@/types';

    export const layout = (props: {
        currentTeam: Team;
        project: Project | null;
    }) => ({
        breadcrumbs: [
            {
                title: 'Projects',
                href: index(props.currentTeam.slug),
            },
            {
                title: props.project ? 'Edit project' : 'New project',
                href: props.project
                    ? edit([props.currentTeam.slug, props.project.id])
                    : '#',
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
    import { store, update } from '@/routes/projects';
    import type {
        ProjectFolder,
        ProjectMember,
        ProjectToolOption,
    } from '@/types';

    let {
        currentTeam,
        project,
        teamMembers,
        memberIds,
        projectFolders,
        toolOptions,
        enabledTools,
    }: {
        currentTeam: Team;
        project: Project | null;
        teamMembers: ProjectMember[];
        memberIds: number[];
        projectFolders: ProjectFolder[];
        toolOptions: ProjectToolOption[];
        enabledTools: string[];
    } = $props();

    const title = $derived(project ? t('Edit project') : t('New project'));
    let allowsAllClients = $derived(project?.allowsAllClients ?? false);
    const formRoute = $derived(
        project
            ? update.form([currentTeam.slug, project.id])
            : store.form(currentTeam.slug),
    );
</script>

<AppHead {title} />

<div class="mx-auto w-full max-w-3xl space-y-6 p-4 sm:p-6">
    <Heading
        {title}
        description={t('Set the project details and choose its participants')}
    />

    <Form {...formRoute} class="space-y-8">
        {#snippet children({ errors, processing })}
            <section class="space-y-5 rounded-xl border p-4 sm:p-6">
                <div class="grid gap-2">
                    <Label for="name">{t('Project name')}</Label>
                    <Input
                        id="name"
                        name="name"
                        value={project?.name ?? ''}
                        maxlength={255}
                        required
                        autofocus
                    />
                    <InputError message={errors.name} />
                </div>

                <div class="grid gap-2">
                    <Label for="description">{t('Description')}</Label>
                    <textarea
                        id="description"
                        name="description"
                        rows="5"
                        maxlength="5000"
                        class="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 aria-invalid:border-destructive flex min-h-28 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                        >{project?.description ?? ''}</textarea
                    >
                    <InputError message={errors.description} />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="starts_on">{t('Start date')}</Label>
                        <Input
                            id="starts_on"
                            type="date"
                            name="starts_on"
                            value={project?.startsOn ?? ''}
                        />
                        <InputError message={errors.starts_on} />
                    </div>

                    <div class="grid gap-2">
                        <Label for="ends_on">{t('End date')}</Label>
                        <Input
                            id="ends_on"
                            type="date"
                            name="ends_on"
                            value={project?.endsOn ?? ''}
                        />
                        <InputError message={errors.ends_on} />
                    </div>
                </div>

                <div
                    class={project ? 'grid gap-4 sm:grid-cols-2' : 'grid gap-4'}
                >
                    {#if project}
                        <div class="grid gap-2">
                            <Label for="project_folder_id">{t('Folder')}</Label>
                            <select
                                id="project_folder_id"
                                name="project_folder_id"
                                class="border-input focus-visible:border-ring focus-visible:ring-ring/50 h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                            >
                                <option value="">{t('No folder')}</option>
                                {#each projectFolders as folder (folder.id)}
                                    <option
                                        value={folder.id}
                                        selected={project.folder?.id ===
                                            folder.id}>{folder.name}</option
                                    >
                                {/each}
                            </select>
                            <InputError message={errors.project_folder_id} />
                        </div>
                    {/if}

                    <div class="grid gap-2">
                        <Label for="lead_id">{t('Project lead')}</Label>
                        <select
                            id="lead_id"
                            name="lead_id"
                            class="border-input focus-visible:border-ring focus-visible:ring-ring/50 h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                        >
                            <option value="">{t('Not set')}</option>
                            {#each teamMembers as member (member.id)}
                                <option
                                    value={member.id}
                                    selected={project?.lead?.id === member.id}
                                    >{member.name}</option
                                >
                            {/each}
                        </select>
                        <InputError message={errors.lead_id} />
                    </div>

                    {#if project}
                        <div class="grid gap-2">
                            <Label for="phase">{t('Phase')}</Label>
                            <Input
                                id="phase"
                                name="phase"
                                value={project.phase ?? ''}
                                maxlength={100}
                                placeholder={t('e.g. Design, Review, Launch')}
                            />
                            <InputError message={errors.phase} />
                        </div>

                        <div class="grid gap-2">
                            <Label for="status">{t('Status')}</Label>
                            <select
                                id="status"
                                name="status"
                                class="border-input focus-visible:border-ring focus-visible:ring-ring/50 h-9 w-full rounded-md border bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                            >
                                <option
                                    value="on_track"
                                    selected={project.status === 'on_track'}
                                    >{t('On track')}</option
                                >
                                <option
                                    value="at_risk"
                                    selected={project.status === 'at_risk'}
                                    >{t('At risk')}</option
                                >
                                <option
                                    value="off_track"
                                    selected={project.status === 'off_track'}
                                    >{t('Off track')}</option
                                >
                                <option
                                    value="complete"
                                    selected={project.status === 'complete'}
                                    >{t('Complete')}</option
                                >
                            </select>
                            <InputError message={errors.status} />
                        </div>
                    {/if}
                </div>
            </section>

            <section class="space-y-4 rounded-xl border p-4 sm:p-6">
                <input type="hidden" name="enabled_tools_present" value="1" />
                <div>
                    <h2 class="font-medium">{t('Project tools')}</h2>
                    <p class="text-sm text-muted-foreground">
                        {t('Choose the tools this project will use.')}
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    {#each toolOptions as tool (tool.id)}
                        <label
                            class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 hover:bg-muted/50"
                        >
                            <input
                                type="checkbox"
                                name="enabled_tools[]"
                                value={tool.id}
                                checked={enabledTools.includes(tool.id)}
                                class="size-4 rounded border-input accent-primary"
                            />
                            <span class="text-sm font-medium">{tool.label}</span
                            >
                        </label>
                    {/each}
                </div>
                <InputError message={errors.enabled_tools} />
            </section>

            <section class="space-y-4 rounded-xl border p-4 sm:p-6">
                <div>
                    <h2 class="font-medium">{t('Client access')}</h2>
                    <p class="text-sm text-muted-foreground">
                        {t(
                            'Individual clients can also be selected as project members below.',
                        )}
                    </p>
                </div>

                <input
                    type="hidden"
                    name="allows_all_clients"
                    value={allowsAllClients ? '1' : '0'}
                />
                <label
                    class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 hover:bg-muted/50"
                >
                    <input
                        type="checkbox"
                        bind:checked={allowsAllClients}
                        class="mt-1 size-4 rounded border-input accent-primary"
                    />
                    <span>
                        <span class="block text-sm font-medium">
                            {t('Allow every client in this team')}
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            {t(
                                'Clients can access all content and participate in this project.',
                            )}
                        </span>
                    </span>
                </label>
                <InputError message={errors.allows_all_clients} />
            </section>

            <section class="space-y-4 rounded-xl border p-4 sm:p-6">
                <div>
                    <h2 class="font-medium">{t('Project members')}</h2>
                    <p class="text-sm text-muted-foreground">
                        {t('Members can view and participate in this project.')}
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    {#each teamMembers as member (member.id)}
                        <label
                            class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 hover:bg-muted/50"
                        >
                            <input
                                type="checkbox"
                                name="member_ids[]"
                                value={member.id}
                                checked={memberIds.includes(member.id)}
                                class="mt-1 size-4 rounded border-input accent-primary"
                            />
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium"
                                    >{member.name}</span
                                >
                                <span
                                    class="block truncate text-xs text-muted-foreground"
                                >
                                    {member.email}
                                    {#if member.role === 'client'}
                                        · {t('Client')}
                                    {/if}
                                </span>
                            </span>
                        </label>
                    {/each}
                </div>

                <InputError message={errors.member_ids} />
            </section>

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <Button variant="outline" asChild class="w-full sm:w-auto">
                    {#snippet children(buttonProps)}
                        <Link
                            {...buttonProps}
                            href={index(currentTeam.slug)}
                            class={buttonProps.class}
                        >
                            {t('Cancel')}
                        </Link>
                    {/snippet}
                </Button>
                <Button
                    type="submit"
                    disabled={processing}
                    class="w-full sm:w-auto"
                >
                    {project ? t('Save project') : t('Create project')}
                </Button>
            </div>
        {/snippet}
    </Form>
</div>
