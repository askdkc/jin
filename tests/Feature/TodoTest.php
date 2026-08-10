<?php

use App\Enums\ActivityType;
use App\Enums\TeamRole;
use App\Models\ChatChannel;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use App\Notifications\MentionNotification;
use App\Notifications\NewTodoListCommentNotification;
use App\Notifications\TodoActivityNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

test('project participants can create and complete assigned todos', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);

    $this->actingAs($member)
        ->post(route('projects.todo-lists.store', [$team, $project]), ['title' => '公開準備'])
        ->assertRedirect(route('projects.todos.index', [$team, $project]));

    $list = TodoList::query()->firstOrFail();

    expect($list->position)->toBe(0);

    $this->actingAs($member)
        ->patch(route('projects.todo-lists.update', [$team, $project, $list]), [
            'title' => '公開準備リスト',
        ])
        ->assertRedirect();

    $this->actingAs($member)
        ->post(route('projects.todos.store', [$team, $project, $list]), [
            'title' => '公開前チェックを行う',
            'details' => 'スマートフォンでも確認する',
            'assignee_id' => $member->id,
            'due_on' => '2026-08-01',
        ])
        ->assertRedirect();

    $todo = Todo::query()->firstOrFail();

    $this->actingAs($owner)
        ->patch(route('projects.todos.toggle', [$team, $project, $list, $todo]))
        ->assertRedirect();

    $this->actingAs($member)
        ->get(route('projects.todos.index', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/todos/Index')
            ->where('lists.0.title', '公開準備リスト')
            ->where('lists.0.todos.0.title', '公開前チェックを行う')
            ->where('lists.0.todos.0.completed', true)
            ->where('lists.0.todos.0.assigneeName', $member->name)
            ->where('lists.0.todos.0.dueOn', '2026-08-01')
            ->missing('lists.0.todos.0.details')
            ->missing('lists.0.todos.0.assigneeId')
            ->missing('lists.0.todos.0.completionNotifyIds')
            ->missing('lists.0.todos.0.creatorName')
            ->missing('lists.0.todos.0.createdAt')
            ->missing('lists.0.todos.0.canEdit')
            ->missing('lists.0.todos.0.attachments')
            ->missing('lists.0.todos.0.subtasks')
            ->missing('lists.0.todos.0.comments'));

    expect($todo->fresh())
        ->completed_by->toBe($owner->id)
        ->position->toBe(0);
});

test('project participants can open todo details while outsiders cannot', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $team->members()->attach($outsider, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);
    $list = TodoList::factory()->for($project)->create(['title' => '公開準備']);
    $todo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create([
        'title' => '表示を確認する',
        'details' => 'モバイルでも確認する',
        'assignee_id' => $member->id,
    ]);
    $todo->comments()->create([
        'project_id' => $project->id,
        'user_id' => $member->id,
        'body' => '確認しました。',
    ]);

    $this->actingAs($member)
        ->get(route('projects.todos.show', [$team, $project, $list, $todo]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/todos/Show')
            ->where('list.title', '公開準備')
            ->where('todo.title', '表示を確認する')
            ->where('todo.assigneeName', $member->name)
            ->where('todo.creatorName', $owner->name)
            ->has('todo.reactions', 0)
            ->where('todo.comments.0.body', '確認しました。'));

    $reactionUrl = route('projects.todos.reaction.toggle', [$team, $project, $list, $todo]);

    $this->actingAs($member)->post($reactionUrl, ['reaction' => '❤️'])->assertRedirect();
    expect($todo->reactors()->whereKey($member->id)->first()?->pivot?->reaction)->toBe('❤️');

    $this->actingAs($member)->post($reactionUrl, ['reaction' => '❤️'])->assertRedirect();
    expect($todo->reactors()->whereKey($member->id)->exists())->toBeFalse();

    $this->actingAs($outsider)
        ->get(route('projects.todos.show', [$team, $project, $list, $todo]))
        ->assertForbidden();

    $this->actingAs($outsider)->post($reactionUrl, ['reaction' => '❤️'])->assertForbidden();
});

