<?php

use App\Enums\ActivityType;
use App\Enums\TeamRole;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
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
            ->where('lists.0.title', '公開準備')
            ->where('lists.0.todos.0.title', '公開前チェックを行う')
            ->where('lists.0.todos.0.completed', true));

    expect($todo->fresh()->completed_by)->toBe($owner->id);
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
            'subtasks' => ['チェックリスト作成', '最終確認'],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $todo = Todo::query()->whereNull('parent_id')->firstOrFail();

    expect($todo->completion_notify_ids)->toBe([$member->id])
        ->and($todo->subtasks()->orderBy('position')->pluck('title')->all())
        ->toBe(['チェックリスト作成', '最終確認']);

    $outsider = User::factory()->create();

    $this->actingAs($owner)
        ->post(route('projects.todos.store', [$team, $project, $list]), [
            'title' => '通知先が不正',
            'completion_notify_ids' => [$outsider->id],
        ])
        ->assertSessionHasErrors('completion_notify_ids.0');
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

    $this->actingAs($owner)
        ->patch(route('projects.todos.update', [$team, $project, $list, $todo]), [
            'title' => '管理者が更新',
        ])
        ->assertRedirect();

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

    $this->actingAs($author)
        ->post(route('projects.todos.comments.store', [$team, $project, $list, $todo]), [
            'body' => 'スマートフォンで確認しました。',
        ])
        ->assertRedirect(route('projects.todos.index', [$team, $project]));

    $comment = Comment::query()->firstOrFail();

    $this->get(route('projects.todos.index', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('lists.0.todos.0.comments.0.body', 'スマートフォンで確認しました。')
            ->where('lists.0.todos.0.comments.0.authorName', 'コメント投稿者')
            ->where('lists.0.todos.0.comments.0.canDelete', true));

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
        ->delete(route('projects.todos.comments.destroy', [$team, $project, $list, $todo, $comment]))
        ->assertRedirect(route('projects.todos.index', [$team, $project]));

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
        ->patch(route('projects.todos.comments.update', [$team, $project, $list, $todo, $comment]), [
            'body' => '再確認しました。',
        ])
        ->assertRedirect(route('projects.todos.index', [$team, $project]));

    expect($comment->fresh()->body)->toContain('再確認しました。');
});

test('todo routes scope lists and todos to their project', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $firstProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $secondProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $firstList = TodoList::factory()->for($firstProject)->create();
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
    $listComment = $secondTodo->comments()->create([
        'project_id' => $project->id,
        'user_id' => $owner->id,
        'body' => 'Listと一緒に削除',
    ]);

    $this->delete(route('projects.todo-lists.destroy', [$team, $project, $list]))
        ->assertRedirect();

    expect($listComment->fresh())->toBeNull();
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

    $this->actingAs($member)
        ->post(route('projects.todos.subtasks.store', [$team, $project, $list, $todo]), [
            'title' => 'モバイル表示を確認する',
        ])
        ->assertRedirect(route('projects.todos.index', [$team, $project]));

    $subtask = Todo::query()->whereNotNull('parent_id')->firstOrFail();

    expect($subtask)
        ->todo_list_id->toBe($list->id)
        ->parent_id->toBe($todo->id)
        ->created_by->toBe($member->id);

    $this->get(route('projects.todos.index', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('lists.0.todos.0.title', '公開準備')
            ->where('lists.0.todos.0.subtasks.0.title', 'モバイル表示を確認する')
            ->where('lists.0.todos.0.subtasks.0.completed', false)
            ->where('lists.0.todos.0.subtasks.0.canEdit', true)
            ->missing('lists.0.todos.1'));

    $this->patch(route('projects.todos.toggle', [$team, $project, $list, $subtask]))
        ->assertRedirect(route('projects.todos.index', [$team, $project]));

    expect($subtask->fresh()->completed_at)->not->toBeNull();
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
