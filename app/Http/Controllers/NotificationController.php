<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Team;
use App\Models\Todo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request, Team $currentTeam): Response
    {
        $projectIds = $this->notificationProjectIds($request, $currentTeam);

        $notifications = $request->user()
            ->notifications()
            ->where('data->teamId', $currentTeam->id)
            ->whereIn('data->projectId', $projectIds)
            ->limit(50)
            ->get()
            ->map(function (DatabaseNotification $notification): array {
                $data = $notification->data;

                return [
                    'id' => $notification->id,
                    'type' => $data['type'],
                    'projectId' => $data['projectId'],
                    'projectName' => $data['projectName'],
                    'messageTitle' => $data['messageTitle'] ?? null,
                    'messageBoardPostId' => $data['messageBoardPostId'] ?? null,
                    'storedFileId' => $data['storedFileId'] ?? null,
                    'chatMessageId' => $data['chatMessageId'] ?? null,
                    'chatChannelId' => $data['chatChannelId'] ?? null,
                    'chatChannelName' => $data['chatChannelName'] ?? null,
                    'chatMessageExcerpt' => $data['chatMessageExcerpt'] ?? null,
                    'todoListId' => $data['todoListId'] ?? null,
                    'todoTitle' => $data['todoTitle'] ?? null,
                    'checkInQuestion' => $data['checkInQuestion'] ?? null,
                    'actorName' => $data['actorName'] ?? null,
                    'read' => $notification->read_at !== null,
                    'createdAt' => $notification->created_at?->toISOString(),
                ];
            });

        return Inertia::render('notifications/Index', [
            'notifications' => $notifications,
        ]);
    }

    public function markAllRead(Request $request, Team $currentTeam): RedirectResponse
    {
        $projectIds = $this->notificationProjectIds($request, $currentTeam);

        $request->user()
            ->unreadNotifications()
            ->where('data->teamId', $currentTeam->id)
            ->whereIn('data->projectId', $projectIds)
            ->update(['read_at' => now()]);

        return to_route('notifications.index', $currentTeam);
    }

    public function open(
        Request $request,
        Team $currentTeam,
        DatabaseNotification $notification,
    ): RedirectResponse {
        abort_unless(
            $notification->notifiable_type === $request->user()->getMorphClass()
            && (string) $notification->notifiable_id === (string) $request->user()->getKey()
            && (int) ($notification->data['teamId'] ?? 0) === $currentTeam->id,
            404,
        );

        $project = Project::query()
            ->visibleTo($request->user(), $currentTeam)
            ->findOrFail((int) ($notification->data['projectId'] ?? 0));

        $notification->markAsRead();

        return redirect()->to($this->notificationUrl($currentTeam, $project, $notification));
    }

    private function notificationUrl(
        Team $team,
        Project $project,
        DatabaseNotification $notification,
    ): string {
        $data = $notification->data;

        return match ($data['type'] ?? null) {
            'chat_message', 'mention_chat' => route('projects.chat.show', [
                'current_team' => $team,
                'project' => $project,
                'channel' => $data['chatChannelId'] ?? null,
            ]),
            'message_comment', 'mention_message' => isset($data['messageBoardPostId'])
                ? route('projects.messages.show', [$team, $project, $data['messageBoardPostId']])
                : route('projects.messages.index', [$team, $project]),
            'mention_file' => isset($data['storedFileId'])
                ? route('projects.files.show', [$team, $project, $data['storedFileId']])
                : route('projects.materials.index', [$team, $project]),
            'todo_list_comment' => isset($data['todoListId'])
                ? route('projects.todo-lists.show', [$team, $project, $data['todoListId']])
                : route('projects.todos.index', [$team, $project]),
            'check_in_due' => isset($data['checkInId'])
                ? route('projects.check-ins.show', [$team, $project, $data['checkInId']])
                : route('projects.check-ins.index', [$team, $project]),
            'todo_assigned', 'todo_comment', 'todo_completed', 'todo_due', 'mention_todo' => $this->todoUrl(
                $team,
                $project,
                (int) ($data['todoId'] ?? 0),
            ),
            default => route('projects.show', [$team, $project]),
        };
    }

    private function todoUrl(Team $team, Project $project, int $todoId): string
    {
        $todo = Todo::query()
            ->select(['id', 'todo_list_id'])
            ->whereHas('todoList', fn (Builder $query) => $query->where('project_id', $project->id))
            ->find($todoId);

        return $todo
            ? route('projects.todos.show', [$team, $project, $todo->todo_list_id, $todo])
            : route('projects.todos.index', [$team, $project]);
    }

    /** @return Builder<Project> */
    private function notificationProjectIds(Request $request, Team $team): Builder
    {
        $query = Project::query()->visibleTo($request->user(), $team);

        // PostgreSQL JSON extraction returns text, so its subquery must return the same type.
        return DB::connection()->getDriverName() === 'pgsql'
            ? $query->selectRaw('CAST(projects.id AS TEXT)')
            : $query->select('projects.id');
    }
}
