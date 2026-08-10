<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function setupTestEnvironment(): array
{
    $environment = getenv();

    foreach (array_keys($environment) as $key) {
        if (str_starts_with($key, 'ONLYOFFICE_') || in_array($key, [
            'APP_DIR',
            'APP_DOMAIN',
            'APP_INTERNAL_PORT',
            'APP_ONLYOFFICE_INTERNAL_URL',
            'APP_URL',
            'DB_NAME',
            'DB_PASSWORD',
            'DB_USER',
            'DOMAIN',
            'EMAIL',
            'ENV_FILE',
            'INTERNAL_PORT_EXPLICIT',
            'OFFICE_DOMAIN',
            'REPO_URL',
            'UPLOAD_MAX_FILE_MB',
            'UPLOAD_MAX_FILES',
            'UPLOAD_MAX_REQUEST_MB',
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
        ->and($process->getOutput())->toContain('Provision and deploy Jin on Ubuntu Server')
        ->and($process->getOutput())->toContain('https://github.com/askdkc/jin')
        ->and($process->getOutput())->toContain('<domain>/onlyoffice');
});

it('dispatches Ubuntu with the public Jin repository and production app path defaults', function () {
    $process = runSetupBash(<<<'BASH'
source "$1"
setup_ubuntu() {
    printf 'repo=%s\napp=%s\ndomain=%s\n' "$REPO_URL" "$APP_DIR" "$APP_DOMAIN"
}
main --platform ubuntu --domain jin.example.com --no-ssl
BASH, base_path('setup.sh'));

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain(
            'repo=https://github.com/askdkc/jin',
            'app=/var/www/jin',
            'domain=jin.example.com',
        );
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

it('declares the proven Ubuntu PostgreSQL and PGroonga package matrix', function () {
    $process = runSetupBash(<<<'BASH'
source "$1"
for VERSION_ID in 24.04 26.04; do
    set_ubuntu_package_matrix
    printf '%s=%s|%s\n' "$VERSION_ID" "$PG_MAJOR" "$PGROONGA_PACKAGE"
done
BASH, base_path('setup.sh'));
    $setup = file_get_contents(base_path('setup.sh'));

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain(
            '24.04=16|postgresql-16-pgroonga',
            '26.04=18|postgresql-18-pgdg-pgroonga',
        )
        ->and($process->getOutput())->not->toContain('source', 'postgresql-17')
        ->and($setup)->toContain(
            'https://deb.nodesource.com/node_24.x',
            'php8.5-fpm',
            'nginx-extras',
            'configure_rabbitmq',
        )
        ->and($setup)->not->toContain(
            'build_pgroonga_from_source',
            'postgresql-17',
            'postgresql-contrib-16',
            'postgresql-contrib-18',
            'php8.4',
        );
});

it('accepts IPv4 and IPv6 loopback-only RabbitMQ listeners', function () {
    $process = runSetupBash(<<<'BASH'
source "$1"
sudo() { "$@"; }
ss() {
    printf '%s\n' \
        'LISTEN 0 128 127.0.0.1:5672 0.0.0.0:*' \
        'LISTEN 0 128 [::1]:25672 [::]:*' \
        'LISTEN 0 128 127.0.0.1:4369 0.0.0.0:*'
}
verify_rabbitmq_loopback
BASH, base_path('setup.sh'));

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('rejects publicly bound RabbitMQ listeners', function () {
    $process = runSetupBash(<<<'BASH'
source "$1"
sudo() { "$@"; }
ss() {
    printf '%s\n' \
        'LISTEN 0 128 0.0.0.0:5672 0.0.0.0:*' \
        'LISTEN 0 128 127.0.0.1:25672 0.0.0.0:*' \
        'LISTEN 0 128 127.0.0.1:4369 0.0.0.0:*'
}
verify_rabbitmq_loopback
BASH, base_path('setup.sh'));

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('RabbitMQ listener 5672 is not loopback-only');
});

it('rebinds the systemd EPMD socket to loopback before starting RabbitMQ', function () {
    $setup = file_get_contents(base_path('setup.sh'));
    $start = strpos($setup, 'configure_rabbitmq() {');
    $end = strpos($setup, "\n}\n\nverify_rabbitmq_loopback()", $start);

    expect($start)->not->toBeFalse()
        ->and($end)->not->toBeFalse();

    $configureRabbitMq = substr($setup, $start, $end - $start);
    $stopPosition = strpos($configureRabbitMq, 'sudo systemctl stop rabbitmq-server epmd.service epmd.socket');
    $startEpmdPosition = strpos($configureRabbitMq, 'sudo systemctl enable --now epmd.socket');
    $startRabbitMqPosition = strpos($configureRabbitMq, 'sudo systemctl restart rabbitmq-server');

    expect($configureRabbitMq)->toContain(
        '/etc/systemd/system/epmd.socket.d/jin.conf',
        'NODENAME=rabbit@localhost',
        '[Socket]\nListenStream=\nListenStream=127.0.0.1:4369\nListenStream=[::1]:4369\n',
        'sudo systemctl daemon-reload',
    )
        ->and($stopPosition)->not->toBeFalse()
        ->and($startEpmdPosition)->not->toBeFalse()
        ->and($startRabbitMqPosition)->not->toBeFalse()
        ->and($stopPosition)->toBeLessThan($startEpmdPosition)
        ->and($startEpmdPosition)->toBeLessThan($startRabbitMqPosition);
});

it('canonicalizes Ubuntu paths and confines its environment file', function () {
    $directory = sys_get_temp_dir().'/jin-setup-'.bin2hex(random_bytes(8));
    $outside = sys_get_temp_dir().'/jin-outside-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    mkdir($outside, 0700);
    symlink($outside, $directory.'/escape');

    $process = runSetupBash(<<<'BASH'
source "$1"
APP_DIR="/home/ubuntu"
if canonicalize_ubuntu_app_dir; then
    exit 1
fi
APP_DIR="/var/www/jin"
canonicalize_ubuntu_app_dir
APP_DIR="$2/escape"
if canonicalize_ubuntu_app_dir; then
    exit 1
fi
APP_DIR="/home/ubuntu/jin-test/../jin"
canonicalize_ubuntu_app_dir
ENV_FILE="$APP_DIR/.env"
validate_ubuntu_env_file
printf 'app=%s\nenv=%s\n' "$APP_DIR" "$ENV_FILE"
ENV_FILE="/tmp/jin.env"
if validate_ubuntu_env_file; then
    exit 1
fi
printf 'confined=ok\n'
BASH, base_path('setup.sh'), $directory);

    try {
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and($process->getOutput())->toContain(
                'app=/home/ubuntu/jin',
                'env=/home/ubuntu/jin/.env',
            );
    } finally {
        unlink($directory.'/escape');
        rmdir($directory);
        rmdir($outside);
    }
});

it('reloads Nginx after enabling the managed application site', function () {
    $setup = file_get_contents(base_path('setup.sh'));
    $start = strpos($setup, 'configure_jin_nginx() {');
    $end = strpos($setup, "\n}\n\nconfigure_jin_supervisor()", $start);

    expect($start)->not->toBeFalse()
        ->and($end)->not->toBeFalse();

    $configureNginx = substr($setup, $start, $end - $start);
    $enablePosition = strpos($configureNginx, 'sudo systemctl enable --now nginx');
    $reloadPosition = strpos($configureNginx, 'sudo systemctl reload nginx');

    expect($enablePosition)->not->toBeFalse()
        ->and($reloadPosition)->not->toBeFalse()
        ->and($enablePosition)->toBeLessThan($reloadPosition);
});

it('captures opt-in Nginx diagnostics before rolling back a failed deployment', function () {
    $process = runSetupBash(<<<'BASH'
source "$1"
JIN_SETUP_DIAGNOSTICS=1
JIN_NGINX_BACKUP_READY=1
JIN_NGINX_WAS_ACTIVE=0
APP_PROCESSES_STOPPED=0
APP_MAINTENANCE=0
SUDO_KEEPALIVE_PID=""
diagnose_jin_nginx_failure() { printf 'diagnose\n'; }
restore_jin_nginx_state() { printf 'restore\n'; return 0; }
discard_jin_nginx_backup() { JIN_NGINX_BACKUP_READY=0; return 0; }
sudo() { return 0; }
set +e
false
cleanup_jin_deployment
status=$?
set -e
[[ "$status" -eq 1 ]]
BASH, base_path('setup.sh'));
    $workflow = (string) file_get_contents(base_path('.github/workflows/setup-smoke.yml'));

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(trim($process->getOutput()))->toBe("diagnose\nrestore")
        ->and($workflow)->toContain('export JIN_SETUP_DIAGNOSTICS=1');
});

it('restores per-run Nginx file, symlink, and absent snapshots exactly', function () {
    $directory = sys_get_temp_dir().'/jin-nginx-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);

    $process = runSetupBash(<<<'BASH'
source "$1"
for state in regular symlink absent; do
    root="$2/$state"
    mkdir -p "$root/sites-available" "$root/sites-enabled"
    site="$root/sites-available/jin"
    enabled="$root/sites-enabled/jin"
    default_link="$root/sites-enabled/default"
    backup="$root/backup"
    case "$state" in
        regular)
            printf 'original site\n' > "$site"
            printf 'original enabled\n' > "$enabled"
            printf 'original default\n' > "$default_link"
            ;;
        symlink)
            for name in site enabled default; do
                target="$root/${name}-target"
                printf '%s target\n' "$name" > "$target"
                case "$name" in
                    site) ln -s "$target" "$site" ;;
                    enabled) ln -s "$target" "$enabled" ;;
                    default) ln -s "$target" "$default_link" ;;
                esac
            done
            ;;
        absent)
            ;;
    esac

    snapshot_jin_nginx_state "$backup" "$site" "$enabled" "$default_link"
    printf 'mutated\n' > "$site"
    rm -f "$enabled" "$default_link"
    ln -s "$root/unmanaged-target" "$enabled"
    printf 'mutated default\n' > "$default_link"
    restore_jin_nginx_state "$backup" "$site" "$enabled" "$default_link"

    case "$state" in
        regular)
            [[ -f "$site" && ! -L "$site" ]] && [[ "$(<"$site")" == 'original site' ]]
            [[ -f "$enabled" && ! -L "$enabled" ]] && [[ "$(<"$enabled")" == 'original enabled' ]]
            [[ -f "$default_link" && ! -L "$default_link" ]] && [[ "$(<"$default_link")" == 'original default' ]]
            ;;
        symlink)
            [[ -L "$site" && "$(readlink "$site")" == "$root/site-target" ]]
            [[ -L "$enabled" && "$(readlink "$enabled")" == "$root/enabled-target" ]]
            [[ -L "$default_link" && "$(readlink "$default_link")" == "$root/default-target" ]]
            ;;
        absent)
            [[ ! -e "$site" && ! -L "$site" ]]
            [[ ! -e "$enabled" && ! -L "$enabled" ]]
            [[ ! -e "$default_link" && ! -L "$default_link" ]]
            ;;
    esac
    discard_jin_nginx_backup "$backup"
    [[ ! -e "$backup" ]]
    rm -rf -- "$root"
done
printf 'restored=regular-symlink-absent\n'
BASH, base_path('setup.sh'), $directory);

    try {
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and($process->getOutput())->toContain('restored=regular-symlink-absent');
    } finally {
        if (is_dir($directory)) {
            (new Filesystem)->deleteDirectory($directory);
        }
    }
});

