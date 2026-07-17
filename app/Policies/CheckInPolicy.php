<?php

namespace App\Policies;

use App\Models\CheckIn;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CheckInPolicy
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
    public function view(User $user, CheckIn $checkIn): bool
    {
        return Gate::forUser($user)->allows('view', $checkIn->project);
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
    public function update(User $user, CheckIn $checkIn): bool
    {
        return $checkIn->created_by === $user->id
            || Gate::forUser($user)->allows('update', $checkIn->project);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CheckIn $checkIn): bool
    {
        return $this->update($user, $checkIn);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, CheckIn $checkIn): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, CheckIn $checkIn): bool
    {
        return false;
    }
}
