<?php

namespace App\Actions\Comments;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\MentionNotification;
use App\Notifications\TodoActivityNotification;
use App\Support\InlineAttachments;
use App\Support\Mentions;
use Illuminate\Support\Facades\DB;

class CreateTodoComment
{
    public function handle(
        Project $project,
        Todo $todo,
        User $user,
        string $body,
    ): Comment {
        $comment = DB::transaction(function () use ($project, $todo, $user, $body): Comment {
            $comment = $todo->comments()->create([
                'body' => $body,
                'project_id' => $project->id,
                'user_id' => $user->id,
            ]);

            InlineAttachments::syncFromBody($comment, $project, $user->id, $comment->body);
            Activity::record(
                $project,
                $user,
                ActivityType::TodoCommentCreated,
                $comment,
                $todo->title,
            );

            return $comment;
        }, attempts: 5);

        TodoActivityNotification::sendToUsers(
            [$todo->created_by, $todo->assignee_id],
            $user,
            $project,
            $todo,
            TodoActivityNotification::Commented,
        );

        MentionNotification::sendToUsers(
            Mentions::extract($comment->body, $project->members),
            $user,
            $project,
            MentionNotification::Todo,
            $todo->title,
            $todo->id,
        );

        return $comment;
    }
}
