import type { TeamRole } from './teams';

export type ProjectMember = {
    id: number;
    name: string;
    email: string;
    role?: TeamRole;
    roleLabel?: string;
};

export type ProjectSummary = {
    id: number;
    name: string;
    description: string | null;
    allowsAllClients: boolean;
    startsOn: string | null;
    endsOn: string | null;
    memberCount: number;
    creatorName: string | null;
    folder: ProjectFolder | null;
    lead: ProjectTool | null;
    phase: string | null;
    status: 'on_track' | 'at_risk' | 'off_track' | 'complete';
    enabledTools: string[];
    isFavorite: boolean;
};

export type ProjectFolder = {
    id: number;
    name: string;
};

export type ProjectToolOption = {
    id: string;
    label: string;
};

export type Project = ProjectSummary & {
    members: ProjectMember[];
};

export type ProjectMaterialPreviewEntry =
    | {
          id: number;
          type: 'folder';
          title: string;
          itemCount: number;
      }
    | {
          id: number;
          type: 'document';
          title: string;
          updaterName: string | null;
          updatedAt: string | null;
      }
    | {
          id: number;
          type: 'file';
          title: string;
          updaterName: string | null;
          updatedAt: string | null;
          mimeType: string | null;
          size: number;
      }
    | {
          id: number;
          type: 'link';
          title: string;
          url: string;
          updaterName: string | null;
          updatedAt: string | null;
      };

export type ProjectHome = {
    messages: {
        count: number;
        latest: {
            id: number;
            title: string;
            body: string;
            authorName: string | null;
            publishedAt: string;
        }[];
    };
    todos: {
        total: number;
        completed: number;
        overdue: number;
        latest: {
            id: number;
            title: string;
            listTitle: string;
            dueOn: string | null;
        }[];
    };
    cardTables: {
        count: number;
        preview: {
            name: string;
            columns: {
                id: number;
                name: string;
                cardCount: number;
            }[];
        } | null;
    };
    checkIns: {
        count: number;
        pending: number;
    };
    schedule: {
        upcoming: {
            id: number;
            title: string;
            startsAt: string;
            allDay: boolean;
        }[];
    };
    materials: {
        entries: ProjectMaterialPreviewEntry[];
    };
    chat: {
        recent: {
            id: number;
            body: string;
            authorName: string | null;
            createdAt: string | null;
        }[];
        latest: {
            id: number;
            body: string;
            authorName: string | null;
            createdAt: string | null;
        } | null;
    };
    activity: {
        latest: ActivityItem[];
        activeLastSevenDays: number;
    };
    timesheets: {
        totalMinutes: number;
        latest: {
            id: number;
            workedOn: string;
            minutes: number;
            userName: string;
        }[];
    };
};

export type ActivityType =
    | 'project.created'
    | 'message.created'
    | 'comment.created'
    | 'todo.created'
    | 'todo.comment.created'
    | 'todo.completed'
    | 'schedule.created'
    | 'document.created'
    | 'file.uploaded'
    | 'card.created'
    | 'check-in.created'
    | 'check-in.answered'
    | 'external-link.created';

export type ActivityTarget =
    | 'project'
    | 'messages'
    | 'todos'
    | 'schedule'
    | 'documents'
    | 'files'
    | 'card-tables'
    | 'check-ins'
    | 'materials';

export type WorkspaceCalendarEvent = {
    id: number;
    title: string;
    startsAt: string;
    endsAt: string;
    allDay: boolean;
    project: ProjectTool;
};

export type WorkspaceGanttProject = ProjectTool & {
    startsOn: string;
    endsOn: string;
};

export type CalendarSubscription = {
    id: number;
    name: string;
    url: string | null;
    revoked: boolean;
    createdAt: string | null;
};

export type MaterialItem = {
    id: number;
    type: 'document' | 'file' | 'link';
    title: string;
    url: string | null;
    folderId: number | null;
    folderName: string | null;
    updaterId: number | null;
    updaterName: string | null;
    updatedAt: string | null;
    canDelete: boolean;
};

export type MaterialFolder = {
    id: number;
    name: string;
    itemCount: number;
    canDelete: boolean;
};

export type MaterialFilters = {
    type: 'document' | 'file' | 'link' | null;
    folder: number | null;
    updater: number | null;
};

export type EverythingType =
    'message' | 'todo' | 'document' | 'file' | 'link' | 'comment';

export type EverythingTarget =
    | 'messages'
    | 'todos'
    | 'documents'
    | 'files'
    | 'materials'
    | 'schedule'
    | 'check_ins';

export type EverythingItem = {
    id: number;
    type: EverythingType;
    title: string;
    snippet: string;
    target: EverythingTarget;
    project: ProjectTool;
    updatedAt: string;
};

export type EverythingFilters = {
    q: string | null;
    type: EverythingType | null;
    project: number | null;
};

