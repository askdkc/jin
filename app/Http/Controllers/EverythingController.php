<?php

namespace App\Http\Controllers;

use App\Http\Requests\Phase10\EverythingIndexRequest;
use App\Models\CardColumn;
use App\Models\CheckInPrompt;
use App\Models\Comment;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use App\Support\RichText;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EverythingController extends Controller
{
    public function __invoke(EverythingIndexRequest $request, Team $currentTeam): Response
    {
        $filters = $request->validated();
        $search = isset($filters['q']) && trim((string) $filters['q']) !== ''
            ? trim((string) $filters['q'])
            : null;
        $type = $filters['type'] ?? null;
        $projectId = isset($filters['project']) ? (int) $filters['project'] : null;
        $user = $request->user();

        $queries = collect([
            'message' => $this->messageQuery($currentTeam, $user, $search, $projectId),
            'todo' => $this->todoQuery($currentTeam, $user, $search, $projectId),
            'document' => $this->documentQuery($currentTeam, $user, $search, $projectId),
            'file' => $this->fileQuery($currentTeam, $user, $search, $projectId),
            'link' => $this->linkQuery($currentTeam, $user, $search, $projectId),
            'comment' => $this->commentQuery($currentTeam, $user, $search, $projectId),
        ])->when($type !== null, fn ($collection) => $collection->only((string) $type));

        /** @var Builder $union */
        $union = $queries->shift();

        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        $results = DB::query()
            ->fromSub($union, 'everything')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->orderBy('type')
            ->paginate(20)
            ->withQueryString();
        $projectIds = collect($results->items())
            ->map(fn (object $item): int => (int) get_object_vars($item)['project_id'])
            ->unique()
            ->values()
            ->all();
        $projects = Project::query()
            ->whereKey($projectIds)
            ->get(['id', 'enabled_tools'])
            ->keyBy('id');
        $fileIds = collect($results->items())
            ->map(fn (object $item): array => get_object_vars($item))
            ->filter(fn (array $item): bool => $item['type'] === 'file')
            ->map(fn (array $item): int => (int) $item['id'])
            ->all();
        $files = StoredFile::query()
            ->with('uploader:id,name,avatar_path')
            ->whereKey($fileIds)
            ->get([
                'id',
                'project_id',
                'uploaded_by',
                'mime_type',
                'size',
                'preview_path',
                'preview_status',
                'created_at',
            ])
            ->keyBy('id');
        $commentIds = collect($results->items())
            ->map(fn (object $item): array => get_object_vars($item))
            ->filter(fn (array $item): bool => $item['type'] === 'comment')
            ->map(fn (array $item): int => (int) $item['id'])
            ->all();
        $comments = Comment::query()
            ->whereKey($commentIds)
            ->with(['commentable' => function ($relation): void {
                if (! $relation instanceof MorphTo) {
                    return;
                }

                $relation->morphWith([
                    Todo::class => ['todoList'],
                    CheckInPrompt::class => ['checkIn'],
                    CardColumn::class => ['cardTable'],
                ]);
            }])
            ->get()
            ->keyBy('id');

        $results->through(function (object $item) use ($comments, $currentTeam, $files, $projects): array {
            $values = get_object_vars($item);
            $project = $projects->get((int) $values['project_id']);
            $file = $values['type'] === 'file'
                ? $files->get((int) $values['id'])
                : null;
            $comment = $values['type'] === 'comment'
                ? $comments->get((int) $values['id'])
                : null;

            return $this->transformResult(
                $item,
                $file instanceof StoredFile ? $file : null,
                $comment instanceof Comment ? $comment : null,
                $project instanceof Project ? $project : null,
                $currentTeam,
            );
        });

        return Inertia::render('everything/Index', [
            'results' => $results,
            'projects' => $this->accessibleProjects($currentTeam, $user),
            'filters' => [
                'q' => $search,
                'type' => $type,
                'project' => $projectId,
            ],
        ]);
    }

    private function messageQuery(Team $team, User $user, ?string $search, ?int $projectId): Builder
    {
        $query = DB::table('message_board_posts')
            ->join('projects', 'projects.id', '=', 'message_board_posts.project_id')
            ->select([
                'message_board_posts.id',
                'message_board_posts.title',
                'message_board_posts.body as snippet',
                'message_board_posts.project_id',
                'projects.name as project_name',
                'message_board_posts.updated_at',
            ])
            ->selectRaw('? as type, NULL as route_parent_id, NULL as route_resource_id', ['message']);

        $this->applySearch($query, $search, ['message_board_posts.title', 'message_board_posts.body']);

        return $this->scopeProjectAccess($query, $team, $user, $projectId);
    }

    private function todoQuery(Team $team, User $user, ?string $search, ?int $projectId): Builder
    {
        $query = DB::table('todos')
            ->join('todo_lists', 'todo_lists.id', '=', 'todos.todo_list_id')
            ->join('projects', 'projects.id', '=', 'todo_lists.project_id')
            ->select([
                'todos.id',
                'todos.title',
                'todos.details as snippet',
                'projects.id as project_id',
                'projects.name as project_name',
                'todos.updated_at',
            ])
            ->selectRaw('? as type', ['todo'])
            ->addSelect('todos.todo_list_id as route_parent_id')
            ->selectRaw('COALESCE(todos.parent_id, todos.id) as route_resource_id');

        $this->applySearch($query, $search, ['todos.title', 'todos.details']);

        return $this->scopeProjectAccess($query, $team, $user, $projectId);
    }

    private function documentQuery(Team $team, User $user, ?string $search, ?int $projectId): Builder
    {
        $query = DB::table('documents')
            ->join('projects', 'projects.id', '=', 'documents.project_id')
            ->select([
                'documents.id',
                'documents.title',
                'documents.body as snippet',
                'documents.project_id',
                'projects.name as project_name',
                'documents.updated_at',
            ])
            ->selectRaw('? as type, NULL as route_parent_id, NULL as route_resource_id', ['document']);

        $this->applySearch($query, $search, ['documents.title', 'documents.body']);

        return $this->scopeProjectAccess($query, $team, $user, $projectId);
    }

    private function fileQuery(Team $team, User $user, ?string $search, ?int $projectId): Builder
    {
        $query = DB::table('stored_files')
            ->join('projects', 'projects.id', '=', 'stored_files.project_id')
            ->select([
                'stored_files.id',
                'stored_files.original_name as title',
                'stored_files.mime_type as snippet',
                'stored_files.project_id',
                'projects.name as project_name',
                'stored_files.updated_at',
            ])
            ->selectRaw('? as type, NULL as route_parent_id, NULL as route_resource_id', ['file']);

        $this->applySearch($query, $search, ['stored_files.original_name']);

        return $this->scopeProjectAccess($query, $team, $user, $projectId);
    }

    private function linkQuery(Team $team, User $user, ?string $search, ?int $projectId): Builder
    {
        $query = DB::table('external_links')
            ->join('projects', 'projects.id', '=', 'external_links.project_id')
            ->select([
                'external_links.id',
                'external_links.title',
                'external_links.url as snippet',
                'external_links.project_id',
                'projects.name as project_name',
                'external_links.updated_at',
            ])
            ->selectRaw('? as type, NULL as route_parent_id, NULL as route_resource_id', ['link']);

        $this->applySearch($query, $search, ['external_links.title', 'external_links.url']);

        return $this->scopeProjectAccess($query, $team, $user, $projectId);
    }

    private function commentQuery(Team $team, User $user, ?string $search, ?int $projectId): Builder
    {
        $query = DB::table('comments')
            ->join('projects', 'projects.id', '=', 'comments.project_id')
            ->select('comments.id')
            ->selectRaw('? as title', [__('Comment')])
            ->addSelect([
                'comments.body as snippet',
                'comments.project_id',
                'projects.name as project_name',
                'comments.updated_at',
            ])
            ->selectRaw('? as type, NULL as route_parent_id, NULL as route_resource_id', ['comment']);

        $this->applySearch($query, $search, ['comments.body']);

        return $this->scopeProjectAccess($query, $team, $user, $projectId);
    }

    /**
     * @param  list<string>  $columns
     */
    private function applySearch(Builder $query, ?string $search, array $columns): void
    {
        if ($search === null) {
            return;
        }

        $query->where(function (Builder $searchQuery) use ($search, $columns): void {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $searchQuery->{$method}($column, 'like', "%{$search}%");
            }
        });
    }

    private function scopeProjectAccess(
        Builder $query,
        Team $team,
        User $user,
        ?int $projectId,
    ): Builder {
        return $query
            ->where('projects.team_id', $team->id)
            ->whereNull('projects.archived_at')
            ->when($projectId !== null, fn (Builder $projectQuery) => $projectQuery->where('projects.id', $projectId))
            ->whereIn(
                'projects.id',
                Project::query()->visibleTo($user, $team)->select('projects.id'),
            );
    }

    /**
     * @return array{
     *     id: int,
     *     type: string,
     *     title: string,
     *     href: string,
     *     project: array{id: int, name: string},
     *     updatedAt: string,
     *     snippet?: string,
     *     mimeType?: string|null,
     *     thumbnailUrl?: string|null,
     *     canAccessFile?: bool,
     *     size?: int,
     *     uploader?: array{id: int, name: string, avatar: string|null}|null,
     *     createdAt?: string|null
     * }
     */
    private function transformResult(
        object $item,
        ?StoredFile $file,
        ?Comment $comment,
        ?Project $project,
        Team $team,
    ): array {
        $values = get_object_vars($item);
        $canAccessFile = $values['type'] === 'file'
            && $project?->id === (int) $values['project_id']
            && $project->usesTool('materials');
        $result = [
            'id' => (int) $values['id'],
            'type' => (string) $values['type'],
            'title' => (string) $values['title'],
            'href' => $this->resultHref($values, $comment, $project, $team),
            'project' => [
                'id' => (int) $values['project_id'],
                'name' => (string) $values['project_name'],
            ],
            'updatedAt' => (string) $values['updated_at'],
        ];

        if ($values['type'] !== 'file') {
            return [
                ...$result,
                'snippet' => Str::limit(RichText::plainText((string) $values['snippet']), 240),
            ];
        }

        $fallbackMimeType = $values['snippet'] !== null
            ? (string) $values['snippet']
            : null;

        if ($file === null) {
            return [
                ...$result,
                'mimeType' => $fallbackMimeType,
                'thumbnailUrl' => null,
                'canAccessFile' => $canAccessFile,
                'size' => 0,
                'uploader' => null,
                'createdAt' => null,
            ];
        }

        return [
            ...$result,
            'mimeType' => $file->mime_type ?? $fallbackMimeType,
            'thumbnailUrl' => $canAccessFile
                && $file->preview_status === 'ready'
                && $file->preview_path !== null
                    ? route('projects.files.thumbnail', [$team, $project, $file], absolute: false)
                    : null,
            'canAccessFile' => $canAccessFile,
            'size' => $file->size,
            'uploader' => $file->uploader === null
                ? null
                : [
                    'id' => $file->uploader->id,
                    'name' => $file->uploader->name,
                    'avatar' => $file->uploader->avatar,
                ],
            'createdAt' => $file->created_at?->toISOString(),
        ];
    }

    /** @param array<string, mixed> $values */
    private function resultHref(
        array $values,
        ?Comment $comment,
        ?Project $project,
        Team $team,
    ): string {
        $projectId = (int) $values['project_id'];
        $fallback = route('projects.show', [$team, $projectId], absolute: false);

        if ($project === null || $project->id !== $projectId) {
            return $fallback;
        }

        return match ($values['type']) {
            'message' => $project->usesTool('messages')
                ? route(
                    'projects.messages.show',
                    [$team, $project, (int) $values['id']],
                    absolute: false,
                )
                : $fallback,
            'todo' => $project->usesTool('todos')
                && (int) $values['route_parent_id'] > 0
                && (int) $values['route_resource_id'] > 0
                    ? route(
                        'projects.todos.show',
                        [
                            $team,
                            $project,
                            (int) $values['route_parent_id'],
                            (int) $values['route_resource_id'],
                        ],
                        absolute: false,
                    )
                    : $fallback,
            'document' => $project->usesTool('materials')
                ? route('projects.documents.index', [$team, $project], absolute: false)
                : $fallback,
            'file' => $project->usesTool('materials')
                ? route(
                    'projects.files.show',
                    [$team, $project, (int) $values['id']],
                    absolute: false,
                )
                : $fallback,
            'link' => $project->usesTool('materials')
                ? route('projects.materials.index', [$team, $project], absolute: false)
                : $fallback,
            'comment' => $this->commentHref($comment, $team, $project),
            default => $fallback,
        };
    }

    private function commentHref(?Comment $comment, Team $team, Project $project): string
    {
        $fallback = route('projects.show', [$team, $project], absolute: false);

        if ($comment === null || $comment->project_id !== $project->id) {
            return $fallback;
        }

        $commentable = $comment->commentable;

        if ($commentable instanceof MessageBoardPost
            && $commentable->project_id === $project->id
            && $project->usesTool('messages')) {
            return route('projects.messages.show', [$team, $project, $commentable], absolute: false);
        }

        if ($commentable instanceof Todo
            && $commentable->todoList->project_id === $project->id
            && $project->usesTool('todos')) {
            if ($commentable->parent_id !== null) {
                return route('projects.todos.index', [$team, $project], absolute: false);
            }

            return route('projects.todos.show', [
                $team,
                $project,
                $commentable->todoList,
                $commentable,
            ], absolute: false);
        }

        if ($commentable instanceof TodoList
            && $commentable->project_id === $project->id
            && $project->usesTool('todos')) {
            return route('projects.todo-lists.show', [$team, $project, $commentable], absolute: false);
        }

        if ($commentable instanceof StoredFile
            && $commentable->project_id === $project->id
            && $project->usesTool('materials')) {
            return route('projects.files.show', [$team, $project, $commentable], absolute: false);
        }

        if ($commentable instanceof ScheduleEvent
            && $commentable->project_id === $project->id
            && $project->usesTool('schedule')) {
            return route('projects.schedule.show', [$team, $project, $commentable], absolute: false);
        }

        if ($commentable instanceof CheckInPrompt
            && $commentable->checkIn->project_id === $project->id
            && $project->usesTool('check_ins')) {
            return route(
                'projects.check-ins.show',
                [$team, $project, $commentable->checkIn],
                absolute: false,
            );
        }

        if ($commentable instanceof CardColumn
            && $commentable->cardTable->project_id === $project->id
            && $project->usesTool('card_tables')) {
            return route('projects.card-columns.show', [
                $team,
                $project,
                $commentable->cardTable,
                $commentable,
            ], absolute: false);
        }

        return $fallback;
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function accessibleProjects(Team $team, User $user): array
    {
        return Project::query()
            ->visibleTo($user, $team)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name])
            ->all();
    }
}
