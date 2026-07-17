<?php

namespace App\Http\Controllers;

use App\Http\Requests\Projects\StoreProjectFolderRequest;
use App\Models\Project;
use App\Models\ProjectFolder;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ProjectFolderController extends Controller
{
    public function store(StoreProjectFolderRequest $request, Team $currentTeam): RedirectResponse
    {
        $currentTeam->projectFolders()->create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
            'position' => ((int) $currentTeam->projectFolders()->max('position')) + 1,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Folder created.')]);

        return back();
    }

    public function destroy(Request $request, Team $currentTeam, ProjectFolder $projectFolder): RedirectResponse
    {
        Gate::authorize('create', [Project::class, $currentTeam]);
        abort_unless($projectFolder->team_id === $currentTeam->id, 404);

        $projectFolder->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Folder deleted.')]);

        return back();
    }
}
