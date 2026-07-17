<?php

namespace App\Broadcasting;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ProjectChatChannel
{
    /**
     * Authenticate the user's access to the project chat channel.
     */
    public function join(User $user, Project $project): bool
    {
        return Gate::forUser($user)->allows('view', $project);
    }
}
