<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Http\Requests\Phase3\SaveMessageBoardPostReactionRequest as SaveReactionRequest;
use App\Http\Requests\Phase9\AnswerCheckInRequest;
use App\Http\Requests\Phase9\StoreCheckInRequest;
use App\Http\Requests\Phase9\UpdateCheckInRequest;
use App\Models\Activity;
use App\Models\CheckIn;
use App\Models\CheckInPrompt;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\InlineAttachments;
use App\Support\Reactions;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CheckInController extends Controller
{
    public function index(Team $currentTeam, Project $project): Response
    {
        Gate::authorize('view', $project);

        $userId = request()->user()->id;
        // Check-in policies grant creators or project managers; the latter is request-wide.
        $canManageProject = Gate::allows('update', $project);

        $checkIns = $project->checkIns()
            ->with(['creator:id,name', 'targets:id,name'])
            ->withCount([
                'prompts as pending_count' => fn ($query) => $query->whereNull('answered_at'),
            ])
            ->latest('id')
            ->get()
            ->map(fn (CheckIn $checkIn): array => [
                'id' => $checkIn->id,
                'question' => $checkIn->question,
                'frequency' => $checkIn->frequency->value,
                'weekdays' => $checkIn->scheduledWeekdays(),
                'timezone' => $checkIn->timezone,
                'nextRunAt' => $checkIn->next_run_at->toISOString(),
                'paused' => $checkIn->paused_at !== null,
                'creatorName' => $checkIn->creator?->name,
                'targetNames' => $checkIn->targets->pluck('name')->all(),
                'pendingCount' => (int) $checkIn->getAttribute('pending_count'),
                'canManage' => $checkIn->created_by === $userId || $canManageProject,
                'canDelete' => $checkIn->created_by === $userId || $canManageProject,
            ]);

        $pendingPrompts = CheckInPrompt::query()
            ->where('user_id', request()->user()->id)
            ->whereNull('answered_at')
            ->whereHas('checkIn', fn ($query) => $query->where('project_id', $project->id))
            ->with('checkIn:id,project_id,question')
            ->oldest('scheduled_for')
            ->get()
            ->map(fn (CheckInPrompt $prompt): array => [
                'id' => $prompt->id,
                'checkInId' => $prompt->check_in_id,
                'question' => $prompt->checkIn->question,
                'scheduledFor' => $prompt->scheduled_for->toISOString(),
            ]);

        $responses = CheckInPrompt::query()
            ->whereNotNull('answered_at')
            ->whereHas('checkIn', fn ($query) => $query->where('project_id', $project->id))
            ->with(['checkIn:id,project_id,question', 'user:id,name'])
            ->latest('answered_at')
            ->limit(100)
            ->get()
            ->map(fn (CheckInPrompt $prompt): array => [
                'id' => $prompt->id,
                'checkInId' => $prompt->check_in_id,
                'question' => $prompt->checkIn->question,
                'answer' => $prompt->answer,
                'userName' => $prompt->user?->name,
                'answeredAt' => $prompt->answered_at?->toISOString(),
                'canEdit' => $prompt->user_id === $userId,
            ]);

        return Inertia::render('projects/check-ins/Index', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'checkIns' => $checkIns,
            'pendingPrompts' => $pendingPrompts,
            'responses' => $responses,
            'members' => $project->members()
                ->orderBy('name')
                ->get(['users.id', 'users.name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
        ]);
    }

    public function store(
        StoreCheckInRequest $request,
        Team $currentTeam,
        Project $project,
    ): RedirectResponse {
        $validated = $request->validated();
        $targetUserIds = $validated['target_user_ids'];
        unset($validated['target_user_ids']);

        $checkIn = DB::transaction(function () use ($request, $project, $validated, $targetUserIds): CheckIn {
            $timezone = $validated['timezone'];
            $nextRunAt = CarbonImmutable::createFromFormat(
                'Y-m-d\\TH:i',
                $validated['next_run_at'],
                $timezone,
            )->setTimezone((string) config('app.timezone'));

            $checkIn = $project->checkIns()->create([
                ...$validated,
                'created_by' => $request->user()->id,
                'weekdays' => $request->weekdays(),
                'next_run_at' => $nextRunAt,
            ]);
            $checkIn->targets()->sync($targetUserIds);

            Activity::record($project, $request->user(), ActivityType::CheckInCreated, $checkIn, $checkIn->question);

            return $checkIn;
        });

        return to_route('projects.check-ins.index', [$currentTeam, $project]);
    }

    public function show(
        Team $currentTeam,
        Project $project,
        CheckIn $checkIn,
    ): Response {
        Gate::authorize('view', $checkIn);

        $checkIn->load(['creator:id,name', 'targets:id,name']);

        $pendingPrompt = $checkIn->prompts()
            ->where('user_id', request()->user()->id)
            ->whereNull('answered_at')
            ->oldest('scheduled_for')
            ->first();

        $canAnswer = $pendingPrompt !== null
            || $checkIn->targets->contains('id', request()->user()->id);

        $responses = $checkIn->prompts()
            ->whereNotNull('answered_at')
            ->with(['user:id,name', 'comments.user:id,name', 'reactors:id,name'])
            ->latest('answered_at')
            ->limit(100)
            ->get()
            ->map(fn (CheckInPrompt $prompt): array => [
                'id' => $prompt->id,
                'checkInId' => $checkIn->id,
                'question' => $checkIn->question,
                'answer' => $prompt->answer,
                'userName' => $prompt->user?->name,
                'answeredAt' => $prompt->answered_at?->toISOString(),
                'canEdit' => $prompt->user_id === request()->user()->id,
                'comments' => $prompt->comments->map(fn ($comment): array => [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'authorName' => $comment->user?->name,
                    'createdAt' => $comment->created_at?->toISOString(),
                ])->all(),
                'reactions' => Reactions::grouped($prompt->reactors, request()->user()->id),
            ]);

        return Inertia::render('projects/check-ins/Show', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'checkIn' => [
                'id' => $checkIn->id,
                'question' => $checkIn->question,
                'frequency' => $checkIn->frequency->value,
                'weekdays' => $checkIn->scheduledWeekdays(),
                'timezone' => $checkIn->timezone,
                'nextRunAt' => $checkIn->next_run_at->toISOString(),
                'nextRunAtLocal' => $checkIn->next_run_at
                    ->timezone($checkIn->timezone)
                    ->format('Y-m-d\TH:i'),
                'paused' => $checkIn->paused_at !== null,
                'creatorName' => $checkIn->creator?->name,
                'targetIds' => $checkIn->targets
                    ->pluck('id')
                    ->map(fn (int $id): int => $id)
                    ->all(),
                'targetNames' => $checkIn->targets->pluck('name')->all(),
                'canManage' => Gate::allows('update', $checkIn),
                'canDelete' => Gate::allows('delete', $checkIn),
                'canAnswer' => $canAnswer,
                'isBookmarked' => request()->user()
                    ->bookmarkedCheckIns()
                    ->whereKey($checkIn->id)
                    ->exists(),
            ],
            'pendingPrompt' => $pendingPrompt === null ? null : [
                'id' => $pendingPrompt->id,
                'checkInId' => $checkIn->id,
                'question' => $checkIn->question,
                'scheduledFor' => $pendingPrompt->scheduled_for->toISOString(),
            ],
            'responses' => $responses,
            'members' => $project->members()
                ->orderBy('name')
                ->get(['users.id', 'users.name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
        ]);
    }

    public function update(
        UpdateCheckInRequest $request,
        Team $currentTeam,
        Project $project,
        CheckIn $checkIn,
    ): RedirectResponse {
        $validated = $request->validated();
        $targetUserIds = $validated['target_user_ids'];
        unset($validated['target_user_ids']);

        DB::transaction(function () use ($request, $checkIn, $validated, $targetUserIds): void {
            $timezone = $validated['timezone'];
            $nextRunAt = CarbonImmutable::createFromFormat(
                'Y-m-d\\TH:i',
                $validated['next_run_at'],
                $timezone,
            )->setTimezone((string) config('app.timezone'));

            $checkIn->update([
                ...$validated,
                'weekdays' => $request->weekdays(),
                'next_run_at' => $nextRunAt,
            ]);
            $checkIn->targets()->sync($targetUserIds);
        });

        return back();
    }

    public function toggleBookmark(
        Request $request,
        Team $currentTeam,
        Project $project,
        CheckIn $checkIn,
    ): RedirectResponse {
        Gate::authorize('view', $checkIn);

        $request->user()->bookmarkedCheckIns()->toggle($checkIn->id);

        return back();
    }

    public function togglePause(
        Team $currentTeam,
        Project $project,
        CheckIn $checkIn,
    ): RedirectResponse {
        Gate::authorize('update', $checkIn);
        $checkIn->update([
            'paused_at' => $checkIn->paused_at === null ? now() : null,
            'next_run_at' => $checkIn->paused_at === null || $checkIn->next_run_at->isFuture()
                ? $checkIn->next_run_at
                : now(),
        ]);

        return back();
    }

    public function destroy(
        Team $currentTeam,
        Project $project,
        CheckIn $checkIn,
    ): RedirectResponse {
        Gate::authorize('delete', $checkIn);
        $checkIn->delete();

        return to_route('projects.check-ins.index', [$currentTeam, $project]);
    }

    public function respond(
        AnswerCheckInRequest $request,
        Team $currentTeam,
        Project $project,
        CheckIn $checkIn,
    ): RedirectResponse {
        $prompt = $checkIn->prompts()->create([
            'user_id' => $request->user()->id,
            'scheduled_for' => now(),
            'requested_at' => now(),
        ]);

        return $this->answer($request, $currentTeam, $project, $checkIn, $prompt);
    }

    public function answer(
        AnswerCheckInRequest $request,
        Team $currentTeam,
        Project $project,
        CheckIn $checkIn,
        CheckInPrompt $prompt,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $project, $prompt): void {
            $prompt->update([
                'answer' => $request->validated('answer'),
                'answered_at' => now(),
            ]);

            InlineAttachments::syncFromBody(
                $prompt,
                $project,
                $request->user()->id,
                $prompt->answer,
            );

            Activity::record(
                $project,
                $request->user(),
                ActivityType::CheckInAnswered,
                $prompt,
                $prompt->checkIn->question,
            );
        });

        return back();
    }

    public function updateAnswer(
        AnswerCheckInRequest $request,
        Team $currentTeam,
        Project $project,
        CheckIn $checkIn,
        CheckInPrompt $prompt,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $project, $prompt): void {
            $prompt->update(['answer' => $request->validated('answer')]);

            InlineAttachments::syncFromBody(
                $prompt,
                $project,
                $request->user()->id,
                $prompt->answer,
            );
        });

        return back();
    }

    public function toggleAnswerReaction(
        SaveReactionRequest $request,
        Team $currentTeam,
        Project $project,
        CheckIn $checkIn,
        CheckInPrompt $prompt,
    ): RedirectResponse {
        Gate::authorize('view', $checkIn);

        Reactions::toggle(
            $prompt->reactors(),
            $request->user()->id,
            (string) $request->validated('reaction'),
        );

        return back();
    }
}
