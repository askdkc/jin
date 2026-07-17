<script module lang="ts">
    import AdminlandController from '@/actions/App/Http/Controllers/AdminlandController';
    import type { Team } from '@/types';

    export const layout = (props: { currentTeam: Team }) => ({
        breadcrumbs: [
            {
                title: props.currentTeam.menuNames.adminland,
                href: AdminlandController(props.currentTeam.slug),
            },
        ],
    });
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import FolderKanban from 'lucide-svelte/icons/folder-kanban';
    import History from 'lucide-svelte/icons/history';
    import Pencil from 'lucide-svelte/icons/pencil';
    import ShieldCheck from 'lucide-svelte/icons/shield-check';
    import Users from 'lucide-svelte/icons/users';
    import AppHead from '@/components/AppHead.svelte';
    import {
        Avatar,
        AvatarFallback,
        AvatarImage,
    } from '@/components/ui/avatar';
    import { Badge } from '@/components/ui/badge';
    import { t } from '@/lib/i18n';
    import { getInitials } from '@/lib/initials';
    import { index as projectIndex } from '@/routes/projects';
    import { edit as editTeam } from '@/routes/teams';
    import type { TeamMember } from '@/types';

    let {
        currentTeam,
        administrators,
        permissions,
    }: {
        currentTeam: Team;
        administrators: TeamMember[];
        permissions: {
            canUpdateMember: boolean;
        };
    } = $props();

    const owners = $derived(
        administrators.filter(
            (administrator) => administrator.role === 'owner',
        ),
    );
    const administratorActions = $derived([
        {
            title: t('Manage people'),
            description: t('Invite people and manage team members'),
            href: editTeam(currentTeam.slug),
            icon: Users,
        },
        ...(permissions.canUpdateMember
            ? [
                  {
                      title: t('Add/remove administrators'),
                      description: t('Change member roles'),
                      href: editTeam(currentTeam.slug),
                      icon: ShieldCheck,
                  },
              ]
            : []),
        {
            title: t('Manage projects'),
            description: t('Create, edit, and archive projects'),
            href: projectIndex(currentTeam.slug),
            icon: FolderKanban,
        },
        {
            title: 'メニュー名を変更',
            description: '本陣、任務、行程などの表示名を設定',
            href: `${editTeam.url(currentTeam.slug)}#menu-names`,
            icon: Pencil,
        },
    ]);
    const ownerActions = $derived([
        {
            title: t('Rename this account'),
            description: t('Change the team name'),
            href: editTeam(currentTeam.slug),
            icon: Pencil,
        },
        {
            title: t('Review audit history'),
            description: t('Review invitations and project access changes'),
            href: editTeam(currentTeam.slug),
            icon: History,
        },
    ]);
</script>

<AppHead title={currentTeam.menuNames.adminland} />

<div
    class="min-h-[calc(100dvh-4rem)] bg-[#f5f7f3] pb-28 text-slate-800 dark:bg-[#07151d] dark:text-slate-100"
>
    <main
        class="mx-auto min-h-[calc(100dvh-4rem)] w-full max-w-5xl bg-white/80 px-5 py-10 shadow-sm sm:px-10 lg:px-14 dark:bg-[#14242c]"
    >
        <header class="mb-10">
            <h1 class="text-4xl font-black tracking-tight sm:text-5xl">
                {currentTeam.menuNames.adminland}
            </h1>
            <p class="mt-2 text-lg text-muted-foreground">
                {t('Manage your Jin account')}
            </p>
        </header>

        <section aria-labelledby="administrators-heading">
            <div class="flex items-center gap-4">
                <h2
                    id="administrators-heading"
                    class="shrink-0 text-2xl font-black"
                >
                    {t('Administrators')}
                </h2>
                <div class="h-px flex-1 bg-border"></div>
            </div>

            <div class="mt-5 flex flex-wrap gap-4">
                {#each administrators as administrator (administrator.id)}
                    <div class="flex items-center gap-3">
                        <Avatar class="size-11">
                            {#if administrator.avatar}
                                <AvatarImage
                                    src={administrator.avatar}
                                    alt={administrator.name}
                                />
                            {/if}
                            <AvatarFallback class="font-bold">
                                {getInitials(administrator.name)}
                            </AvatarFallback>
                        </Avatar>
                        <div>
                            <p class="font-semibold">{administrator.name}</p>
                            <Badge variant="secondary" class="mt-1">
                                {t(administrator.role_label)}
                            </Badge>
                        </div>
                    </div>
                {/each}
            </div>

            <h3 class="mt-9 text-lg font-bold">
                {t("You're an administrator, so you can...")}
            </h3>
            <nav class="mt-3 grid gap-1" aria-label={t('Administrators')}>
                {#each administratorActions as action (action.title)}
                    <Link
                        href={action.href}
                        class="group flex items-center gap-3 rounded-xl px-2 py-2 transition hover:bg-blue-500/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                    >
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-full bg-blue-500/20 text-blue-500"
                        >
                            <action.icon class="size-5" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-lg font-medium">
                                {action.title}
                            </span>
                            <span
                                class="block text-sm text-muted-foreground group-hover:text-foreground/70"
                            >
                                {action.description}
                            </span>
                        </span>
                    </Link>
                {/each}
            </nav>
        </section>

        {#if owners.length > 0 && permissions.canUpdateMember}
            <section class="mt-12" aria-labelledby="owners-heading">
                <div class="flex items-center gap-4">
                    <h2
                        id="owners-heading"
                        class="shrink-0 text-2xl font-black"
                    >
                        {t('Account owners')}
                    </h2>
                    <div class="h-px flex-1 bg-border"></div>
                </div>

                <div class="mt-5 flex flex-wrap gap-4">
                    {#each owners as owner (owner.id)}
                        <div class="flex items-center gap-3">
                            <Avatar class="size-11">
                                {#if owner.avatar}
                                    <AvatarImage
                                        src={owner.avatar}
                                        alt={owner.name}
                                    />
                                {/if}
                                <AvatarFallback class="font-bold">
                                    {getInitials(owner.name)}
                                </AvatarFallback>
                            </Avatar>
                            <p class="font-semibold">{owner.name}</p>
                        </div>
                    {/each}
                </div>

                <h3 class="mt-9 text-lg font-bold">
                    {t("You're an account owner, so you can...")}
                </h3>
                <nav class="mt-3 grid gap-1" aria-label={t('Account owners')}>
                    {#each ownerActions as action (action.title)}
                        <Link
                            href={action.href}
                            class="group flex items-center gap-3 rounded-xl px-2 py-2 transition hover:bg-amber-500/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500"
                        >
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-full bg-amber-500/20 text-amber-500"
                            >
                                <action.icon class="size-5" />
                            </span>
                            <span class="min-w-0">
                                <span class="block text-lg font-medium">
                                    {action.title}
                                </span>
                                <span
                                    class="block text-sm text-muted-foreground group-hover:text-foreground/70"
                                >
                                    {action.description}
                                </span>
                            </span>
                        </Link>
                    {/each}
                </nav>
            </section>
        {/if}
    </main>
</div>