test('todos can be created with subtasks and completion watchers in one request', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);
    $list = TodoList::factory()->for($project)->create();

    $this->actingAs($owner)
        ->post(route('projects.todos.store', [$team, $project, $list]), [
            'title' => 'リリース準備',
            'completion_notify_ids' => [$member->id],
            'subtasks' => [
                [
                    'title' => 'チェックリスト作成',
                    'assignee_id' => $member->id,
                    'due_on' => '2026-08-02',
                ],
                [
                    'title' => '最終確認',
                    'assignee_id' => null,
                    'due_on' => null,
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $todo = Todo::query()->whereNull('parent_id')->firstOrFail();

    expect($todo->completion_notify_ids)->toBe([$member->id])
        ->and($todo->position)->toBe(0)
        ->and($todo->subtasks()->orderBy('position')->pluck('position')->all())->toBe([0, 1])
        ->and($todo->subtasks()->orderBy('position')->pluck('title')->all())
        ->toBe(['チェックリスト作成', '最終確認'])
        ->and($todo->subtasks()->orderBy('position')->first()->assignee_id)->toBe($member->id)
        ->and($todo->subtasks()->orderBy('position')->first()->due_on?->toDateString())->toBe('2026-08-02');

    $outsider = User::factory()->create();

    $this->actingAs($owner)
        ->post(route('projects.todos.store', [$team, $project, $list]), [
            'title' => '通知先が不正',
            'completion_notify_ids' => [$outsider->id],
        ])
        ->assertSessionHasErrors('completion_notify_ids.0');

    $this->actingAs($owner)
        ->post(route('projects.todos.store', [$team, $project, $list]), [
            'title' => 'サブタスク担当者が不正',
            'subtasks' => [[
                'title' => '外部ユーザーには割り当てられない',
                'assignee_id' => $outsider->id,
                'due_on' => null,
            ]],
        ])
        ->assertSessionHasErrors('subtasks.0.assignee_id');
});

test('todos can belong to a project chat channel', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);
    $todoList = TodoList::factory()->for($project)->create();
    $channel = ChatChannel::factory()->for($project)->for($owner, 'creator')->create([
        'name' => 'launch',
    ]);

    $this
        ->actingAs($owner)
        ->post(route('projects.todos.store', [$team, $project, $todoList]), [
            'title' => '公開前チェック',
            'chat_channel_id' => $channel->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $todo = Todo::query()->firstOrFail();

    expect($todo->chatChannel->is($channel))->toBeTrue();

    $this
        ->get(route('projects.todos.show', [$team, $project, $todoList, $todo]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('todo.chatChannel.id', $channel->id)
            ->where('todo.chatChannel.name', 'launch'));

    $channel->delete();

    expect($todo->fresh()->chat_channel_id)->toBeNull();
});

test('a todo cannot link to a chat channel from another project', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $otherProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);
    $todoList = TodoList::factory()->for($project)->create();
    $otherChannel = ChatChannel::factory()->for($otherProject)->for($owner, 'creator')->create();

    $this
        ->actingAs($owner)
        ->post(route('projects.todos.store', [$team, $project, $todoList]), [
            'title' => '不正なチャンネル連携',
            'chat_channel_id' => $otherChannel->id,
        ])
        ->assertSessionHasErrors('chat_channel_id');

    expect(Todo::query()->exists())->toBeFalse();
});

test('top-level todo positions ignore subtask positions', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);
    $list = TodoList::factory()->for($project)->create();
    $firstTodo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create([
        'position' => 0,
    ]);
    Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create([
        'parent_id' => $firstTodo->id,
        'position' => 99,
    ]);

    $this->actingAs($owner)
        ->post(route('projects.todos.store', [$team, $project, $list]), [
            'title' => '次のトップレベル任務',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($list->todos()
        ->whereNull('parent_id')
        ->orderBy('position')
        ->pluck('position')
        ->all())->toBe([0, 1]);
});

test('todo assignees must participate in the project', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($outsider, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);
    $list = TodoList::factory()->for($project)->create();

    $this->actingAs($owner)
        ->post(route('projects.todos.store', [$team, $project, $list]), [
            'title' => '不正な担当者',
            'assignee_id' => $outsider->id,
        ])
        ->assertSessionHasErrors('assignee_id');

    expect(Todo::query()->exists())->toBeFalse();
});

