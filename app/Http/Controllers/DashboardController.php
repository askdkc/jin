<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function redirectToCurrentTeam(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $team = $user->currentTeam ?? $user->personalTeam();
        abort_unless($team instanceof Team, 403);

        return to_route('dashboard', ['current_team' => $team->slug]);
    }

    public function __invoke(Request $request, Team $currentTeam): Response
    {
        Gate::authorize('viewAny', [Project::class, $currentTeam]);

        $email = strtolower($request->user()->email);
        $user = $request->user();
        $userId = $request->user()->id;

        $pendingInvitations = TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ]);

        $myTodos = Todo::query()
            ->select(['id', 'todo_list_id', 'title', 'due_on'])
            ->with('todoList.project:id,team_id,name')
            ->where('assignee_id', $userId)
            ->whereNull('completed_at')
            ->whereHas('todoList.project', fn (Builder $query) => $query
                ->whereIn(
                    'projects.id',
                    Project::query()->visibleTo($user, $currentTeam)->select('projects.id'),
                )
                ->whereNull('archived_at'))
            ->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_on')
            ->limit(50)
            ->get();

        $today = today();
        $endOfWeek = $today->copy()->endOfWeek();
        $eventWindowStart = now();
        $eventWindowEnd = $eventWindowStart->copy()->addDays(7);
        $todoData = fn (Todo $todo): array => [
            'id' => $todo->id,
            'title' => $todo->title,
            'dueOn' => $todo->due_on?->toDateString(),
            'project' => [
                'id' => $todo->todoList->project->id,
                'name' => $todo->todoList->project->name,
            ],
        ];

        return Inertia::render('Dashboard', [
            'pendingInvitations' => $pendingInvitations,
            'myWork' => [
                'overdue' => $myTodos
                    ->filter(fn (Todo $todo): bool => $todo->due_on?->lt($today) ?? false)
                    ->map($todoData)
                    ->values(),
                'today' => $myTodos
                    ->filter(fn (Todo $todo): bool => $todo->due_on?->isSameDay($today) ?? false)
                    ->map($todoData)
                    ->values(),
                'thisWeek' => $myTodos
                    ->filter(fn (Todo $todo): bool => $todo->due_on !== null
                        && $todo->due_on->gt($today)
                        && $todo->due_on->lte($endOfWeek))
                    ->map($todoData)
                    ->values(),
                'later' => $myTodos
                    ->filter(fn (Todo $todo): bool => $todo->due_on === null || $todo->due_on->gt($endOfWeek))
                    ->map($todoData)
                    ->values(),
            ],
            'myProjects' => $currentTeam->projects()
                ->select(['id', 'team_id', 'project_folder_id', 'name', 'description', 'status', 'ends_on', 'updated_at'])
                ->with(['members:id,name', 'folder:id,name'])
                ->withExists(['favoritedBy as is_favorite' => fn (Builder $query) => $query->whereKey($userId)])
                ->visibleTo($user, $currentTeam)
                ->whereNull('archived_at')
                ->orderByDesc('is_favorite')
                ->latest('updated_at')
                ->limit(50)
                ->get()
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'description' => $project->description,
                    'status' => $project->status,
                    'isFavorite' => (bool) $project->getAttribute('is_favorite'),
                    'folder' => $project->folder ? [
                        'id' => $project->folder->id,
                        'name' => $project->folder->name,
                    ] : null,
                    'endsOn' => $project->ends_on?->toDateString(),
                    'members' => $project->members
                        ->take(6)
                        ->map(fn (User $member): array => [
                            'id' => $member->id,
                            'name' => $member->name,
                        ])
                        ->values(),
                ]),
            'myEvents' => ScheduleEvent::query()
                ->select(['id', 'project_id', 'title', 'starts_at', 'ends_at', 'all_day'])
                ->with('project:id,team_id,name')
                ->where('starts_at', '<=', $eventWindowEnd)
                ->where('ends_at', '>=', $eventWindowStart)
                ->whereHas('project', fn (Builder $query) => $query
                    ->whereIn(
                        'projects.id',
                        Project::query()->visibleTo($user, $currentTeam)->select('projects.id'),
                    )
                    ->whereNull('archived_at'))
                ->oldest('starts_at')
                ->limit(10)
                ->get()
                ->map(fn (ScheduleEvent $event): array => [
                    'id' => $event->id,
                    'title' => $event->title,
                    'startsAt' => $event->starts_at->toISOString(),
                    'endsAt' => $event->ends_at->toISOString(),
                    'allDay' => $event->all_day,
                    'project' => [
                        'id' => $event->project->id,
                        'name' => $event->project->name,
                    ],
                ]),
            'projectFolders' => $currentTeam->projectFolders()
                ->orderBy('position')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($folder): array => ['id' => $folder->id, 'name' => $folder->name]),
            'recentActivities' => Activity::query()
                ->select(['id', 'project_id', 'actor_id', 'type', 'subject_id', 'metadata', 'created_at'])
                ->with(['actor:id,name', 'project:id,team_id,name'])
                ->whereHas('project', fn (Builder $query) => $query
                    ->whereIn(
                        'projects.id',
                        Project::query()->visibleTo($user, $currentTeam)->select('projects.id'),
                    )
                    ->whereNull('archived_at'))
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (Activity $activity): array => [
                    'id' => $activity->id,
                    'type' => $activity->type->value,
                    'target' => $activity->type->target(),
                    'actorId' => $activity->actor_id,
                    'actorName' => $activity->actor?->name,
                    'subjectLabel' => $activity->metadata['label'],
                    'subjectId' => ($activity->metadata['subjectDeleted'] ?? false)
                        ? null
                        : $activity->subject_id,
                    'createdAt' => $activity->created_at?->toISOString(),
                    'project' => [
                        'id' => $activity->project->id,
                        'name' => $activity->project->name,
                    ],
                ]),
            'permissions' => [
                'canCreateProject' => Gate::allows('create', [Project::class, $currentTeam]),
                'canInviteMembers' => Gate::allows('inviteMember', $currentTeam),
                'canManageTeam' => Gate::allows('update', $currentTeam),
            ],
        ]);
    }
}
