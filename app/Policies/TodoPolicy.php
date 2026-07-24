<?php

namespace App\Policies;

use App\Models\Todo;
use App\Models\User;

class TodoPolicy
{
    public function __construct(private ProjectPolicy $projects) {}

    public function update(User $user, Todo $todo): bool
    {
        $project = $todo->todoList->project;

        return $project->isVisibleTo($user)
            && ($todo->created_by === $user->id
                || $this->projects->update($user, $project));
    }
}