it('defaults and validates the PHP upload count before generating ini', function () {
    $directory = sys_get_temp_dir().'/jin-setup-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory.'/.env', "UPLOAD_MAX_FILES=\n");

    $process = runSetupBash(<<<'BASH'
source "$1"
ENV_FILE="$2/.env"
UPLOAD_MAX_FILES=""
resolve_upload_max_files
printf 'uploads=%s\n' "$UPLOAD_MAX_FILES"
UPLOAD_MAX_FILES="not-a-number"
if resolve_upload_max_files; then
    exit 1
fi
printf 'invalid_rejected=ok\n'
BASH, base_path('setup.sh'), $directory);

    try {
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and($process->getOutput())->toContain('uploads=20', 'invalid_rejected=ok');
    } finally {
        unlink($directory.'/.env');
        rmdir($directory);
    }
});

it('regenerates local placeholder secrets and identifiers but preserves production values', function () {
    $directory = sys_get_temp_dir().'/jin-setup-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory.'/.env', implode("\n", [
        'ONLYOFFICE_JWT_SECRET=local-secret',
        'ONLYOFFICE_INSTANCE_ID=jin-local',
        '',
    ]));

    $process = runSetupBash(<<<'BASH'
source "$1"
ENV_FILE="$2/.env"
JWT_SECRET=""
INSTANCE_ID=""
resolve_ubuntu_jwt_secret
resolve_ubuntu_instance_id
first_secret="$JWT_SECRET"
first_instance="$INSTANCE_ID"
[[ "$first_secret" != local-secret && "$first_instance" != jin-local ]]
printf 'generated_secret_length=%s\n' "${#first_secret}"
JWT_SECRET="$first_secret"
INSTANCE_ID="$first_instance"
resolve_ubuntu_jwt_secret
resolve_ubuntu_instance_id
[[ "$JWT_SECRET" == "$first_secret" && "$INSTANCE_ID" == "$first_instance" ]]
printf 'ONLYOFFICE_JWT_SECRET=real-production-secret-1234567890\nONLYOFFICE_INSTANCE_ID=real-production-id\n' > "$2/.env"
JWT_SECRET=""
INSTANCE_ID=""
resolve_ubuntu_jwt_secret
resolve_ubuntu_instance_id
[[ "$JWT_SECRET" == real-production-secret-1234567890 && "$INSTANCE_ID" == real-production-id ]]
printf 'secret_length=%s\ninstance=%s\n' "${#JWT_SECRET}" "$INSTANCE_ID"
BASH, base_path('setup.sh'), $directory);

    try {
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and($process->getOutput())->toContain('generated_secret_length=64', 'instance=real-production-id')
            ->and($process->getOutput())->not->toContain('jin-local');
    } finally {
        unlink($directory.'/.env');
        rmdir($directory);
    }
});

