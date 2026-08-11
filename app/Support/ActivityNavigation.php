<?php

namespace App\Support;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Comment;
use App\Models\Todo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ActivityNavigation
{
    /**
     * @param  Collection<int, Activity>  $activities
     * @return array<int, array{todoId: int, todoListId: int}>
     */
    public static function todoCommentTargets(Collection $activities): array
    {
        $commentMorphClass = (new Comment)->getMorphClass();
        $todoCommentActivities = $activities
            ->filter(fn (Activity $activity): bool => $activity->type === ActivityType::TodoCommentCreated
                && $activity->subject_type === $commentMorphClass);
        $activitiesByCommentId = $todoCommentActivities->groupBy('subject_id');

        if ($activitiesByCommentId->isEmpty()) {
            return [];
        }

        $targetsByCommentId = DB::table('comments')
            ->join('todos', 'todos.id', '=', 'comments.commentable_id')
            ->join('todo_lists', 'todo_lists.id', '=', 'todos.todo_list_id')
            ->where('comments.commentable_type', (new Todo)->getMorphClass())
            ->whereColumn('comments.project_id', 'todo_lists.project_id')
            ->whereIn('comments.id', $activitiesByCommentId->keys())
            ->get([
                'comments.id as comment_id',
                'comments.project_id',
                'todos.id as todo_id',
                'todos.todo_list_id',
            ])
            ->keyBy('comment_id');

        return $todoCommentActivities
            ->mapWithKeys(function (Activity $activity) use ($targetsByCommentId): array {
                $target = $targetsByCommentId->get($activity->subject_id);

                if ($target === null || (int) $target->project_id !== $activity->project_id) {
                    return [];
                }

                return [
                    $activity->id => [
                        'todoId' => (int) $target->todo_id,
                        'todoListId' => (int) $target->todo_list_id,
                    ],
                ];
            })
            ->all();
    }
}