test('todo authors and team managers can edit while other members cannot', function () {
    $owner = User::factory()->create();
    $author = User::factory()->create();
    $otherMember = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($author, ['role' => TeamRole::Member->value]);
    $team->members()->attach($otherMember, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$author->id, $otherMember->id]);
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($author, 'creator')->create();

    $this->actingAs($otherMember)
        ->patch(route('projects.todos.update', [$team, $project, $list, $todo]), [
            'title' => '変更不可',
        ])
        ->assertForbidden();

    $showRoute = route('projects.todos.show', [$team, $project, $list, $todo]);

    $this->actingAs($owner)
        ->from($showRoute)
        ->patch(route('projects.todos.update', [$team, $project, $list, $todo]), [
            'title' => '管理者が更新',
        ])
        ->assertRedirect($showRoute);

    expect($todo->fresh()->title)->toBe('管理者が更新');
});

test('project participants can discuss todos while only authors and managers can delete comments', function () {
    $owner = User::factory()->create();
    $author = User::factory()->create(['name' => 'コメント投稿者']);
    $otherMember = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($author, ['role' => TeamRole::Member->value]);
    $team->members()->attach($otherMember, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$author->id, $otherMember->id]);
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create([
        'title' => '公開前チェック',
    ]);

    $showRoute = route('projects.todos.show', [$team, $project, $list, $todo]);

    $this->actingAs($author)
        ->from($showRoute)
        ->post(route('projects.todos.comments.store', [$team, $project, $list, $todo]), [
            'body' => 'スマートフォンで確認しました。',
        ])
        ->assertRedirect($showRoute);

    $comment = Comment::query()->firstOrFail();

    $this->get($showRoute)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('todo.comments.0.body', 'スマートフォンで確認しました。')
            ->where('todo.comments.0.authorName', 'コメント投稿者')
            ->where('todo.comments.0.canDelete', true));

    $this->get(route('activity.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activities.0.type', ActivityType::TodoCommentCreated->value)
            ->where('activities.0.target', 'todos')
            ->where('activities.0.subjectLabel', '公開前チェック'));

    $this->actingAs($otherMember)
        ->delete(route('projects.todos.comments.destroy', [$team, $project, $list, $todo, $comment]))
        ->assertForbidden();

    $this->actingAs($owner)
        ->from($showRoute)
        ->delete(route('projects.todos.comments.destroy', [$team, $project, $list, $todo, $comment]))
        ->assertRedirect($showRoute);

    expect($comment->fresh())->toBeNull();
});

test('todo comment authors and managers can edit while other members cannot', function () {
    $owner = User::factory()->create();
    $author = User::factory()->create();
    $otherMember = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($author, ['role' => TeamRole::Member->value]);
    $team->members()->attach($otherMember, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$author->id, $otherMember->id]);
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create();
    $comment = Comment::factory()->for($project)->for($author, 'user')->for($todo, 'commentable')->create([
        'body' => '確認しました。',
    ]);

    $this->actingAs($otherMember)
        ->patch(route('projects.todos.comments.update', [$team, $project, $list, $todo, $comment]), [
            'body' => '横取り編集。',
        ])
        ->assertForbidden();

    $this->actingAs($author)
        ->from(route('projects.todos.show', [$team, $project, $list, $todo]))
        ->patch(route('projects.todos.comments.update', [$team, $project, $list, $todo, $comment]), [
            'body' => '再確認しました。',
        ])
        ->assertRedirect(route('projects.todos.show', [$team, $project, $list, $todo]));

    expect($comment->fresh()->body)->toContain('再確認しました。');
});

test('todo routes scope lists and todos to their project', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $firstProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $secondProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $firstList = TodoList::factory()->for($firstProject)->create();
    $wrongSourceList = TodoList::factory()->for($firstProject)->create();
    $firstTodo = Todo::factory()->for($firstList, 'todoList')->create();
    $secondList = TodoList::factory()->for($secondProject)->create();
    $secondTodo = Todo::factory()->for($secondList, 'todoList')->create();
    $secondComment = $secondTodo->comments()->create([
        'project_id' => $secondProject->id,
        'user_id' => $owner->id,
        'body' => '別プロジェクトのコメント',
    ]);

    $this->actingAs($owner)
        ->post(route('projects.todos.store', [$team, $firstProject, $secondList]), [
            'title' => '別プロジェクト',
        ])
        ->assertNotFound();

    $this->get(route('projects.todos.show', [$team, $firstProject, $firstList, $secondTodo]))
        ->assertNotFound();

    $this->patch(route('projects.todos.move', [$team, $firstProject, $wrongSourceList, $firstTodo]), [
        'target_list_id' => $wrongSourceList->id,
    ])->assertNotFound();

    expect($firstTodo->fresh()->todo_list_id)->toBe($firstList->id);

    $this->actingAs($owner)
        ->post(route('projects.todos.comments.store', [$team, $firstProject, $firstList, $secondTodo]), [
            'body' => '別プロジェクト',
        ])
        ->assertNotFound();

    $this->actingAs($owner)
        ->delete(route('projects.todos.comments.destroy', [$team, $firstProject, $firstList, $firstTodo, $secondComment]))
        ->assertNotFound();
});

