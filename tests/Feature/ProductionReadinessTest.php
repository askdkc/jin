<?php

use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.key' => 'base64:production-key',
        'app.url' => 'https://groupware.example.com',
        'broadcasting.default' => 'reverb',
        'queue.default' => 'database',
        'cache.default' => 'database',
        'access.enabled' => true,
        'access.allowed_ips' => ['203.0.113.10', '198.51.100.0/24'],
        'trustedproxy.proxies' => ['10.0.0.0/8'],
        'reverb.apps.apps.0.allowed_origins' => ['groupware.example.com'],
        'reverb.apps.apps.0.options.scheme' => 'https',
        'reverb.apps.apps.0.app_id' => 'production-app',
        'reverb.apps.apps.0.key' => 'production-key',
        'reverb.apps.apps.0.secret' => 'production-secret',
        'session.secure' => true,
        'filesystems.disks.local.root' => storage_path('app/private'),
        'filesystems.disks.local.visibility' => null,
    ]);
});

test('safe production configuration passes the deployment check', function () {
    $this->artisan('app:production-check')
        ->expectsOutput('Production configuration is ready.')
        ->assertSuccessful();
});

test('public production configuration does not require an IP allowlist', function () {
    config([
        'access.enabled' => false,
        'access.allowed_ips' => [],
    ]);

    $this->artisan('app:production-check')
        ->expectsOutput('Production configuration is ready.')
        ->assertSuccessful();
});

test('unsafe production configuration fails with an actionable error', function (string $key, mixed $value, string $message) {
    config([$key => $value]);

    $this->artisan('app:production-check')
        ->expectsOutput($message)
        ->assertFailed();
})->with([
    'environment' => ['app.env', 'local', 'APP_ENV must be production.'],
    'debug mode' => ['app.debug', true, 'APP_DEBUG must be false.'],
    'application key' => ['app.key', '', 'APP_KEY must be configured.'],
    'application URL' => ['app.url', 'http://groupware.example.com', 'APP_URL must use HTTPS.'],
    'broadcast connection' => ['broadcasting.default', 'null', 'BROADCAST_CONNECTION must be reverb.'],
    'queue connection' => ['queue.default', 'sync', 'QUEUE_CONNECTION must use an asynchronous driver.'],
    'cache store' => ['cache.default', 'array', 'CACHE_STORE must be persistent.'],
    'empty IP allowlist' => ['access.allowed_ips', [], 'ALLOWED_IPS must not be empty or contain a wildcard when IP restriction is enabled.'],
    'wildcard IP allowlist' => ['access.allowed_ips', ['*'], 'ALLOWED_IPS must not be empty or contain a wildcard when IP restriction is enabled.'],
    'trusted proxy wildcard' => ['trustedproxy.proxies', ['*'], 'TRUSTED_PROXIES must not contain a wildcard.'],
    'Reverb origin' => ['reverb.apps.apps.0.allowed_origins', ['*'], 'REVERB_ALLOWED_ORIGINS must contain only production origins.'],
    'Reverb scheme' => ['reverb.apps.apps.0.options.scheme', 'http', 'REVERB_SCHEME must be https.'],
    'Reverb credentials' => ['reverb.apps.apps.0.key', 'local-key', 'Reverb credentials must not use the example values.'],
    'session cookie' => ['session.secure', false, 'SESSION_SECURE_COOKIE must be true.'],
    'private filesystem root' => ['filesystems.disks.local.root', '/tmp/public', 'The local filesystem must remain private.'],
    'private filesystem visibility' => ['filesystems.disks.local.visibility', 'public', 'The local filesystem must remain private.'],
]);

test('Laravel production caches can be generated', function () {
    try {
        $this->artisan('optimize')->assertSuccessful();
    } finally {
        Artisan::call('optimize:clear');
    }
});
