<?php

use App\Enums\ActivityType;
use App\Enums\TeamRole;
use App\Mcp\Servers\JinServer;
use App\Mcp\Tools\CreateTodoTool;
use App\Mcp\Tools\CreateWorkspaceItemTool;
use App\Mcp\Tools\SearchWorkspaceTool;
use App\Mcp\Tools\UpdateTodoTool;
use App\Mcp\Tools\WorkspaceOverviewTool;
use App\Models\Activity;
use App\Models\Document;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use App\Notifications\TodoActivityNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Laravel\Mcp\Facades\Mcp;

test('jin mcp reads searches and creates todos inside the authenticated users permissions', function () {
    $owner = User::factory()->create(['email' => 'agent@example.test']);
    $team = Team::factory()->create(['slug' => 'mcp-team']);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create([
        'name' => 'AI連携プロジェクト',
    ]);
    $project->members()->attach($owner);
    TodoList::factory()->for($project)->create(['position' => 0]);

    JinServer::actingAs($owner)->tool(CreateTodoTool::class, [
        'team_slug' => $team->slug,
        'project_id' => $project->id,
        'title' => 'AIが登録した任務',
        'assignee_email' => $owner->email,
        'due_on' => '2026-08-20',
    ])->assertHasNoErrors()->assertSee('AIが登録した任務');

    JinServer::actingAs($owner)->tool(CreateTodoTool::class, [
        'team_slug' => $team->slug,
        'project_id' => $project->id,
        'title' => 'AIが登録した次の任務',
    ])->assertHasNoErrors();

    $createdTodo = Todo::query()
        ->where('title', 'AIが登録した任務')
        ->sole();

    expect($createdTodo->todoList->is_loose)->toBeTrue()
        ->and(TodoList::query()->where('is_loose', true)->sole()->position)->toBe(1)
        ->and(TodoList::query()->orderBy('position')->pluck('position')->all())->toBe([0, 1])
        ->and(Todo::query()->orderBy('position')->pluck('position')->all())->toBe([0, 1]);

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
        'todo_id' => $createdTodo->id,
        'completed' => true,
    ])->assertHasNoErrors();

    expect($createdTodo->fresh()->completed_at)->not->toBeNull();

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

