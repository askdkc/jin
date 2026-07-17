<?php

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

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
