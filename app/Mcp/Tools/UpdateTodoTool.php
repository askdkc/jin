<?php

namespace App\Mcp\Tools;

use App\Actions\Todos\SetTodoCompletion;
use App\Actions\Todos\UpdateTodo;
use App\Mcp\McpContext;
use App\Models\Todo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('権限範囲内の任務について、名称、担当者、期限、完了状態を更新します。')]
class UpdateTodoTool extends Tool
{
    public function __construct(
        private McpContext $context,
        private UpdateTodo $updateTodo,
        private SetTodoCompletion $setTodoCompletion,
    ) {}

    public function handle(Request $request): Response
    {
        $input = $request->validate([
            'team_slug' => ['required', 'string', 'max:255'],
            'project_id' => ['required', 'integer'],
            'todo_id' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'assignee_email' => ['nullable', 'email'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'completed' => ['nullable', 'boolean'],
        ]);
        $context = $this->context->project($request, $input['team_slug'], (int) $input['project_id']);
        $todo = $context === null
            ? null
            : Todo::query()
                ->whereHas('todoList', fn ($query) => $query->where('project_id', $context[2]->id))
                ->find($input['todo_id']);

        if ($context === null || ! $todo instanceof Todo) {
            return Response::error('認証済み利用者が閲覧できるチーム、プロジェクト、または任務が見つかりません。');
        }

        [, $user, $project] = $context;

        if (Gate::forUser($user)->denies('update', $todo)) {
            return Response::error('この任務を更新する権限がありません。');
        }

        $changes = [];

        if (filled($input['title'] ?? null)) {
            $changes['title'] = $input['title'];
        }

        if (array_key_exists('assignee_email', $input)) {
            $assignee = filled($input['assignee_email'])
                ? $project->members()->where('email', mb_strtolower($input['assignee_email']))->first()
                : null;

            if (filled($input['assignee_email']) && $assignee === null) {
                return Response::error('担当者はプロジェクトメンバーではありません。');
            }

            $changes['assignee_id'] = $assignee?->id;
        }

        if (array_key_exists('due_on', $input)) {
            $changes['due_on'] = $input['due_on'];
        }

        $hasCompletionChange = array_key_exists('completed', $input);

        if ($changes === [] && ! $hasCompletionChange) {
            return Response::error('更新する項目を1つ以上指定してください。');
        }

        $todo = DB::transaction(function () use ($project, $todo, $user, $changes, $hasCompletionChange, $input): Todo {
            if ($changes !== []) {
                $todo = $this->updateTodo->handle($project, $todo, $user, $changes);
            }

            if ($hasCompletionChange) {
                $todo = $this->setTodoCompletion->handle(
                    $project,
                    $todo,
                    $user,
                    (bool) $input['completed'],
                );
            }

            return $todo;
        }, attempts: 5);

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
            'project_id' => $schema->integer()->description('対象プロジェクトID')->required(),
            'todo_id' => $schema->integer()->description('対象任務ID')->required(),
            'title' => $schema->string()->description('新しい任務名'),
            'assignee_email' => $schema->string()->description('新しい担当者。空値で担当解除'),
            'due_on' => $schema->string()->description('期限（YYYY-MM-DD）'),
            'completed' => $schema->boolean()->description('完了または未完了へ変更'),
        ];
    }
}
