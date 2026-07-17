<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Http\Requests\Notifications\StoreNotificationReplyRequest;
use App\Models\Activity;
use App\Models\CheckInPrompt;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\Team;
use App\Models\Todo;
use App\Notifications\NewMessageCommentNotification;
use App\Notifications\TodoActivityNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class NotificationReplyController extends Controller
{
    public function store(
        StoreNotificationReplyRequest $request,
        Team $currentTeam,
        DatabaseNotification $notification,
    ): RedirectResponse {
        abort_unless(
            $notification->notifiable_id === $request->user()->getKey()
            && (int) ($notification->data['teamId'] ?? 0) === $currentTeam->id,
            404,
        );

        $project = Project::query()
            ->visibleTo($request->user(), $currentTeam)
            ->whereKey((int) ($notification->data['projectId'] ?? 0))
            ->firstOrFail();
        $body = $request->validated('body');

        match ($notification->data['type'] ?? null) {
            'message_comment' => $this->replyToMessage($request, $project, $notification, $body),
            'todo_assigned', 'todo_comment', 'todo_completed', 'todo_due' => $this->replyToTodo($request, $project, $notification, $body),
            'check_in_due' => $this->answerCheckIn($request, $project, $notification, $body),
            default => abort(422, __('This notification cannot be replied to.')),
        };

        $notification->markAsRead();

        return back();
    }

    private function replyToMessage(
        StoreNotificationReplyRequest $request,
        Project $project,
        DatabaseNotification $notification,
        string $body,
    ): void {
        $message = MessageBoardPost::query()
            ->where('project_id', $project->id)
            ->findOrFail((int) ($notification->data['messageBoardPostId'] ?? 0));

        $comment = DB::transaction(function () use ($body, $message, $project, $request) {
            $comment = $message->comments()->create([
                'body' => $body,
                'project_id' => $project->id,
                'user_id' => $request->user()->id,
            ]);
            Activity::record($project, $request->user(), ActivityType::CommentCreated, $comment, $message->title);

            return $comment;
        });

        Notification::send(
            $message->subscribers()->whereKeyNot($request->user()->id)->get(),
            new NewMessageCommentNotification($request->user(), $project, $message),
        );
    }

    private function replyToTodo(
        StoreNotificationReplyRequest $request,
        Project $project,
        DatabaseNotification $notification,
        string $body,
    ): void {
        $todo = Todo::query()
            ->whereHas('todoList', fn ($query) => $query->where('project_id', $project->id))
            ->findOrFail((int) ($notification->data['todoId'] ?? 0));

        $comment = DB::transaction(function () use ($body, $project, $request, $todo) {
            $comment = $todo->comments()->create([
                'body' => $body,
                'project_id' => $project->id,
                'user_id' => $request->user()->id,
            ]);
            Activity::record($project, $request->user(), ActivityType::TodoCommentCreated, $comment, $todo->title);

            return $comment;
        });

        TodoActivityNotification::sendToUsers(
            [$todo->created_by, $todo->assignee_id],
            $request->user(),
            $project,
            $todo,
            TodoActivityNotification::Commented,
        );
    }

    private function answerCheckIn(
        StoreNotificationReplyRequest $request,
        Project $project,
        DatabaseNotification $notification,
        string $body,
    ): void {
        $prompt = CheckInPrompt::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('answered_at')
            ->whereHas('checkIn', fn ($query) => $query->where('project_id', $project->id))
            ->findOrFail((int) ($notification->data['promptId'] ?? 0));

        DB::transaction(function () use ($body, $project, $prompt, $request): void {
            $prompt->update(['answer' => $body, 'answered_at' => now()]);
            Activity::record($project, $request->user(), ActivityType::CheckInAnswered, $prompt, $prompt->checkIn->question);
        });
    }
}
