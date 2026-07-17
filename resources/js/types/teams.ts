export type TeamRole = 'owner' | 'admin' | 'member' | 'client';

export type MenuNames = {
    projectHome: string;
    todos: string;
    cardTable: string;
    checkIns: string;
    calendar: string;
    lineup: string;
    chat: string;
    reports: string;
    timesheets: string;
    adminland: string;
};

export type MenuNameKey = keyof MenuNames;

export type Team = {
    id: number;
    name: string;
    slug: string;
    isPersonal: boolean;
    role?: TeamRole;
    roleLabel?: string;
    isCurrent?: boolean;
    menuNames: MenuNames;
};

export type TeamMember = {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    role: TeamRole;
    role_label: string;
};

export type TeamInvitation = {
    code: string;
    email: string;
    role: TeamRole;
    role_label: string;
    created_at: string;
};

export type TeamInvitationContext = {
    code: string;
    teamName: string;
};

export type TeamAuditLog = {
    id: number;
    event: string;
    label: string;
    actorName: string | null;
    userName: string | null;
    projectName: string | null;
    email: string | null;
    role: TeamRole | null;
    expiresAt: string | null;
    createdAt: string | null;
};

export type DashboardInvitation = {
    code: string;
    inviterName: string;
    team: {
        name: string;
        slug: string;
    };
};

export type TeamPermissions = {
    canUpdateTeam: boolean;
    canDeleteTeam: boolean;
    canAddMember: boolean;
    canUpdateMember: boolean;
    canRemoveMember: boolean;
    canCreateInvitation: boolean;
    canCancelInvitation: boolean;
};

export type RoleOption = {
    value: TeamRole;
    label: string;
};
