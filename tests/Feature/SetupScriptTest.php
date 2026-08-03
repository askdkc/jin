<?php

use Symfony\Component\Process\Process;

function setupTestEnvironment(): array
{
    $environment = getenv();

    foreach (array_keys($environment) as $key) {
        if (str_starts_with($key, 'ONLYOFFICE_') || in_array($key, [
            'APP_DIR',
            'APP_DOMAIN',
            'APP_ONLYOFFICE_INTERNAL_URL',
            'EMAIL',
            'ENV_FILE',
            'OFFICE_DOMAIN',
            'UPLOAD_MAX_FILE_MB',
        ], true)) {
            $environment[$key] = false;
        }
    }

    return $environment;
}

function runSetupBash(string $script, string ...$arguments): Process
{
    $process = new Process(
        ['/bin/bash', '-c', $script, 'setup-test', ...$arguments],
        null,
        setupTestEnvironment(),
    );
    $process->run();

    return $process;
}

it('runs under the macOS system Bash', function () {
    $process = new Process(['/bin/bash', base_path('setup.sh'), '--help'], null, setupTestEnvironment());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain('Provision ONLYOFFICE DocumentServer');
});

it('checks health without Bash 4 lowercase expansion', function () {
    $process = runSetupBash(<<<'BASH'
source "$1"
curl() { printf 'TRUE'; }
sleep() { :; }
wait_for_health http://127.0.0.1:8081 1 0
BASH, base_path('setup.sh'));

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('uses Ubuntu defaults instead of macOS example values', function () {
    $directory = sys_get_temp_dir().'/jin-setup-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory.'/.env.example', implode("\n", [
        'APP_URL=http://localhost',
        'APP_ONLYOFFICE_INTERNAL_URL=http://host.container.internal:8000',
        'ONLYOFFICE_INSTANCE_ID=jin-local',
        'ONLYOFFICE_JWT_SECRET="abcdefghijklmnopqrstuvwxyz123456#safe"',
        '',
    ]));

    $process = runSetupBash(<<<'BASH'
source "$1"
setup_ubuntu() {
    JWT_SECRET="${JWT_SECRET:-abcdefghijklmnopqrstuvwxyz1234567890}"
    DOCUMENT_SERVER_MAX_DOWNLOAD_BYTES="$TARGET_MAX_DOWNLOAD_BYTES"
    configure_app_env
    printf 'internal=%s\ninstance=%s\n' "$TARGET_INTERNAL_URL" "$INSTANCE_ID"
}
main --platform ubuntu --app-dir "$2" --domain jin.example.com --office-domain office.example.com
BASH, base_path('setup.sh'), $directory);

    try {
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and($process->getOutput())->toContain(
                'internal=https://jin.example.com',
                'instance=jin-production',
            )
            ->and(fileperms($directory.'/.env') & 0777)->toBe(0600);
        expect(file_get_contents($directory.'/.env'))
            ->toContain('ONLYOFFICE_JWT_SECRET="abcdefghijklmnopqrstuvwxyz123456#safe"');
    } finally {
        @unlink($directory.'/.env');
        @unlink($directory.'/.env.example');
        @rmdir($directory);
    }
});

it('rejects an OnlyOffice file limit above the application upload limit', function () {
    $directory = sys_get_temp_dir().'/jin-setup-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory.'/.env.example', implode("\n", [
        'APP_URL=http://localhost',
        'UPLOAD_MAX_FILE_MB=100',
        '',
    ]));

    $process = runSetupBash(<<<'BASH'
source "$1"
setup_ubuntu() { :; }
main --platform ubuntu --app-dir "$2" --domain jin.example.com --office-domain office.example.com
BASH, base_path('setup.sh'), $directory);

    try {
        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('must not exceed UPLOAD_MAX_FILE_MB');
    } finally {
        @unlink($directory.'/.env');
        @unlink($directory.'/.env.example');
        @rmdir($directory);
    }
});

it('rejects an HTTPS public URL with an HTTP-only Nginx request', function () {
    $directory = sys_get_temp_dir().'/jin-setup-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory.'/.env.example', "APP_URL=http://localhost\n");

    $process = runSetupBash(<<<'BASH'
source "$1"
setup_ubuntu() { :; }
main --platform ubuntu --app-dir "$2" --office-domain office.example.com --public-url https://office.example.com --no-ssl --nginx
BASH, base_path('setup.sh'), $directory);

    try {
        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('--no-ssl cannot configure an HTTP-only Nginx site');
    } finally {
        @unlink($directory.'/.env');
        @unlink($directory.'/.env.example');
        @rmdir($directory);
    }
});

