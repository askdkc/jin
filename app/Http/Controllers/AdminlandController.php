<?php

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AdminlandController extends Controller
{
    public function __invoke(Team $currentTeam): Response
    {
        Gate::authorize('update', $currentTeam);

        return Inertia::render('adminland/Index', [
            'administrators' => $currentTeam->members()
                ->wherePivotIn('role', [TeamRole::Owner->value, TeamRole::Admin->value])
                ->orderBy('users.name')
                ->get(['users.id', 'users.name', 'users.email'])
                ->map(function (User $administrator): array {
                    /** @var Membership $membership */
                    $membership = $administrator->getRelation('pivot');

                    return [
                        'id' => $administrator->id,
                        'name' => $administrator->name,
                        'email' => $administrator->email,
                        'avatar' => null,
                        'role' => $membership->role->value,
                        'role_label' => $membership->role->label(),
                    ];
                }),
            'permissions' => [
                'canUpdateMember' => Gate::allows('updateMember', $currentTeam),
            ],
        ]);
    }
}
