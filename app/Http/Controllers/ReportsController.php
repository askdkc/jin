<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Team;
use App\Models\Todo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends Controller
{
    public function __invoke(Team $currentTeam): Response
    {
        Gate::authorize('viewAny', [Project::class, $currentTeam]);

        $projectIds = $this->accessibleProjectIds($currentTeam);
        $today = today()->toDateString();
        $thirtyDaysAgo = now()->subDays(30);
        $baseTodos = fn (): Builder => Todo::query()->whereHas(
            'todoList',
            fn (Builder $query) => $query->whereIn('project_id', $projectIds),
        );

        $summary = [
            'incomplete' => $baseTodos()->whereNull('completed_at')->count(),
            'overdue' => $baseTodos()
                ->whereNull('completed_at')
                ->whereDate('due_on', '<', $today)
                ->count(),
            'unassigned' => $baseTodos()
                ->whereNull('completed_at')
                ->whereNull('assignee_id')
                ->count(),
            'completedLast30Days' => $baseTodos()
                ->where('completed_at', '>=', $thirtyDaysAgo)
                ->count(),
        ];

        $overdue = $baseTodos()
            ->with(['todoList.project:id,name', 'assignee:id,name'])
            ->whereNull('completed_at')
            ->whereDate('due_on', '<', $today)
            ->orderBy('due_on')
            ->limit(20)
            ->get()
            ->map(fn (Todo $todo): array => $this->mapTodo($todo));

        $unassigned = $baseTodos()
            ->with('todoList.project:id,name')
            ->whereNull('completed_at')
            ->whereNull('assignee_id')
            ->orderByRaw('due_on IS NULL')
            ->orderBy('due_on')
            ->limit(20)
            ->get()
            ->map(fn (Todo $todo): array => $this->mapTodo($todo));

        $workload = DB::table('todos')
            ->join('todo_lists', 'todo_lists.id', '=', 'todos.todo_list_id')
            ->leftJoin('users', 'users.id', '=', 'todos.assignee_id')
            ->whereIn('todo_lists.project_id', $projectIds)
            ->whereNull('todos.completed_at')
            ->whereNotNull('todos.assignee_id')
            ->groupBy('todos.assignee_id', 'users.name')
            ->orderByDesc('incomplete_count')
            ->select(['todos.assignee_id', 'users.name'])
            ->selectRaw('COUNT(*) as incomplete_count')
            ->get()
            ->map(fn (object $row): array => [
                'userId' => (int) data_get($row, 'assignee_id'),
                'name' => (string) data_get($row, 'name', __('Deleted user')),
                'incompleteCount' => (int) data_get($row, 'incomplete_count'),
            ]);

        return Inertia::render('reports/Index', [
            'summary' => $summary,
            'overdue' => $overdue,
            'unassigned' => $unassigned,
            'workload' => $workload,
        ]);
    }

    /** @return list<int> */
    private function accessibleProjectIds(Team $team): array
    {
        $projectIds = Project::query()
            ->visibleTo(request()->user(), $team)
            ->whereNull('archived_at')
            ->pluck('id')
            ->map(fn (mixed $projectId): int => (int) $projectId)
            ->values()
            ->all();

        return array_values($projectIds);
    }

    /**
     * @return array{id: int, title: string, dueOn: string|null, assigneeName: string|null, project: array{id: int, name: string}}
     */
    private function mapTodo(Todo $todo): array
    {
        return [
            'id' => $todo->id,
            'title' => $todo->title,
            'dueOn' => $todo->due_on?->toDateString(),
            'assigneeName' => $todo->assignee?->name,
            'project' => [
                'id' => $todo->todoList->project->id,
                'name' => $todo->todoList->project->name,
            ],
        ];
    }
}