test('deleting todos or lists removes their comments', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create();
    $todoComment = $todo->comments()->create([
        'project_id' => $project->id,
        'user_id' => $owner->id,
        'body' => 'To-doと一緒に削除',
    ]);

    $this->actingAs($owner)
        ->delete(route('projects.todos.destroy', [$team, $project, $list, $todo]))
        ->assertRedirect();

    expect($todoComment->fresh())->toBeNull();

    $secondTodo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create();
    $todoCommentDeletedWithList = $secondTodo->comments()->create([
        'project_id' => $project->id,
        'user_id' => $owner->id,
        'body' => 'List内のTo-doと一緒に削除',
    ]);
    $listComment = $list->comments()->create([
        'project_id' => $project->id,
        'user_id' => $owner->id,
        'body' => 'Listと一緒に直接削除',
    ]);

    $this->delete(route('projects.todo-lists.destroy', [$team, $project, $list]))
        ->assertRedirect();

    expect($todoCommentDeletedWithList->fresh())->toBeNull()
        ->and($listComment->fresh())->toBeNull();
});

test('todo and list deletions close position gaps in the affected sibling scope', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);
    $firstList = TodoList::factory()->for($project)->create(['position' => 2]);
    $list = TodoList::factory()->for($project)->create(['position' => 7]);
    $lastList = TodoList::factory()->for($project)->create(['position' => 13]);
    $firstTodo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create([
        'position' => 2,
    ]);
    $middleTodo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create([
        'position' => 7,
    ]);
    $lastTodo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create([
        'position' => 13,
    ]);
    $firstSubtask = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create([
        'parent_id' => $firstTodo->id,
        'position' => 3,
    ]);
    $middleSubtask = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create([
        'parent_id' => $firstTodo->id,
        'position' => 8,
    ]);
    $lastSubtask = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create([
        'parent_id' => $firstTodo->id,
        'position' => 15,
    ]);

    $this->actingAs($owner)
        ->delete(route('projects.todos.destroy', [$team, $project, $list, $middleTodo]))
        ->assertRedirect();

    expect($list->todos()
        ->whereNull('parent_id')
        ->orderBy('position')
        ->pluck('id')
        ->all())->toBe([$firstTodo->id, $lastTodo->id])
        ->and($list->todos()
            ->whereNull('parent_id')
            ->orderBy('position')
            ->pluck('position')
            ->all())->toBe([0, 1]);

    $this->delete(route('projects.todos.destroy', [$team, $project, $list, $middleSubtask]))
        ->assertRedirect();

    expect($firstTodo->subtasks()->orderBy('position')->pluck('id')->all())
        ->toBe([$firstSubtask->id, $lastSubtask->id])
        ->and($firstTodo->subtasks()->orderBy('position')->pluck('position')->all())
        ->toBe([0, 1]);

    $this->delete(route('projects.todo-lists.destroy', [$team, $project, $list]))
        ->assertRedirect();

    expect($project->todoLists()->orderBy('position')->pluck('id')->all())
        ->toBe([$firstList->id, $lastList->id])
        ->and($project->todoLists()->orderBy('position')->pluck('position')->all())
        ->toBe([0, 1]);
});