test('jin mcp todo updates preserve omitted fields and share completion side effects', function () {
    $owner = User::factory()->create();
    $creator = User::factory()->create();
    $firstAssignee = User::factory()->create();
    $secondAssignee = User::factory()->create();
    $watcher = User::factory()->create();
    $team = Team::factory()->create(['slug' => 'mcp-todo-workflows']);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach(
        [$creator->id, $firstAssignee->id, $secondAssignee->id, $watcher->id],
        ['role' => TeamRole::Member->value],
    );
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([
        $owner->id,
        $creator->id,
        $firstAssignee->id,
        $secondAssignee->id,
        $watcher->id,
    ]);
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($creator, 'creator')->create([
        'title' => '変更前',
        'details' => '<p>残すメモ</p>',
        'assignee_id' => $firstAssignee->id,
        'due_on' => '2026-08-20',
        'completion_notify_ids' => [$watcher->id],
    ]);
    Notification::fake();

    JinServer::actingAs($owner)->tool(UpdateTodoTool::class, [
        'team_slug' => $team->slug,
        'project_id' => $project->id,
        'todo_id' => $todo->id,
        'title' => '名称だけ変更',
    ])->assertHasNoErrors();

    expect($todo->fresh())
        ->title->toBe('名称だけ変更')
        ->details->toBe('<p>残すメモ</p>')
        ->assignee_id->toBe($firstAssignee->id)
        ->completion_notify_ids->toBe([$watcher->id])
        ->and($todo->fresh()->due_on?->toDateString())->toBe('2026-08-20');
    Notification::assertNothingSent();

    JinServer::actingAs($owner)->tool(UpdateTodoTool::class, [
        'team_slug' => $team->slug,
        'project_id' => $project->id,
        'todo_id' => $todo->id,
        'assignee_email' => $secondAssignee->email,
    ])->assertHasNoErrors();
    Notification::assertSentTo(
        $secondAssignee,
        TodoActivityNotification::class,
        fn (TodoActivityNotification $notification): bool => $notification->type === TodoActivityNotification::Assigned,
    );

    Notification::fake();

    JinServer::actingAs($owner)->tool(UpdateTodoTool::class, [
        'team_slug' => $team->slug,
        'project_id' => $project->id,
        'todo_id' => $todo->id,
        'completed' => true,
    ])->assertHasNoErrors();

    expect($todo->fresh())
        ->completed_at->not->toBeNull()
        ->completed_by->toBe($owner->id)
        ->and(Activity::query()
            ->where('project_id', $project->id)
            ->where('type', ActivityType::TodoCompleted->value)
            ->count())->toBe(1);
    Notification::assertSentTo([$creator, $secondAssignee, $watcher], TodoActivityNotification::class);
    Notification::assertNotSentTo($owner, TodoActivityNotification::class);

    Notification::fake();

    JinServer::actingAs($owner)->tool(UpdateTodoTool::class, [
        'team_slug' => $team->slug,
        'project_id' => $project->id,
        'todo_id' => $todo->id,
        'completed' => true,
    ])->assertHasNoErrors();

    expect(Activity::query()
        ->where('project_id', $project->id)
        ->where('type', ActivityType::TodoCompleted->value)
        ->count())->toBe(1);
    Notification::assertNothingSent();

    JinServer::actingAs($owner)->tool(UpdateTodoTool::class, [
        'team_slug' => $team->slug,
        'project_id' => $project->id,
        'todo_id' => $todo->id,
        'completed' => false,
    ])->assertHasNoErrors();

    expect($todo->fresh())
        ->completed_at->toBeNull()
        ->completed_by->toBeNull()
        ->and(Activity::query()
            ->where('project_id', $project->id)
            ->where('type', ActivityType::TodoCompleted->value)
            ->count())->toBe(1);
    Notification::assertNothingSent();
});

test('http and mcp todo writes produce equivalent overlapping state', function () {
    $owner = User::factory()->create();
    $assignee = User::factory()->create();
    $team = Team::factory()->create(['slug' => 'todo-write-parity']);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($assignee, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $assignee->id]);
    $list = TodoList::factory()->for($project)->create();
    $httpTodo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create();
    $mcpTodo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create();
    Notification::fake();

    $this->actingAs($owner)
        ->patch(route('projects.todos.update', [$team, $project, $list, $httpTodo]), [
            'title' => '共通の変更',
            'assignee_id' => $assignee->id,
            'due_on' => '2026-08-25',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    JinServer::actingAs($owner)->tool(UpdateTodoTool::class, [
        'team_slug' => $team->slug,
        'project_id' => $project->id,
        'todo_id' => $mcpTodo->id,
        'title' => '共通の変更',
        'assignee_email' => $assignee->email,
        'due_on' => '2026-08-25',
    ])->assertHasNoErrors();

    expect($httpTodo->fresh())
        ->title->toBe($mcpTodo->fresh()->title)
        ->assignee_id->toBe($mcpTodo->fresh()->assignee_id)
        ->and($httpTodo->fresh()->due_on?->toDateString())
        ->toBe($mcpTodo->fresh()->due_on?->toDateString());

    $this->patch(route('projects.todos.toggle', [$team, $project, $list, $httpTodo]))
        ->assertRedirect();
    JinServer::actingAs($owner)->tool(UpdateTodoTool::class, [
        'team_slug' => $team->slug,
        'project_id' => $project->id,
        'todo_id' => $mcpTodo->id,
        'completed' => true,
    ])->assertHasNoErrors();

    expect($httpTodo->fresh())
        ->completed_at->not->toBeNull()
        ->completed_by->toBe($owner->id)
        ->and($mcpTodo->fresh()->completed_at)->not->toBeNull()
        ->and($mcpTodo->fresh()->completed_by)->toBe($owner->id);
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
