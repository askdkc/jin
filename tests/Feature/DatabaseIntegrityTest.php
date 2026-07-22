<?php

use App\Models\Document;
use App\Models\Project;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

dataset('workspace query indexes', [
    ['schedule_events', ['starts_at']],
    ['activities', ['actor_id', 'id']],
    ['check_ins', ['project_id', 'id']],
    ['direct_messages', ['sender_id', 'team_id', 'id']],
    ['direct_messages', ['recipient_id', 'team_id', 'id']],
    ['documents', ['material_folder_id', 'project_id']],
    ['external_links', ['material_folder_id', 'project_id']],
    ['todos', ['completed_at', 'due_on', 'id']],
    ['chat_messages', ['parent_id']],
    ['check_in_prompts', ['user_id', 'answered_at', 'scheduled_for']],
    ['notifications', ['notifiable_type', 'notifiable_id', 'created_at']],
    ['notifications', ['notifiable_type', 'notifiable_id', 'read_at']],
    ['stored_files', ['project_id', 'updated_at']],
    ['stored_files', ['material_folder_id', 'project_id']],
    ['team_audit_logs', ['team_id', 'id']],
    ['team_invitations', ['email', 'accepted_at', 'created_at']],
    ['team_invitations', ['team_id', 'email', 'accepted_at', 'expires_at']],
    ['team_members', ['user_id', 'team_id']],
]);

test('workspace query indexes match the application access paths', function (string $table, array $columns) {
    $hasIndex = collect(Schema::getIndexes($table))
        ->contains(fn (array $index): bool => $index['columns'] === $columns);

    expect($hasIndex)->toBeTrue();
})->with('workspace query indexes');

test('message board reaction uniqueness uses a portable index name', function () {
    $index = collect(Schema::getIndexes('message_board_post_reactions'))
        ->first(fn (array $index): bool => $index['columns'] === ['message_board_post_id', 'user_id']);

    expect($index)->not->toBeNull()
        ->and($index['unique'])->toBeTrue()
        ->and(strlen($index['name']))->toBeLessThanOrEqual(63);
});

test('email addresses are stored in canonical form', function () {
    $user = User::factory()->create(['email' => '  MEMBER@Example.COM  ']);
    $invitation = TeamInvitation::factory()->create([
        'email' => '  INVITED@Example.COM  ',
        'invited_by' => $user->id,
    ]);

    expect($user->email)->toBe('member@example.com')
        ->and($invitation->email)->toBe('invited@example.com');
});

test('the default chat channel backfill is idempotent', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user, 'creator')->create();
    $migration = require database_path('migrations/2026_07_16_202254_backfill_default_chat_channels.php');

    $migration->up();
    $migration->down();
    $migration->up();

    expect($project->chatChannels()->where('name', 'general')->count())->toBe(1);
});

test('existing unverified users are backfilled without an unsafe rollback', function () {
    $user = User::factory()->unverified()->create();
    $migration = require database_path('migrations/2026_07_22_000001_backfill_existing_user_email_verification.php');

    $migration->up();
    expect($user->fresh()->email_verified_at)->not->toBeNull();

    $migration->down();
    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

test('stored file inline classification backfill is data-safe and idempotent', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user, 'creator')->create();
    $document = Document::factory()->for($project)->for($user, 'creator')->create();
    $referenced = StoredFile::factory()->for($project)->for($user, 'uploader')->create([
        'attachable_type' => $document->getMorphClass(),
        'attachable_id' => $document->id,
        'is_inline' => false,
    ]);
    $unreferenced = StoredFile::factory()->for($project)->for($user, 'uploader')->create([
        'attachable_type' => $document->getMorphClass(),
        'attachable_id' => $document->id,
        'is_inline' => false,
    ]);
    $neutral = StoredFile::factory()->for($project)->for($user, 'uploader')->create([
        'is_inline' => false,
    ]);
    $document->update([
        'body' => "<a href=\"/projects/{$project->id}/files/{$referenced->id}/preview\">inline</a>",
    ]);
    $migration = require database_path('migrations/2026_07_22_000003_backfill_stored_file_inline_flag.php');

    $migration->up();
    $migration->up();

    expect($referenced->fresh()->is_inline)->toBeTrue()
        ->and($unreferenced->fresh()->is_inline)->toBeFalse()
        ->and($neutral->fresh()->is_inline)->toBeFalse();
});

test('dropping voice notes deletes their stored blobs first', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => 'owner']);
    $migration = require database_path('migrations/2026_07_18_110521_drop_voice_notes_table.php');
    $migration->down();
    $path = "voice-notes/{$team->id}/note.webm";
    Storage::disk('local')->put($path, 'audio');
    DB::table('voice_notes')->insert([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => $path,
        'mime_type' => 'audio/webm',
        'size' => 5,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(Schema::hasTable('voice_notes'))->toBeFalse();
    Storage::disk('local')->assertMissing($path);
});
