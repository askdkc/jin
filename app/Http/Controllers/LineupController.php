<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LineupController extends Controller
{
    public function __invoke(Team $currentTeam): Response
    {
        Gate::authorize('viewAny', [Project::class, $currentTeam]);

        $projects = Project::query()
            ->visibleTo(request()->user(), $currentTeam)
            ->whereNull('archived_at')
            ->orderByRaw('starts_on IS NULL')
            ->orderBy('starts_on')
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'starts_on', 'ends_on']);

        $projectIds = $projects->pluck('id');
        $todoStats = DB::table('todos')
            ->join('todo_lists', 'todo_lists.id', '=', 'todos.todo_list_id')
            ->whereIn('todo_lists.project_id', $projectIds)
            ->groupBy('todo_lists.project_id')
            ->select('todo_lists.project_id')
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('SUM(CASE WHEN todos.completed_at IS NOT NULL THEN 1 ELSE 0 END) as completed_count')
            ->selectRaw(
                'SUM(CASE WHEN todos.completed_at IS NULL AND todos.due_on < ? THEN 1 ELSE 0 END) as overdue_count',
                [today()->toDateString()],
            )
            ->get()
            ->keyBy('project_id');
        $nextEvents = ScheduleEvent::query()
            ->whereIn('project_id', $projectIds)
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->get(['id', 'project_id', 'title', 'starts_at', 'all_day'])
            ->groupBy('project_id')
            ->map(fn (Collection $events) => $events->first());

        return Inertia::render('lineup/Index', [
            'projects' => $projects->map(function (Project $project) use ($todoStats, $nextEvents): array {
                $stats = $todoStats->get($project->id);
                $nextEvent = $nextEvents->get($project->id);

                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'description' => $project->description,
                    'startsOn' => $project->starts_on?->toDateString(),
                    'endsOn' => $project->ends_on?->toDateString(),
                    'todoTotal' => (int) data_get($stats, 'total_count', 0),
                    'todoCompleted' => (int) data_get($stats, 'completed_count', 0),
                    'todoOverdue' => (int) data_get($stats, 'overdue_count', 0),
                    'nextEvent' => $nextEvent === null ? null : [
                        'id' => $nextEvent->id,
                        'title' => $nextEvent->title,
                        'startsAt' => $nextEvent->starts_at->toISOString(),
                        'allDay' => $nextEvent->all_day,
                    ],
                ];
            }),
        ]);
    }
}
