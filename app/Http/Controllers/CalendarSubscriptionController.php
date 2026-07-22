<?php

namespace App\Http\Controllers;

use App\Http\Requests\Phase11\StoreCalendarSubscriptionRequest;
use App\Models\CalendarSubscription;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\Team;
use App\Support\RichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;

class CalendarSubscriptionController extends Controller
{
    public function store(StoreCalendarSubscriptionRequest $request, Team $currentTeam): RedirectResponse
    {
        $currentTeam->calendarSubscriptions()->create([
            'user_id' => $request->user()->id,
            'name' => $request->validated('name'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Calendar subscription created.')]);

        return to_route('calendar.index', $currentTeam);
    }

    public function destroy(
        Request $request,
        Team $currentTeam,
        CalendarSubscription $calendarSubscription,
    ): RedirectResponse {
        abort_unless($calendarSubscription->user_id === $request->user()->id, 403);

        $calendarSubscription->update(['revoked_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Calendar subscription revoked.')]);

        return to_route('calendar.index', $currentTeam);
    }

    public function show(Request $request, CalendarSubscription $calendarSubscription): Response
    {
        abort_unless($request->hasValidSignature() && $calendarSubscription->revoked_at === null, 404);

        $calendarSubscription->load(['team', 'user']);
        $team = $calendarSubscription->team;
        $user = $calendarSubscription->user;

        abort_unless($user->belongsToTeam($team), 404);

        $events = ScheduleEvent::query()
            ->with('project:id,team_id,name')
            ->whereHas('project', fn (Builder $query) => $query
                ->whereIn(
                    'projects.id',
                    Project::query()->visibleTo($user, $team)->select('projects.id'),
                )
                ->whereNull('archived_at'))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        return new Response(
            $this->calendar($events, $team, $request->getHost()),
            headers: [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'Content-Disposition' => 'inline; filename="workspace-calendar.ics"',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    /**
     * @param  Collection<int, ScheduleEvent>  $events
     */
    private function calendar(Collection $events, Team $team, string $host): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//'.config('app.name').'//Workspace Calendar//JA',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escapeText($team->name),
        ];

        foreach ($events as $event) {
            $description = $event->project->name;
            $details = RichText::plainText($event->details);

            if ($details !== '') {
                $description .= "\n".$details;
            }

            $lines = [
                ...$lines,
                'BEGIN:VEVENT',
                "UID:schedule-event-{$event->id}@{$host}",
                'DTSTAMP:'.($event->updated_at ?? now())->copy()->utc()->format('Ymd\THis\Z'),
                'SUMMARY:'.$this->escapeText($event->title),
                'DESCRIPTION:'.$this->escapeText($description),
                'URL:'.route('projects.schedule.index', [$team, $event->project]),
                ...$this->eventDates($event),
                'END:VEVENT',
            ];
        }

        $lines[] = 'END:VCALENDAR';

        return collect($lines)
            ->map(fn (string $line): string => $this->foldLine($line))
            ->implode("\r\n")."\r\n";
    }

    /** @return array{0: string, 1: string} */
    private function eventDates(ScheduleEvent $event): array
    {
        if ($event->all_day) {
            return [
                'DTSTART;VALUE=DATE:'.$event->starts_at->format('Ymd'),
                'DTEND;VALUE=DATE:'.$event->ends_at->copy()->addDay()->format('Ymd'),
            ];
        }

        return [
            'DTSTART:'.$event->starts_at->copy()->utc()->format('Ymd\THis\Z'),
            'DTEND:'.$event->ends_at->copy()->utc()->format('Ymd\THis\Z'),
        ];
    }

    private function escapeText(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ',', ';'],
            ['\\\\', '\n', '\n', '\n', '\,', '\;'],
            $value,
        );
    }

    private function foldLine(string $line): string
    {
        $folded = [];
        $current = '';

        foreach (mb_str_split($line) as $character) {
            if ($current !== '' && strlen($current.$character) > 75) {
                $folded[] = $current;
                $current = ' '.$character;

                continue;
            }

            $current .= $character;
        }

        $folded[] = $current;

        return implode("\r\n", $folded);
    }
}
