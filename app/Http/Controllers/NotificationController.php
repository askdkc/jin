<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Team;
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
