<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::shouldReceive('selectOne')
        ->byDefault()
        ->andReturn((object) ['installed' => true]);

    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'app.url' => 'https://groupware.example.com',
        'database.default' => 'pgsql',
        'broadcasting.default' => 'reverb',
        'queue.default' => 'redis',
        'cache.default' => 'redis',
        'session.driver' => 'redis',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.provider.test',
        'mail.mailers.smtp.port' => 587,
        'mail.mailers.smtp.scheme' => 'smtp',
        'mail.mailers.smtp.username' => 'jin-production',
        'mail.mailers.smtp.password' => 'production-secret',
        'mail.from.address' => 'noreply@jin.test',
        'uploads.max_file_megabytes' => 200,
        'uploads.max_files_per_request' => 20,
        'uploads.max_request_megabytes' => 500,
        'stored-file-content.enabled' => false,
        'stored-file-content.markitdown_path' => PHP_BINARY,
        'stored-file-content.process_timeout' => 120,
        'stored-file-content.job_timeout' => 180,
        'stored-file-content.processing_lease_seconds' => 300,
        'stored-file-content.max_source_bytes' => 50 * 1024 * 1024,
        'stored-file-content.max_output_bytes' => 16 * 1024 * 1024,
        'stored-file-content.max_indexed_bytes' => 2 * 1024 * 1024,
        'onlyoffice.enabled' => false,
        'onlyoffice.max_file_size' => 209715200,
        'onlyoffice.document_server_max_download_bytes' => 209715200,
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

test('enabled file content search passes with executable extractors and pgroonga', function () {
    config(['stored-file-content.enabled' => true]);
    DB::shouldReceive('selectOne')
        ->once()
        ->andReturn((object) ['installed' => true]);

    $this->artisan('app:production-check')
        ->expectsOutput('Production configuration is ready.')
        ->assertSuccessful();
});

test('enabled file content search requires executable extractor commands', function () {
    config([
        'stored-file-content.enabled' => true,
        'stored-file-content.markitdown_path' => '/missing/file-content/markitdown',
    ]);
    DB::shouldReceive('selectOne')
        ->once()
        ->andReturn((object) ['installed' => true]);

    $this->artisan('app:production-check')
        ->expectsOutput('STORED_FILE_CONTENT extractor commands must be executable when enabled.')
        ->assertFailed();
});

test('enabled file content search requires safe resource budgets', function (string $key, int $value, string $message) {
    config([
        'stored-file-content.enabled' => true,
        $key => $value,
    ]);
    DB::shouldReceive('selectOne')
        ->once()
        ->andReturn((object) ['installed' => true]);

    $this->artisan('app:production-check')
        ->expectsOutput($message)
        ->assertFailed();
})->with([
    'process timeout' => [
        'stored-file-content.process_timeout',
        0,
        'STORED_FILE_CONTENT_PROCESS_TIMEOUT must be at least 1 second.',
    ],
    'job timeout' => [
        'stored-file-content.job_timeout',
        120,
        'STORED_FILE_CONTENT_JOB_TIMEOUT must exceed STORED_FILE_CONTENT_PROCESS_TIMEOUT.',
    ],
    'processing lease' => [
        'stored-file-content.processing_lease_seconds',
        180,
        'STORED_FILE_CONTENT_PROCESSING_LEASE_SECONDS must exceed STORED_FILE_CONTENT_JOB_TIMEOUT.',
    ],
    'source bytes minimum' => [
        'stored-file-content.max_source_bytes',
        0,
        'STORED_FILE_CONTENT_MAX_SOURCE_BYTES must be between 1 byte and UPLOAD_MAX_FILE_MB.',
    ],
    'source bytes upload ceiling' => [
        'stored-file-content.max_source_bytes',
        201 * 1024 * 1024,
        'STORED_FILE_CONTENT_MAX_SOURCE_BYTES must be between 1 byte and UPLOAD_MAX_FILE_MB.',
    ],
    'output bytes' => [
        'stored-file-content.max_output_bytes',
        0,
        'STORED_FILE_CONTENT_MAX_OUTPUT_BYTES must be at least 1 byte.',
    ],
    'indexed bytes minimum' => [
        'stored-file-content.max_indexed_bytes',
        0,
        'STORED_FILE_CONTENT_MAX_INDEXED_BYTES must be between 1 byte and STORED_FILE_CONTENT_MAX_OUTPUT_BYTES.',
    ],
    'indexed bytes output ceiling' => [
        'stored-file-content.max_indexed_bytes',
        17 * 1024 * 1024,
        'STORED_FILE_CONTENT_MAX_INDEXED_BYTES must be between 1 byte and STORED_FILE_CONTENT_MAX_OUTPUT_BYTES.',
    ],
    'queue retry budget' => [
        'queue.connections.redis.retry_after',
        180,
        'Queue retry_after must exceed STORED_FILE_CONTENT_JOB_TIMEOUT.',
    ],
]);

