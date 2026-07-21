<?php

namespace App\Http\Middleware;

use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetTeamUrlDefaults
{
    /**
     * Set the default URL parameters for team-based routes.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $routeTeam = $request->route('current_team');
        $currentTeam = $routeTeam instanceof Team ? $routeTeam : $request->user()?->currentTeam;

        if ($currentTeam) {
            URL::defaults([
                'current_team' => $currentTeam->slug,
                'team' => $currentTeam->slug,
            ]);
        }

        return $next($request);
    }
}
