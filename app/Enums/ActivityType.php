<?php

namespace App\Enums;

enum ActivityType: string
{
    case ProjectCreated = 'project.created';
    case MessageCreated = 'message.created';
    case CommentCreated = 'comment.created';
    case TodoCreated = 'todo.created';
    case TodoCommentCreated = 'todo.comment.created';
    case TodoCompleted = 'todo.completed';
    case ScheduleCreated = 'schedule.created';
    case DocumentCreated = 'document.created';
    case FileUploaded = 'file.uploaded';
    case CardCreated = 'card.created';
    case CheckInCreated = 'check-in.created';
    case CheckInAnswered = 'check-in.answered';
    case ExternalLinkCreated = 'external-link.created';

    public function target(): string
    {
        return match ($this) {
            self::ProjectCreated => 'project',
            self::MessageCreated, self::CommentCreated => 'messages',
            self::TodoCreated, self::TodoCommentCreated, self::TodoCompleted => 'todos',
            self::ScheduleCreated => 'schedule',
            self::DocumentCreated => 'documents',
            self::FileUploaded => 'files',
            self::CardCreated => 'card-tables',
            self::CheckInCreated, self::CheckInAnswered => 'check-ins',
            self::ExternalLinkCreated => 'materials',
        };
    }
}
