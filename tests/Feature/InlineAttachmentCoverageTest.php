<?php

use App\Enums\CheckInFrequency;
use App\Enums\TeamRole;
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
use Illuminate\Http\UploadedFile;
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
        ->attachable_id->toBe($attachable->getKey());
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

    expectInlineAttachmentClaimed($updatedUpload['id'], $document);
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
    expectInlineAttachmentClaimed($createdUpload['id'], $event);

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

    expectInlineAttachmentClaimed($updatedUpload['id'], $event);
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

    expectInlineAttachmentClaimed($updatedUpload['id'], $card);
    expect($card->fresh()->version)->toBe(2);
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
    expectInlineAttachmentClaimed($updatedListCommentUpload['id'], $listComment);
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
