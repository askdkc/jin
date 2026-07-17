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
        $ganttRange = $view === 'gantt'
            ? (string) ($request->validated('range') ?? 'quarter')
            : 'quarter';

        if ($view === 'gantt') {
            $customStart = $ganttRange === 'custom'
                ? CarbonImmutable::parse((string) $request->validated('start'), $timezone)->startOfDay()
                : $anchor;
            $customEnd = $ganttRange === 'custom'
                ? CarbonImmutable::parse((string) $request->validated('end'), $timezone)->startOfDay()
                : $anchor;
            $customDayCount = $customStart->diffInDays($customEnd) + 1;
            $halfStart = $anchor->month <= 6
                ? $anchor->startOfYear()
                : $anchor->startOfYear()->addMonths(6);

            [$start, $end, $previous, $next] = match ($ganttRange) {
                'week' => [
                    $anchor->startOfWeek(CarbonInterface::SUNDAY),
                    $anchor->endOfWeek(CarbonInterface::SATURDAY),
                    $anchor->startOfWeek(CarbonInterface::SUNDAY)->subWeek(),
                    $anchor->startOfWeek(CarbonInterface::SUNDAY)->addWeek(),
                ],
                'month' => [
                    $anchor->startOfMonth(),
                    $anchor->endOfMonth(),
                    $anchor->startOfMonth()->subMonthNoOverflow(),
                    $anchor->startOfMonth()->addMonthNoOverflow(),
                ],
                'half-year' => [
                    $halfStart,
                    $halfStart->addMonths(6)->subSecond(),
                    $halfStart->subMonths(6),
                    $halfStart->addMonths(6),
                ],
                'year' => [
                    $anchor->startOfYear(),
                    $anchor->endOfYear(),
                    $anchor->startOfYear()->subYear(),
                    $anchor->startOfYear()->addYear(),
                ],
                'custom' => [
                    $customStart,
                    $customEnd->endOfDay(),
                    $customStart->subDays($customDayCount),
                    $customStart->addDays($customDayCount),
                ],
                default => [
                    $anchor->startOfQuarter(),
                    $anchor->endOfQuarter(),
                    $anchor->startOfQuarter()->subQuarter(),
                    $anchor->startOfQuarter()->addQuarter(),
                ],
            };
        } else {
            [$start, $end, $previous, $next] = match ($view) {
                'day' => [$anchor, $anchor->endOfDay(), $anchor->subDay(), $anchor->addDay()],
                'week' => [
                    $anchor->startOfWeek(CarbonInterface::SUNDAY),
                    $anchor->endOfWeek(CarbonInterface::SATURDAY),
                    $anchor->subWeek(),
                    $anchor->addWeek(),
                ],
                default => [
                    $anchor->startOfMonth(),
                    $anchor->endOfMonth(),
                    $anchor->subMonthNoOverflow(),
                    $anchor->addMonthNoOverflow(),
                ],
            };
        }
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
            'date' => ($ganttRange === 'custom' ? $start : $anchor)->toDateString(),
            'today' => CarbonImmutable::now($timezone)->toDateString(),
            'rangeStart' => $start->toDateString(),
            'rangeEnd' => $end->toDateString(),
            'previousDate' => $previous->toDateString(),
            'nextDate' => $next->toDateString(),
            'ganttRange' => $ganttRange,
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
