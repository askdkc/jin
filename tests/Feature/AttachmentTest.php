<?php

use App\Enums\TeamRole;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('project participants can attach private files to messages', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($member);
    $post = MessageBoardPost::factory()->for($project)->for($owner)->create();

    $this->actingAs($member)
        ->post(route('projects.messages.attachments.store', [$team, $project, $post]), [
            'file' => UploadedFile::fake()->create('公開資料.pdf', 100, 'application/pdf'),
        ])
        ->assertRedirect(route('projects.messages.show', [$team, $project, $post]));

    $attachment = StoredFile::query()->firstOrFail();

    expect($attachment)
        ->attachable_type->toBe(MessageBoardPost::class)
        ->attachable_id->toBe($post->id)
        ->uploaded_by->toBe($member->id);
    Storage::disk('local')->assertExists($attachment->path);

    $this->get(route('projects.messages.show', [$team, $project, $post]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('post.attachments.0.name', '公開資料.pdf')
            ->where('post.attachments.0.canDelete', true));

    $this->get(route('projects.files.download', [$team, $project, $attachment]))
        ->assertOk();
});

test('project participants can attach files to todos', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $list = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($list, 'todoList')->for($owner, 'creator')->create();

    $this->actingAs($owner)
        ->post(route('projects.todos.attachments.store', [$team, $project, $list, $todo]), [
            'file' => UploadedFile::fake()->create('確認表.xlsx', 100, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ])
        ->assertRedirect(route('projects.todos.index', [$team, $project]));

    $attachment = StoredFile::query()->firstOrFail();

    expect($attachment)
        ->attachable_type->toBe(Todo::class)
        ->attachable_id->toBe($todo->id);

    $this->get(route('projects.todos.index', [$team, $project]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('lists.0.todos.0.attachments.0.name', '確認表.xlsx'));
});

test('attachment routes enforce project scoped bindings', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $visibleProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $otherProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $otherPost = MessageBoardPost::factory()->for($otherProject)->for($owner)->create();
    $visibleList = TodoList::factory()->for($visibleProject)->create();
    $otherList = TodoList::factory()->for($otherProject)->create();
    $otherTodo = Todo::factory()->for($otherList, 'todoList')->create();
    $file = UploadedFile::fake()->create('越境.pdf', 10, 'application/pdf');

    $this->actingAs($owner)
        ->post(route('projects.messages.attachments.store', [$team, $visibleProject, $otherPost]), ['file' => $file])
        ->assertNotFound();

    $this->post(route('projects.todos.attachments.store', [$team, $visibleProject, $visibleList, $otherTodo]), ['file' => $file])
        ->assertNotFound();

    expect(StoredFile::query()->exists())->toBeFalse();
});

test('a file dropped into the editor uploads inline and is linked once the message references it', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();

    $response = $this->actingAs($owner)
        ->postJson(route('projects.messages.inline-attachments.store', [$team, $project]), [
            'file' => UploadedFile::fake()->create('企画書.pdf', 100, 'application/pdf'),
        ])
        ->assertCreated()
        ->json();

    expect($response)->toHaveKeys(['id', 'name', 'mimeType', 'previewUrl'])
        ->and($response['name'])->toBe('企画書.pdf')
        ->and($response['mimeType'])->toBe('application/pdf');

    $attachment = StoredFile::query()->findOrFail($response['id']);

    expect($attachment)
        ->attachable_type->toBeNull()
        ->attachable_id->toBeNull();

    $this->actingAs($owner)
        ->post(route('projects.messages.store', [$team, $project]), [
            'title' => '企画のお知らせ',
            'body' => "<p>資料を添付しました。</p><p><a href=\"{$response['previewUrl']}\">📎 企画書.pdf</a></p>",
        ])
        ->assertRedirect();

    $post = MessageBoardPost::query()->firstOrFail();

    expect($attachment->fresh())
        ->attachable_type->toBe(MessageBoardPost::class)
        ->attachable_id->toBe($post->id);
});

test('deleting a message keeps its attachment in project files', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $post = MessageBoardPost::factory()->for($project)->for($owner)->create();
    Storage::disk('local')->put('projects/test/retained.pdf', 'retained');
    $attachment = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'attachable_type' => MessageBoardPost::class,
        'attachable_id' => $post->id,
        'path' => 'projects/test/retained.pdf',
    ]);

    $this->actingAs($owner)
        ->delete(route('projects.messages.destroy', [$team, $project, $post]))
        ->assertRedirect();

    expect($attachment->fresh())
        ->attachable_type->toBeNull()
        ->attachable_id->toBeNull();
    Storage::disk('local')->assertExists($attachment->path);
});
