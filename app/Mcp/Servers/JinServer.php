<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateTodoTool;
use App\Mcp\Tools\CreateWorkspaceItemTool;
use App\Mcp\Tools\SearchWorkspaceTool;
use App\Mcp\Tools\UpdateTodoTool;
use App\Mcp\Tools\WorkspaceOverviewTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Jin Server')]
#[Version('1.0.0')]
#[Instructions('JINのプロジェクト、任務、掲示、資料、予定、会話を参照・検索し、利用者の権限範囲で作成・更新します。各ツールにはチームslugと操作主体のメールアドレスが必要です。')]
class JinServer extends Server
{
    protected array $tools = [
        WorkspaceOverviewTool::class,
        SearchWorkspaceTool::class,
        CreateTodoTool::class,
        UpdateTodoTool::class,
        CreateWorkspaceItemTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
