<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\TeamAuditEvent;
use App\Http\Requests\Projects\SaveProjectRequest;
use App\Models\Activity;
use App\Models\CardColumn;
use App\Models\CardTable;
use App\Models\ChatMessage;
use App\Models\CheckInPrompt;
use App\Models\Document;
use App\Models\ExternalLink;
use App\Models\Membership;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\TeamAuditLog;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * Display the active projects visible to the user.
     */
    public function index(Request $request, Team $currentTeam): Response
    {
        Gate::authorize('viewAny', [Project::class, $currentTeam]);

        $user = $request->user();
        $canCreateProject = Gate::allows('create', [Project::class, $currentTeam]);

        $projects = $currentTeam->projects()
            ->with(['creator:id,name', 'folder:id,name', 'lead:id,name'])
            ->withExists(['favoritedBy as is_favorite' => fn (Builder $query) => $query->whereKey($user->id)])
            ->withCount('members')
            ->whereNull('archived_at')
            ->visibleTo($user, $currentTeam)
            ->latest()
            ->get()
            ->map(fn (Project $project): array => $this->projectSummary($project));

        return Inertia::render('projects/Index', [
            'projects' => $projects,
            'permissions' => [
                'canCreateProject' => $canCreateProject,
            ],
        ]);
    }

    /**
     * Show the project creation form.
     */
    public function create(Request $request, Team $currentTeam): Response
    {
        Gate::authorize('create', [Project::class, $currentTeam]);

        return Inertia::render('projects/Form', [
            'project' => null,
            'teamMembers' => $this->teamMembers($currentTeam),
            'memberIds' => [$request->user()->id],
            'projectFolders' => $this->projectFolders($currentTeam),
            'toolOptions' => $this->projectToolOptions($currentTeam),
            'enabledTools' => Project::AVAILABLE_TOOLS,
        ]);
    }

    /**
     * Store a new project.
     */
    public function store(SaveProjectRequest $request, Team $currentTeam): RedirectResponse
    {
        $validated = $request->validated();
        $memberIds = $validated['member_ids'] ?? [];
        $hasEnabledTools = $request->boolean('enabled_tools_present');
        unset($validated['member_ids'], $validated['enabled_tools_present']);

        if ($hasEnabledTools) {
            $validated['enabled_tools'] = $validated['enabled_tools'] ?? [];
        }

        $project = DB::transaction(function () use ($request, $currentTeam, $validated, $memberIds): Project {
            $project = $currentTeam->projects()->create([
                ...$validated,
                'created_by' => $request->user()->id,
            ]);

            $project->members()->sync(array_unique([
                ...$memberIds,
                $request->user()->id,
            ]));

            $project->chatChannels()->create([
                'created_by' => $request->user()->id,
                'name' => 'general',
                'description' => 'プロジェクト全体の会話',
            ]);

            $project->todoLists()->create([
                'title' => '未分類の任務',
                'is_loose' => true,
                'position' => 0,
            ]);

            Activity::record(
                $project,
                $request->user(),
                ActivityType::ProjectCreated,
                $project,
                $project->name,
            );

            return $project;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project created.')]);

        return to_route('projects.show', [$currentTeam, $project]);
    }

    /**
     * Display a project.
     */
    public function show(Request $request, Team $currentTeam, Project $project): Response
    {
        Gate::authorize('view', $project);

        $project->load(['creator:id,name', 'members:id,name,email', 'folder:id,name', 'lead:id,name']);
        $project->setAttribute('is_favorite', $project->favoritedBy()->whereKey($request->user()->id)->exists());

        return Inertia::render('projects/Show', [
            'project' => $this->projectDetails($project),
            'home' => $this->projectHome($project),
            'permissions' => [
                'canUpdateProject' => Gate::allows('update', $project),
                'canArchiveProject' => Gate::allows('archive', $project),
            ],
        ]);
    }

    /**
     * Show the project editing form.
     */
    public function edit(Team $currentTeam, Project $project): Response
    {
        Gate::authorize('update', $project);

        return Inertia::render('projects/Form', [
            'project' => $this->projectDetails($project),
            'teamMembers' => $this->teamMembers($currentTeam),
            'memberIds' => $project->members()->pluck('users.id'),
            'projectFolders' => $this->projectFolders($currentTeam),
            'toolOptions' => $this->projectToolOptions($currentTeam),
            'enabledTools' => $project->enabledTools(),
        ]);
    }

    /**
     * Update a project and its participants.
     */
    public function update(SaveProjectRequest $request, Team $currentTeam, Project $project): RedirectResponse
    {
        $validated = $request->validated();
        $memberIds = $validated['member_ids'] ?? [];
        $hasEnabledTools = $request->boolean('enabled_tools_present');
        unset($validated['member_ids'], $validated['enabled_tools_present']);

        if ($hasEnabledTools) {
            $validated['enabled_tools'] = $validated['enabled_tools'] ?? [];
        }

        DB::transaction(function () use ($request, $currentTeam, $project, $validated, $memberIds): void {
            $removedMembers = $project->members()
                ->whereNotIn('users.id', $memberIds)
                ->get();

            $project->update($validated);
            $project->members()->sync($memberIds);

            $removedMembers->each(fn (User $removedMember) => TeamAuditLog::record(
                team: $currentTeam,
                event: TeamAuditEvent::ProjectMemberRemoved,
                actor: $request->user(),
                subjectUser: $removedMember,
                project: $project,
            ));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project updated.')]);

        return to_route('projects.show', [$currentTeam, $project]);
    }

    /**
     * Archive a project.
     */
    public function archive(Team $currentTeam, Project $project): RedirectResponse
    {
        Gate::authorize('archive', $project);

        $project->update(['archived_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project archived.')]);

        return to_route('projects.index', $currentTeam);
    }

    /**
     * Convert a project to its list representation.
     *
     * @return array<string, mixed>
     */
    private function projectSummary(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'allowsAllClients' => $project->allows_all_clients,
            'startsOn' => $project->starts_on?->toDateString(),
            'endsOn' => $project->ends_on?->toDateString(),
            'memberCount' => (int) ($project->getAttribute('members_count') ?? $project->members->count()),
            'creatorName' => $project->creator?->name,
            'folder' => $project->folder ? ['id' => $project->folder->id, 'name' => $project->folder->name] : null,
            'lead' => $project->lead ? ['id' => $project->lead->id, 'name' => $project->lead->name] : null,
            'phase' => $project->phase,
            'status' => $project->status,
            'enabledTools' => $project->enabledTools(),
            'isFavorite' => (bool) ($project->getAttribute('is_favorite') ?? false),
        ];
    }

    /**
     * Convert a project to its detail representation.
     *
     * @return array<string, mixed>
     */
    private function projectDetails(Project $project): array
    {
        $project->loadMissing(['creator:id,name', 'members:id,name,email', 'folder:id,name', 'lead:id,name']);

        return [
            ...$this->projectSummary($project),
            'members' => $project->members->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function projectHome(Project $project): array
    {
        $todoQuery = Todo::query()
            ->whereHas('todoList', fn (Builder $query) => $query->where('project_id', $project->id));
        $recentChatMessages = $project->chatMessages()
            ->with('user:id,name')
            ->latest('id')
            ->limit(5)
            ->get()
            ->reverse()
            ->values();
        $latestChatMessage = $recentChatMessages->last();
        $cardTablePreview = $project->cardTables()
            ->select(['id', 'project_id', 'name'])
            ->with(['cardColumns' => fn (Relation $query) => $query
                ->select(['id', 'card_table_id', 'name', 'position'])
                ->withCount('cards')
                ->orderBy('position')
                ->limit(5)])
            ->orderBy('position')
            ->first();

        return [
            'messages' => [
                'count' => $project->messageBoardPosts()->count(),
                'latest' => $project->messageBoardPosts()
                    ->with('user:id,name')
                    ->latest('published_at')
                    ->limit(3)
                    ->get()
                    ->map(fn (MessageBoardPost $post): array => [
                        'id' => $post->id,
                        'title' => $post->title,
                        'body' => Str::limit($post->body, 180),
                        'authorName' => $post->user?->name,
                        'publishedAt' => $post->published_at->toISOString(),
                    ]),
            ],
            'todos' => [
                'total' => (clone $todoQuery)->count(),
                'completed' => (clone $todoQuery)->whereNotNull('completed_at')->count(),
                'overdue' => (clone $todoQuery)
                    ->whereNull('completed_at')
                    ->whereDate('due_on', '<', today())
                    ->count(),
                'latest' => (clone $todoQuery)
                    ->with('todoList:id,title')
                    ->whereNull('parent_id')
                    ->whereNull('completed_at')
                    ->latest('updated_at')
                    ->latest('id')
                    ->limit(5)
                    ->get()
                    ->map(fn (Todo $todo): array => [
                        'id' => $todo->id,
                        'title' => $todo->title,
                        'listTitle' => $todo->todoList->title,
                        'dueOn' => $todo->due_on?->toDateString(),
                    ]),
            ],
            'cardTables' => [
                'count' => $project->cardTables()->count(),
                'preview' => $cardTablePreview instanceof CardTable ? [
                    'name' => $cardTablePreview->name,
                    'columns' => $cardTablePreview->cardColumns->map(fn (CardColumn $column): array => [
                        'id' => $column->id,
                        'name' => $column->name,
                        'cardCount' => (int) $column->getAttribute('cards_count'),
                    ]),
                ] : null,
            ],
            'checkIns' => [
                'count' => $project->checkIns()->count(),
                'pending' => CheckInPrompt::query()
                    ->where('user_id', request()->user()->id)
                    ->whereNull('answered_at')
                    ->whereHas('checkIn', fn (Builder $query) => $query->where('project_id', $project->id))
                    ->count(),
            ],
            'schedule' => [
                'upcoming' => $project->scheduleEvents()
                    ->where('ends_at', '>=', now())
                    ->oldest('starts_at')
                    ->limit(3)
                    ->get()
                    ->map(fn (ScheduleEvent $event): array => [
                        'id' => $event->id,
                        'title' => $event->title,
                        'startsAt' => $event->starts_at->toISOString(),
                        'allDay' => $event->all_day,
                    ]),
            ],
            'materials' => [
                'documents' => $project->documents()
                    ->latest('updated_at')
                    ->limit(3)
                    ->get()
                    ->map(fn (Document $document): array => [
                        'id' => $document->id,
                        'title' => $document->title,
                        'updatedAt' => $document->updated_at?->toISOString(),
                    ]),
                'files' => $project->storedFiles()
                    ->latest()
                    ->limit(3)
                    ->get()
                    ->map(fn (StoredFile $file): array => [
                        'id' => $file->id,
                        'name' => $file->original_name,
                        'createdAt' => $file->created_at?->toISOString(),
                    ]),
                'links' => $project->externalLinks()
                    ->latest('updated_at')
                    ->limit(3)
                    ->get()
                    ->map(fn (ExternalLink $link): array => [
                        'id' => $link->id,
                        'title' => $link->title,
                        'url' => $link->url,
                        'updatedAt' => $link->updated_at?->toISOString(),
                    ]),
            ],
            'activity' => [
                'activeLastSevenDays' => $project->activities()
                    ->where('created_at', '>=', now()->subDays(7))
                    ->whereNotNull('actor_id')
                    ->distinct('actor_id')
                    ->count('actor_id'),
                'latest' => $project->activities()
                    ->with('actor:id,name')
                    ->latest('id')
                    ->limit(5)
                    ->get()
                    ->map(fn (Activity $activity): array => [
                        'id' => $activity->id,
                        'type' => $activity->type->value,
                        'target' => $activity->type->target(),
                        'actorId' => $activity->actor_id,
                        'actorName' => $activity->actor?->name,
                        'subjectLabel' => $activity->metadata['label'],
                        'createdAt' => $activity->created_at?->toISOString(),
                        'project' => [
                            'id' => $project->id,
                            'name' => $project->name,
                        ],
                    ]),
            ],
            'chat' => [
                'recent' => $recentChatMessages->map(fn (ChatMessage $message): array => [
                    'id' => $message->id,
                    'body' => Str::limit($message->body, 240),
                    'authorName' => $message->user?->name,
                    'createdAt' => $message->created_at?->toISOString(),
                ]),
                'latest' => $latestChatMessage instanceof ChatMessage ? [
                    'id' => $latestChatMessage->id,
                    'body' => $latestChatMessage->body,
                    'authorName' => $latestChatMessage->user?->name,
                    'createdAt' => $latestChatMessage->created_at?->toISOString(),
                ] : null,
            ],
            'timesheets' => [
                'totalMinutes' => (int) $project->timeEntries()->sum('minutes'),
                'latest' => $project->timeEntries()
                    ->with('user:id,name')
                    ->latest('worked_on')
                    ->latest('id')
                    ->limit(3)
                    ->get()
                    ->map(fn ($entry): array => [
                        'id' => $entry->id,
                        'workedOn' => $entry->worked_on->toDateString(),
                        'minutes' => $entry->minutes,
                        'userName' => $entry->user->name,
                    ]),
            ],
        ];
    }

    /** @return array<int, array{id: int, name: string}> */
    private function projectFolders(Team $team): array
    {
        return $team->projectFolders()
            ->orderBy('position')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($folder): array => ['id' => $folder->id, 'name' => $folder->name])
            ->all();
    }

    /** @return array<int, array{id: string, label: string}> */
    private function projectToolOptions(Team $team): array
    {
        $menuNames = $team->menuNames();

        return [
            ['id' => 'messages', 'label' => __('Message board')],
            ['id' => 'todos', 'label' => $menuNames['todos']],
            ['id' => 'materials', 'label' => __('Materials library')],
            ['id' => 'schedule', 'label' => $menuNames['calendar']],
            ['id' => 'chat', 'label' => $menuNames['chat']],
            ['id' => 'card_tables', 'label' => $menuNames['cardTable']],
            ['id' => 'check_ins', 'label' => $menuNames['checkIns']],
            ['id' => 'timesheets', 'label' => $menuNames['timesheets']],
        ];
    }

    /**
     * Get selectable members for a project.
     *
     * @return array<int, array{id: int, name: string, email: string, role: string, roleLabel: string}>
     */
    private function teamMembers(Team $team): array
    {
        return $team->members()
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(function (User $member): array {
                /** @var Membership $membership */
                $membership = $member->getRelation('pivot');

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $membership->role->value,
                    'roleLabel' => $membership->role->label(),
                ];
            })
            ->all();
    }
}
