<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('team administrators can visit Adminland', function () {
    $owner = User::factory()->create(['name' => 'Owner']);
    $administrator = User::factory()->create(['name' => 'Administrator']);
    $member = User::factory()->create(['name' => 'Member']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($administrator, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($administrator)
        ->get(route('adminland.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('adminland/Index')
            ->has('administrators', 2)
            ->where('administrators.0.name', 'Administrator')
            ->where('administrators.0.role', TeamRole::Admin->value)
            ->where('administrators.1.name', 'Owner')
            ->where('administrators.1.role', TeamRole::Owner->value)
            ->where('permissions.canUpdateMember', false));
});

test('team owners can manage administrator roles in Adminland', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner)
        ->get(route('adminland.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.canUpdateMember', true));
});

test('regular team members cannot visit Adminland', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member)
        ->get(route('adminland.index', $team))
        ->assertForbidden();
});
