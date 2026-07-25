<?php

use App\Enums\CheckInFrequency;
use App\Enums\TeamRole;
use App\Models\Activity;
use App\Models\Card;
use App\Models\CardColumn;
use App\Models\CardTable;
use App\Models\CheckIn;
use App\Models\CheckInPrompt;
use App\Models\Comment;
use App\Models\Document;
use App\Models\MessageBoardPost;
use App\Models\Project;
use App\Models\ScheduleEvent;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use App\Support\InlineAttachments;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

function inlineAttachmentCoverageWorkspace(): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach($owner);

    return [$owner, $team, $project];
}

function inlineAttachmentCoverageUpload($test, User $user, Team $team, Project $project): array
{
    return $test->actingAs($user)
        ->postJson(route('projects.inline-attachments.store', [$team, $project]), [
            'file' => UploadedFile::fake()->create('inline.pdf', 10, 'application/pdf'),
        ])
        ->assertCreated()
        ->json();
}

function inlineAttachmentCoverageBody(string $previewUrl, string $label = '添付'): string
{
    return "<p>before</p><p><a href=\"{$previewUrl}\">{$label}</a></p><p>after</p>";
}

function expectInlineAttachmentClaimed(int $fileId, object $attachable): void
{
    expect(StoredFile::query()->findOrFail($fileId))
        ->attachable_type->toBe($attachable->getMorphClass())
        ->attachable_id->toBe($attachable->getKey())
        ->is_inline->toBeTrue();
}

function expectInlineAttachmentDetached(int $fileId): void
{
    expect(StoredFile::query()->findOrFail($fileId))
        ->attachable_type->toBeNull()
        ->attachable_id->toBeNull()
        ->is_inline->toBeTrue()
        ->and(StoredFile::query()->inMaterialLibrary()->whereKey($fileId)->exists())
        ->toBeFalse();
}

/** @param array<string, mixed> $attributes */
function inlineAttachmentCoverageFile(
    Project $project,
    User $uploader,
    array $attributes = [],
): StoredFile {
    return StoredFile::withoutEvents(fn (): StoredFile => StoredFile::factory()
        ->for($project)
        ->for($uploader, 'uploader')
        ->create([
            'is_inline' => true,
            'preview_status' => null,
            ...$attributes,
        ]));
}

test('document store and update claim neutral inline uploads to the document', function () {
    Storage::fake('local');
    [$owner, $team, $project] = inlineAttachmentCoverageWorkspace();

    $createdUpload = inlineAttachmentCoverageUpload($this, $owner, $team, $project);

    $this->actingAs($owner)
        ->post(route('projects.documents.store', [$team, $project]), [
            'title' => '添付文書',
            'body' => inlineAttachmentCoverageBody($createdUpload['previewUrl']),
        ])
        ->assertRedirect();

    $document = Document::query()->firstOrFail();
    expectInlineAttachmentClaimed($createdUpload['id'], $document);

    $updatedUpload = inlineAttachmentCoverageUpload($this, $owner, $team, $project);

    $this->actingAs($owner)
        ->patch(route('projects.documents.update', [$team, $project, $document]), [
            'title' => '添付文書を更新',
            'body' => inlineAttachmentCoverageBody($updatedUpload['previewUrl'], '更新添付'),
        ])
        ->assertRedirect();

    expectInlineAttachmentDetached($createdUpload['id']);
    expectInlineAttachmentClaimed($updatedUpload['id'], $document);

    $this->actingAs($owner)
        ->delete(route('projects.documents.destroy', [$team, $project, $document]))
        ->assertRedirect();

    expectInlineAttachmentDetached($createdUpload['id']);
    expectInlineAttachmentDetached($updatedUpload['id']);
});

