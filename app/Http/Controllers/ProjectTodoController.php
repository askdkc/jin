<?php

namespace App\Http\Controllers;

use App\Actions\Todos\CreateTodo;
use App\Actions\Todos\SetTodoCompletion;
use App\Actions\Todos\UpdateTodo;
use App\Enums\ActivityType;
use App\Http\Requests\Phase3\MoveTodoRequest;
use App\Http\Requests\Phase3\SaveMessageBoardPostReactionRequest as SaveReactionRequest;
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
use App\Support\InlineAttachments;
use App\Support\OrderedPositions;
use App\Support\Reactions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectTodoController extends Controller
{
    public function __construct(
        private CreateTodo $createTodo,
        private UpdateTodo $updateTodo,
        private SetTodoCompletion $setTodoCompletion,
    ) {}

    public function index(Team $currentTeam, Project $project): Response
    {
        Gate::authorize('view', $project);

        $canManageProject = Gate::allows('update', $project);
        // The board renders summaries only; detail relations are loaded by show().
        $lists = $project->todoLists()
            ->with(['todos' => fn ($query) => $query
                ->select(['id', 'todo_list_id', 'title', 'assignee_id', 'due_on', 'completed_at', 'position'])
                ->with('assignee:id,name')
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
                    ->map(fn (Todo $todo): array => [
                        'id' => $todo->id,
                        'title' => $todo->title,
                        'completed' => $todo->completed_at !== null,
                        'assigneeName' => $todo->assignee?->name,
                        'dueOn' => $todo->due_on?->toDateString(),
                    ])
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
            'chatChannel:id,name',
            'creator:id,name',
            'comments' => fn ($query) => $query->with('user:id,name')->oldest(),
            'subtasks' => fn ($query) => $query->with('assignee:id,name')->orderBy('position'),
            'attachments' => fn ($query) => $query->with('uploader:id,name')->oldest(),
            'reactors:id,name',
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
        DB::transaction(function () use ($request, $project): void {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $lastPosition = $project->todoLists()->max('position');
            $project->todoLists()->create([
                ...$request->validated(),
                'position' => $lastPosition === null ? 0 : ((int) $lastPosition) + 1,
            ]);
        }, attempts: 5);

        return back(fallback: route('projects.todos.index', [$currentTeam, $project]));
    }

    public function updateList(
        SaveTodoListRequest $request,
        Team $currentTeam,
        Project $project,
        TodoList $todoList,
    ): RedirectResponse {
        $todoList->update($request->validated());

        return back(fallback: route('projects.todos.index', [$currentTeam, $project]));
    }

    public function destroyList(Team $currentTeam, Project $project, TodoList $todoList): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_if($todoList->is_loose, 422, 'The loose to-do list cannot be deleted.');
        DB::transaction(function () use ($project, $todoList): void {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $todoList = $project->todoLists()->lockForUpdate()->findOrFail($todoList->id);
            $todoIds = $todoList->todos()->orderBy('id')->lockForUpdate()->pluck('id');
            $commentQuery = Comment::query()->where(function ($query) use ($todoIds, $todoList): void {
                $query->where(function ($todoComments) use ($todoIds): void {
                    $todoComments->where('commentable_type', (new Todo)->getMorphClass())
                        ->whereIn('commentable_id', $todoIds);
                })->orWhere(function ($listComments) use ($todoList): void {
                    $listComments->where('commentable_type', $todoList->getMorphClass())
                        ->where('commentable_id', $todoList->id);
                });
            });

            InlineAttachments::detachMany((new Todo)->getMorphClass(), $todoIds);
            InlineAttachments::detachMany((new Comment)->getMorphClass(), (clone $commentQuery)->pluck('id'));
            $commentQuery->delete();
            $todoList->delete();
            OrderedPositions::resequence(
                new TodoList,
                $project->todoLists()->orderBy('position')->orderBy('id')->pluck('id'),
            );
        }, attempts: 5);

        return back(fallback: route('projects.todos.index', [$currentTeam, $project]));
    }

    public function storeTodo(
        SaveTodoRequest $request,
        Team $currentTeam,
        Project $project,
        TodoList $todoList,
    ): RedirectResponse {
        $this->createTodo->handle(
            $project,
            $todoList,
            $request->user(),
            $request->validated(),
        );

        return back(fallback: route('projects.todos.index', [$currentTeam, $project]));
    }

    public function updateTodo(
        SaveTodoRequest $request,
        Team $currentTeam,
        Project $project,
        TodoList $todoList,
        Todo $todo,
    ): RedirectResponse {
        $this->updateTodo->handle(
            $project,
            $todo,
            $request->user(),
            $request->validated(),
        );

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
            $todo = Todo::query()
                ->where('todo_list_id', $todoList->id)
                ->whereNull('parent_id')
                ->lockForUpdate()
                ->findOrFail($todo->id);
            $lastPosition = $todo->subtasks()->max('position');
            $subtask = $todo->subtasks()->create([
                ...$request->validated(),
                'todo_list_id' => $todoList->id,
                'created_by' => $request->user()->id,
                'position' => $lastPosition === null ? 0 : ((int) $lastPosition) + 1,
            ]);

            Activity::record($project, $request->user(), ActivityType::TodoCreated, $subtask, $subtask->title);
        }, attempts: 5);

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

        $this->setTodoCompletion->toggle($project, $todo, $request->user());

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

        DB::transaction(function () use ($project, $targetList, $requestedPosition, $todoList, $todo): void {
            $listIds = array_values(array_unique([$todoList->id, $targetList->id]));
            $lockedListIds = TodoList::query()
                ->where('project_id', $project->id)
                ->whereIn('id', $listIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->all();
            abort_unless(count($lockedListIds) === count($listIds), 404);

            $todo = Todo::query()
                ->where('todo_list_id', $todoList->id)
                ->whereNull('parent_id')
                ->lockForUpdate()
                ->findOrFail($todo->id);
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
        }, attempts: 5);

        return to_route('projects.todos.index', [$currentTeam, $project]);
    }

    public function toggleReaction(
        SaveReactionRequest $request,
        Team $currentTeam,
        Project $project,
        TodoList $todoList,
        Todo $todo,
    ): RedirectResponse {
        Gate::authorize('view', $project);

        Reactions::toggle(
            $todo->reactors(),
            $request->user()->id,
            (string) $request->validated('reaction'),
        );

        return back(fallback: route('projects.todos.show', [$currentTeam, $project, $todoList, $todo]));
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

        DB::transaction(function () use ($todoList, $todo, $parentId): void {
            if ($parentId === null) {
                $todoList = TodoList::query()->lockForUpdate()->findOrFail($todoList->id);
            } else {
                Todo::query()
                    ->where('todo_list_id', $todoList->id)
                    ->lockForUpdate()
                    ->findOrFail($parentId);
            }

            $todo = Todo::query()
                ->where('todo_list_id', $todoList->id)
                ->lockForUpdate()
                ->findOrFail($todo->id);
            $todoIds = $todo->subtasks()
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->push($todo->id);
            $commentQuery = Comment::query()
                ->where('commentable_type', (new Todo)->getMorphClass())
                ->whereIn('commentable_id', $todoIds);

            InlineAttachments::detachMany((new Comment)->getMorphClass(), (clone $commentQuery)->pluck('id'));
            InlineAttachments::detachMany((new Todo)->getMorphClass(), $todoIds);
            $commentQuery->delete();
            $todo->delete();
            $remainingTodoIds = Todo::query()
                ->where('todo_list_id', $todoList->id)
                ->when(
                    $parentId === null,
                    fn ($query) => $query->whereNull('parent_id'),
                    fn ($query) => $query->where('parent_id', $parentId),
                )
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('id');
            OrderedPositions::resequence(new Todo, $remainingTodoIds);
        }, attempts: 5);

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
        OrderedPositions::resequence(new Todo, $todoIds);
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
            'chatChannel' => $todo->chatChannel === null
                ? null
                : [
                    'id' => $todo->chatChannel->id,
                    'name' => $todo->chatChannel->name,
                ],
            'completed' => $todo->completed_at !== null,
            'creatorName' => $todo->creator?->name,
            'createdAt' => $todo->created_at?->toISOString(),
            'canEdit' => $canManageProject || $todo->created_by === $userId,
            'reactions' => Reactions::grouped($todo->reactors, $userId),
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
                'assigneeId' => $subtask->assignee_id,
                'assigneeName' => $subtask->assignee?->name,
                'dueOn' => $subtask->due_on?->toDateString(),
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
}
