export type RealtimeNotificationPayload = {
    id: string;
    type: string;
    teamId?: number;
    projectId?: number;
    projectName?: string;
    actorName?: string | null;
    messageTitle?: string | null;
    todoTitle?: string | null;
    checkInQuestion?: string | null;
    teamName?: string;
    inviterName?: string;
};

export type RealtimeNotificationToast = {
    title: string;
    description: string;
};

type Translate = (key: string, replacements?: Record<string, string>) => string;

export function realtimeNotificationToast(
    notification: RealtimeNotificationPayload,
    translate: Translate,
): RealtimeNotificationToast {
    const actorName = notification.actorName ?? translate('Someone');
    const projectName = notification.projectName ?? translate('Notification');
    const itemTitle =
        notification.messageTitle ??
        notification.todoTitle ??
        notification.checkInQuestion ??
        translate('Project update');

    switch (notification.type) {
        case 'team_invitation': {
            const teamName = notification.teamName ?? translate('Notification');
            const inviterName =
                notification.inviterName ?? translate('Someone');

            return {
                title: translate("You've been invited to join :teamName", {
                    teamName,
                }),
                description: translate(
                    ':inviterName has invited you to join the :teamName team.',
                    { inviterName, teamName },
                ),
            };
        }
        case 'mention_message':
            return {
                title: translate(':actorName mentioned you in a message', {
                    actorName,
                }),
                description: itemTitle,
            };
        case 'mention_todo':
            return {
                title: translate(':actorName mentioned you in a to-do', {
                    actorName,
                }),
                description: itemTitle,
            };
        case 'mention_file':
            return {
                title: translate(':actorName mentioned you in a file', {
                    actorName,
                }),
                description: itemTitle,
            };
        case 'message_comment':
        case 'todo_list_comment':
        case 'todo_comment':
            return {
                title: projectName,
                description: translate(
                    ':actorName commented on :messageTitle.',
                    {
                        actorName,
                        messageTitle: itemTitle,
                    },
                ),
            };
        case 'todo_assigned':
            return {
                title: projectName,
                description: translate(
                    ':actorName assigned you to :todoTitle.',
                    {
                        actorName,
                        todoTitle: itemTitle,
                    },
                ),
            };
        case 'todo_completed':
            return {
                title: projectName,
                description: translate(':actorName completed :todoTitle.', {
                    actorName,
                    todoTitle: itemTitle,
                }),
            };
        case 'todo_due':
            return {
                title: projectName,
                description: translate(':todoTitle is due soon.', {
                    todoTitle: itemTitle,
                }),
            };
        case 'check_in_due':
            return {
                title: projectName,
                description: translate(
                    'A new check-in question is ready to answer: :question',
                    { question: itemTitle },
                ),
            };
        default:
            return {
                title: notification.actorName ?? projectName,
                description: itemTitle,
            };
    }
}
