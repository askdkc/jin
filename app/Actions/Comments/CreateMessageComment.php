<?php

namespace App\Actions\Comments;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Comment;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\User;
use App\Notifications\MentionNotification;
use App\Notifications\NewMessageCommentNotification;
use App\Support\InlineAttachments;
use App\Support\Mentions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class CreateMessageComment
{
    public function handle(
        Project $project,
        MessageBoardPost $messageBoardPost,
        User $user,
        string $body,
    ): Comment {
        $comment = DB::transaction(function () use ($project, $messageBoardPost, $user, $body): Comment {
            $comment = $messageBoardPost->comments()->create([
                'body' => $body,
                'project_id' => $project->id,
                'user_id' => $user->id,
            ]);

            InlineAttachments::syncFromBody($comment, $project, $user->id, $comment->body);
            Activity::record(
                $project,
                $user,
                ActivityType::CommentCreated,
                $comment,
                $messageBoardPost->title,
            );

            return $comment;
        }, attempts: 5);

        $recipients = $messageBoardPost->subscribers()
            ->where('users.id', '<>', $user->id)
            ->get();
        Notification::send(
            $recipients,
            new NewMessageCommentNotification($user, $project, $messageBoardPost),
        );

        MentionNotification::sendToUsers(
            Mentions::extract($comment->body, $project->members),
            $user,
            $project,
            MentionNotification::Message,
            $messageBoardPost->title,
            $messageBoardPost->id,
        );

        return $comment;
    }
}
