<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request, Team $currentTeam): Response
    {
        $projectIds = Project::query()
            ->visibleTo($request->user(), $currentTeam)
            ->pluck('id');

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
        $projectIds = Project::query()
            ->visibleTo($request->user(), $currentTeam)
            ->pluck('id');

        $request->user()
            ->unreadNotifications()
            ->where('data->teamId', $currentTeam->id)
            ->whereIn('data->projectId', $projectIds)
            ->update(['read_at' => now()]);

        return to_route('notifications.index', $currentTeam);
    }
}
