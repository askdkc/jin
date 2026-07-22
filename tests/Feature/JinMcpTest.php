<?php

use App\Enums\TeamRole;
use App\Mcp\Tools\CreateTodoTool;
use App\Mcp\Tools\CreateWorkspaceItemTool;
use App\Mcp\Tools\SearchWorkspaceTool;
use App\Mcp\Tools\UpdateTodoTool;
use App\Mcp\Tools\WorkspaceOverviewTool;
use App\Models\Document;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\Team;
use App\Models\Todo;
use App\Models\User;
use Laravel\Mcp\Request;

test('jin mcp reads searches and creates todos inside actor permissions', function () {
    $owner = User::factory()->create(['email' => 'agent@example.test']);
    $team = Team::factory()->create(['slug' => 'mcp-team']);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create([
        'name' => 'AI連携プロジェクト',
    ]);
    $project->members()->attach($owner);

    $created = (new CreateTodoTool)->handle(new Request([
        'team_slug' => $team->slug,
        'user_email' => $owner->email,
        'project_id' => $project->id,
        'title' => 'AIが登録した任務',
        'assignee_email' => $owner->email,
        'due_on' => '2026-08-20',
    ]));
    $createdPayload = json_decode((string) $created->content(), true, flags: JSON_THROW_ON_ERROR);

    expect($createdPayload['created'])->toBeTrue()
        ->and(Todo::query()->firstOrFail()->title)->toBe('AIが登録した任務')
        ->and(Todo::query()->firstOrFail()->todoList->is_loose)->toBeTrue();

    $overview = (new WorkspaceOverviewTool)->handle(new Request([
        'team_slug' => $team->slug,
        'user_email' => $owner->email,
    ]));
    $overviewPayload = json_decode((string) $overview->content(), true, flags: JSON_THROW_ON_ERROR);

    expect($overviewPayload['projects'][0]['name'])->toBe('AI連携プロジェクト')
        ->and($overviewPayload['open_todos'][0]['title'])->toBe('AIが登録した任務');

    $search = (new SearchWorkspaceTool)->handle(new Request([
        'team_slug' => $team->slug,
        'user_email' => $owner->email,
        'query' => 'AIが登録',
    ]));
    $searchPayload = json_decode((string) $search->content(), true, flags: JSON_THROW_ON_ERROR);

    expect($searchPayload['todos'][0]['title'])->toBe('AIが登録した任務');

    $updated = (new UpdateTodoTool)->handle(new Request([
        'team_slug' => $team->slug,
        'user_email' => $owner->email,
        'project_id' => $project->id,
        'todo_id' => Todo::query()->firstOrFail()->id,
        'completed' => true,
    ]));
    $updatedPayload = json_decode((string) $updated->content(), true, flags: JSON_THROW_ON_ERROR);

    expect($updatedPayload['todo']['completed'])->toBeTrue();

    foreach ([
        ['kind' => 'message', 'title' => 'AI掲示', 'body' => '**共有**'],
        ['kind' => 'document', 'title' => 'AI資料', 'body' => '<table><tr><td>内容</td></tr></table>'],
        ['kind' => 'chat', 'body' => 'AIからの会話'],
    ] as $item) {
        $response = (new CreateWorkspaceItemTool)->handle(new Request([
            'team_slug' => $team->slug,
            'user_email' => $owner->email,
            'project_id' => $project->id,
            ...$item,
        ]));
        $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
        expect($payload['created'])->toBeTrue();
    }

    expect(MessageBoardPost::query()->firstOrFail()->title)->toBe('AI掲示')
        ->and(Document::query()->firstOrFail()->body)->toContain('<table>')
        ->and($project->chatMessages()->firstOrFail()->body)->toBe('AIからの会話');
});

test('jin mcp refuses an actor outside the team', function () {
    $team = Team::factory()->create(['slug' => 'private-team']);

    $response = (new WorkspaceOverviewTool)->handle(new Request([
        'team_slug' => $team->slug,
        'user_email' => 'outsider@example.test',
    ]));

    expect((string) $response->content())->toContain('見つかりません');
});

test('jin mcp refuses a zero duration schedule event', function () {
    $owner = User::factory()->create(['email' => 'schedule-agent@example.test']);
    $team = Team::factory()->create(['slug' => 'mcp-schedule-team']);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);

    $response = (new CreateWorkspaceItemTool)->handle(new Request([
        'team_slug' => $team->slug,
        'user_email' => $owner->email,
        'project_id' => $project->id,
        'kind' => 'event',
        'title' => 'ゼロ時間予定',
        'starts_at' => '2026-08-20 10:00:00',
        'ends_at' => '2026-08-20 10:00:00',
    ]));

    expect((string) $response->content())
        ->toContain('終了日時は開始日時より後にしてください。')
        ->and($project->scheduleEvents()->exists())->toBeFalse();
});
