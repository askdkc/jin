<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Http\Requests\Phase3\MoveTodoRequest;
use App\Http\Requests\Phase3\SaveTodoListRequest;
use App\Http\Requests\Phase3\SaveTodoRequest;
use App\Http\Requests\Phase3\StoreSubtaskRequest;
use App\Models\Activity;
use App\Models\Comment;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use App\Notifications\MentionNotification;
use App\Notifications\TodoActivityNotification;
use App\Support\InlineAttachments;
use App\Support\Mentions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectTodoController extends Controller
{
    public function index(Team $currentTeam, Project $project): Response
    {
        Gate::authorize('view', $project);

        $canManageProject = Gate::allows('update', $project);
        $lists = $project->todoLists()
            ->with(['todos' => fn ($query) => $query
                ->with([
                    'assignee:id,name',
                    'creator:id,name',
                    'comments' => fn ($commentQuery) => $commentQuery->with('user:id,name')->oldest(),
                    'subtasks' => fn ($subtaskQuery) => $subtaskQuery->orderBy('position'),
                    'attachments' => fn ($attachmentQuery) => $attachmentQuery->with('uploader:id,name')->oldest(),
                ])
                ->whereNull('parent_id')
                ->orderBy('position')])
            ->orderByDesc('is_loose')
            ->orderBy('position')
            ->get()
            ->map(fn (TodoList $list): array => [
                'id' => $list->id,
                'title' => $list->title,
                'isLoose' => $list->is_loose,
                'canDelete' => $canManageProject,
                'todos' => $list->todos
                    ->map(fn (Todo $todo): array => $this->todoData(
                        $todo,
                        $canManageProject,
                        request()->user()->id,
                    ))
                    ->all(),
            ]);

        return Inertia::render('projects/todos/Index', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'lists' => $lists,
            'members' => $project->members()
                ->orderBy('name')
                ->get(['users.id', 'users.name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
        ]);
    }

    public function show(
        Team $currentTeam,
        Project $project,
        TodoList $todoList,
        Todo $todo,
    ): Response {
        Gate::authorize('view', $project);
        abort_if($todo->parent_id !== null, 404);

        $canManageProject = Gate::allows('update', $project);
        $todo->load([
            'assignee:id,name',
            'creator:id,name',
            'comments' => fn ($query) => $query->with('user:id,name')->oldest(),
            'subtasks' => fn ($query) => $query->orderBy('position'),
            'attachments' => fn ($query) => $query->with('uploader:id,name')->oldest(),
        ]);

        return Inertia::render('projects/todos/Show', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'list' => ['id' => $todoList->id, 'title' => $todoList->title],
            'todo' => $this->todoData(
                $todo,
                $canManageProject,
                request()->user()->id,
            ),
            'members' => $project->members()
                ->orderBy('name')
                ->get(['users.id', 'users.name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
        ]);
    }

    public function showList(Team $currentTeam, Project $project, TodoList $todoList): Response
    {
        Gate::authorize('view', $project);
        abort_unless($todoList->project_id === $project->id, 404);

        $canManageProject = Gate::allows('update', $project);
        $userId = request()->user()->id;
        $todoList->load([
            'todos' => fn ($query) => $query
                ->with('assignee:id,name')
                ->whereNull('parent_id')
                ->orderBy('position'),
            'comments' => fn ($query) => $query->with('user:id,name')->oldest(),
            'subscribers:id,name',
        ]);

        return Inertia::render('projects/todos/ListShow', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'list' => [
                'id' => $todoList->id,
                'title' => $todoList->title,
                'canDelete' => $canManageProject,
                'subscribed' => $todoList->subscribers->contains('id', $userId),
                'subscriberCount' => $todoList->subscribers->count(),
            ],
            'todos' => $todoList->todos->map(fn (Todo $todo): array => [
                'id' => $todo->id,
                'title' => $todo->title,
                'completed' => $todo->completed_at !== null,
                'assigneeName' => $todo->assignee?->name,
                'dueOn' => $todo->due_on?->toDateString(),
                'hasNotes' => filled($todo->details),
            ])->all(),
            'comments' => $todoList->comments->map(fn (Comment $comment): array => [
                'id' => $comment->id,
                'body' => $comment->body,
                'authorName' => $comment->user?->name,
                'createdAt' => $comment->created_at?->toISOString(),
                'canDelete' => $canManageProject || $comment->user_id === $userId,
            ])->all(),
            'members' => $project->members()
                ->orderBy('name')
                ->get(['users.id', 'users.name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
        ]);
    }

    public function toggleListSubscription(Team $currentTeam, Project $project, TodoList $todoList): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($todoList->project_id === $project->id, 404);

        $userId = request()->user()->id;

        if ($todoList->subscribers()->whereKey($userId)->exists()) {
            $todoList->subscribers()->detach($userId);
        } else {
            $todoList->subscribers()->attach($userId);
        }

        return back(fallback: route('projects.todo-lists.show', [$currentTeam, $project, $todoList]));
    }

    public function storeList(SaveTodoListRequest $request, Team $currentTeam, Project $project): RedirectResponse
    {
        $project->todoLists()->create([
            ...$request->validated(),
            'position' => ((int) $project->todoLists()->max('position')) + 1,
        ]);

        return back(fallback: route('projects.todos.index', [$currentTeam, $project]));
    }

    public function destroyList(Team $currentTeam, Project $project, TodoList $todoList): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_if($todoList->is_loose, 422, 'The loose to-do list cannot be deleted.');
        DB::transaction(function () use ($todoList): void {
            StoredFile::query()
                ->where('attachable_type', Todo::class)
                ->whereIn('attachable_id', $todoList->todos()->select('id'))
                ->update(['attachable_type' => null, 'attachable_id' => null]);
            Comment::query()
                ->where('commentable_type', Todo::class)
                ->whereIn('commentable_id', $todoList->todos()->select('id'))
                ->delete();
            $todoList->delete();
        });

        return back(fallback: route('projects.todos.index', [$currentTeam, $project]));
    }

    public function storeTodo(
        SaveTodoRequest $request,
        Team $currentTeam,
        Project $project,
        TodoList $todoList,
    ): RedirectResponse {
        $todo = DB::transaction(function () use ($request, $project, $todoList): Todo {
            $todo = $todoList->todos()->create([
                ...$request->safe()->except('subtasks'),
                'created_by' => $request->user()->id,
                'position' => ((int) $todoList->todos()->max('position')) + 1,
            ]);

            foreach (array_values($request->validated('subtasks') ?? []) as $index => $subtaskTitle) {
                $todo->subtasks()->create([
                    'todo_list_id' => $todoList->id,
                    'title' => $subtaskTitle,
                    'created_by' => $request->user()->id,
                    'position' => $index + 1,
                ]);
            }

            InlineAttachments::syncFromBody(
                $todo,
                $project,
                $request->user()->id,
                $todo->details,
            );

            Activity::record($project, $request->user(), ActivityType::TodoCreated, $todo, $todo->title);

            return $todo;
        });

        TodoActivityNotification::sendToUsers(
            [$todo->assignee_id],
            $request->user(),
            $project,
            $todo,
            TodoActivityNotification::Assigned,
        );

        $this->notifyMentions($project, $request->user(), $todo);

        return back(fallback: route('projects.todos.index', [$currentTeam, $project]));
    }

    public function updateTodo(
        SaveTodoRequest $request,
        Team $currentTeam,
        Project $project,
        TodoList $todoList,
        Todo $todo,
    ): RedirectResponse {
        $previousAssigneeId = $todo->assignee_id;
        $previousDetails = $todo->details;
        $todo->update([
            ...$request->safe()->except('subtasks'),
            'completion_notify_ids' => $request->validated('completion_notify_ids') ?? [],
        ]);

        if ($todo->assignee_id !== $previousAssigneeId) {
            TodoActivityNotification::sendToUsers(
                [$todo->assignee_id],
                $request->user(),
                $project,
                $todo,
                TodoActivityNotification::Assigned,
            );
        }

        // ponytail: only re-notify mentions when the notes actually changed, so inline
        // per-field edits (due date, assignee…) don't re-ping everyone mentioned in the notes.
        if ($todo->details !== $previousDetails) {
            $this->notifyMentions($project, $request->user(), $todo);
        }

        return back(fallback: route('projects.todos.index', [$currentTeam, $project]));
    }

    public function storeSubtask(
        StoreSubtaskRequest $request,
        Team $currentTeam,
        Project $project,
        TodoList $todoList,
        Todo $todo,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $project, $todoList, $todo): void {
            $subtask = $todo->subtasks()->create([
                ...$request->validated(),
                'todo_list_id' => $todoList->id,
                'created_by' => $request->user()->id,
                'position' => ((int) $todo->subtasks()->max('position')) + 1,
            ]);

            Activity::record($project, $request->user(), ActivityType::TodoCreated, $subtask, $subtask->title);
        });

        return back(fallback: route('projects.todos.index', [$currentTeam, $project]));
    }

    public function toggleTodo(
        Request $request,
        Team $currentTeam,
        Project $project,
        TodoList $todoList,
        Todo $todo,
    ): RedirectResponse {
        Gate::authorize('view', $project);

        $wasIncomplete = $todo->completed_at === null;

        DB::transaction(function () use ($request, $project, $todo, $wasIncomplete): void {
            $todo->update($wasIncomplete
                ? ['completed_at' => now(), 'completed_by' => $request->user()->id]
                : ['completed_at' => null, 'completed_by' => null]);

            if ($wasIncomplete) {
                Activity::record($project, $request->user(), ActivityType::TodoCompleted, $todo, $todo->title);
            }
        });

        if ($wasIncomplete) {
            TodoActivityNotification::sendToUsers(
                [$todo->created_by, $todo->assignee_id, ...($todo->completion_notify_ids ?? [])],
                $request->user(),
                $project,
                $todo,
                TodoActivityNotification::Completed,
            );
        }

        return back(fallback: route('projects.todos.index', [$currentTeam, $project]));
    }

    public function moveTodo(
        MoveTodoRequest $request,
        Team $currentTeam,
        Project $project,
        TodoList $todoList,
        Todo $todo,
    ): RedirectResponse {
        $targetList = $project->todoLists()->findOrFail((int) $request->validated('target_list_id'));
        $requestedPosition = $request->validated('target_position');

        DB::transaction(function () use ($targetList, $requestedPosition, $todoList, $todo): void {
            $sourceTodoIds = Todo::query()
                ->where('todo_list_id', $todoList->id)
                ->whereNull('parent_id')
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->reject(fn (int $id): bool => $id === $todo->id)
                ->values();

            if ($targetList->is($todoList)) {
                $targetTodoIds = $sourceTodoIds;
            } else {
                $targetTodoIds = Todo::query()
                    ->where('todo_list_id', $targetList->id)
                    ->whereNull('parent_id')
                    ->orderBy('position')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id')
                    ->values();

                $todo->update(['todo_list_id' => $targetList->id]);
                $todo->subtasks()->update(['todo_list_id' => $targetList->id]);
                $this->normalizeTodoPositions($sourceTodoIds);
            }

            $targetPosition = min(
                $requestedPosition === null ? $targetTodoIds->count() : (int) $requestedPosition,
                $targetTodoIds->count(),
            );
            $targetTodoIds->splice($targetPosition, 0, [$todo->id]);
            $this->normalizeTodoPositions($targetTodoIds);
        }, attempts: 3);

        return to_route('projects.todos.index', [$currentTeam, $project]);
    }

    public function destroyTodo(
        Team $currentTeam,
        Project $project,
        TodoList $todoList,
        Todo $todo,
    ): RedirectResponse {
        Gate::authorize('view', $project);
        abort_unless($todo->created_by === request()->user()->id || Gate::allows('update', $project), 403);
        $parentId = $todo->parent_id;

        DB::transaction(function () use ($todo): void {
            $todoIds = $todo->subtasks()->pluck('id')->push($todo->id);

            Comment::query()
                ->where('commentable_type', Todo::class)
                ->whereIn('commentable_id', $todoIds)
                ->delete();
            StoredFile::query()
                ->where('attachable_type', Todo::class)
                ->whereIn('attachable_id', $todoIds)
                ->update(['attachable_type' => null, 'attachable_id' => null]);
            $todo->delete();
        });

        if ($parentId !== null) {
            return back(fallback: route('projects.todos.show', [
                $currentTeam,
                $project,
                $todoList,
                $parentId,
            ]));
        }

        return to_route('projects.todos.index', [$currentTeam, $project]);
    }

    /** @param Collection<int, int> $todoIds */
    private function normalizeTodoPositions(Collection $todoIds): void
    {
        $todoIds->each(function (int $todoId, int $position): void {
            Todo::query()->whereKey($todoId)->update(['position' => $position]);
        });
    }

    /** @return array<string, mixed> */
    private function todoData(Todo $todo, bool $canManageProject, int $userId): array
    {
        return [
            'id' => $todo->id,
            'title' => $todo->title,
            'details' => $todo->details,
            'assigneeId' => $todo->assignee_id,
            'assigneeName' => $todo->assignee?->name,
            'completionNotifyIds' => $todo->completion_notify_ids ?? [],
            'dueOn' => $todo->due_on?->toDateString(),
            'completed' => $todo->completed_at !== null,
            'creatorName' => $todo->creator?->name,
            'createdAt' => $todo->created_at?->toISOString(),
            'canEdit' => $canManageProject || $todo->created_by === $userId,
            'attachments' => $todo->attachments->map(fn (StoredFile $file): array => [
                'id' => $file->id,
                'name' => $file->original_name,
                'mimeType' => $file->mime_type,
                'size' => $file->size,
                'uploaderName' => $file->uploader?->name,
                'createdAt' => $file->created_at?->toISOString(),
                'canDelete' => $canManageProject || $file->uploaded_by === $userId,
            ])->all(),
            'subtasks' => $todo->subtasks->map(fn (Todo $subtask): array => [
                'id' => $subtask->id,
                'title' => $subtask->title,
                'completed' => $subtask->completed_at !== null,
                'canEdit' => $canManageProject || $subtask->created_by === $userId,
            ])->all(),
            'comments' => $todo->comments->map(fn (Comment $comment): array => [
                'id' => $comment->id,
                'body' => $comment->body,
                'authorName' => $comment->user?->name,
                'createdAt' => $comment->created_at?->toISOString(),
                'canDelete' => $canManageProject || $comment->user_id === $userId,
            ])->all(),
        ];
    }

    private function notifyMentions(Project $project, User $actor, Todo $todo): void
    {
        MentionNotification::sendToUsers(
            Mentions::extract($todo->details, $project->members),
            $actor,
            $project,
            MentionNotification::Todo,
            $todo->title,
        );
    }
}
