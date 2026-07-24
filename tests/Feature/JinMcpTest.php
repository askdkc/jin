<?php

use App\Enums\TeamRole;
use App\Mcp\Servers\JinServer;
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
use App\Models\TodoList;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Facades\Mcp;

test('jin mcp reads searches and creates todos inside the authenticated users permissions', function () {
    $owner = User::factory()->create(['email' => 'agent@example.test']);
    $team = Team::factory()->create(['slug' => 'mcp-team']);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create([
        'name' => 'AI連携プロジェクト',
    ]);
    $project->members()->attach($owner);

    JinServer::actingAs($owner)->tool(CreateTodoTool::class, [
        'team_slug' => $team->slug,
        'project_id' => $project->id,
        'title' => 'AIが登録した任務',
        'assignee_email' => $owner->email,
        'due_on' => '2026-08-20',
    ])->assertHasNoErrors()->assertSee('AIが登録した任務');

    expect(Todo::query()->firstOrFail()->title)->toBe('AIが登録した任務')
        ->and(Todo::query()->firstOrFail()->todoList->is_loose)->toBeTrue();

    JinServer::actingAs($owner)->tool(WorkspaceOverviewTool::class, [
        'team_slug' => $team->slug,
    ])->assertHasNoErrors()->assertSee(['AI連携プロジェクト', 'AIが登録した任務']);

    JinServer::actingAs($owner)->tool(SearchWorkspaceTool::class, [
        'team_slug' => $team->slug,
        'query' => 'AIが登録',
    ])->assertHasNoErrors()->assertSee('AIが登録した任務');

    JinServer::actingAs($owner)->tool(UpdateTodoTool::class, [
        'team_slug' => $team->slug,
        'project_id' => $project->id,
        'todo_id' => Todo::query()->firstOrFail()->id,
        'completed' => true,
    ])->assertHasNoErrors();

    expect(Todo::query()->firstOrFail()->completed_at)->not->toBeNull();

    foreach ([
        ['kind' => 'message', 'title' => 'AI掲示', 'body' => '**共有**'],
        ['kind' => 'document', 'title' => 'AI資料', 'body' => '<table><tr><td>内容</td></tr></table>'],
        ['kind' => 'chat', 'body' => 'AIからの会話'],
    ] as $item) {
        JinServer::actingAs($owner)->tool(CreateWorkspaceItemTool::class, [
            'team_slug' => $team->slug,
            'project_id' => $project->id,
            ...$item,
        ])->assertHasNoErrors();
    }

    expect(MessageBoardPost::query()->firstOrFail()->title)->toBe('AI掲示')
        ->and(Document::query()->firstOrFail()->body)->toContain('<table>')
        ->and($project->chatMessages()->firstOrFail()->body)->toBe('AIからの会話');
});

test('jin mcp requires an authenticated actor who belongs to the requested team', function () {
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create(['slug' => 'private-team']);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    JinServer::tool(WorkspaceOverviewTool::class, [
        'team_slug' => $team->slug,
    ])->assertHasErrors()->assertSee('認証済み利用者');

    JinServer::actingAs($outsider)->tool(WorkspaceOverviewTool::class, [
        'team_slug' => $team->slug,
    ])->assertHasErrors()->assertSee('認証済み利用者');
});

test('jin mcp cannot update another users todo without project management permission', function () {
    $owner = User::factory()->create();
    $creator = User::factory()->create();
    $other = User::factory()->create();
    $team = Team::factory()->create(['slug' => 'mcp-permissions']);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach([$creator->id, $other->id], ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$creator->id, $other->id]);
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($creator, 'creator')->create([
        'title' => '変更前',
    ]);

    JinServer::actingAs($other)->tool(UpdateTodoTool::class, [
        'team_slug' => $team->slug,
        'project_id' => $project->id,
        'todo_id' => $todo->id,
        'title' => '権限外変更',
    ])->assertHasErrors()->assertSee('権限がありません');

    expect($todo->fresh()->title)->toBe('変更前');

    JinServer::actingAs($owner)->tool(UpdateTodoTool::class, [
        'team_slug' => $team->slug,
        'project_id' => $project->id,
        'todo_id' => $todo->id,
        'title' => '管理者変更',
    ])->assertHasNoErrors();

    expect($todo->fresh()->title)->toBe('管理者変更');
});

test('jin mcp refuses a zero duration schedule event', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['slug' => 'mcp-schedule-team']);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);

    JinServer::actingAs($owner)->tool(CreateWorkspaceItemTool::class, [
        'team_slug' => $team->slug,
        'project_id' => $project->id,
        'kind' => 'event',
        'title' => 'ゼロ時間予定',
        'starts_at' => '2026-08-20 10:00:00',
        'ends_at' => '2026-08-20 10:00:00',
    ])->assertHasErrors()->assertSee('終了日時は開始日時より後にしてください。');

    expect($project->scheduleEvents()->exists())->toBeFalse();
});

test('jin mcp start command rejects an unknown fixed actor', function () {
    $this->artisan('jin:mcp', ['user' => 'missing@example.test'])
        ->expectsOutputToContain('not found')
        ->assertFailed();
});

test('jin mcp start command runs the registered server as the fixed actor', function () {
    $user = User::factory()->create(['email' => 'agent@example.test']);
    $started = false;

    Mcp::shouldReceive('getLocalServer')
        ->once()
        ->with('jin')
        ->andReturn(function () use (&$started, $user): void {
            $started = true;

            expect(Auth::id())->toBe($user->id);
        });

    $this->artisan('jin:mcp', ['user' => ' AGENT@EXAMPLE.TEST '])
        ->assertSuccessful();

    expect($started)->toBeTrue();
});
