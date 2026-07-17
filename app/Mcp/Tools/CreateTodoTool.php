<?php

namespace App\Mcp\Tools;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Team;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('指定プロジェクトの未分類任務に新しい任務を作成します。利用者のプロジェクト閲覧権限を検証します。')]
class CreateTodoTool extends Tool
{
    public function handle(Request $request): Response
    {
        $input = $request->validate([
            'team_slug' => ['required', 'string', 'max:255'],
            'user_email' => ['required', 'email'],
            'project_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'assignee_email' => ['nullable', 'email'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $team = Team::query()->where('slug', $input['team_slug'])->first();
        $user = $team?->members()->where('email', strtolower($input['user_email']))->first();

        if (! $team || ! $user instanceof User) {
            return Response::error('チームまたは所属ユーザーが見つかりません。');
        }

        $project = Project::query()
            ->visibleTo($user, $team)
            ->whereKey($input['project_id'])
            ->first();

        if (! $project) {
            return Response::error('閲覧可能なプロジェクトが見つかりません。');
        }

        $assignee = isset($input['assignee_email'])
            ? $project->members()->where('email', strtolower($input['assignee_email']))->first()
            : null;

        if (isset($input['assignee_email']) && ! $assignee instanceof User) {
            return Response::error('担当者はプロジェクトメンバーではありません。');
        }

        $todo = DB::transaction(function () use ($input, $project, $user, $assignee): Todo {
            $list = $project->todoLists()->firstOrCreate(
                ['is_loose' => true],
                ['title' => '未分類の任務', 'position' => 0],
            );
            $todo = $list->todos()->create([
                'created_by' => $user->id,
                'assignee_id' => $assignee?->id,
                'title' => $input['title'],
                'due_on' => $input['due_on'] ?? null,
                'position' => ((int) $list->todos()->whereNull('parent_id')->max('position')) + 1,
            ]);
            Activity::record($project, $user, ActivityType::TodoCreated, $todo, $todo->title);

            return $todo;
        });

        return Response::json([
            'created' => true,
            'todo' => [
                'id' => $todo->id,
                'title' => $todo->title,
                'project_id' => $project->id,
                'assignee' => $assignee?->name,
                'due_on' => $todo->due_on?->toDateString(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'team_slug' => $schema->string()->description('対象チームのslug')->required(),
            'user_email' => $schema->string()->description('操作主体となる所属ユーザーのメールアドレス')->required(),
            'project_id' => $schema->integer()->description('任務を追加するプロジェクトID')->required(),
            'title' => $schema->string()->description('任務名')->required(),
            'assignee_email' => $schema->string()->description('任意の担当者メールアドレス'),
            'due_on' => $schema->string()->description('任意の期限（YYYY-MM-DD）'),
        ];
    }
}