test('document creation and its inline attachment claim roll back together', function () {
    Storage::fake('local');
    [$owner, $team, $project] = inlineAttachmentCoverageWorkspace();
    $upload = inlineAttachmentCoverageUpload($this, $owner, $team, $project);

    Event::listen('eloquent.creating: '.Activity::class, function (): void {
        throw new RuntimeException('Forced activity creation failure.');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($owner)
        ->post(route('projects.documents.store', [$team, $project]), [
            'title' => 'ロールバック対象',
            'body' => inlineAttachmentCoverageBody($upload['previewUrl']),
        ]))
        ->toThrow(RuntimeException::class, 'Forced activity creation failure.');

    expect(Document::query()->where('title', 'ロールバック対象')->exists())->toBeFalse()
        ->and(StoredFile::query()->findOrFail($upload['id']))
        ->attachable_type->toBeNull()
        ->attachable_id->toBeNull();
});

test('document updates roll back when an inline upload has been claimed for deletion', function () {
    Storage::fake('local');
    [$owner, $team, $project] = inlineAttachmentCoverageWorkspace();
    $document = Document::factory()->for($project)->for($owner, 'creator')->create([
        'title' => '更新前',
        'body' => '<p>元の本文</p>',
    ]);
    $upload = inlineAttachmentCoverageUpload($this, $owner, $team, $project);

    StoredFile::query()->whereKey($upload['id'])->update([
        'preview_status' => 'deleting',
    ]);

    $this->actingAs($owner)
        ->patch(route('projects.documents.update', [$team, $project, $document]), [
            'title' => '更新後',
            'body' => inlineAttachmentCoverageBody($upload['previewUrl']),
        ])
        ->assertSessionHasErrors('body');

    expect($document->fresh())
        ->title->toBe('更新前')
        ->body->toBe('<p>元の本文</p>')
        ->and(StoredFile::query()->findOrFail($upload['id']))
        ->attachable_type->toBeNull()
        ->attachable_id->toBeNull()
        ->preview_status->toBe('deleting');
});

test('inline uploads in non-deleting preview states remain attachable', function () {
    Storage::fake('local');
    [$owner, $team, $project] = inlineAttachmentCoverageWorkspace();
    $uploads = [];

    foreach ([null, 'pending', 'processing', 'ready'] as $status) {
        $upload = inlineAttachmentCoverageUpload($this, $owner, $team, $project);
        StoredFile::query()->whereKey($upload['id'])->update(['preview_status' => $status]);
        $uploads[] = $upload;
    }

    $body = implode('', array_map(
        fn (array $upload): string => inlineAttachmentCoverageBody($upload['previewUrl']),
        $uploads,
    ));

    $this->actingAs($owner)
        ->post(route('projects.documents.store', [$team, $project]), [
            'title' => '状態別添付',
            'body' => $body,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $document = Document::query()->where('title', '状態別添付')->firstOrFail();

    foreach ($uploads as $upload) {
        expectInlineAttachmentClaimed($upload['id'], $document);
    }
});

test('schedule store and update claim inline uploads to the primary event', function () {
    Storage::fake('local');
    [$owner, $team, $project] = inlineAttachmentCoverageWorkspace();

    $createdUpload = inlineAttachmentCoverageUpload($this, $owner, $team, $project);

    $this->actingAs($owner)
        ->post(route('projects.schedule.store', [$team, $project]), [
            'title' => '繰り返し予定',
            'details' => inlineAttachmentCoverageBody($createdUpload['previewUrl']),
            'starts_at' => '2026-08-03T10:00',
            'ends_at' => '2026-08-03T11:00',
            'all_day' => false,
            'recurrence' => 'weekly',
            'recurrence_until' => '2026-08-17',
        ])
        ->assertRedirect();

    $event = ScheduleEvent::query()->where('title', '繰り返し予定')->oldest('id')->firstOrFail();
    $occurrenceDetails = ScheduleEvent::query()
        ->where('recurrence_group_id', $event->recurrence_group_id)
        ->whereKeyNot($event->id)
        ->pluck('details');

    expectInlineAttachmentClaimed($createdUpload['id'], $event);
    expect($event->details)->toContain($createdUpload['previewUrl'])
        ->and($occurrenceDetails)->toHaveCount(2)
        ->and($occurrenceDetails->every(fn (?string $details): bool => $details !== null
            && str_contains($details, 'before')
            && str_contains($details, 'after')
            && ! str_contains($details, '/files/')))->toBeTrue();

    $updatedUpload = inlineAttachmentCoverageUpload($this, $owner, $team, $project);

    $this->actingAs($owner)
        ->patch(route('projects.schedule.update', [$team, $project, $event]), [
            'title' => '繰り返し予定を更新',
            'details' => inlineAttachmentCoverageBody($updatedUpload['previewUrl'], '更新添付'),
            'starts_at' => '2026-08-03T10:00',
            'ends_at' => '2026-08-03T11:00',
            'all_day' => false,
        ])
        ->assertRedirect();

    expectInlineAttachmentDetached($createdUpload['id']);
    expectInlineAttachmentClaimed($updatedUpload['id'], $event);

    $this->actingAs($owner)
        ->delete(route('projects.schedule.destroy', [$team, $project, $event]))
        ->assertRedirect();

    expectInlineAttachmentDetached($createdUpload['id']);
    expectInlineAttachmentDetached($updatedUpload['id']);
});

test('schedule creation, recurrence, and its inline attachment claim roll back together', function () {
    Storage::fake('local');
    [$owner, $team, $project] = inlineAttachmentCoverageWorkspace();
    $upload = inlineAttachmentCoverageUpload($this, $owner, $team, $project);

    Event::listen('eloquent.creating: '.Activity::class, function (): void {
        throw new RuntimeException('Forced activity creation failure.');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($owner)
        ->post(route('projects.schedule.store', [$team, $project]), [
            'title' => 'ロールバック対象',
            'details' => inlineAttachmentCoverageBody($upload['previewUrl']),
            'starts_at' => '2026-08-03T10:00',
            'ends_at' => '2026-08-03T11:00',
            'recurrence' => 'weekly',
            'recurrence_until' => '2026-08-17',
        ]))
        ->toThrow(RuntimeException::class, 'Forced activity creation failure.');

    expect(ScheduleEvent::query()->where('title', 'ロールバック対象')->exists())->toBeFalse()
        ->and(StoredFile::query()->findOrFail($upload['id']))
        ->attachable_type->toBeNull()
        ->attachable_id->toBeNull();
});

test('card store and update claim inline uploads while advancing the card version', function () {
    Storage::fake('local');
    [$owner, $team, $project] = inlineAttachmentCoverageWorkspace();
    $cardTable = CardTable::factory()->for($project)->create();
    $cardColumn = CardColumn::factory()->for($cardTable)->create();

    $createdUpload = inlineAttachmentCoverageUpload($this, $owner, $team, $project);

    $this->actingAs($owner)
        ->post(route('projects.cards.store', [$team, $project, $cardTable, $cardColumn]), [
            'title' => '添付カード',
            'description' => inlineAttachmentCoverageBody($createdUpload['previewUrl']),
        ])
        ->assertRedirect();

    $card = Card::query()->firstOrFail();
    expectInlineAttachmentClaimed($createdUpload['id'], $card);

    $updatedUpload = inlineAttachmentCoverageUpload($this, $owner, $team, $project);

    $this->actingAs($owner)
        ->patch(route('projects.cards.update', [$team, $project, $cardTable, $cardColumn, $card]), [
            'title' => '添付カードを更新',
            'description' => inlineAttachmentCoverageBody($updatedUpload['previewUrl'], '更新添付'),
            'version' => 1,
        ])
        ->assertRedirect();

    expectInlineAttachmentDetached($createdUpload['id']);
    expectInlineAttachmentClaimed($updatedUpload['id'], $card);
    expect($card->fresh()->version)->toBe(2);

    $this->actingAs($owner)
        ->delete(route('projects.cards.destroy', [$team, $project, $cardTable, $cardColumn, $card]))
        ->assertRedirect();

    expectInlineAttachmentDetached($createdUpload['id']);
    expectInlineAttachmentDetached($updatedUpload['id']);
});

test('card column comments claim update and detach inline uploads', function () {
    Storage::fake('local');
    [$owner, $team, $project] = inlineAttachmentCoverageWorkspace();
    $cardTable = CardTable::factory()->for($project)->create();
    $cardColumn = CardColumn::factory()->for($cardTable)->create();
    $createdUpload = inlineAttachmentCoverageUpload($this, $owner, $team, $project);

    $this->actingAs($owner)
        ->post(route('projects.card-columns.comments.store', [$team, $project, $cardTable, $cardColumn]), [
            'body' => inlineAttachmentCoverageBody($createdUpload['previewUrl']),
        ])
        ->assertRedirect();

    $comment = $cardColumn->comments()->firstOrFail();
    expectInlineAttachmentClaimed($createdUpload['id'], $comment);

    $updatedUpload = inlineAttachmentCoverageUpload($this, $owner, $team, $project);
    $this->actingAs($owner)
        ->patch(route('projects.card-columns.comments.update', [
            $team,
            $project,
            $cardTable,
            $cardColumn,
            $comment,
        ]), [
            'body' => inlineAttachmentCoverageBody($updatedUpload['previewUrl'], '更新添付'),
        ])
        ->assertRedirect();

    expectInlineAttachmentDetached($createdUpload['id']);
    expectInlineAttachmentClaimed($updatedUpload['id'], $comment);

    $this->actingAs($owner)
        ->delete(route('projects.card-columns.comments.destroy', [
            $team,
            $project,
            $cardTable,
            $cardColumn,
            $comment,
        ]))
        ->assertRedirect();

    expectInlineAttachmentDetached($updatedUpload['id']);
});

test('check-in answers claim inline uploads to the answered prompt', function () {
    Storage::fake('local');
    [$owner, $team, $project] = inlineAttachmentCoverageWorkspace();
    $checkIn = CheckIn::factory()->for($project)->for($owner, 'creator')->create([
        'frequency' => CheckInFrequency::Weekly,
    ]);
    $prompt = CheckInPrompt::factory()->for($checkIn)->for($owner)->create();
    $upload = inlineAttachmentCoverageUpload($this, $owner, $team, $project);

    $this->actingAs($owner)
        ->post(route('projects.check-ins.answer', [$team, $project, $checkIn, $prompt]), [
            'answer' => inlineAttachmentCoverageBody($upload['previewUrl']),
        ])
        ->assertRedirect();

    expectInlineAttachmentClaimed($upload['id'], $prompt);

    $this->actingAs($owner)
        ->delete(route('projects.check-ins.destroy', [$team, $project, $checkIn]))
        ->assertRedirect();

    expectInlineAttachmentDetached($upload['id']);
});

test('todo updates and todo comments claim inline uploads to their exact models', function () {
    Storage::fake('local');
    Notification::fake();
    [$owner, $team, $project] = inlineAttachmentCoverageWorkspace();
    $todoList = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($todoList, 'todoList')->for($owner, 'creator')->create();

    $todoUpload = inlineAttachmentCoverageUpload($this, $owner, $team, $project);
    $this->actingAs($owner)
        ->patch(route('projects.todos.update', [$team, $project, $todoList, $todo]), [
            'title' => $todo->title,
            'details' => inlineAttachmentCoverageBody($todoUpload['previewUrl']),
        ])
        ->assertRedirect();
    expectInlineAttachmentClaimed($todoUpload['id'], $todo);

    $todoCommentUpload = inlineAttachmentCoverageUpload($this, $owner, $team, $project);
    $this->actingAs($owner)
        ->post(route('projects.todos.comments.store', [$team, $project, $todoList, $todo]), [
            'body' => inlineAttachmentCoverageBody($todoCommentUpload['previewUrl']),
        ])
        ->assertRedirect();
    $todoComment = Comment::query()->where('commentable_type', $todo->getMorphClass())->firstOrFail();
    expectInlineAttachmentClaimed($todoCommentUpload['id'], $todoComment);

    $updatedTodoCommentUpload = inlineAttachmentCoverageUpload($this, $owner, $team, $project);
    $this->actingAs($owner)
        ->patch(route('projects.todos.comments.update', [$team, $project, $todoList, $todo, $todoComment]), [
            'body' => inlineAttachmentCoverageBody($updatedTodoCommentUpload['previewUrl'], '更新添付'),
        ])
        ->assertRedirect();
    expectInlineAttachmentDetached($todoCommentUpload['id']);
    expectInlineAttachmentClaimed($updatedTodoCommentUpload['id'], $todoComment);

    $listCommentUpload = inlineAttachmentCoverageUpload($this, $owner, $team, $project);
    $this->actingAs($owner)
        ->post(route('projects.todo-lists.comments.store', [$team, $project, $todoList]), [
            'body' => inlineAttachmentCoverageBody($listCommentUpload['previewUrl']),
        ])
        ->assertRedirect();
    $listComment = Comment::query()
        ->where('commentable_type', $todoList->getMorphClass())
        ->firstOrFail();
    expectInlineAttachmentClaimed($listCommentUpload['id'], $listComment);

    $updatedListCommentUpload = inlineAttachmentCoverageUpload($this, $owner, $team, $project);
    $this->actingAs($owner)
        ->patch(route('projects.todo-lists.comments.update', [$team, $project, $todoList, $listComment]), [
            'body' => inlineAttachmentCoverageBody($updatedListCommentUpload['previewUrl'], '更新添付'),
        ])
        ->assertRedirect();
    expectInlineAttachmentDetached($listCommentUpload['id']);
    expectInlineAttachmentClaimed($updatedListCommentUpload['id'], $listComment);

    $this->actingAs($owner)
        ->delete(route('projects.todos.destroy', [$team, $project, $todoList, $todo]))
        ->assertRedirect();

    expectInlineAttachmentDetached($todoUpload['id']);
    expectInlineAttachmentDetached($todoCommentUpload['id']);
    expectInlineAttachmentDetached($updatedTodoCommentUpload['id']);

    $this->actingAs($owner)
        ->delete(route('projects.todo-lists.destroy', [$team, $project, $todoList]))
        ->assertRedirect();

    expectInlineAttachmentDetached($listCommentUpload['id']);
    expectInlineAttachmentDetached($updatedListCommentUpload['id']);
});

test('inline attachment references reject missing foreign deleting and already claimed uploads', function () {
    [$owner, $team, $project] = inlineAttachmentCoverageWorkspace();
    $otherUploader = User::factory()->create();
    $team->members()->attach($otherUploader, ['role' => TeamRole::Member->value]);
    $project->members()->attach($otherUploader);
    $otherProject = Project::factory()->for($team)->for($owner, 'creator')->create();
    $document = Document::factory()->for($project)->for($owner, 'creator')->create([
        'title' => '変更前',
        'body' => '<p>元の本文</p>',
    ]);
    $otherDocument = Document::factory()->for($project)->for($owner, 'creator')->create();
    $foreignUploaderFile = inlineAttachmentCoverageFile($project, $otherUploader);
    $foreignProjectFile = inlineAttachmentCoverageFile($otherProject, $owner);
    $deletingFile = inlineAttachmentCoverageFile($project, $owner, [
        'preview_status' => 'deleting',
    ]);
    $claimedFile = inlineAttachmentCoverageFile($project, $owner, [
        'attachable_type' => $otherDocument->getMorphClass(),
        'attachable_id' => $otherDocument->id,
    ]);
    $missingFileId = ((int) StoredFile::query()->max('id')) + 1000;
    $references = [
        'foreign uploader' => $foreignUploaderFile->id,
        'foreign project' => $foreignProjectFile->id,
        'deleting' => $deletingFile->id,
        'claimed elsewhere' => $claimedFile->id,
        'missing' => $missingFileId,
    ];

    foreach ($references as $label => $fileId) {
        $previewUrl = "/{$team->slug}/projects/{$project->id}/files/{$fileId}/preview";

        $this->actingAs($owner)
            ->patch(route('projects.documents.update', [$team, $project, $document]), [
                'title' => "不正な参照: {$label}",
                'body' => inlineAttachmentCoverageBody($previewUrl),
            ])
            ->assertSessionHasErrors('body');

        expect($document->fresh())
            ->title->toBe('変更前')
            ->body->toBe('<p>元の本文</p>');
    }

    expect($foreignUploaderFile->fresh()->attachable_id)->toBeNull()
        ->and($foreignProjectFile->fresh()->attachable_id)->toBeNull()
        ->and($deletingFile->fresh()->attachable_id)->toBeNull()
        ->and($claimedFile->fresh()->attachable_id)->toBe($otherDocument->id);
});

test('invalid inline attachment updates preserve the previous body and attachment', function () {
    [$owner, $team, $project] = inlineAttachmentCoverageWorkspace();
    $otherUploader = User::factory()->create();
    $team->members()->attach($otherUploader, ['role' => TeamRole::Member->value]);
    $project->members()->attach($otherUploader);
    $document = Document::factory()->for($project)->for($owner, 'creator')->create([
        'title' => '変更前',
    ]);
    $originalFile = inlineAttachmentCoverageFile($project, $owner, [
        'attachable_type' => $document->getMorphClass(),
        'attachable_id' => $document->id,
    ]);
    $originalPreviewUrl = "/{$team->slug}/projects/{$project->id}/files/{$originalFile->id}/preview";
    $document->update([
        'body' => inlineAttachmentCoverageBody($originalPreviewUrl, '元の添付'),
    ]);
    $originalBody = $document->fresh()->body;
    $forgedFile = inlineAttachmentCoverageFile($project, $otherUploader);
    $forgedPreviewUrl = "/{$team->slug}/projects/{$project->id}/files/{$forgedFile->id}/preview";

    $this->actingAs($owner)
        ->patch(route('projects.documents.update', [$team, $project, $document]), [
            'title' => '変更後',
            'body' => inlineAttachmentCoverageBody($forgedPreviewUrl, '不正な添付'),
        ])
        ->assertSessionHasErrors('body');

    expect($document->fresh())
        ->title->toBe('変更前')
        ->body->toBe($originalBody);
    expectInlineAttachmentClaimed($originalFile->id, $document);
    expect($forgedFile->fresh())
        ->attachable_type->toBeNull()
        ->attachable_id->toBeNull();
});

test('project collaborators can retain inline attachments already linked to the edited model', function () {
    [$owner, $team, $project] = inlineAttachmentCoverageWorkspace();
    $collaborator = User::factory()->create();
    $team->members()->attach($collaborator, ['role' => TeamRole::Member->value]);
    $project->members()->attach($collaborator);
    $document = Document::factory()->for($project)->for($owner, 'creator')->create();
    $file = inlineAttachmentCoverageFile($project, $collaborator, [
        'attachable_type' => $document->getMorphClass(),
        'attachable_id' => $document->id,
    ]);
    $previewUrl = "/{$team->slug}/projects/{$project->id}/files/{$file->id}/preview";

    $this->actingAs($owner)
        ->patch(route('projects.documents.update', [$team, $project, $document]), [
            'title' => '共同編集後',
            'body' => inlineAttachmentCoverageBody($previewUrl),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($document->fresh()->title)->toBe('共同編集後');
    expectInlineAttachmentClaimed($file->id, $document);
});

test('inline attachment parsing uses exact local preview href and src references', function () {
    [$owner, $team, $project] = inlineAttachmentCoverageWorkspace();
    $document = Document::factory()->for($project)->for($owner, 'creator')->create();
    $linkFile = inlineAttachmentCoverageFile($project, $owner);
    $imageFile = inlineAttachmentCoverageFile($project, $owner);
    $plainTextFile = inlineAttachmentCoverageFile($project, $owner);
    $dataAttributeFile = inlineAttachmentCoverageFile($project, $owner);
    $commentFile = inlineAttachmentCoverageFile($project, $owner);
    $externalFile = inlineAttachmentCoverageFile($project, $owner);
    $malformedFile = inlineAttachmentCoverageFile($project, $owner);
    $ordinaryFile = inlineAttachmentCoverageFile($project, $owner, ['is_inline' => false]);
    $previewUrl = fn (int $fileId): string => "/{$team->slug}/projects/{$project->id}/files/{$fileId}/preview";
    $body = implode('', [
        '<a href="'.$previewUrl($linkFile->id).'?download=1">link</a>',
        '<img src="'.$previewUrl($imageFile->id).'" alt="image">',
        '<p>'.$previewUrl($plainTextFile->id).'</p>',
        '<div data-preview="'.$previewUrl($dataAttributeFile->id).'">data</div>',
        '<!-- <a href="'.$previewUrl($commentFile->id).'">comment</a> -->',
        '<a href="https://example.test'.$previewUrl($externalFile->id).'">external</a>',
        '<a href="/'.$team->slug.'/projects/'.$project->id.'/files/'.$malformedFile->id.'suffix/preview">malformed</a>',
        '<a href="'.$previewUrl($ordinaryFile->id).'">ordinary file</a>',
    ]);

    InlineAttachments::syncFromBody($document, $project, $owner->id, $body);

    expectInlineAttachmentClaimed($linkFile->id, $document);
    expectInlineAttachmentClaimed($imageFile->id, $document);

    foreach ([$plainTextFile, $dataAttributeFile, $commentFile, $externalFile, $malformedFile, $ordinaryFile] as $file) {
        expect($file->fresh())
            ->attachable_type->toBeNull()
            ->attachable_id->toBeNull();
    }
});

test('removeFromBody strips links and images for every supported rich text attribute', function () {
    Storage::fake('local');
    [$owner, , $project] = inlineAttachmentCoverageWorkspace();
    $todoList = TodoList::factory()->for($project)->create();
    $todo = Todo::factory()->for($todoList, 'todoList')->for($owner, 'creator')->create();
    $checkIn = CheckIn::factory()->for($project)->for($owner, 'creator')->create();

    $attachables = [
        [$todo, 'details'],
        [MessageBoardPost::factory()->for($project)->for($owner, 'user')->create(), 'body'],
        [$todo->comments()->create(['project_id' => $project->id, 'user_id' => $owner->id, 'body' => 'comment']), 'body'],
        [$todoList->comments()->create(['project_id' => $project->id, 'user_id' => $owner->id, 'body' => 'list comment']), 'body'],
        [Document::factory()->for($project)->for($owner, 'creator')->create(), 'body'],
        [ScheduleEvent::factory()->for($project)->for($owner, 'creator')->create(), 'details'],
        [Card::factory()->for(CardColumn::factory()->for(CardTable::factory()->for($project)), 'cardColumn')->for($owner, 'creator')->create(), 'description'],
        [CheckInPrompt::factory()->for($checkIn)->for($owner)->create(), 'answer'],
    ];

    foreach ($attachables as [$attachable, $attribute]) {
        $file = StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
        ]);
        $previewUrl = "/projects/{$project->id}/files/{$file->id}/preview";
        $attachable->setAttribute($attribute, inlineAttachmentCoverageBody($previewUrl))->save();

        InlineAttachments::removeFromBody($file->fresh());

        $cleaned = (string) $attachable->fresh()->getAttribute($attribute);
        expect($cleaned)
            ->toContain('before')
            ->toContain('after')
            ->not->toContain($previewUrl);
    }
});

test('abandoned inline uploads are pruned only after the grace period', function () {
    Storage::fake('local');
    [$owner, $team, $project] = inlineAttachmentCoverageWorkspace();
    $makeFile = function (string $path, CarbonImmutable $createdAt) use ($owner, $project): StoredFile {
        Storage::disk('local')->put($path, 'inline upload');

        return StoredFile::factory()->for($project)->for($owner, 'uploader')->create([
            'is_inline' => true,
            'attachable_type' => null,
            'attachable_id' => null,
            'path' => $path,
            'original_name' => basename($path),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    };
    $old = $makeFile('projects/test/old-inline.txt', now()->subHours(25));
    $recent = $makeFile('projects/test/recent-inline.txt', now()->subHours(2));
    $attached = $makeFile('projects/test/attached-inline.txt', now()->subHours(25));
    $previewUrl = "/projects/{$project->id}/files/{$attached->id}/preview";

    $this->actingAs($owner)
        ->post(route('projects.documents.store', [$team, $project]), [
            'title' => '保存済み添付',
            'body' => inlineAttachmentCoverageBody($previewUrl),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->artisan('stored-files:prune-inline')->assertSuccessful();

    expect($old->fresh())->toBeNull()
        ->and($recent->fresh())->not->toBeNull()
        ->and($attached->fresh())->not->toBeNull();
    Storage::disk('local')->assertMissing($old->path);
    Storage::disk('local')->assertExists($recent->path);
    Storage::disk('local')->assertExists($attached->path);
});
