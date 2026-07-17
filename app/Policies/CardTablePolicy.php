<?php

namespace App\Policies;

use App\Models\CardTable;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CardTablePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->currentTeam !== null;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, CardTable $cardTable): bool
    {
        return Gate::forUser($user)->allows('view', $cardTable->project);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, Project $project): bool
    {
        return Gate::forUser($user)->allows('view', $project);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, CardTable $cardTable): bool
    {
        return $this->view($user, $cardTable);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CardTable $cardTable): bool
    {
        return Gate::forUser($user)->allows('update', $cardTable->project);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, CardTable $cardTable): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, CardTable $cardTable): bool
    {
        return false;
    }
}
