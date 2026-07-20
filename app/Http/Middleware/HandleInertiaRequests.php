<?php

namespace App\Http\Middleware;

use App\Models\BubbleUp;
use App\Models\DirectMessage;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\Team;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $routeTeam = $request->route('current_team');
        $currentTeam = $routeTeam instanceof Team ? $routeTeam : $user?->currentTeam;

        return [
            ...parent::share($request),
            'translations' => fn (): array => is_file(lang_path(app()->getLocale().'.json'))
                ? json_decode((string) file_get_contents(lang_path(app()->getLocale().'.json')), true, 512, JSON_THROW_ON_ERROR)
                : [],
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user?->currentTeam ? $user->toUserTeam($user->currentTeam) : null,
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],
            'notificationSummary' => fn (): array => [
                'unreadCount' => $user && $currentTeam
                    ? ($user->showsUnreadNotificationCount()
                        ? $user->unreadNotifications()
                            ->where('data->teamId', $currentTeam->id)
                            ->whereIn(
                                'data->projectId',
                                Project::query()->visibleTo($user, $currentTeam)->select('projects.id'),
                            )
                            ->count()
                        : 0)
                    : 0,
            ],
            'navigationProjects' => fn (): array => $user && $currentTeam
                ? Project::query()
                    ->visibleTo($user, $currentTeam)
                    ->whereNull('archived_at')
                    ->orderBy('name')
                    ->limit(50)
                    ->get(['id', 'name'])
                    ->map(fn (Project $project): array => [
                        'id' => $project->id,
                        'name' => $project->name,
                    ])
                    ->all()
                : [],
            'navigationPeople' => fn (): array => $user && $currentTeam
                ? $currentTeam->members()
                    ->whereKeyNot($user->id)
                    ->orderBy('name')
                    ->limit(50)
                    ->get(['users.id', 'users.name'])
                    ->map(fn (User $member): array => [
                        'id' => $member->id,
                        'name' => $member->name,
                    ])
                    ->all()
                : [],
            'workspaceBar' => fn (): array => $user && $currentTeam
                ? $this->workspaceBar($user, $currentTeam)
                : $this->emptyWorkspaceBar(),
        ];
    }

    /** @return array<string, mixed> */
    private function workspaceBar(User $user, Team $team): array
    {
        $visibleProjectIds = Project::query()->visibleTo($user, $team)->select('projects.id');
        $eventWindowEnd = now()->addDays(7);

        $tasks = Todo::query()
            ->select(['id', 'todo_list_id', 'title', 'due_on'])
            ->with('todoList.project:id,team_id,name')
            ->where('assignee_id', $user->id)
            ->whereNull('completed_at')
            ->whereHas('todoList.project', fn (Builder $query) => $query
                ->whereIn('projects.id', clone $visibleProjectIds)
                ->whereNull('archived_at'))
            ->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_on')
            ->limit(10)
            ->get()
            ->map(fn (Todo $todo): array => [
                'id' => $todo->id,
                'title' => $todo->title,
                'dueOn' => $todo->due_on?->toDateString(),
                'project' => [
                    'id' => $todo->todoList->project->id,
                    'name' => $todo->todoList->project->name,
                ],
            ]);

        $events = ScheduleEvent::query()
            ->select(['id', 'project_id', 'title', 'starts_at', 'ends_at', 'all_day', 'meeting_url'])
            ->with('project:id,team_id,name')
            ->whereBetween('starts_at', [now(), $eventWindowEnd])
            ->whereHas('project', fn (Builder $query) => $query
                ->whereIn('projects.id', clone $visibleProjectIds)
                ->whereNull('archived_at'))
            ->oldest('starts_at')
            ->limit(10)
            ->get();

        $notificationQuery = $user->notifications()
            ->where('data->teamId', $team->id)
            ->whereIn('data->projectId', clone $visibleProjectIds);
        $pings = DirectMessage::query()
            ->with(['sender:id,name', 'recipient:id,name'])
            ->where('team_id', $team->id)
            ->where(fn (Builder $query) => $query
                ->where('sender_id', $user->id)
                ->orWhere('recipient_id', $user->id))
            ->latest('id')
            ->limit(30)
            ->get()
            ->reverse()
            ->values();

        $reminder = $user->wantsEventReminders() ? $events
            ->first(fn (ScheduleEvent $event): bool => ! $event->all_day
                && $event->starts_at->between(now(), now()->addMinutes(15))) : null;

        return [
            'tasks' => $tasks,
            'events' => $events->map(fn (ScheduleEvent $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'startsAt' => $event->starts_at->toISOString(),
                'endsAt' => $event->ends_at->toISOString(),
                'allDay' => $event->all_day,
                'meetingUrl' => $event->meeting_url,
                'project' => ['id' => $event->project->id, 'name' => $event->project->name],
            ]),
            'bookmarks' => Project::query()
                ->visibleTo($user, $team)
                ->whereHas('favoritedBy', fn (Builder $query) => $query->whereKey($user->id))
                ->whereNull('archived_at')
                ->latest('updated_at')
                ->limit(10)
                ->get(['id', 'name', 'description'])
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'description' => $project->description,
                ]),
            'notifications' => (clone $notificationQuery)
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn (DatabaseNotification $notification): array => [
                    'id' => $notification->id,
                    'type' => $notification->data['type'],
                    'projectId' => $notification->data['projectId'],
                    'projectName' => $notification->data['projectName'],
                    'title' => $notification->data['messageTitle']
                        ?? $notification->data['todoTitle']
                        ?? $notification->data['checkInQuestion']
                        ?? null,
                    'replyable' => isset($notification->data['messageBoardPostId'])
                        || isset($notification->data['todoId'])
                        || isset($notification->data['promptId']),
                    'actorName' => $notification->data['actorName'] ?? null,
                    'read' => $notification->read_at !== null,
                    'createdAt' => $notification->created_at?->toISOString(),
                ]),
            'pings' => $pings->map(fn (DirectMessage $message): array => [
                'id' => $message->id,
                'body' => $message->body,
                'read' => $message->read_at !== null,
                'createdAt' => $message->created_at?->toISOString(),
                'sender' => ['id' => $message->sender->id, 'name' => $message->sender->name],
                'recipient' => ['id' => $message->recipient->id, 'name' => $message->recipient->name],
            ]),
            'unreadPings' => DirectMessage::query()
                ->where('team_id', $team->id)
                ->where('recipient_id', $user->id)
                ->whereNull('read_at')
                ->count(),
            'people' => $team->members()
                ->whereKeyNot($user->id)
                ->orderBy('name')
                ->get(['users.id', 'users.name'])
                ->map(fn (User $member): array => ['id' => $member->id, 'name' => $member->name]),
            'bubbleUps' => BubbleUp::query()
                ->where('team_id', $team->id)
                ->where('user_id', $user->id)
                ->whereNull('completed_at')
                ->orderBy('remind_at')
                ->limit(20)
                ->get()
                ->map(fn (BubbleUp $bubbleUp): array => [
                    'id' => $bubbleUp->id,
                    'label' => $bubbleUp->label,
                    'url' => $bubbleUp->url,
                    'remindAt' => $bubbleUp->remind_at->toISOString(),
                    'due' => $bubbleUp->remind_at->isPast(),
                ]),
            'reminder' => $reminder ? [
                'id' => $reminder->id,
                'title' => $reminder->title,
                'startsAt' => $reminder->starts_at->toISOString(),
                'meetingUrl' => $reminder->meeting_url,
                'project' => ['id' => $reminder->project->id, 'name' => $reminder->project->name],
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyWorkspaceBar(): array
    {
        return [
            'tasks' => [],
            'events' => [],
            'bookmarks' => [],
            'notifications' => [],
            'pings' => [],
            'unreadPings' => 0,
            'people' => [],
            'bubbleUps' => [],
            'reminder' => null,
        ];
    }
}
