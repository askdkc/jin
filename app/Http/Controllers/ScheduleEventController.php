<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Http\Requests\Phase3\SaveMessageBoardPostReactionRequest as SaveReactionRequest;
use App\Http\Requests\Phase3\SaveScheduleEventRequest;
use App\Models\Activity;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\Team;
use App\Models\User;
use App\Support\InlineAttachments;
use App\Support\Reactions;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ScheduleEventController extends Controller
{
    // ponytail: caps how far a recurring series is materialized up front; raise or move
    // to a background job if projects need recurrences spanning further than this.
    private const MAX_RECURRING_OCCURRENCES = 60;

    public function show(
        Request $request,
        Team $currentTeam,
        Project $project,
        ScheduleEvent $scheduleEvent,
    ): Response {
        Gate::authorize('view', $project);

        $userId = $request->user()->id;
        $canManageProject = Gate::allows('update', $project);
        $scheduleEvent->load([
            'creator:id,name',
            'comments' => fn ($query) => $query->with('user:id,name')->oldest(),
            'reactors:id,name',
        ]);

        return Inertia::render('projects/schedule/Show', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'event' => [
                'id' => $scheduleEvent->id,
                'title' => $scheduleEvent->title,
                'details' => $scheduleEvent->details,
                'meetingUrl' => $scheduleEvent->meeting_url,
                'startsAt' => $scheduleEvent->starts_at->toISOString(),
                'endsAt' => $scheduleEvent->ends_at->toISOString(),
                'allDay' => $scheduleEvent->all_day,
                'recurrence' => $scheduleEvent->recurrence,
                'creatorName' => $scheduleEvent->creator?->name,
                'canEdit' => $canManageProject || $scheduleEvent->created_by === $userId,
                'reactions' => Reactions::grouped($scheduleEvent->reactors, $userId),
                'comments' => $scheduleEvent->comments->map(fn (Comment $comment): array => [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'authorName' => $comment->user?->name,
                    'createdAt' => $comment->created_at?->toISOString(),
                    'canDelete' => $canManageProject || $comment->user_id === $userId,
                ])->all(),
            ],
            'members' => $project->members()
                ->orderBy('name')
                ->get(['users.id', 'users.name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
        ]);
    }

    public function index(Request $request, Team $currentTeam, Project $project): Response
    {
        Gate::authorize('view', $project);

        $timezone = (string) config('app.timezone');
        $requestedView = $request->string('view')->toString();
        $view = in_array($requestedView, ['day', 'week', 'gantt'], true) ? $requestedView : 'month';

        $monthValue = $request->string('month')->toString();
        $dateValue = $request->string('date')->toString();
        $anchor = match (true) {
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue) === 1 => CarbonImmutable::createFromFormat('!Y-m-d', $dateValue, $timezone),
            preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthValue) === 1 => CarbonImmutable::createFromFormat('!Y-m', $monthValue, $timezone),
            default => CarbonImmutable::now($timezone)->startOfDay(),
        };
        $month = $anchor->startOfMonth();
        $canManageProject = Gate::allows('update', $project);
        $userId = $request->user()->id;
        $projectStart = $project->starts_on !== null
            ? CarbonImmutable::instance($project->starts_on)->startOfDay()
            : null;
        $projectEnd = $project->ends_on !== null
            ? CarbonImmutable::instance($project->ends_on)->endOfDay()
            : null;
        if ($projectStart !== null
            && $projectEnd !== null
            && $projectStart->lessThanOrEqualTo($projectEnd)) {
            $ganttUsesProjectDates = true;
            $ganttStart = $projectStart;
            $ganttEnd = $projectEnd;
            $ganttPrevious = $projectStart;
            $ganttNext = $projectEnd;
        } else {
            $ganttUsesProjectDates = false;
            $ganttStart = $anchor->startOfWeek(CarbonImmutable::SUNDAY)->subWeeks(5);
            $ganttEnd = $ganttStart->addWeeks(12)->subSecond();
            $ganttPrevious = $anchor->subWeeks(6);
            $ganttNext = $anchor->addWeeks(6);
        }

        [$rangeStart, $rangeEnd, $previousDate, $nextDate] = match ($view) {
            'day' => [$anchor, $anchor->endOfDay(), $anchor->subDay(), $anchor->addDay()],
            'week' => [
                $anchor->startOfWeek(CarbonImmutable::SUNDAY),
                $anchor->endOfWeek(CarbonImmutable::SATURDAY),
                $anchor->subWeek(),
                $anchor->addWeek(),
            ],
            'gantt' => [$ganttStart, $ganttEnd, $ganttPrevious, $ganttNext],
            default => [
                $month,
                $month->endOfMonth(),
                $anchor->subMonthNoOverflow(),
                $anchor->addMonthNoOverflow(),
            ],
        };

        $mapEvent = fn (ScheduleEvent $event): array => [
            'id' => $event->id,
            'title' => $event->title,
            'details' => $event->details,
            'meetingUrl' => $event->meeting_url,
            'startsAt' => $event->starts_at->format('Y-m-d\\TH:i'),
            'endsAt' => $event->ends_at->format('Y-m-d\\TH:i'),
            'allDay' => $event->all_day,
            'recurrence' => $event->recurrence,
            'creatorName' => $event->creator?->name,
            'canEdit' => $canManageProject || $event->created_by === $userId,
        ];

        $overlappingEvents = fn (CarbonImmutable $start, CarbonImmutable $end) => $project->scheduleEvents()
            ->where('starts_at', '<=', $end)
            ->where('ends_at', '>', $start);

        $events = $overlappingEvents($rangeStart, $rangeEnd)
            ->with('creator:id,name')
            ->orderBy('starts_at')
            ->get()
            ->map($mapEvent);

        $weeks = null;

        if ($view === 'month') {
            $gridStart = $month->startOfWeek(CarbonImmutable::SUNDAY);
            $gridEnd = $month->endOfMonth()->endOfWeek(CarbonImmutable::SATURDAY);

            $gridEvents = $overlappingEvents($gridStart, $gridEnd)
                ->with('creator:id,name')
                ->orderBy('starts_at')
                ->get()
                ->map($mapEvent);

            $weeks = $this->buildCalendarGrid($gridStart, $gridEnd, $month, $gridEvents);
        }

        return Inertia::render('projects/schedule/Index', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'view' => $view,
            'date' => $anchor->toDateString(),
            'today' => CarbonImmutable::now($timezone)->toDateString(),
            'rangeStart' => $rangeStart->toDateString(),
            'rangeEnd' => $rangeEnd->toDateString(),
            'previousDate' => $previousDate->toDateString(),
            'nextDate' => $nextDate->toDateString(),
            'ganttUsesProjectDates' => $ganttUsesProjectDates,
            'month' => $month->format('Y-m'),
            'events' => $events,
            'weeks' => $weeks,
        ]);
    }

    /**
     * @param  Collection<int, covariant array{startsAt: string, endsAt: string}>  $events
     * @return list<list<array<string, mixed>>>
     */
    private function buildCalendarGrid(CarbonImmutable $gridStart, CarbonImmutable $gridEnd, CarbonImmutable $month, $events): array
    {
        $today = CarbonImmutable::now()->toDateString();
        $weeks = [];
        $cursor = $gridStart;

        while ($cursor->lessThanOrEqualTo($gridEnd)) {
            $week = [];

            for ($i = 0; $i < 7; $i++) {
                $dateKey = $cursor->toDateString();
                $nextDateKey = $cursor->addDay()->toDateString();

                $week[] = [
                    'date' => $dateKey,
                    'day' => $cursor->day,
                    'inCurrentMonth' => $cursor->month === $month->month,
                    'isToday' => $dateKey === $today,
                    'monthLabel' => $cursor->day === 1 ? $cursor->translatedFormat('M j, Y') : null,
                    'events' => $events->filter(fn (array $event): bool => $event['startsAt'] < "{$nextDateKey}T00:00"
                        && $event['endsAt'] > "{$dateKey}T00:00")->values()->all(),
                ];

                $cursor = $cursor->addDay();
            }

            $weeks[] = $week;
        }

        return $weeks;
    }

    public function store(SaveScheduleEventRequest $request, Team $currentTeam, Project $project): RedirectResponse
    {
        $data = $request->validated();
        $recurrence = $data['recurrence'] ?? null;
        $recurrenceUntil = $data['recurrence_until'] ?? null;
        unset($data['recurrence'], $data['recurrence_until']);
        $data['created_by'] = $request->user()->id;

        $event = DB::transaction(function () use ($data, $project, $request, $recurrence, $recurrenceUntil): ScheduleEvent {
            $groupId = $recurrence !== null ? (string) Str::uuid() : null;

            $event = $project->scheduleEvents()->create([
                ...$data,
                'recurrence' => $recurrence,
                'recurrence_group_id' => $groupId,
            ]);

            if ($recurrence !== null && $recurrenceUntil !== null && $groupId !== null) {
                $this->createRecurringOccurrences($project, $data, $recurrence, $groupId, $recurrenceUntil);
            }

            InlineAttachments::syncFromBody(
                $event,
                $project,
                $request->user()->id,
                $event->details,
            );
            Activity::record($project, $request->user(), ActivityType::ScheduleCreated, $event, $event->title);

            return $event;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event created.')]);

        return to_route('projects.schedule.index', [$currentTeam, $project, 'month' => $event->starts_at->format('Y-m')]);
    }

    public function toggleReaction(
        SaveReactionRequest $request,
        Team $currentTeam,
        Project $project,
        ScheduleEvent $scheduleEvent,
    ): RedirectResponse {
        Gate::authorize('view', $project);

        Reactions::toggle(
            $scheduleEvent->reactors(),
            $request->user()->id,
            (string) $request->validated('reaction'),
        );

        return back(fallback: route('projects.schedule.show', [$currentTeam, $project, $scheduleEvent]));
    }

    /** @param  array<string, mixed>  $data */
    private function createRecurringOccurrences(
        Project $project,
        array $data,
        string $recurrence,
        string $groupId,
        string $recurrenceUntil,
    ): void {
        $startsAt = CarbonImmutable::parse($data['starts_at']);
        $durationMinutes = $startsAt->diffInMinutes(CarbonImmutable::parse($data['ends_at']));
        $until = CarbonImmutable::parse($recurrenceUntil)->endOfDay();
        $details = $data['details'] ?? null;
        $occurrenceData = [
            ...$data,
            'details' => InlineAttachments::withoutFileReferences(is_string($details) ? $details : null),
        ];

        for ($i = 1; $i <= self::MAX_RECURRING_OCCURRENCES; $i++) {
            $nextStart = match ($recurrence) {
                'daily' => $startsAt->addDays($i),
                'weekly' => $startsAt->addWeeks($i),
                default => $startsAt->addMonthsNoOverflow($i),
            };

            if ($nextStart->greaterThan($until)) {
                break;
            }

            $project->scheduleEvents()->create([
                ...$occurrenceData,
                'starts_at' => $nextStart,
                'ends_at' => $nextStart->addMinutes($durationMinutes),
                'recurrence' => $recurrence,
                'recurrence_group_id' => $groupId,
            ]);
        }
    }

    public function update(
        SaveScheduleEventRequest $request,
        Team $currentTeam,
        Project $project,
        ScheduleEvent $scheduleEvent,
    ): RedirectResponse {
        $data = $request->validated();
        $scheduleEvent = DB::transaction(function () use ($data, $scheduleEvent, $project, $request): ScheduleEvent {
            $lockedEvent = ScheduleEvent::query()
                ->whereKey($scheduleEvent->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedEvent->update($data);
            InlineAttachments::syncFromBody(
                $lockedEvent,
                $project,
                $request->user()->id,
                $lockedEvent->details,
            );

            return $lockedEvent;
        });

        return to_route('projects.schedule.index', [$currentTeam, $project, 'month' => $scheduleEvent->starts_at->format('Y-m')]);
    }

    public function destroy(
        Team $currentTeam,
        Project $project,
        ScheduleEvent $scheduleEvent,
    ): RedirectResponse {
        Gate::authorize('view', $project);
        abort_unless($scheduleEvent->created_by === request()->user()->id || Gate::allows('update', $project), 403);

        $month = $scheduleEvent->starts_at->format('Y-m');
        DB::transaction(function () use ($scheduleEvent): void {
            InlineAttachments::detach($scheduleEvent);
            InlineAttachments::detachMany((new Comment)->getMorphClass(), $scheduleEvent->comments()->pluck('id'));
            $scheduleEvent->comments()->delete();
            $scheduleEvent->delete();
        });

        return to_route('projects.schedule.index', [$currentTeam, $project, 'month' => $month]);
    }
}
