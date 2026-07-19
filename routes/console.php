<?php

use App\Enums\TeamAuditEvent;
use App\Models\TeamAuditLog;
use App\Models\TeamInvitation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('app:production-check', function (): int {
    $allowedIps = config('access.allowed_ips');
    $trustedProxies = config('trustedproxy.proxies');
    $reverbOrigins = config('reverb.apps.apps.0.allowed_origins');
    $reverbCredentials = [
        config('reverb.apps.apps.0.app_id'),
        config('reverb.apps.apps.0.key'),
        config('reverb.apps.apps.0.secret'),
    ];

    $checks = [
        'APP_ENV must be production.' => config('app.env') === 'production',
        'APP_DEBUG must be false.' => config('app.debug') === false,
        'APP_KEY must be configured.' => filled(config('app.key')),
        'APP_URL must use HTTPS.' => str_starts_with((string) config('app.url'), 'https://'),
        'BROADCAST_CONNECTION must be reverb.' => config('broadcasting.default') === 'reverb',
        'QUEUE_CONNECTION must use an asynchronous driver.' => ! in_array(config('queue.default'), ['sync', 'null', 'deferred', 'background'], true),
        'CACHE_STORE must be persistent.' => ! in_array(config('cache.default'), ['array', 'null'], true),
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
    ->description('Notify assignees about incomplete to-dos due today');

Schedule::command('check-ins:dispatch')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->description('Create due check-in prompts and queue notifications');

Schedule::command('model:prune --model=App\\Models\\PersonalNoteAttachment')
    ->daily()
    ->withoutOverlapping()
    ->name('prune-orphaned-personal-note-attachments')
    ->description('Prune orphaned personal-note attachments');
