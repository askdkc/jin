<?php

use App\Http\Middleware\HandleInertiaRequests;
use Inertia\Testing\AssertableInertia as Assert;

test('a supported locale is saved in the session and redirects back', function () {
    $this->from('/login')
        ->get(route('language.switch', 'fr'))
        ->assertRedirect('/login')
        ->assertSessionHas('locale', 'fr');
});

test('language switching never redirects to a cross origin referrer', function () {
    $this->withSession(['locale' => 'ja'])
        ->withHeader('Referer', 'https://attacker.example/phish')
        ->get(route('language.switch', 'fr'))
        ->assertRedirect(route('home'))
        ->assertSessionHas('locale', 'fr');
});

test('an unsupported locale is rejected without changing the session', function () {
    $this->withSession(['locale' => 'ja'])
        ->get(route('language.switch', 'not-supported'))
        ->assertNotFound()
        ->assertSessionHas('locale', 'ja');
});

test('the session locale is applied to the next request', function () {
    $this->withSession(['locale' => 'de'])
        ->get(route('home'))
        ->assertOk();

    expect(app()->getLocale())->toBe('de');
});

test('the home Inertia response shares locale names and the selected catalog', function () {
    $locales = [
        'en',
        'ja',
        'fr',
        'de',
        'zh_CN',
        'zh_TW',
        'es',
        'pt_BR',
        'pt_PT',
        'ko',
    ];

    $localeNames = [
        'en' => 'English',
        'ja' => '日本語',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'zh_CN' => '简体中文',
        'zh_TW' => '繁體中文',
        'es' => 'Español',
        'pt_BR' => 'Português (Brasil)',
        'pt_PT' => 'Português (Portugal)',
        'ko' => '한국어',
    ];

    $this->withSession(['locale' => 'fr'])
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('locale', 'fr')
            ->where('name', config('app.name'))
            ->where('locales', $locales)
            ->where('localeNames', $localeNames)
            ->where('translations.Log in', 'Se connecter'),
        );
});

test('the locale-scoped once prop omits only the catalog already loaded by the client', function () {
    $headers = [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Except-Once-Props' => 'translations.fr',
    ];

    $this->withSession(['locale' => 'fr'])
        ->withHeaders($headers)
        ->get(route('home'))
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('props.locale', 'fr')
        ->assertJsonMissingPath('props.translations');

    $this->withSession(['locale' => 'de'])
        ->withHeaders($headers)
        ->get(route('home'))
        ->assertOk()
        ->assertJsonPath('props.locale', 'de')
        ->assertJsonPath('props.translations.Log in', 'Anmelden');
});

test('the root html lang maps underscore locales to hyphenated language tags', function () {
    $this->withSession(['locale' => 'zh_CN'])
        ->get(route('home'))
        ->assertSee('<html lang="zh-CN"', false);
});

test('a missing catalog falls back to the configured usable fallback locale', function () {
    config([
        'app.locale' => 'missing',
        'app.fallback_locale' => 'fr',
        'app.supported_locales' => [
            'missing' => 'Missing',
            'fr' => 'Français',
            'en' => 'English',
        ],
    ]);

    $this->withSession(['locale' => 'missing'])
        ->get(route('home'))
        ->assertOk();

    expect(app()->getLocale())->toBe('fr');
});