export type ReportSummary = {
    incomplete: number;
    overdue: number;
    unassigned: number;
    completedLast30Days: number;
};

export type ReportTodo = {
    id: number;
    title: string;
    dueOn: string | null;
    assigneeName: string | null;
    project: ProjectTool;
};

export type WorkloadItem = {
    userId: number;
    name: string;
    incompleteCount: number;
};

export type LineupProject = ProjectTool & {
    description: string | null;
    startsOn: string | null;
    endsOn: string | null;
    todoTotal: number;
    todoCompleted: number;
    todoOverdue: number;
    nextEvent: {
        id: number;
        title: string;
        startsAt: string;
        allDay: boolean;
    } | null;
};

export type CheckInSchedule = {
    id: number;
    question: string;
    frequency: 'daily' | 'weekly' | 'monthly';
    weekdays: number[];
    timezone: string;
    nextRunAt: string;
    paused: boolean;
    creatorName: string | null;
    targetNames: string[];
    pendingCount: number;
    canManage: boolean;
    canDelete: boolean;
};

export type CheckInDetail = CheckInSchedule & {
    nextRunAtLocal: string;
    targetIds: number[];
    canAnswer: boolean;
    isBookmarked: boolean;
};

export type CheckInPrompt = {
    id: number;
    checkInId: number;
    question: string;
    scheduledFor: string;
};

export type CheckInResponse = {
    id: number;
    checkInId: number;
    question: string;
    answer: string | null;
    userName: string | null;
    answeredAt: string | null;
    canEdit: boolean;
};

export type CheckInResponseDetail = CheckInResponse & {
    comments: {
        id: number;
        body: string;
        authorName: string | null;
        createdAt: string | null;
    }[];
    reactions: ProjectReaction[];
};

export type ProjectCard = {
    id: number;
    title: string;
    description: string | null;
    assigneeId: number | null;
    assigneeName: string | null;
    creatorName: string | null;
    dueOn: string | null;
    position: number;
    version: number;
    canDelete: boolean;
};

export type ProjectCardColumn = {
    id: number;
    name: string;
    position: number;
    canDelete: boolean;
    collapsed: boolean;
    cards: ProjectCard[];
};

export type ProjectCardTable = {
    id: number;
    name: string;
    canDelete: boolean;
    triage: ProjectCardColumn;
    notNow: ProjectCardColumn;
    done: ProjectCardColumn;
    columns: ProjectCardColumn[];
};

export type ProjectCardColumnDetail = ProjectCardColumn & {
    isTriage: boolean;
    isNotNow: boolean;
    isDone: boolean;
    comments: MessageComment[];
};

export type ActivityItem = {
    id: number;
    type: ActivityType;
    target: ActivityTarget;
    actorId: number | null;
    actorName: string | null;
    subjectLabel: string;
    subjectId: number | null;
    createdAt: string | null;
    project: ProjectTool;
};

export type ActivityFilters = {
    q: string | null;
    project: number | null;
    user: number | null;
};

export type ActivityPerson = {
    id: number;
    name: string;
};

export type ProjectPermissions = {
    canCreateProject?: boolean;
    canUpdateProject?: boolean;
    canArchiveProject?: boolean;
};

export type ChatUser = {
    id: number;
    name: string;
};

export type ChatMessage = {
    id: number;
    channelId: number;
    parentId: number | null;
    body: string;
    createdAt: string;
    replyCount: number;
    user: ChatUser | null;
};

export type ChatChannel = {
    id: number;
    name: string;
    description: string | null;
    messageCount: number;
};

export type ChatProject = {
    id: number;
    name: string;
};

export type ChatHistoryResponse = {
    messages: ChatMessage[];
    nextCursor?: string | null;
    hasMore?: boolean;
};

export type ChatStoreResponse = {
    message: ChatMessage;
};

export type ChatThreadResponse = ChatHistoryResponse & {
    message: ChatMessage;
};

export type ProjectTool = {
    id: number;
    name: string;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    links: PaginationLink[];
};

export type MessageComment = {
    id: number;
    body: string;
    authorName: string | null;
    createdAt: string | null;
    canDelete: boolean;
};

export type ProjectReaction = {
    reaction: string;
    count: number;
    reacted: boolean;
    names: string[];
};

export type MessageBoardPost = {
    id: number;
    title: string;
    body: string;
    authorName: string | null;
    publishedAt: string;
    pinned: boolean;
    subscribed: boolean;
    notifyCount: number;
    canEdit: boolean;
    reactions: ProjectReaction[];
    attachments: ProjectStoredFile[];
    comments: MessageComment[];
};

export type MessageBoardPostSummary = {
    id: number;
    title: string;
    authorName: string | null;
    publishedAt: string;
    pinned: boolean;
    snippet: string;
    commentsCount: number;
};

