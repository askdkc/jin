<script module lang="ts">
    import { edit, index } from '@/routes/teams';
    import type { Team } from '@/types';

    export const layout = (props: { team: Team }) => ({
        breadcrumbs: [
            {
                title: 'Teams',
                href: index(),
            },
            {
                title: props.team.name,
                href: edit(props.team.slug),
            },
        ],
    });
</script>

<script lang="ts">
    import { Form, Link, router } from '@inertiajs/svelte';
    import ChevronDown from 'lucide-svelte/icons/chevron-down';
    import Mail from 'lucide-svelte/icons/mail';
    import UserPlus from 'lucide-svelte/icons/user-plus';
    import X from 'lucide-svelte/icons/x';
    import AppHead from '@/components/AppHead.svelte';
    import CancelInvitationModal from '@/components/CancelInvitationModal.svelte';
    import DeleteTeamModal from '@/components/DeleteTeamModal.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import InviteMemberModal from '@/components/InviteMemberModal.svelte';
    import RemoveMemberModal from '@/components/RemoveMemberModal.svelte';
    import {
        Avatar,
        AvatarFallback,
        AvatarImage,
    } from '@/components/ui/avatar';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import {
        DropdownMenu,
        DropdownMenuContent,
        DropdownMenuItem,
        DropdownMenuTrigger,
    } from '@/components/ui/dropdown-menu';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import {
        Tooltip,
        TooltipContent,
        TooltipProvider,
        TooltipTrigger,
    } from '@/components/ui/tooltip';
    import { t } from '@/lib/i18n';
    import { getInitials } from '@/lib/initials';
    import { update } from '@/routes/teams';
    import { update as updateMember } from '@/routes/teams/members';
    import type {
        Paginated,
        RoleOption,
        TeamAuditLog,
        TeamInvitation,
        MenuNameKey,
        TeamMember,
        TeamPermissions,
    } from '@/types';

    let {
        team,
        members,
        invitations,
        auditLogs,
        permissions,
        availableRoles,
    }: {
        team: Team;
        members: TeamMember[];
        invitations: TeamInvitation[];
        auditLogs: Paginated<TeamAuditLog>;
        permissions: TeamPermissions;
        availableRoles: RoleOption[];
    } = $props();

    let inviteDialogOpen = $state(false);
    let deleteDialogOpen = $state(false);
    let removeMemberDialogOpen = $state(false);
    let memberToRemove = $state<TeamMember | null>(null);
    let cancelInvitationDialogOpen = $state(false);
    let invitationToCancel = $state<TeamInvitation | null>(null);

    const menuNameFields: Array<{
        key: MenuNameKey;
        currentName: string;
        description: string;
    }> = [
        {
            key: 'projectHome',
            currentName: 'プロジェクトホーム',
            description: 'プロジェクト全体の拠点・ダッシュボード',
        },
        {
            key: 'todos',
            currentName: 'ToDo',
            description: 'メンバーごとの作業・タスク管理',
        },
        {
            key: 'cardTable',
            currentName: 'Card Table',
            description: 'カード形式で進捗を管理するボード',
        },
        {
            key: 'checkIns',
            currentName: '振り返り',
            description: 'プロジェクトの振り返り・改善記録',
        },
        {
            key: 'calendar',
            currentName: 'カレンダー',
            description: '予定・期限・イベントを管理',
        },
        {
            key: 'lineup',
            currentName: 'Lineup',
            description: '全プロジェクトのスケジュールや配置を俯瞰',
        },
        {
            key: 'chat',
            currentName: 'リアルタイムの会話',
            description: 'チームメンバーとのリアルタイムチャット',
        },
        {
            key: 'reports',
            currentName: 'レポート',
            description: 'プロジェクトの状況や進捗レポート',
        },
        {
            key: 'timesheets',
            currentName: '工数',
            description: '作業時間・工数の記録と集計',
        },
        {
            key: 'adminland',
            currentName: 'Adminland',
            description: '組織・ユーザー・権限などの管理',
        },
    ];

    const pageTitle = $derived(
        permissions.canUpdateTeam ? `Edit ${team.name}` : `View ${team.name}`,
    );

    const updateMemberRole = (member: TeamMember, newRole: string) => {
        router.visit(updateMember([team.slug, member.id]), {
            data: { role: newRole },
            preserveScroll: true,
        });
    };

    const confirmRemoveMember = (member: TeamMember) => {
        memberToRemove = member;
        removeMemberDialogOpen = true;
    };

    const confirmCancelInvitation = (invitation: TeamInvitation) => {
        invitationToCancel = invitation;
        cancelInvitationDialogOpen = true;
    };

    const callClickHandler = (handler: unknown, event: MouseEvent) => {
        if (typeof handler === 'function') {
            handler(event);
        }
    };

    const dateTimeFormatter = new Intl.DateTimeFormat('ja-JP', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    const formatDateTime = (value: string | null): string =>
        value ? dateTimeFormatter.format(new Date(value)) : '';
    const paginationLabel = (label: string): string =>
        label.includes('Previous')
            ? t('Previous')
            : label.includes('Next')
              ? t('Next')
              : label;
</script>

<AppHead title={pageTitle} />

<h1 class="sr-only">{pageTitle}</h1>

<div class="flex flex-col space-y-10">
    <div class="space-y-6">
        {#if permissions.canUpdateTeam}
            <Heading
                variant="small"
                title={t('Team settings')}
                description={t('Update your team name and settings')}
            />

            <Form {...update.form(team.slug)} class="space-y-6">
                {#snippet children({ errors, processing })}
                    <div class="grid gap-2">
                        <Label for="name">{t('Team name')}</Label>
                        <Input
                            id="name"
                            name="name"
                            value={team.name}
                            required
                            data-test="team-name-input"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <fieldset
                        id="menu-names"
                        class="scroll-mt-24 space-y-4 border-t pt-6"
                    >
                        <legend class="text-lg font-semibold">
                            メニュー名
                        </legend>
                        <p class="text-sm text-muted-foreground">
                            チーム内で表示する各機能の名称を変更できます。
                        </p>
                        <div class="grid gap-4 md:grid-cols-2">
                            {#each menuNameFields as field (field.key)}
                                <div class="grid gap-2 rounded-lg border p-4">
                                    <Label for={`menu-name-${field.key}`}>
                                        {field.currentName}
                                    </Label>
                                    <Input
                                        id={`menu-name-${field.key}`}
                                        name={`menu_names[${field.key}]`}
                                        value={team.menuNames[field.key]}
                                        maxlength={30}
                                        required
                                        data-test={`menu-name-${field.key}`}
                                    />
                                    <p class="text-xs text-muted-foreground">
                                        {field.description}
                                    </p>
                                    <InputError
                                        message={errors[
                                            `menu_names.${field.key}`
                                        ]}
                                    />
                                </div>
                            {/each}
                        </div>
                    </fieldset>

                    <div class="flex items-center gap-4">
                        <Button
                            type="submit"
                            disabled={processing}
                            data-test="team-save-button">{t('Save')}</Button
                        >
                    </div>
                {/snippet}
            </Form>
        {:else}
            <Heading variant="small" title={team.name} />
        {/if}
    </div>

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <Heading
                variant="small"
                title={t('Team members')}
                description={permissions.canCreateInvitation
                    ? t('Manage who belongs to this team')
                    : ''}
            />

            {#if permissions.canCreateInvitation}
                <Button
                    onclick={() => (inviteDialogOpen = true)}
                    data-test="invite-member-button"
                >
                    <UserPlus class="h-4 w-4" />
                    {t('Invite member')}
                </Button>
            {/if}
        </div>

        <div class="space-y-3">
            {#each members as member (member.id)}
                <div
                    class="flex items-center justify-between rounded-lg border p-4"
                    data-test="member-row"
                >
                    <div class="flex items-center gap-4">
                        <Avatar class="h-10 w-10">
                            {#if member.avatar}
                                <AvatarImage
                                    src={member.avatar}
                                    alt={member.name}
                                />
                            {/if}
                            <AvatarFallback
                                >{getInitials(member.name)}</AvatarFallback
                            >
                        </Avatar>

                        <div>
                            <div class="font-medium">{member.name}</div>
                            <div class="text-sm text-muted-foreground">
                                {member.email}
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        {#if member.role !== 'owner' && permissions.canUpdateMember}
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    {#snippet children(props)}
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onclick={props.onclick}
                                            aria-expanded={props[
                                                'aria-expanded'
                                            ]}
                                            data-state={props['data-state']}
                                            data-test="member-role-trigger"
                                        >
                                            {t(member.role_label)}
                                            <ChevronDown
                                                class="ml-2 h-4 w-4 opacity-50"
                                            />
                                        </Button>
                                    {/snippet}
                                </DropdownMenuTrigger>
                                <DropdownMenuContent>
                                    {#each availableRoles as role (role.value)}
                                        <DropdownMenuItem asChild>
                                            {#snippet children(props)}
                                                <button
                                                    type="button"
                                                    class={props.class}
                                                    data-test="member-role-option"
                                                    onclick={() => {
                                                        props.onClick?.();
                                                        updateMemberRole(
                                                            member,
                                                            role.value,
                                                        );
                                                    }}
                                                >
                                                    {t(role.label)}
                                                </button>
                                            {/snippet}
                                        </DropdownMenuItem>
                                    {/each}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        {:else}
                            <Badge variant="secondary"
                                >{t(member.role_label)}</Badge
                            >
                        {/if}

                        {#if member.role !== 'owner' && permissions.canRemoveMember}
                            <TooltipProvider delayDuration={0}>
                                <Tooltip>
                                    <TooltipTrigger>
                                        {#snippet child({ props })}
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                {...props}
                                                data-test="member-remove-button"
                                                onclick={(event) => {
                                                    callClickHandler(
                                                        props.onClick,
                                                        event,
                                                    );
                                                    confirmRemoveMember(member);
                                                }}
                                            >
                                                <X class="h-4 w-4" />
                                            </Button>
                                        {/snippet}
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        <p>{t('Remove member')}</p>
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        {/if}
                    </div>
                </div>
            {/each}
        </div>
    </div>

    {#if invitations.length > 0}
        <div class="space-y-6">
            <Heading
                variant="small"
                title={t('Pending invitations')}
                description={t("Invitations that haven't been accepted yet")}
            />

            <div class="space-y-3">
                {#each invitations as invitation (invitation.code)}
                    <div
                        class="flex items-center justify-between rounded-lg border p-4"
                        data-test="invitation-row"
                    >
                        <div class="flex items-center gap-4">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full bg-muted"
                            >
                                <Mail class="h-5 w-5 text-muted-foreground" />
                            </div>
                            <div>
                                <div class="font-medium">
                                    {invitation.email}
                                </div>
                                <div class="text-sm text-muted-foreground">
                                    {t(invitation.role_label)}
                                </div>
                            </div>
                        </div>

                        {#if permissions.canCancelInvitation}
                            <TooltipProvider delayDuration={0}>
                                <Tooltip>
                                    <TooltipTrigger>
                                        {#snippet child({ props })}
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                {...props}
                                                data-test="invitation-cancel-button"
                                                onclick={(event) => {
                                                    callClickHandler(
                                                        props.onClick,
                                                        event,
                                                    );
                                                    confirmCancelInvitation(
                                                        invitation,
                                                    );
                                                }}
                                            >
                                                <X class="h-4 w-4" />
                                            </Button>
                                        {/snippet}
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        <p>{t('Cancel invitation')}</p>
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        {/if}
                    </div>
                {/each}
            </div>
        </div>
    {/if}

    <div class="space-y-6">
        <Heading
            variant="small"
            title={t('Audit history')}
            description={t('Review invitations and project access changes')}
        />

        {#if auditLogs.data.length > 0}
            <div class="divide-y rounded-lg border" data-test="audit-log-list">
                {#each auditLogs.data as auditLog (auditLog.id)}
                    <div
                        class="flex flex-col gap-2 p-4 sm:flex-row sm:items-start sm:justify-between"
                        data-test="audit-log-row"
                    >
                        <div class="min-w-0 space-y-1">
                            <div class="font-medium">{auditLog.label}</div>
                            <div
                                class="break-words text-sm text-muted-foreground"
                            >
                                {#if auditLog.projectName}
                                    {auditLog.projectName}
                                    {#if auditLog.userName}
                                        · {auditLog.userName}
                                    {/if}
                                {:else if auditLog.email}
                                    {auditLog.email}
                                {:else if auditLog.userName}
                                    {auditLog.userName}
                                {/if}
                            </div>
                            {#if auditLog.expiresAt}
                                <div class="text-xs text-muted-foreground">
                                    {t('Expires :date', {
                                        date: formatDateTime(
                                            auditLog.expiresAt,
                                        ),
                                    })}
                                </div>
                            {/if}
                        </div>

                        <div
                            class="shrink-0 text-xs text-muted-foreground sm:text-right"
                        >
                            {#if auditLog.actorName}
                                <div>{auditLog.actorName}</div>
                            {/if}
                            <time datetime={auditLog.createdAt || ''}>
                                {formatDateTime(auditLog.createdAt)}
                            </time>
                        </div>
                    </div>
                {/each}
            </div>

            {#if auditLogs.links.length > 3}
                <nav
                    class="flex flex-wrap justify-center gap-2"
                    aria-label={t('Pagination')}
                >
                    {#each auditLogs.links as link, index (index)}
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
        {:else}
            <p
                class="text-sm text-muted-foreground"
                data-test="audit-log-empty"
            >
                {t('No audit history yet.')}
            </p>
        {/if}
    </div>

    {#if permissions.canDeleteTeam && !team.isPersonal}
        <div class="space-y-6">
            <Heading
                variant="small"
                title={t('Delete team')}
                description={t('Permanently delete your team')}
            />
            <div
                class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10"
            >
                <div
                    class="relative space-y-0.5 text-red-600 dark:text-red-100"
                >
                    <p class="font-medium">{t('Warning')}</p>
                    <p class="text-sm">
                        {t(
                            'Please proceed with caution, this cannot be undone.',
                        )}
                    </p>
                </div>

                <Button
                    variant="destructive"
                    onclick={() => (deleteDialogOpen = true)}
                    data-test="delete-team-button"
                >
                    {t('Delete team')}
                </Button>
            </div>
        </div>
    {/if}
</div>

{#if permissions.canCreateInvitation}
    <InviteMemberModal {team} {availableRoles} bind:open={inviteDialogOpen} />
{/if}

<RemoveMemberModal
    {team}
    member={memberToRemove}
    bind:open={removeMemberDialogOpen}
/>

<CancelInvitationModal
    {team}
    invitation={invitationToCancel}
    bind:open={cancelInvitationDialogOpen}
/>

{#if permissions.canDeleteTeam && !team.isPersonal}
    <DeleteTeamModal {team} bind:open={deleteDialogOpen} />
{/if}
