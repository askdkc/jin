<?php

namespace App\Http\Controllers;

use App\Actions\CheckIns\SubmitCheckInAnswer;
use App\Actions\Comments\CreateMessageComment;
use App\Actions\Comments\CreateTodoComment;
use App\Http\Requests\Notifications\StoreNotificationReplyRequest;
use App\Models\CheckInPrompt;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\Team;
use App\Models\Todo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;

class NotificationReplyController extends Controller
{
    public function __construct(
        private SubmitCheckInAnswer $submitCheckInAnswer,
        private CreateMessageComment $createMessageComment,
        private CreateTodoComment $createTodoComment,
    ) {}

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

        $this->createMessageComment->handle(
            $project,
            $message,
            $request->user(),
            $body,
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

        $this->createTodoComment->handle(
            $project,
            $todo,
            $request->user(),
            $body,
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
            ->whereHas('checkIn', fn ($query) => $query->where('project_id', $project->id))
            ->with('checkIn')
            ->findOrFail((int) ($notification->data['promptId'] ?? 0));

        $this->submitCheckInAnswer->handle(
            $project,
            $prompt->checkIn,
            $request->user(),
            $body,
            $prompt,
            validationAttribute: 'body',
        );
    }
}
