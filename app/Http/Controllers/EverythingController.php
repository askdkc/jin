<?php

namespace App\Http\Controllers;

use App\Enums\StoredFileContentStatus;
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
use App\Support\OnlyOfficeDocumentTypeResolver;
use App\Support\RichText;
use App\Support\StoredFileContentSearch;
use App\Support\StoredFileContentSnippet;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EverythingController extends Controller
{
    public function __construct(
        private OnlyOfficeDocumentTypeResolver $onlyOfficeDocumentTypeResolver,
        private StoredFileContentSearch $storedFileContentSearch,
        private StoredFileContentSnippet $storedFileContentSnippet,
    ) {}

    public function __invoke(EverythingIndexRequest $request, Team $currentTeam): Response
    {
        $filters = $request->validated();
        $search = isset($filters['q']) && trim((string) $filters['q']) !== ''
            ? trim((string) $filters['q'])
            : null;
        /** @var list<string> $fileCategories */
        $fileCategories = array_values($filters['file_categories'] ?? []);
        $type = $fileCategories === [] ? ($filters['type'] ?? null) : 'file';
        $projectId = isset($filters['project']) ? (int) $filters['project'] : null;
        $sort = isset($filters['sort'])
            ? (string) $filters['sort']
            : EverythingIndexRequest::DEFAULT_SORT;
        $user = $request->user();

        $queries = collect([
            'message' => $this->messageQuery($currentTeam, $user, $search, $projectId),
            'chat' => $this->chatQuery($currentTeam, $user, $search, $projectId),
            'todo' => $this->todoQuery($currentTeam, $user, $search, $projectId),
            'document' => $this->documentQuery($currentTeam, $user, $search, $projectId),
            'file' => $this->fileQuery($currentTeam, $user, $search, $projectId, $fileCategories),
            'link' => $this->linkQuery($currentTeam, $user, $search, $projectId),
            'comment' => $this->commentQuery($currentTeam, $user, $search, $projectId),
        ])->when($type !== null, fn ($collection) => $collection->only((string) $type));

        /** @var Builder $union */
        $union = $queries->shift();

        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        $resultsQuery = DB::query()->fromSub($union, 'everything');
        $this->applySort($resultsQuery, $sort);
        $paginationParameters = array_filter([
            'q' => $search,
            'type' => $type,
            'project' => $projectId,
            'sort' => $sort,
            'file_categories' => $fileCategories,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
        $results = $resultsQuery
            ->paginate(20)
            ->appends($paginationParameters);
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
            ->values()
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
        $fileSnippets = $this->fileSnippets($fileIds, $search);
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

        $results->through(function (object $item) use (
            $comments,
            $currentTeam,
            $files,
            $fileSnippets,
            $projects,
        ): array {
            $values = get_object_vars($item);
            $project = $projects->get((int) $values['project_id']);
            $file = $values['type'] === 'file'
                ? $files->get((int) $values['id'])
                : null;
            $fileSnippet = $values['type'] === 'file'
                ? $fileSnippets->get((int) $values['id'])
                : null;
            $comment = $values['type'] === 'comment'
                ? $comments->get((int) $values['id'])
                : null;

            return $this->transformResult(
                $item,
                $file instanceof StoredFile ? $file : null,
                is_string($fileSnippet) ? $fileSnippet : null,
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
                'sort' => $sort,
                'fileCategories' => $fileCategories,
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

    private function chatQuery(Team $team, User $user, ?string $search, ?int $projectId): Builder
    {
        $query = DB::table('chat_messages')
            ->join('projects', 'projects.id', '=', 'chat_messages.project_id')
            ->leftJoin('chat_channels', function (JoinClause $join): void {
                $join->on('chat_channels.id', '=', 'chat_messages.chat_channel_id')
                    ->on('chat_channels.project_id', '=', 'chat_messages.project_id');
            })
            ->select('chat_messages.id')
            ->selectRaw('COALESCE(chat_channels.name, ?) as title', [__('Chat')])
            ->addSelect([
                'chat_messages.body as snippet',
                'chat_messages.project_id',
                'projects.name as project_name',
                'chat_messages.updated_at',
            ])
            ->selectRaw('? as type', ['chat'])
            ->addSelect([
                'chat_messages.chat_channel_id as route_parent_id',
                'chat_messages.id as route_resource_id',
            ]);

        $this->applySearch($query, $search, ['chat_messages.body']);

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

    /** @param list<string> $fileCategories */
    private function fileQuery(
        Team $team,
        User $user,
        ?string $search,
        ?int $projectId,
        array $fileCategories,
    ): Builder {
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

        if ($search !== null) {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where(
                    'stored_files.original_name',
                    'like',
                    "%{$search}%",
                );
                $this->storedFileContentSearch->addContentMatch(
                    $searchQuery,
                    $search,
                );
            });
        }

        $this->applyFileCategoryFilters($query, $fileCategories);

        return $this->scopeProjectAccess($query, $team, $user, $projectId);
    }

    /**
     * @param  array<int, int>  $fileIds
     * @return Collection<int, string>
     */
    private function fileSnippets(array $fileIds, ?string $search): Collection
    {
        if ($search === null || $fileIds === []) {
            return collect();
        }

        return DB::table('stored_file_contents')
            ->join(
                'stored_files',
                'stored_files.id',
                '=',
                'stored_file_contents.stored_file_id',
            )
            ->whereIn('stored_file_contents.stored_file_id', $fileIds)
            ->whereColumn(
                'stored_file_contents.source_checksum',
                'stored_files.checksum',
            )
            ->where(
                'stored_file_contents.status',
                StoredFileContentStatus::Ready->value,
            )
            ->where('stored_file_contents.extractor', 'markitdown')
            ->whereNotNull('stored_file_contents.content')
            ->get([
                'stored_file_contents.stored_file_id',
                'stored_file_contents.content',
            ])
            ->mapWithKeys(function (object $contentRow) use ($search): array {
                $values = get_object_vars($contentRow);
                $snippet = $this->storedFileContentSnippet->make(
                    (string) $values['content'],
                    $search,
                );

                return $snippet === null
                    ? []
                    : [(int) $values['stored_file_id'] => $snippet];
            });
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

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            EverythingIndexRequest::SORT_UPDATED_ASC => $query
                ->orderByRaw('CASE WHEN updated_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('updated_at')
                ->orderBy('id')
                ->orderBy('type'),
            EverythingIndexRequest::SORT_NAME_ASC => $query
                ->orderBy('title')
                ->orderBy('id')
                ->orderBy('type'),
            EverythingIndexRequest::SORT_NAME_DESC => $query
                ->orderByDesc('title')
                ->orderByDesc('id')
                ->orderBy('type'),
            default => $query
                ->orderByRaw('CASE WHEN updated_at IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->orderBy('type'),
        };
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

    private function normalizedMimeTypeExpression(): Expression
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return DB::raw("LOWER(TRIM(SPLIT_PART(COALESCE(stored_files.mime_type, ''), ';', 1)))");
        }

        if ($driver === 'sqlsrv') {
            return DB::raw("LOWER(LTRIM(RTRIM(CASE WHEN CHARINDEX(';', COALESCE(stored_files.mime_type, '')) > 0 THEN SUBSTRING(COALESCE(stored_files.mime_type, ''), 1, CHARINDEX(';', COALESCE(stored_files.mime_type, '')) - 1) ELSE COALESCE(stored_files.mime_type, '') END)))");
        }

        return DB::raw("LOWER(TRIM(CASE WHEN INSTR(COALESCE(stored_files.mime_type, ''), ';') > 0 THEN SUBSTR(COALESCE(stored_files.mime_type, ''), 1, INSTR(COALESCE(stored_files.mime_type, ''), ';') - 1) ELSE COALESCE(stored_files.mime_type, '') END))");
    }

    /** @param list<string> $fileCategories */
    private function applyFileCategoryFilters(Builder $query, array $fileCategories): void
    {
        if ($fileCategories === []) {
            return;
        }

        $query->where(function (Builder $categoryQuery) use ($fileCategories): void {
            foreach ($fileCategories as $index => $fileCategory) {
                $method = $index === 0 ? 'where' : 'orWhere';

                $categoryQuery->{$method}(function (Builder $selectedCategoryQuery) use ($fileCategory): void {
                    $this->applyFileCategoryFilter($selectedCategoryQuery, $fileCategory);
                });
            }
        });
    }

    private function applyFileCategoryFilter(Builder $query, string $fileCategory): void
    {
        switch ($fileCategory) {
            case EverythingIndexRequest::FILE_CATEGORY_IMAGE:
                $query->where($this->normalizedMimeTypeExpression(), 'like', 'image/%');
                break;
            case EverythingIndexRequest::FILE_CATEGORY_VIDEO:
                $query->where($this->normalizedMimeTypeExpression(), 'like', 'video/%');
                break;
            case EverythingIndexRequest::FILE_CATEGORY_OFFICE:
                $this->applyOfficeFileFilter($query);
                break;
            case EverythingIndexRequest::FILE_CATEGORY_PDF:
                $query->whereRaw('LOWER(stored_files.original_name) LIKE ?', ['%.pdf']);
                $this->applyNormalizedMimeTypeFilter($query, ['application/pdf']);
                break;
        }
    }

    private function applyOfficeFileFilter(Builder $query): void
    {
        $query->where(function (Builder $officeQuery): void {
            $position = 0;

            foreach ($this->onlyOfficeDocumentTypeResolver->formats() as $extension => $format) {
                $method = $position === 0 ? 'where' : 'orWhere';

                $officeQuery->{$method}(function (Builder $formatQuery) use ($extension, $format): void {
                    $formatQuery->whereRaw('LOWER(stored_files.original_name) LIKE ?', ["%.{$extension}"]);
                    $this->applyNormalizedMimeTypeFilter($formatQuery, $format['mimes']);
                });

                $position++;
            }
        });
    }

    /** @param list<string> $mimeTypes */
    private function applyNormalizedMimeTypeFilter(Builder $query, array $mimeTypes): void
    {
        $query->where(function (Builder $mimeQuery) use ($mimeTypes): void {
            foreach ($mimeTypes as $index => $mimeType) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $mimeQuery->{$method}(
                    $this->normalizedMimeTypeExpression(),
                    '=',
                    $mimeType,
                );
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
     *     snippet: string|null,
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
        ?string $fileSnippet,
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
                'snippet' => $fileSnippet,
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
            'snippet' => $fileSnippet,
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
            'chat' => $project->usesTool('chat')
                ? $this->chatHref($team, $project, (int) $values['route_parent_id'])
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

    private function chatHref(Team $team, Project $project, int $channelId): string
    {
        $parameters = [
            'current_team' => $team,
            'project' => $project,
        ];

        if ($channelId > 0) {
            $parameters['channel'] = $channelId;
        }

        return route('projects.chat.show', $parameters, absolute: false);
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
