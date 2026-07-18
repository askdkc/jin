import CardTableController from '@/actions/App/Http/Controllers/CardTableController';
import CheckInController from '@/actions/App/Http/Controllers/CheckInController';
import MessageBoardController from '@/actions/App/Http/Controllers/MessageBoardController';
import ProjectDocumentController from '@/actions/App/Http/Controllers/ProjectDocumentController';
import ProjectFileController from '@/actions/App/Http/Controllers/ProjectFileController';
import ProjectMaterialController from '@/actions/App/Http/Controllers/ProjectMaterialController';
import ProjectTodoController from '@/actions/App/Http/Controllers/ProjectTodoController';
import ScheduleEventController from '@/actions/App/Http/Controllers/ScheduleEventController';
import { t } from '@/lib/i18n';
import { show } from '@/routes/projects';
import type { ActivityItem, ActivityType } from '@/types';

const actionKeys: Record<ActivityType, string> = {
    'project.created': 'created a project',
    'message.created': 'posted a message',
    'comment.created': 'added a comment',
    'todo.created': 'added a to-do',
    'todo.comment.created': 'commented on a to-do',
    'todo.completed': 'completed a to-do',
    'schedule.created': 'added an event',
    'document.created': 'created a document',
    'file.uploaded': 'uploaded a file',
    'card.created': 'added a card',
    'check-in.created': 'created a check-in',
    'check-in.answered': 'answered a check-in',
    'external-link.created': 'added an external link',
};

export const activityAction = (type: ActivityType): string =>
    t(actionKeys[type]);

export const activityHref = (teamSlug: string, activity: ActivityItem) => {
    const projectArguments: [string, number] = [teamSlug, activity.project.id];

    switch (activity.target) {
        case 'messages':
            return MessageBoardController.index(projectArguments);
        case 'todos':
            return ProjectTodoController.index(projectArguments);
        case 'schedule':
            return ScheduleEventController.index(projectArguments);
        case 'documents':
            return ProjectDocumentController.index(projectArguments);
        case 'files':
            return ProjectFileController.show([
                teamSlug,
                activity.project.id,
                activity.subjectId,
            ]);
        case 'card-tables':
            return CardTableController.index(projectArguments);
        case 'check-ins':
            return CheckInController.index(projectArguments);
        case 'materials':
            return ProjectMaterialController.index(projectArguments);
        default:
            return show(projectArguments);
    }
};
