<?php

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

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

test('consolidated migrations expose the final schema directly', function () {
    expect(Schema::hasColumns('users', [
        'two_factor_secret',
        'current_team_id',
        'notification_preferences',
        'job_title',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('stored_files', [
            'attachable_type',
            'material_folder_id',
            'preview_status',
            'checksum',
            'is_inline',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('card_columns', [
            'is_collapsed',
            'is_triage',
            'is_not_now',
            'is_done',
        ]))->toBeTrue()
        ->and(Schema::hasTable('voice_notes'))->toBeFalse();
});