test('project participants can create complete and view subtasks', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create([
        'title' => '公開準備',
    ]);

    $showRoute = route('projects.todos.show', [$team, $project, $list, $todo]);

    $this->actingAs($member)
        ->from($showRoute)
        ->post(route('projects.todos.subtasks.store', [$team, $project, $list, $todo]), [
            'title' => 'モバイル表示を確認する',
            'assignee_id' => $owner->id,
            'due_on' => '2026-08-03',
        ])
        ->assertRedirect($showRoute);

    $subtask = Todo::query()->whereNotNull('parent_id')->firstOrFail();

    expect($subtask)
        ->todo_list_id->toBe($list->id)
        ->parent_id->toBe($todo->id)
        ->position->toBe(0)
        ->created_by->toBe($member->id)
        ->assignee_id->toBe($owner->id)
        ->and($subtask->due_on?->toDateString())->toBe('2026-08-03');

    $outsider = User::factory()->create();

    $this->from($showRoute)
        ->post(route('projects.todos.subtasks.store', [$team, $project, $list, $todo]), [
            'title' => 'プロジェクト外の担当者',
            'assignee_id' => $outsider->id,
            'due_on' => '2026-08-04',
        ])
        ->assertRedirect($showRoute)
        ->assertSessionHasErrors('assignee_id');

    expect($todo->subtasks()->count())->toBe(1);

    $this->get($showRoute)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('todo.title', '公開準備')
            ->where('todo.subtasks.0.title', 'モバイル表示を確認する')
            ->where('todo.subtasks.0.completed', false)
            ->where('todo.subtasks.0.assigneeId', $owner->id)
            ->where('todo.subtasks.0.assigneeName', $owner->name)
            ->where('todo.subtasks.0.dueOn', '2026-08-03')
            ->where('todo.subtasks.0.canEdit', true)
            ->missing('todo.subtasks.1'));

    $this->from($showRoute)
        ->patch(route('projects.todos.toggle', [$team, $project, $list, $subtask]))
        ->assertRedirect($showRoute);

    expect($subtask->fresh()->completed_at)->not->toBeNull();

    $this->from($showRoute)
        ->delete(route('projects.todos.destroy', [$team, $project, $list, $subtask]))
        ->assertRedirect($showRoute);

    expect($subtask->fresh())->toBeNull();
});

test('subtasks stay inside their project and are removed with their parent', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $firstProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $secondProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $firstList = TodoList::factory()->for($firstProject)->create();
    $secondList = TodoList::factory()->for($secondProject)->create();
    $parent = Todo::factory()->for($firstList, 'todoList')->for($owner, 'creator')->create();
    $subtask = Todo::factory()->for($firstList, 'todoList')->for($owner, 'creator')->create([
        'parent_id' => $parent->id,
    ]);
    $comment = $subtask->comments()->create([
        'project_id' => $firstProject->id,
        'user_id' => $owner->id,
        'body' => 'サブタスクのコメント',
    ]);

    $this->actingAs($owner)
        ->post(route('projects.todos.subtasks.store', [$team, $secondProject, $secondList, $parent]), [
            'title' => '別プロジェクトから追加',
        ])
        ->assertNotFound();

    $this->delete(route('projects.todos.destroy', [$team, $firstProject, $firstList, $parent]))
        ->assertRedirect(route('projects.todos.index', [$team, $firstProject]));

    expect($parent->fresh())->toBeNull()
        ->and($subtask->fresh())->toBeNull()
        ->and($comment->fresh())->toBeNull();
});

test('project participants can reorder todos and move them between lists', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);
    $firstList = TodoList::factory()->for($project)->create(['position' => 0]);
    $secondList = TodoList::factory()->for($project)->create(['position' => 1]);
    $firstTodo = Todo::factory()->for($firstList, 'todoList')->create(['position' => 20]);
    $movingTodo = Todo::factory()->for($firstList, 'todoList')->create(['position' => 20]);
    $lastTodo = Todo::factory()->for($firstList, 'todoList')->create(['position' => 90]);
    $subtask = Todo::factory()->for($firstList, 'todoList')->create([
        'parent_id' => $movingTodo->id,
        'position' => 0,
    ]);
    $destinationTodo = Todo::factory()->for($secondList, 'todoList')->create(['position' => 9]);

    $this->actingAs($member)
        ->patch(route('projects.todos.move', [$team, $project, $firstList, $movingTodo]), [
            'target_list_id' => $secondList->id,
            'target_position' => 0,
        ])
        ->assertRedirect(route('projects.todos.index', [$team, $project]));

    expect($movingTodo->fresh())
        ->todo_list_id->toBe($secondList->id)
        ->position->toBe(0)
        ->and($subtask->fresh()->todo_list_id)->toBe($secondList->id)
        ->and($destinationTodo->fresh()->position)->toBe(1)
        ->and($firstTodo->fresh()->position)->toBe(0)
        ->and($lastTodo->fresh()->position)->toBe(1);

    $this->patch(route('projects.todos.move', [$team, $project, $firstList, $firstTodo]), [
        'target_list_id' => $firstList->id,
        'target_position' => 1,
    ])->assertRedirect();

    expect($lastTodo->fresh()->position)->toBe(0)
        ->and($firstTodo->fresh()->position)->toBe(1);
});

