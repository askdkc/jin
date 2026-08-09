<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Localization
{
    /** @var array<string, array{modifiedAt: int|false, usable: bool}> */
    private static array $catalogUsabilityCache = [];

    public function handle(Request $request, Closure $next): Response
    {
        $supportedLocales = config('app.supported_locales', []);
        $supportedLocales = is_array($supportedLocales) ? $supportedLocales : [];
        $candidates = [
            $request->session()->get('locale'),
            config('app.locale'),
            config('app.fallback_locale'),
            'en',
        ];

        foreach ($candidates as $locale) {
            if (! is_string($locale)
                || ! array_key_exists($locale, $supportedLocales)
                || ! $this->hasUsableCatalog($locale)
            ) {
                continue;
            }

            App::setLocale($locale);

            break;
        }

        return $next($request);
    }

    private function hasUsableCatalog(string $locale): bool
    {
        if ($locale === 'en') {
            return true;
        }

        if (preg_match('/\A[a-z]{2}(?:_[A-Z]{2})?\z/', $locale) !== 1) {
            return false;
        }

        $path = lang_path("{$locale}.json");

        if (! is_file($path)) {
            return false;
        }

        $modifiedAt = @filemtime($path);
        $cached = self::$catalogUsabilityCache[$path] ?? null;

        if ($cached !== null && $cached['modifiedAt'] === $modifiedAt) {
            return $cached['usable'];
        }

        try {
            $catalog = json_decode(
                (string) file_get_contents($path),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (Throwable) {
            $catalog = null;
        }

        $usable = is_array($catalog);
        self::$catalogUsabilityCache[$path] = [
            'modifiedAt' => $modifiedAt,
            'usable' => $usable,
        ];

        return $usable;
    }
}
