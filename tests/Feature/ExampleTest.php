<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('guests can view the JIN introduction', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('auth.user', null)
            ->where('currentTeam', null),
        );
});

test('authenticated users receive their dashboard context', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('auth.user.id', $user->id)
            ->where('currentTeam.slug', $team->slug),
        );
});

test('shared workspace context reuses the route team and defers the people query', function () {
    $user = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $user->update(['current_team_id' => $team->id]);
    $user = $user->fresh();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($user)
        ->get(route('dashboard', $team))
        ->assertOk();

    $queries = collect(DB::getQueryLog())
        ->pluck('query')
        // Normalize identifier quoting across SQLite, MySQL, PostgreSQL, and SQL Server.
        ->map(fn (string $query): string => str_replace(['"', '`', '[', ']'], '', strtolower($query)));
    DB::disableQueryLog();

    $peopleQueryCount = $queries
        ->filter(fn (string $query): bool => str_contains($query, 'from users inner join team_members'))
        ->count();
    $currentTeamQueryCount = $queries
        ->filter(fn (string $query): bool => str_contains($query, 'from teams where teams.id = ?'))
        ->count();

    expect($peopleQueryCount)->toBe(0)
        ->and($currentTeamQueryCount)->toBe(0);
});

test('the introduction uses the JIN brand copy and icon', function () {
    $welcome = file_get_contents(resource_path('js/pages/Welcome.svelte'));
    $appLogo = file_get_contents(resource_path('js/components/AppLogo.svelte'));
    $appHeader = file_get_contents(resource_path('js/components/AppHeader.svelte'));
    $favicon = file_get_contents(public_path('favicon.svg'));

    expect($welcome)
        ->toContain('>陣（JIN）</span')
        ->toContain('チームの仕事を、<br />ひとつの陣に')
        ->not->toContain('チームの仕事を、<br />ひとつの陣に。')
        ->toContain('必要な情報が、ひとつに集まる<br')
        ->not->toContain('必要な情報が、ひとつに集まる。<br')
        ->not->toContain('/>迷わず判断し、すぐ動ける。')
        ->toContain('覚える順番は、<br />たった三つ')
        ->not->toContain('覚える順番は、<br />たった三つ。')
        ->and($appLogo)
        ->toContain('陣（JIN）')
        ->and($appHeader)
        ->toContain('onclick={openJump}')
        ->toContain('id="global-navigation-menu"')
        ->and($favicon)
        ->toContain('id="gunbai-head"');
});
