<?php

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

dataset('workspace query indexes', [
    ['activities', ['actor_id', 'id']],
    ['check_ins', ['project_id', 'id']],
    ['documents', ['material_folder_id', 'project_id']],
    ['external_links', ['material_folder_id', 'project_id']],
    ['stored_files', ['material_folder_id', 'project_id']],
    ['team_invitations', ['email', 'accepted_at', 'created_at']],
    ['team_invitations', ['team_id', 'email', 'accepted_at', 'expires_at']],
    ['team_members', ['user_id', 'team_id']],
]);

test('workspace query indexes match the application access paths', function (string $table, array $columns) {
    $hasIndex = collect(Schema::getIndexes($table))
        ->contains(fn (array $index): bool => $index['columns'] === $columns);

    expect($hasIndex)->toBeTrue();
})->with('workspace query indexes');

test('email addresses are stored in canonical form', function () {
    $user = User::factory()->create(['email' => '  MEMBER@Example.COM  ']);
    $invitation = TeamInvitation::factory()->create([
        'email' => '  INVITED@Example.COM  ',
        'invited_by' => $user->id,
    ]);

    expect($user->email)->toBe('member@example.com')
        ->and($invitation->email)->toBe('invited@example.com');
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
