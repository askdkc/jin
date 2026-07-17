<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\Team;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('利用者が閲覧できるJINのプロジェクト、未完了任務、直近予定をまとめて返します。')]
class WorkspaceOverviewTool extends Tool
{
    public function handle(Request $request): Response
    {
        $input = $request->validate([
            'team_slug' => ['required', 'string', 'max:255'],
            'user_email' => ['required', 'email'],
        ]);
        $team = Team::query()->where('slug', $input['team_slug'])->first();
        $user = $team?->members()->where('email', strtolower($input['user_email']))->first();

        if (! $team || ! $user instanceof User) {
            return Response::error('チームまたは所属ユーザーが見つかりません。');
        }

        $projects = Project::query()
            ->visibleTo($user, $team)
            ->whereNull('archived_at')
            ->withCount(['todoLists', 'messageBoardPosts', 'documents', 'storedFiles'])
            ->with(['scheduleEvents' => fn ($query) => $query
                ->where('starts_at', '>=', now())
                ->oldest('starts_at')
                ->limit(5)])
            ->orderBy('name')
            ->get();
        $projectIds = $projects->pluck('id');
        $todos = Todo::query()
            ->with(['todoList.project:id,name', 'assignee:id,name'])
            ->whereNull('parent_id')
            ->whereNull('completed_at')
            ->whereHas('todoList', fn ($query) => $query->whereIn('project_id', $projectIds))
            ->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_on')
            ->limit(50)
            ->get();

        return Response::json([
            'team' => ['id' => $team->id, 'name' => $team->name, 'slug' => $team->slug],
            'projects' => $projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status,
                'starts_on' => $project->starts_on?->toDateString(),
                'ends_on' => $project->ends_on?->toDateString(),
                'counts' => [
                    'todo_lists' => $project->todo_lists_count,
                    'messages' => $project->message_board_posts_count,
                    'documents' => $project->documents_count,
                    'files' => $project->stored_files_count,
                ],
                'upcoming_events' => $project->scheduleEvents
                    ->map(fn ($event): array => [
                        'id' => $event->id,
                        'title' => $event->title,
                        'starts_at' => $event->starts_at->toISOString(),
                        'meeting_url' => $event->meeting_url,
                    ])
                    ->values()
                    ->all(),
            ]),
            'open_todos' => $todos->map(fn (Todo $todo): array => [
                'id' => $todo->id,
                'title' => $todo->title,
                'project' => $todo->todoList->project->name,
                'assignee' => $todo->assignee?->name,
                'due_on' => $todo->due_on?->toDateString(),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'team_slug' => $schema->string()->description('対象チームのslug')->required(),
            'user_email' => $schema->string()->description('操作主体となる所属ユーザーのメールアドレス')->required(),
        ];
    }
}