export type ProjectNotification = {
    id: string;
    type:
        | 'message_comment'
        | 'todo_assigned'
        | 'todo_comment'
        | 'todo_completed'
        | 'todo_due'
        | 'check_in_due'
        | 'mention_message'
        | 'mention_todo'
        | 'mention_file'
        | 'todo_list_comment';
    projectId: number;
    projectName: string;
    messageTitle: string | null;
    messageBoardPostId: number | null;
    storedFileId: number | null;
    todoListId: number | null;
    todoTitle: string | null;
    checkInQuestion: string | null;
    actorName: string | null;
    read: boolean;
    createdAt: string | null;
};

export type TodoMember = {
    id: number;
    name: string;
};

export type ProjectSubtask = {
    id: number;
    title: string;
    completed: boolean;
    assigneeId: number | null;
    assigneeName: string | null;
    dueOn: string | null;
    canEdit: boolean;
};

export type ProjectTodo = {
    id: number;
    title: string;
    details: string | null;
    assigneeId: number | null;
    assigneeName: string | null;
    completionNotifyIds: number[];
    dueOn: string | null;
    completed: boolean;
    creatorName: string | null;
    createdAt: string | null;
    canEdit: boolean;
    reactions: ProjectReaction[];
    attachments: ProjectStoredFile[];
    subtasks: ProjectSubtask[];
    comments: MessageComment[];
};

export type ProjectTodoList = {
    id: number;
    title: string;
    isLoose: boolean;
    canDelete: boolean;
    todos: ProjectTodo[];
};

export type ProjectScheduleEvent = {
    id: number;
    title: string;
    details: string | null;
    meetingUrl: string | null;
    startsAt: string;
    endsAt: string;
    allDay: boolean;
    recurrence: 'daily' | 'weekly' | 'monthly' | null;
    creatorName: string | null;
    canEdit: boolean;
};

export type ProjectScheduleEventDetail = ProjectScheduleEvent & {
    reactions: ProjectReaction[];
    comments: MessageComment[];
};

export type ScheduleGridDay = {
    date: string;
    day: number;
    inCurrentMonth: boolean;
    isToday: boolean;
    monthLabel: string | null;
    events: ProjectScheduleEvent[];
};

export type DashboardTodo = {
    id: number;
    title: string;
    dueOn: string | null;
    project: ProjectTool;
};

export type DashboardMyWork = {
    overdue: DashboardTodo[];
    today: DashboardTodo[];
    thisWeek: DashboardTodo[];
    later: DashboardTodo[];
};

export type DashboardProject = {
    id: number;
    name: string;
    description: string | null;
    status: 'on_track' | 'at_risk' | 'off_track' | 'complete';
    isFavorite: boolean;
    folder: ProjectFolder | null;
    endsOn: string | null;
    members: ChatUser[];
};

export type DashboardEvent = {
    id: number;
    title: string;
    startsAt: string;
    endsAt: string;
    allDay: boolean;
    project: ProjectTool;
};

export type ProjectTimeEntry = {
    id: number;
    workedOn: string;
    minutes: number;
    notes: string | null;
    userId: number;
    userName: string;
    todoTitle: string | null;
    canDelete: boolean;
};

export type PersonalNote = {
    id: number;
    title: string;
    body: string;
    updatedAt: string | null;
};

export type ProjectDocument = {
    id: number;
    title: string;
    body: string;
    creatorName: string | null;
    folderId: number | null;
    folderName: string | null;
    updatedAt: string | null;
    canEdit: boolean;
    publicUrl: string | null;
};

export type WorkspacePing = {
    id: number;
    body: string;
    read: boolean;
    createdAt: string | null;
    sender: ChatUser;
    recipient: ChatUser;
};

export type WorkspaceNotification = {
    id: string;
    type: ProjectNotification['type'];
    projectId: number;
    projectName: string;
    title: string | null;
    replyable: boolean;
    actorName: string | null;
    read: boolean;
    createdAt: string | null;
};

export type WorkspaceBubbleUp = {
    id: number;
    label: string;
    url: string;
    remindAt: string;
    due: boolean;
};

export type WorkspaceReminder = {
    id: number;
    title: string;
    startsAt: string;
    meetingUrl: string | null;
    project: ProjectTool;
};

export type WorkspaceBar = {
    tasks?: DashboardTodo[];
    events?: (DashboardEvent & { meetingUrl: string | null })[];
    bookmarks?: Pick<DashboardProject, 'id' | 'name' | 'description'>[];
    notifications: WorkspaceNotification[];
    pings?: WorkspacePing[];
    unreadPings: number;
    bubbleUps: WorkspaceBubbleUp[];
    reminder: WorkspaceReminder | null;
};

export type ProjectStoredFile = {
    id: number;
    name: string;
    mimeType: string | null;
    size: number;
    uploaderName: string | null;
    folderId: number | null;
    folderName: string | null;
    createdAt: string | null;
    canDelete: boolean;
};
