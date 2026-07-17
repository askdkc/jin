<?php

namespace App\Http\Controllers;

use App\Http\Requests\Phase9\WorkspaceCalendarRequest;
use App\Models\CalendarSubscription;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceCalendarController extends Controller
{
    public function __invoke(WorkspaceCalendarRequest $request, Team $currentTeam): Response
    {
        $timezone = (string) config('app.timezone');
        $view = (string) ($request->validated('view') ?? 'month');
        $date = (string) ($request->validated('date')
            ?? ($request->validated('month') ? $request->validated('month').'-01' : today()->toDateString()));
        $anchor = CarbonImmutable::parse($date, $timezone)->startOfDay();

        [$start, $end, $previous, $next] = match ($view) {
            'day' => [$anchor, $anchor->endOfDay(), $anchor->subDay(), $anchor->addDay()],
            'week' => [
                $anchor->startOfWeek(CarbonInterface::SUNDAY),
                $anchor->endOfWeek(CarbonInterface::SATURDAY),
                $anchor->subWeek(),
                $anchor->addWeek(),
            ],
            'gantt' => [
                $anchor->startOfWeek(CarbonInterface::SUNDAY)->subWeeks(5),
                $anchor->startOfWeek(CarbonInterface::SUNDAY)->subWeeks(5)->addWeeks(12)->subSecond(),
                $anchor->subWeeks(6),
                $anchor->addWeeks(6),
            ],
            default => [
                $anchor->startOfMonth(),
                $anchor->endOfMonth(),
                $anchor->subMonthNoOverflow(),
                $anchor->addMonthNoOverflow(),
            ],
        };
        $events = ScheduleEvent::query()
            ->with('project:id,team_id,name')
            ->where('starts_at', '<=', $end)
            ->where('ends_at', '>=', $start)
            ->whereHas('project', fn (Builder $query) => $query
                ->whereIn(
                    'projects.id',
                    Project::query()->visibleTo($request->user(), $currentTeam)->select('projects.id'),
                )
                ->whereNull('archived_at'))
            ->orderBy('starts_at')
            ->get()
            ->map(fn (ScheduleEvent $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'startsAt' => $event->starts_at->toISOString(),
                'endsAt' => $event->ends_at->toISOString(),
                'allDay' => $event->all_day,
                'project' => [
                    'id' => $event->project->id,
                    'name' => $event->project->name,
                ],
            ]);
        $ganttProjects = $view === 'gantt'
            ? Project::query()
                ->visibleTo($request->user(), $currentTeam)
                ->whereNull('archived_at')
                ->whereNotNull('starts_on')
                ->whereNotNull('ends_on')
                ->whereDate('starts_on', '<=', $end)
                ->whereDate('ends_on', '>=', $start)
                ->orderBy('starts_on')
                ->get(['id', 'name', 'starts_on', 'ends_on'])
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'startsOn' => $project->starts_on->toDateString(),
                    'endsOn' => $project->ends_on->toDateString(),
                ])
            : collect();

        return Inertia::render('calendar/Index', [
            'view' => $view,
            'date' => $anchor->toDateString(),
            'today' => CarbonImmutable::now($timezone)->toDateString(),
            'rangeStart' => $start->toDateString(),
            'rangeEnd' => $end->toDateString(),
            'previousDate' => $previous->toDateString(),
            'nextDate' => $next->toDateString(),
            'month' => $anchor->format('Y-m'),
            'previousMonth' => $anchor->subMonthNoOverflow()->format('Y-m'),
            'nextMonth' => $anchor->addMonthNoOverflow()->format('Y-m'),
            'events' => $events,
            'ganttProjects' => $ganttProjects,
            'subscriptions' => $request->user()
                ->calendarSubscriptions()
                ->where('team_id', $currentTeam->id)
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn (CalendarSubscription $subscription): array => [
                    'id' => $subscription->id,
                    'name' => $subscription->name,
                    'url' => $subscription->revoked_at === null
                        ? URL::signedRoute('calendar-subscriptions.show', ['calendar_subscription' => $subscription])
                        : null,
                    'revoked' => $subscription->revoked_at !== null,
                    'createdAt' => $subscription->created_at?->toISOString(),
                ]),
        ]);
    }
}
