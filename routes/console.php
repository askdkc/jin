<?php

use App\Enums\TeamAuditEvent;
use App\Models\TeamAuditLog;
use App\Models\TeamInvitation;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Process\ExecutableFinder;

Artisan::command('app:production-check', function (): int {
    $allowedIps = config('access.allowed_ips');
    $trustedProxies = config('trustedproxy.proxies');
    $reverbOrigins = config('reverb.apps.apps.0.allowed_origins');
    $reverbCredentials = [
        config('reverb.apps.apps.0.app_id'),
        config('reverb.apps.apps.0.key'),
        config('reverb.apps.apps.0.secret'),
    ];
    $configuredKey = (string) config('app.key');
    $decodedKey = str_starts_with($configuredKey, 'base64:')
        ? base64_decode(substr($configuredKey, 7), true)
        : $configuredKey;
    $onlyOfficeEnabled = config('onlyoffice.enabled') === true;
    $onlyOfficeSecret = (string) config('onlyoffice.jwt_secret');
    $onlyOfficeMaxFileSize = (int) config('onlyoffice.max_file_size');
    $documentServerMaxDownload = (int) config('onlyoffice.document_server_max_download_bytes');
    $uploadMaxFileMegabytes = (int) config('uploads.max_file_megabytes');
    $uploadMaxFiles = (int) config('uploads.max_files_per_request');
    $uploadMaxRequestMegabytes = (int) config('uploads.max_request_megabytes');
    $mailDriver = (string) config('mail.default');
    $mailHost = strtolower((string) config('mail.mailers.smtp.host'));
    $mailPort = (int) config('mail.mailers.smtp.port');
    $mailScheme = (string) config('mail.mailers.smtp.scheme');
    $mailUsername = (string) config('mail.mailers.smtp.username');
    $mailPassword = (string) config('mail.mailers.smtp.password');
    $mailFrom = (string) config('mail.from.address');
    $storedFileContentEnabled = config('stored-file-content.enabled') === true;
    $storedFileContentProcessTimeout = (int) config('stored-file-content.process_timeout');
    $storedFileContentJobTimeout = (int) config('stored-file-content.job_timeout');
    $storedFileContentProcessingLease = (int) config('stored-file-content.processing_lease_seconds');
    $storedFileContentMaxSourceBytes = (int) config('stored-file-content.max_source_bytes');
    $storedFileContentMaxOutputBytes = (int) config('stored-file-content.max_output_bytes');
    $storedFileContentMaxIndexedBytes = (int) config('stored-file-content.max_indexed_bytes');
    $queueRetryAfter = (int) config('queue.connections.'.config('queue.default').'.retry_after');
    $executableFinder = new ExecutableFinder;
    $extractorPaths = [
        (string) config('stored-file-content.markitdown_path'),
        (string) config('stored-file-content.antiword_path'),
        (string) config('stored-file-content.catdoc_path'),
        (string) config('stored-file-content.xls2csv_path'),
        (string) config('stored-file-content.catppt_path'),
    ];
    $extractorsAvailable = array_filter(
        $extractorPaths,
        static function (string $path) use ($executableFinder): bool {
            if ($path === '') {
                return true;
            }

            if (str_contains($path, DIRECTORY_SEPARATOR)) {
                return ! is_file($path) || ! is_executable($path);
            }

            return $executableFinder->find($path) === null;
        },
    ) === [];
    $pgroongaInstalled = false;

    if ($storedFileContentEnabled && config('database.default') === 'pgsql') {
        try {
            $extension = DB::selectOne(<<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM pg_extension
                    WHERE extname = 'pgroonga'
                ) AS installed
            SQL);
            $pgroongaInstalled = filter_var(
                $extension->installed ?? false,
                FILTER_VALIDATE_BOOL,
            );
        } catch (Throwable) {
            $pgroongaInstalled = false;
        }
    }

    $checks = [
        'APP_ENV must be production.' => config('app.env') === 'production',
        'APP_DEBUG must be false.' => config('app.debug') === false,
        'APP_KEY must be a valid key for the configured cipher.' => is_string($decodedKey)
            && Encrypter::supported($decodedKey, (string) config('app.cipher')),
        'APP_URL must use HTTPS.' => str_starts_with((string) config('app.url'), 'https://'),
        'DB_CONNECTION must be pgsql.' => config('database.default') === 'pgsql',
        'BROADCAST_CONNECTION must be reverb.' => config('broadcasting.default') === 'reverb',
        'QUEUE_CONNECTION must be redis.' => config('queue.default') === 'redis',
        'CACHE_STORE must be redis.' => config('cache.default') === 'redis',
        'SESSION_DRIVER must be redis.' => config('session.driver') === 'redis',
        'MAIL_MAILER must deliver mail.' => ! in_array($mailDriver, ['array', 'log'], true),
        'MAIL_HOST must use a configured SMTP provider.' => $mailDriver !== 'smtp'
            || ($mailHost !== '' && ! in_array($mailHost, ['127.0.0.1', '::1', 'localhost'], true)),
        'MAIL_PORT must be a valid SMTP port.' => $mailDriver !== 'smtp'
            || ($mailPort >= 1 && $mailPort <= 65535),
        'MAIL_SCHEME must be smtp or smtps.' => $mailDriver !== 'smtp'
            || in_array($mailScheme, ['smtp', 'smtps'], true),
        'MAIL_USERNAME and MAIL_PASSWORD must be configured for SMTP.' => $mailDriver !== 'smtp'
            || ($mailUsername !== '' && $mailPassword !== ''),
        'MAIL_FROM_ADDRESS must not use an example domain.' => $mailFrom !== ''
            && ! str_ends_with(strtolower($mailFrom), '@example.com'),
        'UPLOAD_MAX_FILE_MB must be at least 1.' => $uploadMaxFileMegabytes >= 1,
        'UPLOAD_MAX_FILES must be at least 1.' => $uploadMaxFiles >= 1,
        'UPLOAD_MAX_REQUEST_MB must be at least UPLOAD_MAX_FILE_MB.' => $uploadMaxRequestMegabytes >= $uploadMaxFileMegabytes,
        'STORED_FILE_CONTENT extractor commands must be executable when enabled.' => ! $storedFileContentEnabled
            || $extractorsAvailable,
        'STORED_FILE_CONTENT_PROCESS_TIMEOUT must be at least 1 second.' => ! $storedFileContentEnabled
            || $storedFileContentProcessTimeout >= 1,
        'STORED_FILE_CONTENT_JOB_TIMEOUT must exceed STORED_FILE_CONTENT_PROCESS_TIMEOUT.' => ! $storedFileContentEnabled
            || $storedFileContentJobTimeout > $storedFileContentProcessTimeout,
        'STORED_FILE_CONTENT_PROCESSING_LEASE_SECONDS must exceed STORED_FILE_CONTENT_JOB_TIMEOUT.' => ! $storedFileContentEnabled
            || $storedFileContentProcessingLease > $storedFileContentJobTimeout,
        'STORED_FILE_CONTENT_MAX_SOURCE_BYTES must be between 1 byte and UPLOAD_MAX_FILE_MB.' => ! $storedFileContentEnabled
            || ($storedFileContentMaxSourceBytes >= 1
                && $storedFileContentMaxSourceBytes <= $uploadMaxFileMegabytes * 1024 * 1024),
        'STORED_FILE_CONTENT_MAX_OUTPUT_BYTES must be at least 1 byte.' => ! $storedFileContentEnabled
            || $storedFileContentMaxOutputBytes >= 1,
        'STORED_FILE_CONTENT_MAX_INDEXED_BYTES must be between 1 byte and STORED_FILE_CONTENT_MAX_OUTPUT_BYTES.' => ! $storedFileContentEnabled
            || ($storedFileContentMaxIndexedBytes >= 1
                && $storedFileContentMaxIndexedBytes <= $storedFileContentMaxOutputBytes),
        'Queue retry_after must exceed STORED_FILE_CONTENT_JOB_TIMEOUT.' => ! $storedFileContentEnabled
            || $queueRetryAfter > $storedFileContentJobTimeout,
        'PGroonga extension must be installed when file-content search is enabled.' => ! $storedFileContentEnabled
            || $pgroongaInstalled,
        'ONLYOFFICE configuration must be complete when enabled.' => ! $onlyOfficeEnabled
            || (filter_var(config('onlyoffice.document_server_url'), FILTER_VALIDATE_URL) !== false
                && str_starts_with((string) config('onlyoffice.public_url'), 'https://')
                && filter_var(config('onlyoffice.internal_url'), FILTER_VALIDATE_URL) !== false
                && strlen($onlyOfficeSecret) >= 32
                && filled(config('onlyoffice.instance_id'))),
        'ONLYOFFICE_MAX_FILE_SIZE_BYTES must not exceed UPLOAD_MAX_FILE_MB.' => ! $onlyOfficeEnabled
            || ($onlyOfficeMaxFileSize > 0
                && $onlyOfficeMaxFileSize <= $uploadMaxFileMegabytes * 1024 * 1024),
        'ONLYOFFICE_MAX_FILE_SIZE_BYTES must not exceed Document Server maxDownloadBytes.' => ! $onlyOfficeEnabled
            || ($documentServerMaxDownload > 0
                && $onlyOfficeMaxFileSize <= $documentServerMaxDownload),
        'ALLOWED_IPS must not be empty or contain a wildcard when IP restriction is enabled.' => config('access.enabled') === false
            || (is_array($allowedIps) && $allowedIps !== [] && ! in_array('*', $allowedIps, true)),
        'TRUSTED_PROXIES must not contain a wildcard.' => is_array($trustedProxies) && ! in_array('*', $trustedProxies, true),
        'REVERB_ALLOWED_ORIGINS must contain only production origins.' => is_array($reverbOrigins)
            && $reverbOrigins !== []
            && array_intersect($reverbOrigins, ['*', 'localhost', '127.0.0.1', '::1']) === [],
        'REVERB_SCHEME must be https.' => config('reverb.apps.apps.0.options.scheme') === 'https',
        'Reverb credentials must not use the example values.' => array_filter(
            $reverbCredentials,
            static fn (mixed $credential): bool => ! is_string($credential)
                || $credential === ''
                || str_starts_with($credential, 'local-'),
        ) === [],
        'SESSION_SECURE_COOKIE must be true.' => config('session.secure') === true,
        'The local filesystem must remain private.' => config('filesystems.disks.local.root') === storage_path('app/private')
            && config('filesystems.disks.local.visibility') !== 'public',
    ];

    $failed = false;

    foreach ($checks as $message => $passed) {
        if (! $passed) {
            $this->error($message);
            $failed = true;
        }
    }

    if ($failed) {
        return Command::FAILURE;
    }

    $this->info('Production configuration is ready.');

    return Command::SUCCESS;
})->purpose('Fail deployment when production configuration is unsafe');

Schedule::call(function () {
    TeamInvitation::query()
        ->with('team')
        ->whereNull('accepted_at')
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->eachById(function (TeamInvitation $invitation): void {
            DB::transaction(function () use ($invitation): void {
                TeamAuditLog::record(
                    team: $invitation->team,
                    event: TeamAuditEvent::InvitationExpired,
                    email: $invitation->email,
                );

                $invitation->delete();
            });
        });
})->daily()->description('Delete expired team invitations');

Schedule::command('todos:send-due-reminders')
    ->dailyAt('08:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->description('Create reminder deliveries for incomplete to-dos due today');

Schedule::command('todos:dispatch-pending-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->description('Queue pending to-do reminder deliveries');

Schedule::command('check-ins:dispatch')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->description('Create due check-in prompts and queue notifications');

Schedule::command('personal-note-attachments:prune')
    ->daily()
    ->withoutOverlapping()
    ->name('prune-orphaned-personal-note-attachments')
    ->description('Prune orphaned personal-note attachments');

Schedule::command('stored-files:prune-inline')
    ->daily()
    ->withoutOverlapping()
    ->name('prune-orphaned-inline-stored-files')
    ->description('Prune abandoned inline project files');