test('todo moves reject subtasks and destination lists from another project', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $firstProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $secondProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $firstList = TodoList::factory()->for($firstProject)->create();
    $secondList = TodoList::factory()->for($secondProject)->create();
    $todo = Todo::factory()->for($firstList, 'todoList')->create();
    $subtask = Todo::factory()->for($firstList, 'todoList')->create(['parent_id' => $todo->id]);

    $this->actingAs($owner)
        ->from(route('projects.todos.index', [$team, $firstProject]))
        ->patch(route('projects.todos.move', [$team, $firstProject, $firstList, $todo]), [
            'target_list_id' => $secondList->id,
        ])
        ->assertRedirect(route('projects.todos.index', [$team, $firstProject]))
        ->assertSessionHasErrors('target_list_id');

    $this->patch(route('projects.todos.move', [$team, $firstProject, $firstList, $subtask]), [
        'target_list_id' => $firstList->id,
    ])->assertForbidden();
});

test('a project member can open a to-do list and see its todos', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $team->members()->attach($outsider, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);
    $list = TodoList::factory()->for($project)->create(['title' => '公開準備']);
    Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create([
        'title' => '表示を確認する',
        'details' => 'メモあり',
        'assignee_id' => $member->id,
    ]);

    $this->actingAs($member)
        ->get(route('projects.todo-lists.show', [$team, $project, $list]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/todos/ListShow')
            ->where('list.title', '公開準備')
            ->where('todos.0.title', '表示を確認する')
            ->where('todos.0.assigneeName', $member->name)
            ->where('todos.0.hasNotes', true));

    $this->actingAs($outsider)
        ->get(route('projects.todo-lists.show', [$team, $project, $list]))
        ->assertForbidden();
});

test('commenting on a to-do list follows the commenter and notifies subscribers', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);
    $list = TodoList::factory()->for($project)->create(['title' => '公開準備']);
    $list->subscribers()->attach($member->id);

    $this->actingAs($owner)
        ->post(route('projects.todo-lists.comments.store', [$team, $project, $list]), [
            'body' => '<p>進捗どうですか？</p>',
        ])
        ->assertRedirect();

    expect($list->comments()->count())->toBe(1)
        ->and($list->subscribers()->whereKey($owner->id)->exists())->toBeTrue();

    Notification::assertSentTo($member, NewTodoListCommentNotification::class);
    Notification::assertNotSentTo($owner, NewTodoListCommentNotification::class);
});

test('to-do list comments notify only subscribers who still have project access', function () {
    $owner = User::factory()->create();
    $subscriber = User::factory()->create();
    $removedFromProject = User::factory()->create();
    $removedFromTeam = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach(
        [$subscriber->id, $removedFromProject->id, $removedFromTeam->id],
        ['role' => TeamRole::Member->value],
    );
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([
        $subscriber->id,
        $removedFromProject->id,
        $removedFromTeam->id,
    ]);
    $list = TodoList::factory()->for($project)->create();
    $list->subscribers()->attach([
        $subscriber->id,
        $removedFromProject->id,
        $removedFromTeam->id,
    ]);
    $project->members()->detach($removedFromProject);
    $team->members()->detach($removedFromTeam);
    Notification::fake();

    $this->actingAs($owner)
        ->post(route('projects.todo-lists.comments.store', [$team, $project, $list]), [
            'body' => '<p>進捗を共有します。</p>',
        ])
        ->assertRedirect();

    Notification::assertSentTo($subscriber, NewTodoListCommentNotification::class);
    Notification::assertNotSentTo($removedFromProject, NewTodoListCommentNotification::class);
    Notification::assertNotSentTo($removedFromTeam, NewTodoListCommentNotification::class);
});

