<?php

namespace App\Http\Controllers;

use App\Http\Requests\Projects\StoreTimeEntryRequest;
use App\Models\Project;
use App\Models\Team;
use App\Models\TimeEntry;
use App\Models\Todo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectTimeEntryController extends Controller
{
    public function index(Request $request, Team $currentTeam, Project $project): Response
    {
        Gate::authorize('view', $project);
        abort_unless($project->usesTool('timesheets'), 404);

        $entries = $project->timeEntries()
            ->with(['user:id,name', 'todo:id,title'])
            ->latest('worked_on')
            ->latest('id')
            ->get()
            ->map(fn (TimeEntry $entry): array => [
                'id' => $entry->id,
                'workedOn' => $entry->worked_on->toDateString(),
                'minutes' => $entry->minutes,
                'notes' => $entry->notes,
                'userId' => $entry->user_id,
                'userName' => $entry->user->name,
                'todoTitle' => $entry->todo?->title,
                'canDelete' => $entry->user_id === $request->user()->id || Gate::allows('update', $project),
            ]);

        $todos = Todo::query()
            ->whereNull('parent_id')
            ->whereHas('todoList', fn ($query) => $query->where('project_id', $project->id))
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(fn (Todo $todo): array => ['id' => $todo->id, 'name' => $todo->title]);

        return Inertia::render('projects/time-entries/Index', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'entries' => $entries,
            'todos' => $todos,
            'totalMinutes' => $entries->sum('minutes'),
        ]);
    }

    public function store(StoreTimeEntryRequest $request, Team $currentTeam, Project $project): RedirectResponse
    {
        $project->timeEntries()->create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Time entry saved.')]);

        return back();
    }

    public function destroy(Request $request, Team $currentTeam, Project $project, TimeEntry $timeEntry): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($timeEntry->project_id === $project->id, 404);
        abort_unless($timeEntry->user_id === $request->user()->id || Gate::allows('update', $project), 403);

        $timeEntry->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Time entry deleted.')]);

        return back();
    }
}