it('derives the Ubuntu public, internal, and DocumentServer URLs separately', function () {
    $directory = sys_get_temp_dir().'/jin-setup-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory.'/.env', implode("\n", [
        'UPLOAD_MAX_FILE_MB=200',
        'UPLOAD_MAX_REQUEST_MB=500',
        '',
    ]));

    $process = runSetupBash(<<<'BASH'
source "$1"
ENV_FILE="$2/.env"
APP_DOMAIN=jin.example.com
NO_SSL=0
ONLYOFFICE_PORT=8081
APP_INTERNAL_PORT=8090
PORT_EXPLICIT=1
INTERNAL_PORT_EXPLICIT=1
ONLYOFFICE_PUBLIC_PATH=/onlyoffice
resolve_ubuntu_deployment_values
printf 'document=%s\npublic=%s\ninternal=%s\n' "$TARGET_DOCUMENT_SERVER_URL" "$TARGET_PUBLIC_URL" "$TARGET_INTERNAL_URL"
BASH, base_path('setup.sh'), $directory);

    try {
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and($process->getOutput())->toContain(
                'document=http://127.0.0.1:8081',
                'public=https://jin.example.com/onlyoffice',
                'internal=http://127.0.0.1:8090',
            );
    } finally {
        @unlink($directory.'/.env');
        @rmdir($directory);
    }
});