test('a member can toggle their subscription to a to-do list', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);
    $list = TodoList::factory()->for($project)->create();

    $this->actingAs($member)
        ->patch(route('projects.todo-lists.subscription.toggle', [$team, $project, $list]))
        ->assertRedirect();
    expect($list->subscribers()->whereKey($member->id)->exists())->toBeTrue();

    $this->actingAs($member)
        ->patch(route('projects.todo-lists.subscription.toggle', [$team, $project, $list]))
        ->assertRedirect();
    expect($list->subscribers()->whereKey($member->id)->exists())->toBeFalse();
});

test('todo updates change only submitted fields and explicit empty values still clear fields', function () {
    $owner = User::factory()->create();
    $assignee = User::factory()->create();
    $watcher = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach([$assignee->id, $watcher->id], ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $assignee->id, $watcher->id]);
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create([
        'title' => '変更前',
        'details' => '<p>残すメモ</p>',
        'assignee_id' => $assignee->id,
        'due_on' => '2026-08-20',
        'completion_notify_ids' => [$watcher->id],
    ]);
    Notification::fake();

    $this->actingAs($owner)
        ->patch(route('projects.todos.update', [$team, $project, $list, $todo]), [
            'title' => '名称だけ変更',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($todo->fresh())
        ->title->toBe('名称だけ変更')
        ->details->toBe('<p>残すメモ</p>')
        ->assignee_id->toBe($assignee->id)
        ->completion_notify_ids->toBe([$watcher->id])
        ->and($todo->fresh()->due_on?->toDateString())->toBe('2026-08-20');
    Notification::assertNothingSent();

    $this->patch(route('projects.todos.update', [$team, $project, $list, $todo]), [
        'details' => null,
        'assignee_id' => '',
        'due_on' => '',
        'completion_notify_ids' => [],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($todo->fresh())
        ->title->toBe('名称だけ変更')
        ->details->toBeNull()
        ->assignee_id->toBeNull()
        ->completion_notify_ids->toBe([])
        ->due_on->toBeNull();
    Notification::assertNothingSent();
});

test('todo updates notify only for changed assignment and note concerns', function () {
    $owner = User::factory()->create();
    $firstAssignee = User::factory()->create();
    $secondAssignee = User::factory()->create();
    $mentioned = User::factory()->create(['name' => '田中太郎']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach(
        [$firstAssignee->id, $secondAssignee->id, $mentioned->id],
        ['role' => TeamRole::Member->value],
    );
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([
        $owner->id,
        $firstAssignee->id,
        $secondAssignee->id,
        $mentioned->id,
    ]);
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create([
        'title' => '公開前チェック',
        'details' => '<p>確認事項</p>',
        'assignee_id' => $firstAssignee->id,
    ]);
    Notification::fake();

    $this->actingAs($owner)
        ->patch(route('projects.todos.update', [$team, $project, $list, $todo]), [
            'due_on' => '2026-08-21',
        ])
        ->assertRedirect();
    Notification::assertNothingSent();

    $this->patch(route('projects.todos.update', [$team, $project, $list, $todo]), [
        'assignee_id' => $secondAssignee->id,
    ])->assertRedirect();
    Notification::assertSentTo(
        $secondAssignee,
        TodoActivityNotification::class,
        fn (TodoActivityNotification $notification): bool => $notification->type === TodoActivityNotification::Assigned,
    );
    Notification::assertNotSentTo($firstAssignee, TodoActivityNotification::class);

    Notification::fake();

    $this->patch(route('projects.todos.update', [$team, $project, $list, $todo]), [
        'details' => "<p>@{$mentioned->name} 確認してください。</p>",
    ])->assertRedirect();
    Notification::assertSentTo(
        $mentioned,
        MentionNotification::class,
        fn (MentionNotification $notification): bool => $notification->type === MentionNotification::Todo
            && $notification->title === $todo->title,
    );

    Notification::fake();

    $this->patch(route('projects.todos.update', [$team, $project, $list, $todo]), [
        'title' => '公開前チェック（更新）',
    ])->assertRedirect();
    Notification::assertNothingSent();
});
