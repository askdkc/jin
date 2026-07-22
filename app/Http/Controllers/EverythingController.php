<?php

namespace App\Http\Controllers;

use App\Http\Requests\Phase10\EverythingIndexRequest;
use App\Models\CheckInPrompt;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\User;
use App\Support\RichText;
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
            ->withQueryString()
            ->through(fn (object $item): array => $this->transformResult($item));

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
            ->selectRaw('? as type, ? as target', ['message', 'messages']);

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
            ->selectRaw('? as type, ? as target', ['todo', 'todos']);

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
            ->selectRaw('? as type, ? as target', ['document', 'documents']);

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
            ->selectRaw('? as type, ? as target', ['file', 'files']);

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
            ->selectRaw('? as type, ? as target', ['link', 'materials']);

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
            ->selectRaw('? as type', ['comment'])
            ->selectRaw(
                'CASE
                    WHEN comments.commentable_type = ? THEN ?
                    WHEN comments.commentable_type = ? THEN ?
                    WHEN comments.commentable_type = ? THEN ?
                    WHEN comments.commentable_type = ? THEN ?
                    ELSE ?
                END as target',
                [
                    MessageBoardPost::class, 'messages',
                    StoredFile::class, 'files',
                    ScheduleEvent::class, 'schedule',
                    CheckInPrompt::class, 'check_ins',
                    'todos',
                ],
            );

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
     * @return array{id: int, type: string, title: string, snippet: string, target: string, project: array{id: int, name: string}, updatedAt: string}
     */
    private function transformResult(object $item): array
    {
        $values = get_object_vars($item);

        return [
            'id' => (int) $values['id'],
            'type' => (string) $values['type'],
            'title' => (string) $values['title'],
            'snippet' => Str::limit(RichText::plainText((string) $values['snippet']), 240),
            'target' => (string) $values['target'],
            'project' => [
                'id' => (int) $values['project_id'],
                'name' => (string) $values['project_name'],
            ],
            'updatedAt' => (string) $values['updated_at'],
        ];
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
