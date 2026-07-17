<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectFavoriteController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Team $currentTeam, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        $request->user()->favoriteProjects()->toggle($project->id);

        return back();
    }
}
