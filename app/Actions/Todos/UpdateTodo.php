<?php

namespace App\Actions\Todos;

use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\MentionNotification;
use App\Notifications\TodoActivityNotification;
use App\Support\InlineAttachments;
use App\Support\Mentions;
use Illuminate\Support\Facades\DB;

class UpdateTodo
{
    /**
     * @param  array{
     *     title?: string,
     *     details?: string|null,
     *     assignee_id?: int|null,
     *     due_on?: string|null,
     *     chat_channel_id?: int|null,
     *     completion_notify_ids?: array<int, int>|null
     * }  $changes
     */
    public function handle(
        Project $project,
        Todo $todo,
        User $actor,
        array $changes,
    ): Todo {
        [$todo, $assigneeChanged, $detailsChanged] = DB::transaction(
            function () use ($project, $todo, $actor, $changes): array {
                $todo = Todo::query()
                    ->whereHas('todoList', fn ($query) => $query->where('project_id', $project->id))
                    ->lockForUpdate()
                    ->findOrFail($todo->id);
                $todo->fill($changes);
                $assigneeChanged = $todo->isDirty('assignee_id');
                $detailsChanged = $todo->isDirty('details');
                $todo->save();

                if ($detailsChanged) {
                    InlineAttachments::syncFromBody($todo, $project, $actor->id, $todo->details);
                }

                return [$todo, $assigneeChanged, $detailsChanged];
            },
            attempts: 5,
        );

        if ($assigneeChanged) {
            TodoActivityNotification::sendToUsers(
                [$todo->assignee_id],
                $actor,
                $project,
                $todo,
                TodoActivityNotification::Assigned,
            );
        }

        if ($detailsChanged) {
            MentionNotification::sendToUsers(
                Mentions::extract($todo->details, $project->members),
                $actor,
                $project,
                MentionNotification::Todo,
                $todo->title,
            );
        }

        return $todo;
    }
}
