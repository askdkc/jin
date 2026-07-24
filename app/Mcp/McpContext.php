<?php

namespace App\Mcp;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Laravel\Mcp\Request;

class McpContext
{
    /** @return array{0: Team, 1: User}|null */
    public function team(Request $request, string $teamSlug): ?array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $team = Team::query()->where('slug', $teamSlug)->first();

        if (! $team instanceof Team || ! $user->belongsToTeam($team)) {
            return null;
        }

        return [$team, $user];
    }

    /** @return array{0: Team, 1: User, 2: Project}|null */
    public function project(Request $request, string $teamSlug, int $projectId): ?array
    {
        $context = $this->team($request, $teamSlug);

        if ($context === null) {
            return null;
        }

        [$team, $user] = $context;
        $project = Project::query()
            ->visibleTo($user, $team)
            ->whereKey($projectId)
            ->first();

        return $project instanceof Project ? [$team, $user, $project] : null;
    }
}