it('derives HTTP URLs for the bounded no-SSL Ubuntu path', function () {
    $directory = sys_get_temp_dir().'/jin-setup-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory.'/.env', "UPLOAD_MAX_FILE_MB=200\nUPLOAD_MAX_REQUEST_MB=500\n");

    $process = runSetupBash(<<<'BASH'
source "$1"
ENV_FILE="$2/.env"
APP_DOMAIN=localhost
NO_SSL=1
ONLYOFFICE_PORT=8181
APP_INTERNAL_PORT=9191
PORT_EXPLICIT=1
INTERNAL_PORT_EXPLICIT=1
ONLYOFFICE_PUBLIC_PATH=/onlyoffice
resolve_ubuntu_deployment_values
printf 'public=%s\ninternal=%s\n' "$TARGET_PUBLIC_URL" "$TARGET_INTERNAL_URL"
BASH, base_path('setup.sh'), $directory);

    try {
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and($process->getOutput())->toContain(
                'public=http://localhost/onlyoffice',
                'internal=http://127.0.0.1:9191',
            );
    } finally {
        @unlink($directory.'/.env');
        @rmdir($directory);
    }
});

it('rejects an OnlyOffice file limit above the application upload limit', function () {
    $directory = sys_get_temp_dir().'/jin-setup-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory.'/.env', implode("\n", [
        'UPLOAD_MAX_FILE_MB=100',
        'UPLOAD_MAX_REQUEST_MB=500',
        'ONLYOFFICE_MAX_FILE_SIZE_BYTES=209715200',
        '',
    ]));

    $process = runSetupBash(<<<'BASH'
source "$1"
ENV_FILE="$2/.env"
APP_DOMAIN=jin.example.com
NO_SSL=1
ONLYOFFICE_PORT=8081
APP_INTERNAL_PORT=8090
PORT_EXPLICIT=1
INTERNAL_PORT_EXPLICIT=1
ONLYOFFICE_PUBLIC_PATH=/onlyoffice
resolve_ubuntu_deployment_values
BASH, base_path('setup.sh'), $directory);

    try {
        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('must not exceed UPLOAD_MAX_FILE_MB');
    } finally {
        @unlink($directory.'/.env');
        @rmdir($directory);
    }
});

