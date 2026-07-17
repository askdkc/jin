<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Team;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('権限範囲内の任務について、名称、担当者、期限、完了状態を更新します。')]
class UpdateTodoTool extends Tool
{
    public function handle(Request $request): Response
    {
        $input = $request->validate([
            'team_slug' => ['required', 'string', 'max:255'],
            'user_email' => ['required', 'email'],
            'project_id' => ['required', 'integer'],
            'todo_id' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'assignee_email' => ['nullable', 'email'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'completed' => ['nullable', 'boolean'],
        ]);
        $team = Team::query()->where('slug', $input['team_slug'])->first();
        $user = $team?->members()->where('email', strtolower($input['user_email']))->first();
        $project = $team && $user
            ? Project::query()->visibleTo($user, $team)->whereKey($input['project_id'])->first()
            : null;
        $todo = $project
            ? Todo::query()
                ->whereHas('todoList', fn ($query) => $query->where('project_id', $project->id))
                ->find($input['todo_id'])
            : null;

        if (! $team || ! $user instanceof User || ! $project || ! $todo instanceof Todo) {
            return Response::error('チーム、所属ユーザー、プロジェクト、または任務が見つかりません。');
        }

        $changes = [];

        if (filled($input['title'] ?? null)) {
            $changes['title'] = $input['title'];
        }

        if (array_key_exists('assignee_email', $input)) {
            $assignee = filled($input['assignee_email'])
                ? $project->members()->where('email', strtolower($input['assignee_email']))->first()
                : null;

            if (filled($input['assignee_email']) && ! $assignee instanceof User) {
                return Response::error('担当者はプロジェクトメンバーではありません。');
            }

            $changes['assignee_id'] = $assignee?->id;
        }

        if (array_key_exists('due_on', $input)) {
            $changes['due_on'] = $input['due_on'];
        }

        if (array_key_exists('completed', $input)) {
            $changes['completed_at'] = $input['completed'] ? now() : null;
            $changes['completed_by'] = $input['completed'] ? $user->id : null;
        }

        if ($changes === []) {
            return Response::error('更新する項目を1つ以上指定してください。');
        }

        $wasIncomplete = $todo->completed_at === null;
        $isCompleting = array_key_exists('completed', $input) && (bool) $input['completed'];
        $todo->update($changes);

        if ($wasIncomplete && $isCompleting) {
            Activity::record($project, $user, ActivityType::TodoCompleted, $todo, $todo->title);
        }

        $todo->refresh()->load('assignee');

        return Response::json([
            'updated' => true,
            'todo' => [
                'id' => $todo->id,
                'title' => $todo->title,
                'assignee' => $todo->assignee?->name,
                'due_on' => $todo->due_on?->toDateString(),
                'completed' => $todo->completed_at !== null,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'team_slug' => $schema->string()->description('対象チームのslug')->required(),
            'user_email' => $schema->string()->description('操作主体となる所属ユーザーのメールアドレス')->required(),
            'project_id' => $schema->integer()->description('対象プロジェクトID')->required(),
            'todo_id' => $schema->integer()->description('対象任務ID')->required(),
            'title' => $schema->string()->description('新しい任務名'),
            'assignee_email' => $schema->string()->description('新しい担当者。空値で担当解除'),
            'due_on' => $schema->string()->description('期限（YYYY-MM-DD）'),
            'completed' => $schema->boolean()->description('完了または未完了へ変更'),
        ];
    }
}
