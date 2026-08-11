<?php

namespace App\Actions\Todos;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use App\Notifications\MentionNotification;
use App\Notifications\TodoActivityNotification;
use App\Support\InlineAttachments;
use App\Support\Mentions;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreateTodo
{
    /** @param array<string, mixed> $attributes */
    public function handle(
        Project $project,
        TodoList $todoList,
        User $actor,
        array $attributes,
    ): Todo {
        $subtasks = $attributes['subtasks'] ?? [];

        if (! is_array($subtasks)) {
            throw new InvalidArgumentException('Todo subtasks must be an array.');
        }

        $subtasks = array_values($subtasks);
        unset($attributes['subtasks']);

        $todo = DB::transaction(function () use ($project, $todoList, $actor, $attributes, $subtasks): Todo {
            $todoList = TodoList::query()
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->findOrFail($todoList->id);
            $lastPosition = $todoList->todos()->whereNull('parent_id')->max('position');
            $todo = $todoList->todos()->create([
                ...$attributes,
                'created_by' => $actor->id,
                'position' => $lastPosition === null ? 0 : ((int) $lastPosition) + 1,
            ]);

            foreach ($subtasks as $index => $subtaskAttributes) {
                if (! is_array($subtaskAttributes)) {
                    throw new InvalidArgumentException('Each todo subtask must be an array.');
                }

                $todo->subtasks()->create([
                    ...$subtaskAttributes,
                    'todo_list_id' => $todoList->id,
                    'created_by' => $actor->id,
                    'position' => $index,
                ]);
            }

            InlineAttachments::syncFromBody($todo, $project, $actor->id, $todo->details);
            Activity::record($project, $actor, ActivityType::TodoCreated, $todo, $todo->title);

            return $todo;
        }, attempts: 5);

        TodoActivityNotification::sendToUsers(
            [$todo->assignee_id],
            $actor,
            $project,
            $todo,
            TodoActivityNotification::Assigned,
        );

        MentionNotification::sendToUsers(
            Mentions::extract($todo->details, $project->members),
            $actor,
            $project,
            MentionNotification::Todo,
            $todo->title,
            $todo->id,
        );

        return $todo;
    }
}
