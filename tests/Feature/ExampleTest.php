<?php

use App\Models\User;
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
