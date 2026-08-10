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
    $japanese = json_decode(
        (string) file_get_contents(lang_path('ja.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($welcome)
        ->toContain('>陣（JIN）</span')
        ->toContain('Bring your team\'s work')
        ->toContain('into one JIN workspace')
        ->and($japanese["Bring your team's work"])->toBe('チームの仕事を、')
        ->and($japanese['into one JIN workspace'])->toBe('ひとつの陣に')
        ->and($japanese['All the information you need, together'])->toBe('必要な情報が、ひとつに集まる')
        ->and($japanese['Decide clearly and move quickly'])->toBe('迷わず判断し、すぐ動ける')
        ->and($japanese['Three simple steps'])->toBe('覚える順番は、')
        ->and($japanese['That is all it takes'])->toBe('たった三つ')
        ->and($appLogo)
        ->toContain('陣（JIN）')
        ->and($appHeader)
        ->toContain('onclick={openJump}')
        ->toContain('id="global-navigation-menu"')
        ->and($favicon)
        ->toContain('id="gunbai-head"');
});

test('the introduction exposes translated registration and login links to guests', function () {
    $welcome = (string) file_get_contents(resource_path('js/pages/Welcome.svelte'));

    expect($welcome)
        ->toContain("import { dashboard, login, register } from '@/routes';")
        ->toContain('data-test="welcome-login-link"')
        ->toContain('data-test="welcome-register-button"')
        ->toContain('data-test="welcome-hero-register-button"')
        ->toContain('data-test="welcome-footer-register-button"')
        ->toContain('data-test="welcome-hero-login-link"')
        ->toContain("t('Register')")
        ->toContain("t('Create account')")
        ->toContain("t('Log in')");

    expect(substr_count($welcome, 'href={toUrl(register())}'))->toBe(3);

    foreach (array_keys(config('app.supported_locales')) as $locale) {
        if ($locale === 'en') {
            continue;
        }

        $translations = json_decode(
            (string) file_get_contents(lang_path("{$locale}.json")),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($translations)
            ->toHaveKey('Register')
            ->toHaveKey('Log in');
    }
});
