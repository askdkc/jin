<?php

namespace App\Actions\Todos;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\TodoActivityNotification;
use Illuminate\Support\Facades\DB;

class SetTodoCompletion
{
    public function handle(
        Project $project,
        Todo $todo,
        User $actor,
        bool $completed,
    ): Todo {
        return $this->transition($project, $todo, $actor, $completed);
    }

    public function toggle(Project $project, Todo $todo, User $actor): Todo
    {
        return $this->transition($project, $todo, $actor);
    }

    private function transition(
        Project $project,
        Todo $todo,
        User $actor,
        ?bool $completed = null,
    ): Todo {
        [$todo, $shouldNotifyCompletion] = DB::transaction(
            function () use ($project, $todo, $actor, $completed): array {
                $todo = Todo::query()
                    ->whereHas('todoList', fn ($query) => $query->where('project_id', $project->id))
                    ->lockForUpdate()
                    ->findOrFail($todo->id);
                $wasCompleted = $todo->completed_at !== null;
                $shouldComplete = $completed ?? ! $wasCompleted;

                if ($shouldComplete === $wasCompleted) {
                    return [$todo, false];
                }

                $todo->update($shouldComplete
                    ? ['completed_at' => now(), 'completed_by' => $actor->id]
                    : ['completed_at' => null, 'completed_by' => null]);

                if ($shouldComplete) {
                    Activity::record($project, $actor, ActivityType::TodoCompleted, $todo, $todo->title);
                }

                return [$todo, $shouldComplete];
            },
            attempts: 5,
        );

        if ($shouldNotifyCompletion) {
            TodoActivityNotification::sendToUsers(
                [$todo->created_by, $todo->assignee_id, ...($todo->completion_notify_ids ?? [])],
                $actor,
                $project,
                $todo,
                TodoActivityNotification::Completed,
            );
        }

        return $todo;
    }
}