test('enabled file content search requires pgroonga', function () {
    config(['stored-file-content.enabled' => true]);
    DB::shouldReceive('selectOne')
        ->once()
        ->andReturn((object) ['installed' => false]);

    $this->artisan('app:production-check')
        ->expectsOutput('PGroonga extension must be installed for PostgreSQL full-text search.')
        ->assertFailed();
});

test('unsafe production configuration fails with an actionable error', function (string $key, mixed $value, string $message) {
    config([$key => $value]);

    $this->artisan('app:production-check')
        ->expectsOutput($message)
        ->assertFailed();
})->with([
    'environment' => ['app.env', 'local', 'APP_ENV must be production.'],
    'debug mode' => ['app.debug', true, 'APP_DEBUG must be false.'],
    'application key' => ['app.key', 'base64:short', 'APP_KEY must be a valid key for the configured cipher.'],
    'application URL' => ['app.url', 'http://groupware.example.com', 'APP_URL must use HTTPS.'],
    'database connection' => ['database.default', 'sqlite', 'DB_CONNECTION must be pgsql.'],
    'broadcast connection' => ['broadcasting.default', 'null', 'BROADCAST_CONNECTION must be reverb.'],
    'queue connection' => ['queue.default', 'database', 'QUEUE_CONNECTION must be redis.'],
    'cache store' => ['cache.default', 'database', 'CACHE_STORE must be redis.'],
    'session driver' => ['session.driver', 'database', 'SESSION_DRIVER must be redis.'],
    'mail driver' => ['mail.default', 'log', 'MAIL_MAILER must deliver mail.'],
    'mail host' => ['mail.mailers.smtp.host', '127.0.0.1', 'MAIL_HOST must use a configured SMTP provider.'],
    'mail port' => ['mail.mailers.smtp.port', 0, 'MAIL_PORT must be a valid SMTP port.'],
    'mail scheme' => ['mail.mailers.smtp.scheme', null, 'MAIL_SCHEME must be smtp or smtps.'],
    'mail username' => ['mail.mailers.smtp.username', null, 'MAIL_USERNAME and MAIL_PASSWORD must be configured for SMTP.'],
    'mail password' => ['mail.mailers.smtp.password', null, 'MAIL_USERNAME and MAIL_PASSWORD must be configured for SMTP.'],
    'mail sender' => ['mail.from.address', 'hello@example.com', 'MAIL_FROM_ADDRESS must not use an example domain.'],
    'file upload size' => ['uploads.max_file_megabytes', 0, 'UPLOAD_MAX_FILE_MB must be at least 1.'],
    'file upload count' => ['uploads.max_files_per_request', 0, 'UPLOAD_MAX_FILES must be at least 1.'],
    'aggregate upload size' => ['uploads.max_request_megabytes', 100, 'UPLOAD_MAX_REQUEST_MB must be at least UPLOAD_MAX_FILE_MB.'],
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

test('enabled onlyoffice requires complete production configuration', function () {
    config([
        'onlyoffice.enabled' => true,
        'onlyoffice.document_server_url' => 'http://127.0.0.1:8081',
        'onlyoffice.public_url' => 'http://office.example.com',
        'onlyoffice.internal_url' => 'https://groupware.example.com',
        'onlyoffice.jwt_secret' => 'short',
        'onlyoffice.instance_id' => '',
    ]);

    $this->artisan('app:production-check')
        ->expectsOutput('ONLYOFFICE configuration must be complete when enabled.')
        ->assertFailed();
});

test('enabled onlyoffice requires compatible application and document server file limits', function (string $key, int $value, string $message) {
    config([
        'onlyoffice.enabled' => true,
        'onlyoffice.document_server_url' => 'http://127.0.0.1:8081',
        'onlyoffice.public_url' => 'https://office.jin.test',
        'onlyoffice.internal_url' => 'https://groupware.example.com',
        'onlyoffice.jwt_secret' => str_repeat('s', 32),
        'onlyoffice.instance_id' => 'production',
        $key => $value,
    ]);

    $this->artisan('app:production-check')
        ->expectsOutput($message)
        ->assertFailed();
})->with([
    'application max exceeds upload max' => [
        'uploads.max_file_megabytes',
        100,
        'ONLYOFFICE_MAX_FILE_SIZE_BYTES must not exceed UPLOAD_MAX_FILE_MB.',
    ],
    'application max exceeds document server max' => [
        'onlyoffice.document_server_max_download_bytes',
        100 * 1024 * 1024,
        'ONLYOFFICE_MAX_FILE_SIZE_BYTES must not exceed Document Server maxDownloadBytes.',
    ],
]);

test('Laravel production caches can be generated', function () {
    try {
        $this->artisan('optimize')->assertSuccessful();
    } finally {
        Artisan::call('optimize:clear');
    }
});