it('rejects malformed OnlyOffice boolean settings before writing the environment', function () {
    $directory = sys_get_temp_dir().'/jin-setup-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory.'/.env.example', implode("\n", [
        'APP_URL=http://localhost',
        'ONLYOFFICE_ALLOW_DOWNLOAD=flase',
        '',
    ]));

    $process = runSetupBash(<<<'BASH'
source "$1"
setup_ubuntu() {
    JWT_SECRET='abcdefghijklmnopqrstuvwxyz1234567890'
    DOCUMENT_SERVER_MAX_DOWNLOAD_BYTES="$TARGET_MAX_DOWNLOAD_BYTES"
    configure_app_env
}
main --platform ubuntu --app-dir "$2"
BASH, base_path('setup.sh'), $directory);

    try {
        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('ONLYOFFICE_ALLOW_DOWNLOAD must be true, false, 1, or 0');
    } finally {
        @unlink($directory.'/.env');
        @unlink($directory.'/.env.example');
        @rmdir($directory);
    }
});

it('fails closed for malformed boolean values loaded by Laravel config', function () {
    $environment = setupTestEnvironment();
    $environment['ONLYOFFICE_ALLOW_DOWNLOAD'] = 'flase';

    $process = new Process([
        PHP_BINARY,
        '-r',
        'function env(string $key, mixed $default = null): mixed { $value = getenv($key); return $value === false ? $default : $value; } $config = require "config/onlyoffice.php"; var_export($config["allow_download"]);',
    ], base_path(), $environment);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toBe('false');
});

it('accepts JWT secrets with dotenv-safe punctuation', function () {
    $process = runSetupBash(<<<'BASH'
source "$1"
JWT_SECRET='abcdefghijklmnopqrstuvwxyz123456#safe'
validate_jwt_secret
BASH, base_path('setup.sh'));

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('rejects JWT secrets containing whitespace', function () {
    $process = runSetupBash(<<<'BASH'
source "$1"
JWT_SECRET='abcdefghijklmnopqrstuvwxyz123456 with-space'
validate_jwt_secret
BASH, base_path('setup.sh'));

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('whitespace');
});

it('rejects ambiguous URLs and unsafe integer sizes', function () {
    $process = runSetupBash(<<<'BASH'
source "$1"
valid_http_url 'http://example.com:80:90' && exit 1
valid_http_url 'http://example.com/path' && exit 1
valid_http_url 'https://office.example.com:443/' || exit 1
valid_positive_integer '9007199254740992' && exit 1
valid_positive_integer '209715200' || exit 1
BASH, base_path('setup.sh'));

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('checks the document server editor API during readiness', function () {
    $process = runSetupBash(<<<'BASH'
source "$1"
curl() { printf 'ok'; }
sleep() { :; }
wait_for_editor_api http://127.0.0.1:8081 1 0
BASH, base_path('setup.sh'));

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('enables JWT for an existing Ubuntu configuration', function () {
    $configFile = tempnam(sys_get_temp_dir(), 'jin-onlyoffice-');
    file_put_contents($configFile, '{"services":{"CoAuthoring":{}}}');
    $secret = str_repeat('a', 64);

    $process = runSetupBash(<<<'BASH'
source "$1"
sudo() {
    if [[ "$1" == "env" ]]; then
        shift
        env "$@"
    else
        "$@"
    fi
}
JWT_SECRET="$3"
configure_ubuntu_jwt "$2"
BASH, base_path('setup.sh'), $configFile, $secret);

    try {
        $config = json_decode((string) file_get_contents($configFile), true, flags: JSON_THROW_ON_ERROR);

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and($config['services']['CoAuthoring']['token']['enable'])->toBe([
                'browser' => true,
                'request' => [
                    'inbox' => true,
                    'outbox' => true,
                ],
            ])
            ->and($config['services']['CoAuthoring']['secret']['session']['string'])->toBe($secret)
            ->and($config['services']['CoAuthoring']['token']['inbox']['header'])->toBe('AuthorizationJwt');
    } finally {
        @unlink($configFile);
    }
});

it('updates Ubuntu download limits without replacing the JSON file directly', function () {
    $configFile = tempnam(sys_get_temp_dir(), 'jin-onlyoffice-');
    file_put_contents($configFile, '{"FileConverter":{"converter":{"maxDownloadBytes":100}}}');

    $process = runSetupBash(<<<'BASH'
source "$1"
sudo() {
    if [[ "$1" == "env" ]]; then
        shift
        env "$@"
    else
        "$@"
    fi
}
TARGET_MAX_DOWNLOAD_BYTES=209715200
configure_ubuntu_max_download_bytes "$2"
printf 'configured=%s\n' "$DOCUMENT_SERVER_MAX_DOWNLOAD_BYTES"
BASH, base_path('setup.sh'), $configFile);

    try {
        $config = json_decode((string) file_get_contents($configFile), true, flags: JSON_THROW_ON_ERROR);

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and($process->getOutput())->toContain('configured=209715200')
            ->and($config['FileConverter']['converter']['maxDownloadBytes'])->toBe(209715200);
    } finally {
        @unlink($configFile);
    }
});
