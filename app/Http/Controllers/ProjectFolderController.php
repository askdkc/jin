<?php

namespace App\Http\Controllers;

use App\Http\Requests\Projects\StoreProjectFolderRequest;
use App\Models\Project;
use App\Models\ProjectFolder;
use App\Models\Team;
use App\Support\OrderedPositions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ProjectFolderController extends Controller
{
    public function store(StoreProjectFolderRequest $request, Team $currentTeam): RedirectResponse
    {
        DB::transaction(function () use ($request, $currentTeam): void {
            $currentTeam = Team::query()->lockForUpdate()->findOrFail($currentTeam->id);
            $lastPosition = $currentTeam->projectFolders()->max('position');
            $currentTeam->projectFolders()->create([
                ...$request->validated(),
                'created_by' => $request->user()->id,
                'position' => $lastPosition === null ? 0 : ((int) $lastPosition) + 1,
            ]);
        }, attempts: 5);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Folder created.')]);

        return back();
    }

    public function destroy(Request $request, Team $currentTeam, ProjectFolder $projectFolder): RedirectResponse
    {
        Gate::authorize('create', [Project::class, $currentTeam]);
        abort_unless($projectFolder->team_id === $currentTeam->id, 404);

        DB::transaction(function () use ($currentTeam, $projectFolder): void {
            $currentTeam = Team::query()->lockForUpdate()->findOrFail($currentTeam->id);
            $projectFolder = $currentTeam->projectFolders()->findOrFail($projectFolder->id);
            $projectFolder->delete();
            OrderedPositions::resequence(
                new ProjectFolder,
                $currentTeam->projectFolders()->orderBy('position')->orderBy('id')->pluck('id'),
            );
        }, attempts: 5);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Folder deleted.')]);

        return back();
    }
}