it('loads the three production URL directions from safe config defaults', function () {
    $environment = setupTestEnvironment();
    $environment['APP_URL'] = 'https://jin.example.com';
    foreach (['ONLYOFFICE_DOCUMENT_SERVER_URL', 'ONLYOFFICE_PUBLIC_URL', 'APP_ONLYOFFICE_INTERNAL_URL', 'ONLYOFFICE_INSTANCE_ID'] as $key) {
        $environment[$key] = false;
    }

    $process = new Process([
        PHP_BINARY,
        '-r',
        'function env(string $key, mixed $default = null): mixed { $value = getenv($key); return $value === false ? $default : $value; } $config = require "config/onlyoffice.php"; echo json_encode(array_intersect_key($config, array_flip(["document_server_url", "public_url", "internal_url", "instance_id", "allow_download", "allow_print", "allow_copy"])), JSON_THROW_ON_ERROR);',
    ], base_path(), $environment);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'document_server_url' => 'http://127.0.0.1:8081',
            'public_url' => 'https://jin.example.com/onlyoffice',
            'internal_url' => 'http://127.0.0.1:8090',
            'instance_id' => 'jin-production',
            'allow_download' => false,
            'allow_print' => false,
            'allow_copy' => true,
        ]);
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

it('restarts Nginx after narrowing the OnlyOffice listener', function () {
    $setup = (string) file_get_contents(base_path('setup.sh'));
    $start = strpos($setup, 'configure_ubuntu_onlyoffice_deployment() {');
    $end = strpos($setup, "\n}\n\nconfigure_jin_tls()", $start);

    expect($start)->not->toBeFalse()
        ->and($end)->not->toBeFalse();

    $configureOnlyOffice = substr($setup, $start, $end - $start);
    $restrictPosition = strpos($configureOnlyOffice, 'restrict_ubuntu_onlyoffice_listener');
    $testPosition = strpos($configureOnlyOffice, 'sudo nginx -t');
    $restartPosition = strpos($configureOnlyOffice, 'sudo systemctl restart nginx');
    $servicesPosition = strpos($configureOnlyOffice, 'restart_ubuntu_onlyoffice');

    expect($restrictPosition)->not->toBeFalse()
        ->and($testPosition)->not->toBeFalse()
        ->and($restartPosition)->not->toBeFalse()
        ->and($servicesPosition)->not->toBeFalse()
        ->and($restrictPosition)->toBeLessThan($testPosition)
        ->and($testPosition)->toBeLessThan($restartPosition)
        ->and($restartPosition)->toBeLessThan($servicesPosition);
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

it('documents the supported one-shot Ubuntu deployment contract', function () {
    $readme = (string) file_get_contents(base_path('README.md'));

    expect($readme)
        ->toContain('Ubuntu Server 24.04 / 26.04')
        ->toContain('PostgreSQL 16')
        ->toContain('PostgreSQL 18')
        ->toContain('Node.js 24')
        ->toContain('/onlyoffice')
        ->toContain('./setup.sh')
        ->not->toContain('PostgreSQL 17', 'setup_22.x', 'OFFICE_DOMAIN', '--office-domain');
});

it('keeps the ONLYOFFICE CI readiness counter defined', function () {
    $testsWorkflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

    expect($testsWorkflow)
        ->toContain('for attempt in {1..30}; do')
        ->not->toContain('for _ in {1..30}; do');
});

it('requires PHP 8.5 across Composer CI and deployment documentation', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $testsWorkflow = (string) file_get_contents(base_path('.github/workflows/tests.yml'));
    $lintWorkflow = (string) file_get_contents(base_path('.github/workflows/lint.yml'));
    $readme = (string) file_get_contents(base_path('README.md'));

    expect($composer['require']['php'])->toBe('^8.5')
        ->and($testsWorkflow)->toContain("php-version: ['8.5']")
        ->and($testsWorkflow)->not->toContain('8.4')
        ->and($lintWorkflow)->toContain("php-version: '8.5'")
        ->and($lintWorkflow)->not->toContain('8.4')
        ->and($readme)->toContain('PHP 8.5')
        ->and($readme)->not->toContain('PHP 8.4', 'php8.4', 'php@8.4');
});

it('reclaims hosted runner tool directories before checking Docker disk capacity', function () {
    $workflow = (string) file_get_contents(base_path('.github/workflows/setup-smoke.yml'));
    $cleanupPosition = strpos($workflow, 'name: Reclaim hosted runner tool directories');
    $buildPosition = strpos($workflow, 'name: Build Ubuntu systemd image');

    expect($cleanupPosition)->not->toBeFalse()
        ->and($buildPosition)->not->toBeFalse()
        ->and($cleanupPosition)->toBeLessThan($buildPosition)
        ->and($workflow)->toContain(
            '/opt/hostedtoolcache',
            '/opt/ghc',
            '/usr/local/lib/android',
            '/usr/local/share/powershell',
            '/usr/local/share/chromium',
            '/usr/local/share/phantomjs',
            '/usr/share/dotnet',
            'if [[ -e "$directory" || -L "$directory" ]]; then',
            'sudo rm -rf -- "$directory"',
            'docker_root_dir="$(docker info --format',
            'Docker backing filesystem has only',
            'free_mb < 30720',
            'at least 30720 MB',
        );
});

it('upgrades the disposable image before systemd boots and never upgrades the running container', function () {
    $dockerfile = (string) file_get_contents(base_path('.github/setup-systemd.Dockerfile'));
    $workflow = (string) file_get_contents(base_path('.github/workflows/setup-smoke.yml'));
    $containerScriptStart = strpos($workflow, 'docker exec --interactive "$container_name" bash -s <<\'CONTAINER\'');
    $containerScriptEnd = strpos($workflow, '          CONTAINER', $containerScriptStart);
    $containerScript = substr($workflow, $containerScriptStart, $containerScriptEnd - $containerScriptStart);
    $upgradePosition = strpos($dockerfile, 'full-upgrade');
    $installPosition = strpos($dockerfile, 'apt-get install');

    expect($upgradePosition)->not->toBeFalse()
        ->and($installPosition)->not->toBeFalse()
        ->and($upgradePosition)->toBeLessThan($installPosition)
        ->and($dockerfile)->toContain(
            'RUN apt-get update',
            'DEBIAN_FRONTEND=noninteractive apt-get -y full-upgrade',
            'ca-certificates',
            'dbus',
            'systemd',
            'systemd-sysv',
        )
        ->and($containerScript)->not->toContain('apt-get update', 'apt-get upgrade', 'apt-get full-upgrade');
});
