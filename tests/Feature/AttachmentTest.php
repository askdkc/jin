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
        ->is_inline->toBeFalse()
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

    $showRoute = route('projects.todos.show', [$team, $project, $list, $todo]);

    $this->actingAs($owner)
        ->from($showRoute)
        ->post(route('projects.todos.attachments.store', [$team, $project, $list, $todo]), [
            'file' => UploadedFile::fake()->create('確認表.xlsx', 100, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ])
        ->assertRedirect($showRoute);

    $attachment = StoredFile::query()->firstOrFail();

    expect($attachment)
        ->attachable_type->toBe(Todo::class)
        ->attachable_id->toBe($todo->id)
        ->is_inline->toBeFalse();

    $this->patch(route('projects.todos.update', [$team, $project, $list, $todo]), [
        'title' => $todo->title,
        'details' => '<p>本文だけを更新</p>',
    ])->assertRedirect();

    expect($attachment->fresh())
        ->attachable_type->toBe(Todo::class)
        ->attachable_id->toBe($todo->id);

    $this->get($showRoute)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/todos/Show')
            ->where('todo.attachments.0.name', '確認表.xlsx'));
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
        ->postJson(route('projects.inline-attachments.store', [$team, $project]), [
            'file' => UploadedFile::fake()->create('企画書.pdf', 100, 'application/pdf'),
        ])
        ->assertCreated()
        ->json();

    expect($response)->toHaveKeys(['id', 'name', 'mimeType', 'previewUrl', 'inline'])
        ->and($response['name'])->toBe('企画書.pdf')
        ->and($response['mimeType'])->toBe('application/pdf')
        ->and($response['inline'])->toBeFalse();

    $attachment = StoredFile::query()->findOrFail($response['id']);

    expect($attachment)
        ->attachable_type->toBeNull()
        ->attachable_id->toBeNull()
        ->is_inline->toBeTrue();

    $ordinaryAttachment = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'path' => "projects/{$project->id}/attachments/ordinary.pdf",
    ]);
    $ordinaryPreviewUrl = route(
        'projects.files.preview',
        [$team, $project, $ordinaryAttachment],
        absolute: false,
    );

    $this->actingAs($owner)
        ->post(route('projects.messages.store', [$team, $project]), [
            'title' => '企画のお知らせ',
            'body' => "<p>資料を添付しました。</p><p><a href=\"{$response['previewUrl']}\">📎 企画書.pdf</a><a href=\"{$ordinaryPreviewUrl}\">ordinary.pdf</a></p>",
        ])
        ->assertRedirect();

    $post = MessageBoardPost::query()->firstOrFail();

    expect($attachment->fresh())
        ->attachable_type->toBe(MessageBoardPost::class)
        ->attachable_id->toBe($post->id)
        ->and($ordinaryAttachment->fresh())
        ->attachable_type->toBeNull()
        ->attachable_id->toBeNull()
        ->is_inline->toBeFalse();
});

test('authorized project members can use inline uploads without the messages tool', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create([
        'enabled_tools' => ['todos'],
    ]);
    $project->members()->attach($member);

    foreach ([
        ['photo.jpg', 'image/jpeg'],
        ['photo.png', 'image/png'],
        ['photo.webp', 'image/webp'],
        ['document.pdf', 'application/pdf'],
    ] as [$name, $mimeType]) {
        $this->actingAs($member)
            ->postJson(route('projects.inline-attachments.store', [$team, $project]), [
                'file' => UploadedFile::fake()->create($name, 10, $mimeType),
            ])
            ->assertCreated()
            ->assertJsonPath('inline', in_array($mimeType, [
                'image/jpeg',
                'image/png',
                'image/webp',
            ], true));
    }
});

test('a file dropped into a new todo rejects another users upload and rolls back the owners claim', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);
    $list = TodoList::factory()->for($project)->create();

    $memberUpload = $this->actingAs($member)
        ->postJson(route('projects.inline-attachments.store', [$team, $project]), [
            'file' => UploadedFile::fake()->create('member.pdf', 10, 'application/pdf'),
        ])
        ->assertCreated()
        ->json();
    $ownerUpload = $this->actingAs($owner)
        ->postJson(route('projects.inline-attachments.store', [$team, $project]), [
            'file' => UploadedFile::fake()->create('owner.pdf', 10, 'application/pdf'),
        ])
        ->assertCreated()
        ->json();

    $this->actingAs($owner)
        ->post(route('projects.todos.store', [$team, $project, $list]), [
            'title' => '添付ファイルを確認する',
            'details' => "<a href=\"{$memberUpload['previewUrl']}\">member.pdf</a><a href=\"{$ownerUpload['previewUrl']}\">owner.pdf</a>",
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('details');

    expect(Todo::query()->exists())->toBeFalse()
        ->and(StoredFile::query()->findOrFail($ownerUpload['id']))
        ->attachable_type->toBeNull()
        ->attachable_id->toBeNull()
        ->and(StoredFile::query()->findOrFail($memberUpload['id']))
        ->attachable_type->toBeNull()
        ->attachable_id->toBeNull();
});

test('deleting a message keeps its attachment in project files', function () {
    Storage::fake('local');

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $post = MessageBoardPost::factory()->for($project)->for($owner)->create();
    $path = "projects/{$project->id}/attachments/retained.pdf";
    Storage::disk('local')->put($path, 'retained');
    $attachment = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
        'attachable_type' => MessageBoardPost::class,
        'attachable_id' => $post->id,
        'path' => $path,
    ]);

    $this->actingAs($owner)
        ->delete(route('projects.messages.destroy', [$team, $project, $post]))
        ->assertRedirect();

    expect($attachment->fresh())
        ->attachable_type->toBeNull()
        ->attachable_id->toBeNull()
        ->is_inline->toBeFalse()
        ->and(StoredFile::query()->inMaterialLibrary()->whereKey($attachment->id)->exists())
        ->toBeTrue();
    Storage::disk('local')->assertExists($attachment->path);
});
