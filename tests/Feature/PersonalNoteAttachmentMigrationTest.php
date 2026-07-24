<?php

use App\Enums\TeamRole;
use App\Jobs\DeletePersonalNoteAttachmentFile;
use App\Models\PersonalNoteAttachment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

function personalNoteAttachmentSoftDeleteMigration(): Migration
{
    return require database_path('migrations/2026_07_24_144232_add_deleted_at_to_personal_note_attachments_table.php');
}

function personalNoteAttachmentForMigration(): PersonalNoteAttachment
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

test('personal note attachment soft delete migration rolls back without tombstones', function () {
    $migration = personalNoteAttachmentSoftDeleteMigration();

    try {
        call_user_func([$migration, 'down']);

        expect(Schema::hasColumn('personal_note_attachments', 'deleted_at'))->toBeFalse();
    } finally {
        if (! Schema::hasColumn('personal_note_attachments', 'deleted_at')) {
            call_user_func([$migration, 'up']);
        }
    }

    expect(Schema::hasColumn('personal_note_attachments', 'deleted_at'))->toBeTrue();
});

test('personal note attachment soft delete migration refuses rollback with a pending tombstone', function () {
    Storage::fake('local');
    Queue::fake();
    $attachment = personalNoteAttachmentForMigration();
    $attachment->delete();
    $migration = personalNoteAttachmentSoftDeleteMigration();

    expect(fn () => call_user_func([$migration, 'down']))
        ->toThrow(RuntimeException::class, 'soft-deleted attachments are pending physical cleanup');

    expect(Schema::hasColumn('personal_note_attachments', 'deleted_at'))->toBeTrue()
        ->and(PersonalNoteAttachment::withTrashed()->find($attachment->id)?->trashed())->toBeTrue();
});

test('personal note attachment soft delete migration rolls back after cleanup completes', function () {
    Storage::fake('local');
    Queue::fake();
    $attachment = personalNoteAttachmentForMigration();
    $attachment->delete();

    (new DeletePersonalNoteAttachmentFile(
        $attachment->id,
        $attachment->disk,
        $attachment->path,
    ))->handle();

    expect(PersonalNoteAttachment::withTrashed()->find($attachment->id))->toBeNull()
        ->and(Storage::disk('local')->exists($attachment->path))->toBeFalse();

    $migration = personalNoteAttachmentSoftDeleteMigration();

    try {
        call_user_func([$migration, 'down']);

        expect(Schema::hasColumn('personal_note_attachments', 'deleted_at'))->toBeFalse();
    } finally {
        if (! Schema::hasColumn('personal_note_attachments', 'deleted_at')) {
            call_user_func([$migration, 'up']);
        }
    }

    expect(Schema::hasColumn('personal_note_attachments', 'deleted_at'))->toBeTrue();
});
