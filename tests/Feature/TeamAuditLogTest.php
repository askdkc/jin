<?php

use App\Enums\TeamAuditEvent;
use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\TeamAuditLog;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

test('invitation lifecycle and reissue are audited', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner)
        ->post(route('teams.invitations.store', $team), [
            'email' => $invitedUser->email,
            'role' => TeamRole::Client->value,
        ])
        ->assertRedirect(route('teams.edit', $team));

    $firstInvitation = TeamInvitation::query()->sole();

    $this->delete(route('teams.invitations.destroy', [$team, $firstInvitation]))
        ->assertRedirect(route('teams.edit', $team));

    $this->post(route('teams.invitations.store', $team), [
        'email' => $invitedUser->email,
        'role' => TeamRole::Client->value,
    ])->assertRedirect(route('teams.edit', $team));

    $reissuedInvitation = TeamInvitation::query()->sole();

    $this->actingAs($invitedUser)
        ->get(route('invitations.accept', $reissuedInvitation))
        ->assertRedirect(route('dashboard'));

    $logs = $team->auditLogs()->oldest('id')->get();

    expect($logs->pluck('event')->all())->toBe([
        TeamAuditEvent::InvitationSent,
        TeamAuditEvent::InvitationRevoked,
        TeamAuditEvent::InvitationReissued,
        TeamAuditEvent::InvitationAccepted,
    ])->and($logs[0]->metadata)->toMatchArray([
        'role' => TeamRole::Client->value,
    ])->and($logs[0]->metadata['expires_at'])->not->toBeNull()
        ->and($logs[3]->subject_user_id)->toBe($invitedUser->id);
});

test('declining an invitation is audited before it is deleted', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $invitedUser->email,
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser)
        ->delete(route('invitations.decline', $invitation))
        ->assertRedirect(route('dashboard'));

    $this->assertDatabaseHas('team_audit_logs', [
        'team_id' => $team->id,
        'event' => TeamAuditEvent::InvitationDeclined->value,
        'email' => $invitedUser->email,
        'actor_id' => $invitedUser->id,
        'subject_user_id' => $invitedUser->id,
    ]);
});

test('removing a project participant is audited', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $project = Project::factory()->for($team)->for($owner, 'creator')->create();
    $project->members()->attach([$owner->id, $member->id]);

    $this->actingAs($owner)
        ->patch(route('projects.update', [$team, $project]), [
            'name' => $project->name,
            'description' => null,
            'starts_on' => null,
            'ends_on' => null,
            'member_ids' => [$owner->id],
        ])
        ->assertRedirect(route('projects.show', [$team, $project]));

    $this->assertDatabaseHas('team_audit_logs', [
        'team_id' => $team->id,
        'event' => TeamAuditEvent::ProjectMemberRemoved->value,
        'actor_id' => $owner->id,
        'subject_user_id' => $member->id,
        'project_id' => $project->id,
    ]);
});

test('team settings paginate the complete audit history', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    TeamAuditLog::factory()->count(51)->create([
        'team_id' => $team->id,
        'actor_id' => $owner->id,
    ]);

    $latestLog = $team->auditLogs()->latest('id')->firstOrFail();

    $this->actingAs($owner)
        ->get(route('teams.edit', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/Edit')
            ->has('auditLogs.data', 25)
            ->where('auditLogs.data.0.id', $latestLog->id)
            ->where('auditLogs.data.0.label', TeamAuditEvent::InvitationSent->label()),
        );

    $this->actingAs($owner)
        ->get(route('teams.edit', ['team' => $team, 'audit_page' => 3]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('auditLogs.data', 1),
        );
});
