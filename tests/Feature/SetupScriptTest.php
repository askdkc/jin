<?php

use Symfony\Component\Process\Process;

function runSetupBash(string $script, string ...$arguments): Process
{
    $process = new Process(['/bin/bash', '-c', $script, 'setup-test', ...$arguments]);
    $process->run();

    return $process;
}

it('runs under the macOS system Bash', function () {
    $process = new Process(['/bin/bash', base_path('setup.sh'), '--help']);
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
        '',
    ]));

    $process = runSetupBash(<<<'BASH'
source "$1"
setup_ubuntu() {
    JWT_SECRET='abcdefghijklmnopqrstuvwxyz1234567890'
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
            ->toContain('ONLYOFFICE_JWT_SECRET="abcdefghijklmnopqrstuvwxyz1234567890"');
    } finally {
        @unlink($directory.'/.env');
        @unlink($directory.'/.env.example');
        @rmdir($directory);
    }
});

it('rejects JWT secrets that dotenv would truncate', function () {
    $process = runSetupBash(<<<'BASH'
source "$1"
JWT_SECRET='abcdefghijklmnopqrstuvwxyz123456#truncated'
validate_jwt_secret
BASH, base_path('setup.sh'));

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('base64-safe punctuation');
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
