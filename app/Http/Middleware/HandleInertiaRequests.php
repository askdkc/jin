<?php

namespace App\Http\Middleware;

use App\Models\BubbleUp;
use App\Models\ChatMessage;
use App\Models\DirectMessage;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\Team;
use App\Models\Todo;
use App\Models\User;
use App\Support\RichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Middleware;
use Throwable;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /** @var array<string, array{modifiedAt: int|false, catalog: array<string, string>}> */
    private static array $catalogCache = [];

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $routeTeam = $request->route('current_team');
        $currentTeam = $routeTeam instanceof Team ? $routeTeam : $user?->currentTeam;
        $locale = app()->getLocale();
        $localeNames = config('app.supported_locales', []);
        $localeNames = is_array($localeNames) ? $localeNames : [];
        $translationOnceKey = "translations.{$locale}";
        $translationCatalog = Inertia::once(fn (): array => $this->loadCatalog($locale))
            ->as($translationOnceKey)
            ->until(now()->addDay());
        $cachedOnceKeys = array_map(
            trim(...),
            explode(',', (string) $request->header('X-Inertia-Except-Once-Props', '')),
        );

        foreach ($cachedOnceKeys as $cachedOnceKey) {
            if (str_starts_with($cachedOnceKey, 'translations.') && $cachedOnceKey !== $translationOnceKey) {
                $translationCatalog->fresh();

                break;
            }
        }

        return [
            ...parent::share($request),
            'locale' => $locale,
            'locales' => array_keys($localeNames),
            'localeNames' => $localeNames,
            'translations' => $translationCatalog,
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user && $currentTeam ? $user->toUserTeam($currentTeam) : null,
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],
            'notificationSummary' => fn (): array => [
                'unreadCount' => $user && $currentTeam
                    ? ($user->showsUnreadNotificationCount()
                        ? $user->unreadNotifications()
                            ->where('data->teamId', $currentTeam->id)
                            ->whereIn(
                                'data->projectId',
                                $this->notificationProjectIds($user, $currentTeam),
                            )
                            ->count()
                        : 0)
                    : 0,
            ],
            'navigationProjects' => fn (): array => $user && $currentTeam
                ? Project::query()
                    ->visibleTo($user, $currentTeam)
                    ->whereNull('archived_at')
                    ->orderBy('name')
                    ->limit(50)
                    ->get(['id', 'name'])
                    ->map(fn (Project $project): array => [
                        'id' => $project->id,
                        'name' => $project->name,
                    ])
                    ->all()
                : [],
            'navigationPeople' => $user && $currentTeam
                ? Inertia::optional(fn (): array => $this->workspacePeople($user, $currentTeam))
                    ->once(as: "workspace.{$user->id}.{$currentTeam->id}.people")
                    ->fresh()
                : [],
            // Keep the parent as a plain array so Inertia can resolve one nested partial prop at a time.
            'workspaceBar' => $user && $currentTeam
                ? $this->workspaceBar($user, $currentTeam)
                : $this->emptyWorkspaceBar(),
        ];
    }

    /** @return array<string, string> */
    private function loadCatalog(string $locale): array
    {
        $supportedLocales = config('app.supported_locales', []);

        if ($locale === 'en'
            || ! is_array($supportedLocales)
            || ! array_key_exists($locale, $supportedLocales)
            || preg_match('/\A[a-z]{2}(?:_[A-Z]{2})?\z/', $locale) !== 1
        ) {
            return [];
        }

        $path = lang_path("{$locale}.json");

        if (! is_file($path)) {
            return [];
        }

        $modifiedAt = @filemtime($path);
        $cached = self::$catalogCache[$path] ?? null;

        if ($cached !== null && $cached['modifiedAt'] === $modifiedAt) {
            return $cached['catalog'];
        }

        try {
            $catalog = json_decode(
                (string) file_get_contents($path),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (Throwable) {
            $catalog = [];
        }

        $catalog = is_array($catalog) ? array_filter($catalog, 'is_string') : [];
        self::$catalogCache[$path] = [
            'modifiedAt' => $modifiedAt,
            'catalog' => $catalog,
        ];

        return $catalog;
    }

    /** @return array<string, mixed> */
    private function workspaceBar(User $user, Team $team): array
    {
        $key = "workspace.{$user->id}.{$team->id}";

        return [
            'tasks' => Inertia::optional(fn (): array => $this->workspaceTasks($user, $team))
                ->once(as: "{$key}.tasks")
                ->fresh(),
            'events' => Inertia::optional(fn (): array => $this->workspaceEvents($user, $team))
                ->once(as: "{$key}.events")
                ->fresh(),
            'bookmarks' => Inertia::optional(fn (): array => $this->workspaceBookmarks($user, $team))
                ->once(as: "{$key}.bookmarks")
                ->fresh(),
            'notifications' => fn (): array => $this->workspaceNotifications($user, $team),
            'pings' => Inertia::optional(fn (): array => $this->workspacePings($user, $team))
                ->once(as: "{$key}.pings")
                ->fresh(),
            'unreadPings' => fn (): int => DirectMessage::query()
                ->where('team_id', $team->id)
                ->where('recipient_id', $user->id)
                ->whereNull('read_at')
                ->count(),
            'bubbleUps' => fn (): array => $this->workspaceBubbleUps($user, $team),
            // The reminder is visible outside the events panel, so it remains eager and narrowly scoped.
            'reminder' => fn (): ?array => $this->workspaceReminder($user, $team),
        ];
    }

    /** @return Builder<Project> */
    private function visibleProjectIds(User $user, Team $team): Builder
    {
        return Project::query()->visibleTo($user, $team)->select('projects.id');
    }

    /** @return Builder<Project> */
    private function notificationProjectIds(User $user, Team $team): Builder
    {
        $query = Project::query()->visibleTo($user, $team);

        // PostgreSQL's ->> operator returns text; keep the JSON/subquery comparison type-safe.
        return DB::connection()->getDriverName() === 'pgsql'
            ? $query->selectRaw('CAST(projects.id AS TEXT)')
            : $query->select('projects.id');
    }

    /** @return array<int, array<string, mixed>> */
    private function workspaceTasks(User $user, Team $team): array
    {
        return Todo::query()
            ->select(['id', 'todo_list_id', 'title', 'due_on'])
            ->with('todoList.project:id,team_id,name')
            ->where('assignee_id', $user->id)
            ->whereNull('completed_at')
            ->whereHas('todoList.project', fn (Builder $query) => $query
                ->whereIn('projects.id', $this->visibleProjectIds($user, $team))
                ->whereNull('archived_at'))
            ->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_on')
            ->limit(10)
            ->get()
            ->map(fn (Todo $todo): array => [
                'id' => $todo->id,
                'title' => $todo->title,
                'dueOn' => $todo->due_on?->toDateString(),
                'project' => [
                    'id' => $todo->todoList->project->id,
                    'name' => $todo->todoList->project->name,
                ],
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function workspaceEvents(User $user, Team $team): array
    {
        $now = now();

        return ScheduleEvent::query()
            ->select(['id', 'project_id', 'title', 'starts_at', 'ends_at', 'all_day', 'meeting_url'])
            ->with('project:id,team_id,name')
            ->whereBetween('starts_at', [$now, $now->copy()->addDays(7)])
            ->whereHas('project', fn (Builder $query) => $query
                ->whereIn('projects.id', $this->visibleProjectIds($user, $team))
                ->whereNull('archived_at'))
            ->oldest('starts_at')
            ->limit(10)
            ->get()
            ->map(fn (ScheduleEvent $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'startsAt' => $event->starts_at->toISOString(),
                'endsAt' => $event->ends_at->toISOString(),
                'allDay' => $event->all_day,
                'meetingUrl' => $event->meeting_url,
                'project' => ['id' => $event->project->id, 'name' => $event->project->name],
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function workspaceBookmarks(User $user, Team $team): array
    {
        return Project::query()
            ->visibleTo($user, $team)
            ->whereHas('favoritedBy', fn (Builder $query) => $query->whereKey($user->id))
            ->whereNull('archived_at')
            ->latest('updated_at')
            ->limit(10)
            ->get(['id', 'name', 'description'])
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function workspaceNotifications(User $user, Team $team): array
    {
        $notifications = $user->unreadNotifications()
            ->where('data->teamId', $team->id)
            ->whereIn('data->projectId', $this->notificationProjectIds($user, $team))
            ->latest()
            ->limit(10)
            ->get();
        $chatMessageIds = $notifications
            ->filter(fn (DatabaseNotification $notification): bool => empty($notification->data['chatMessageExcerpt']))
            ->map(fn (DatabaseNotification $notification): ?int => isset($notification->data['chatMessageId'])
                    ? (int) $notification->data['chatMessageId']
                    : null)
            ->filter()
            ->values();
        $chatMessageExcerpts = $chatMessageIds->isEmpty()
            ? collect()
            : ChatMessage::query()
                ->whereKey($chatMessageIds)
                ->get(['id', 'body'])
                ->mapWithKeys(fn (ChatMessage $message): array => [
                    $message->id => Str::limit(RichText::plainText($message->body), 120),
                ]);

        return $notifications
            ->map(function (DatabaseNotification $notification) use ($chatMessageExcerpts): array {
                $messageTitle = $notification->data['messageTitle'] ?? null;
                $chatMessageId = isset($notification->data['chatMessageId'])
                    ? (int) $notification->data['chatMessageId']
                    : null;
                $chatChannelName = $notification->data['chatChannelName']
                    ?? (in_array($notification->data['type'], ['mention_chat', 'chat_message'], true)
                        && is_string($messageTitle)
                            ? Str::after($messageTitle, '#')
                            : null);

                return [
                    'id' => $notification->id,
                    'type' => $notification->data['type'],
                    'projectId' => $notification->data['projectId'],
                    'projectName' => $notification->data['projectName'],
                    'title' => $messageTitle
                        ?? $notification->data['todoTitle']
                        ?? $notification->data['checkInQuestion']
                        ?? null,
                    'messageTitle' => $messageTitle,
                    'todoTitle' => $notification->data['todoTitle'] ?? null,
                    'checkInQuestion' => $notification->data['checkInQuestion'] ?? null,
                    'chatChannelName' => $chatChannelName,
                    'chatMessageExcerpt' => $notification->data['chatMessageExcerpt']
                        ?? ($chatMessageId !== null ? $chatMessageExcerpts->get($chatMessageId) : null),
                    'replyable' => isset($notification->data['messageBoardPostId'])
                        || isset($notification->data['todoId'])
                        || isset($notification->data['promptId']),
                    'actorName' => $notification->data['actorName'] ?? null,
                    'read' => $notification->read_at !== null,
                    'createdAt' => $notification->created_at?->toISOString(),
                ];
            })
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function workspacePings(User $user, Team $team): array
    {
        return DirectMessage::query()
            ->with(['sender:id,name', 'recipient:id,name'])
            ->where('team_id', $team->id)
            ->where(fn (Builder $query) => $query
                ->where('sender_id', $user->id)
                ->orWhere('recipient_id', $user->id))
            ->latest('id')
            ->limit(30)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (DirectMessage $message): array => [
                'id' => $message->id,
                'body' => $message->body,
                'read' => $message->read_at !== null,
                'createdAt' => $message->created_at?->toISOString(),
                'sender' => ['id' => $message->sender->id, 'name' => $message->sender->name],
                'recipient' => ['id' => $message->recipient->id, 'name' => $message->recipient->name],
            ])
            ->all();
    }

    /** @return array<int, array{id: int, name: string}> */
    private function workspacePeople(User $user, Team $team): array
    {
        return $team->members()
            ->whereKeyNot($user->id)
            ->orderBy('name')
            ->get(['users.id', 'users.name'])
            ->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function workspaceBubbleUps(User $user, Team $team): array
    {
        return BubbleUp::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->whereNull('completed_at')
            ->orderBy('remind_at')
            ->limit(20)
            ->get()
            ->map(fn (BubbleUp $bubbleUp): array => [
                'id' => $bubbleUp->id,
                'label' => $bubbleUp->label,
                'url' => $bubbleUp->url,
                'remindAt' => $bubbleUp->remind_at->toISOString(),
                'due' => $bubbleUp->remind_at->isPast(),
            ])
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function workspaceReminder(User $user, Team $team): ?array
    {
        if (! $user->wantsEventReminders()) {
            return null;
        }

        $now = now();
        $reminder = ScheduleEvent::query()
            ->select(['id', 'project_id', 'title', 'starts_at', 'meeting_url'])
            ->with('project:id,team_id,name')
            ->where('all_day', false)
            ->whereBetween('starts_at', [$now, $now->copy()->addMinutes(15)])
            ->whereHas('project', fn (Builder $query) => $query
                ->whereIn('projects.id', $this->visibleProjectIds($user, $team))
                ->whereNull('archived_at'))
            ->oldest('starts_at')
            ->first();

        return $reminder ? [
            'id' => $reminder->id,
            'title' => $reminder->title,
            'startsAt' => $reminder->starts_at->toISOString(),
            'meetingUrl' => $reminder->meeting_url,
            'project' => ['id' => $reminder->project->id, 'name' => $reminder->project->name],
        ] : null;
    }

    /** @return array<string, mixed> */
    private function emptyWorkspaceBar(): array
    {
        return [
            'tasks' => [],
            'events' => [],
            'bookmarks' => [],
            'notifications' => [],
            'pings' => [],
            'unreadPings' => 0,
            'bubbleUps' => [],
            'reminder' => null,
        ];
    }
}
