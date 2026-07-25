<?php

namespace App\Mcp\Tools;

use App\Actions\Todos\CreateTodo;
use App\Mcp\McpContext;
use App\Models\Project;
use App\Models\Todo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('指定プロジェクトの未分類任務に新しい任務を作成します。認証済み利用者のプロジェクト閲覧権限を検証します。')]
class CreateTodoTool extends Tool
{
    public function __construct(
        private McpContext $context,
        private CreateTodo $createTodo,
    ) {}

    public function handle(Request $request): Response
    {
        $input = $request->validate([
            'team_slug' => ['required', 'string', 'max:255'],
            'project_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'assignee_email' => ['nullable', 'email'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $context = $this->context->project($request, $input['team_slug'], (int) $input['project_id']);

        if ($context === null) {
            return Response::error('認証済み利用者が閲覧できるチームまたはプロジェクトが見つかりません。');
        }

        [, $user, $project] = $context;
        $assignee = isset($input['assignee_email'])
            ? $project->members()->where('email', mb_strtolower($input['assignee_email']))->first()
            : null;

        if (isset($input['assignee_email']) && $assignee === null) {
            return Response::error('担当者はプロジェクトメンバーではありません。');
        }

        $todo = DB::transaction(function () use ($project, $user, $input, $assignee): Todo {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $list = $project->todoLists()->where('is_loose', true)->first();

            if ($list === null) {
                $lastPosition = $project->todoLists()->max('position');
                $list = $project->todoLists()->create([
                    'title' => '未分類の任務',
                    'is_loose' => true,
                    'position' => $lastPosition === null ? 0 : ((int) $lastPosition) + 1,
                ]);
            }

            return $this->createTodo->handle(
                $project,
                $list,
                $user,
                [
                    'title' => $input['title'],
                    'assignee_id' => $assignee?->id,
                    'due_on' => $input['due_on'] ?? null,
                ],
            );
        }, attempts: 5);

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
            'project_id' => $schema->integer()->description('任務を追加するプロジェクトID')->required(),
            'title' => $schema->string()->description('任務名')->required(),
            'assignee_email' => $schema->string()->description('任意の担当者メールアドレス'),
            'due_on' => $schema->string()->description('任意の期限（YYYY-MM-DD）'),
        ];
    }
}
