<?php

namespace App\Policies;

use App\Models\MaterialFolder;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class MaterialFolderPolicy
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
    public function view(User $user, MaterialFolder $materialFolder): bool
    {
        return Gate::forUser($user)->allows('view', $materialFolder->project);
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
    public function update(User $user, MaterialFolder $materialFolder): bool
    {
        return $materialFolder->created_by === $user->id
            || Gate::forUser($user)->allows('update', $materialFolder->project);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, MaterialFolder $materialFolder): bool
    {
        return $this->update($user, $materialFolder);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, MaterialFolder $materialFolder): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, MaterialFolder $materialFolder): bool
    {
        return false;
    }
}
