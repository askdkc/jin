<?php

use App\Enums\TeamRole;
use App\Jobs\DeletePersonalNoteAttachmentFile;
use App\Models\PersonalNoteAttachment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

function personalNoteAttachmentForCleanup(): PersonalNoteAttachment
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $path = "personal-notes/{$user->id}/migration-test.png";
    Storage::disk('local')->put($path, 'private attachment');

    return PersonalNoteAttachment::create([
        'team_id' => $team->id,
        'uploaded_by' => $user->id,
        'disk' => 'local',
        'path' => $path,
        'original_name' => 'migration-test.png',
        'mime_type' => 'image/png',
        'size' => 18,
    ]);
}

test('personal note attachments include indexed soft deletes in the baseline schema', function () {
    $hasDeletedAtIndex = collect(Schema::getIndexes('personal_note_attachments'))
        ->contains(fn (array $index): bool => $index['columns'] === ['deleted_at']);

    expect(Schema::hasColumn('personal_note_attachments', 'deleted_at'))->toBeTrue()
        ->and($hasDeletedAtIndex)->toBeTrue();
});

test('personal note attachment deletion retains a tombstone pending cleanup', function () {
    Storage::fake('local');
    Queue::fake();
    $attachment = personalNoteAttachmentForCleanup();
    $attachment->delete();

    expect(Schema::hasColumn('personal_note_attachments', 'deleted_at'))->toBeTrue()
        ->and(PersonalNoteAttachment::withTrashed()->find($attachment->id)?->trashed())->toBeTrue();
});

test('personal note attachment cleanup removes the tombstone and stored file', function () {
    Storage::fake('local');
    Queue::fake();
    $attachment = personalNoteAttachmentForCleanup();
    $attachment->delete();

    (new DeletePersonalNoteAttachmentFile(
        $attachment->id,
        $attachment->disk,
        $attachment->path,
    ))->handle();

    expect(PersonalNoteAttachment::withTrashed()->find($attachment->id))->toBeNull()
        ->and(Storage::disk('local')->exists($attachment->path))->toBeFalse();
});
