<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Event;

test('team member roles can be updated by owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->patch(route('teams.members.update', [$team, $member]), [
            'role' => TeamRole::Admin->value,
        ]);

    $response->assertRedirect(route('teams.edit', $team));

    expect($team->members()->where('user_id', $member->id)->first()->pivot->role->value)->toEqual(TeamRole::Admin->value);
});

test('team member roles cannot be updated by non owners', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($admin)
        ->patch(route('teams.members.update', [$team, $member]), [
            'role' => TeamRole::Admin->value,
        ]);

    $response->assertForbidden();
});

test('team members can be removed by owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $member]));

    $response->assertRedirect(route('teams.edit', $team));

    expect($member->fresh()->belongsToTeam($team))->toBeFalse();
});

test('team members cannot be removed by non owners', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($admin)
        ->delete(route('teams.members.destroy', [$team, $member]));

    $response->assertForbidden();
});

test('team owner cannot be removed', function () {
    $owner = User::factory()->create();
    $legacyOwner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($legacyOwner, ['role' => TeamRole::Owner->value]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $legacyOwner]));

    $response->assertForbidden();

    expect($legacyOwner->fresh()->belongsToTeam($team))->toBeTrue();
});

test('team owner role cannot be changed', function () {
    $owner = User::factory()->create();
    $legacyOwner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($legacyOwner, ['role' => TeamRole::Owner->value]);

    $response = $this
        ->actingAs($owner)
        ->patch(route('teams.members.update', [$team, $legacyOwner]), [
            'role' => TeamRole::Admin->value,
        ]);

    $response->assertForbidden();

    expect($team->members()->where('user_id', $legacyOwner->id)->first()->pivot->role->value)->toEqual(TeamRole::Owner->value);
});

test('team member role cannot be set to owner', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->patch(route('teams.members.update', [$team, $member]), [
            'role' => TeamRole::Owner->value,
        ]);

    $response->assertSessionHasErrors('role');

    expect($team->members()->where('user_id', $member->id)->first()->pivot->role->value)->toEqual(TeamRole::Member->value);
});

test('removed member current team is set to personal team', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $personalTeam = $member->personalTeam();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $member->update(['current_team_id' => $team->id]);

    $this
        ->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $member]));

    expect($member->fresh()->current_team_id)->toEqual($personalTeam->id);
});

test('removing a member from another personal team never keeps that removed team current', function () {
    $owner = User::factory()->create();
    $team = $owner->personalTeam();
    $member = User::factory()->create();
    $memberPersonalTeam = $member->personalTeam();

    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->update(['current_team_id' => $team->id]);

    // Without explicitly excluding the target, personalTeam() resolves this older team first.
    expect($member->personalTeam()?->is($team))->toBeTrue();

    $this->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $member]))
        ->assertRedirect(route('teams.edit', $team));

    expect($member->fresh()->belongsToTeam($team))->toBeFalse()
        ->and($member->fresh()->current_team_id)->toBe($memberPersonalTeam->id);
});

test('member removal rolls back when switching the current team fails', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->update(['current_team_id' => $team->id]);

    Event::listen('eloquent.updating: '.User::class, function (User $updating) use ($member): void {
        if ($updating->is($member)) {
            throw new RuntimeException('Forced current-team update failure.');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $member])))
        ->toThrow(RuntimeException::class, 'Forced current-team update failure.');

    expect($member->fresh()->belongsToTeam($team))->toBeTrue()
        ->and($member->fresh()->current_team_id)->toBe($team->id);
});
