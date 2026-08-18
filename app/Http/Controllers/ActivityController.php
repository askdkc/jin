<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActivityIndexRequest;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\ActivityNavigation;
use App\Support\FullTextSearch;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    public function __construct(private FullTextSearch $fullTextSearch) {}

    public function __invoke(ActivityIndexRequest $request, Team $currentTeam): Response
    {
        $filters = $request->validated();
        $search = isset($filters['q']) && trim((string) $filters['q']) !== ''
            ? trim((string) $filters['q'])
            : null;
        $projectId = isset($filters['project']) ? (int) $filters['project'] : null;
        $actorId = isset($filters['user']) ? (int) $filters['user'] : null;
        $visibleProjects = Project::query()
            ->visibleTo($request->user(), $currentTeam)
            ->whereNull('archived_at');

        $activities = Activity::query()
            ->select(['id', 'project_id', 'actor_id', 'type', 'subject_type', 'subject_id', 'metadata', 'created_at'])
            ->with(['actor:id,name,avatar_path', 'project:id,team_id,name'])
            ->whereIn('project_id', (clone $visibleProjects)->select('projects.id'))
            ->when($projectId !== null, fn (Builder $query) => $query->where('project_id', $projectId))
            ->when($actorId !== null, fn (Builder $query) => $query->where('actor_id', $actorId))
            ->when($search !== null, function (Builder $query) use ($search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $this->fullTextSearch->apply(
                        $searchQuery,
                        FullTextSearch::ACTIVITIES,
                        $search,
                    );
                    $searchQuery->orWhereIn(
                        'actor_id',
                        User::query()
                            ->where(function (Builder $actorQuery) use ($search): void {
                                $this->fullTextSearch->apply(
                                    $actorQuery,
                                    FullTextSearch::USERS,
                                    $search,
                                );
                            })
                            ->select('users.id'),
                    );
                    $searchQuery->orWhereIn(
                        'project_id',
                        Project::query()
                            ->where(function (Builder $projectQuery) use ($search): void {
                                $this->fullTextSearch->apply(
                                    $projectQuery,
                                    FullTextSearch::PROJECTS,
                                    $search,
                                );
                            })
                            ->select('projects.id'),
                    );
                });
            })
            ->latest('id')
            ->limit(50)
            ->get();
        $todoCommentTargets = ActivityNavigation::todoCommentTargets($activities);
        $activities = $activities
            ->map(fn (Activity $activity): array => [
                'id' => $activity->id,
                'type' => $activity->type->value,
                'target' => $activity->type->target(),
                'actorId' => $activity->actor_id,
                'actorName' => $activity->actor?->name,
                'actorAvatar' => $activity->actor?->avatar,
                'subjectLabel' => $activity->metadata['label'],
                'subjectId' => ($activity->metadata['subjectDeleted'] ?? false)
                    ? null
                    : $activity->subject_id,
                'todoId' => $todoCommentTargets[$activity->id]['todoId'] ?? null,
                'todoListId' => $todoCommentTargets[$activity->id]['todoListId'] ?? null,
                'createdAt' => $activity->created_at?->toISOString(),
                'project' => [
                    'id' => $activity->project->id,
                    'name' => $activity->project->name,
                ],
            ]);

        return Inertia::render('activity/Index', [
            'activities' => $activities,
            'projects' => (clone $visibleProjects)
                ->orderBy('name')
                ->get(['projects.id', 'projects.name'])
                ->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name]),
            'people' => User::query()
                ->whereIn('id', Activity::query()
                    ->select('actor_id')
                    ->whereIn('project_id', (clone $visibleProjects)->select('projects.id'))
                    ->whereNotNull('actor_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
            'filters' => [
                'q' => $search,
                'project' => $projectId,
                'user' => $actorId,
            ],
        ]);
    }
}
