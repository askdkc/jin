#!/usr/bin/env bash
#
# jin - one-shot provisioning for ONLYOFFICE DocumentServer
#
# Ubuntu installs the official ONLYOFFICE package. Apple silicon macOS uses
# Apple's container runtime and named volumes. The script is safe to rerun:
# the application .env and the DocumentServer JWT configuration are updated
# in place, while existing data volumes are preserved.
#
# Examples:
#   ./setup.sh
#   ./setup.sh --domain jin.example.com --office-domain office.example.com
#   ./setup.sh --no-nginx --onlyoffice-port 8081
#
set -euo pipefail

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"

# ---------------------------------------------------------------- helpers --
log()  { printf '\033[1;34m[jin-onlyoffice]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[jin-onlyoffice:warn]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[jin-onlyoffice:error]\033[0m %s\n' "$*" >&2; exit 1; }

valid_hostname() {
    [[ "$1" =~ ^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$ ]]
}

valid_ipv4() {
    local address="$1" octet
    local -a octets

    IFS=. read -r -a octets <<< "$address"
    [[ "${#octets[@]}" -eq 4 ]] || return 1

    for octet in "${octets[@]}"; do
        [[ "$octet" =~ ^[0-9]{1,3}$ ]] || return 1
        (( 10#$octet <= 255 )) || return 1
    done
}

valid_local_hostname() {
    valid_hostname "$1" || [[ "$1" == "localhost" ]] || valid_ipv4 "$1"
}

valid_port() {
    [[ "$1" =~ ^[0-9]+$ ]] && (( 10#$1 >= 1 && 10#$1 <= 65535 ))
}

valid_positive_integer() {
    [[ "$1" =~ ^[0-9]+$ ]] && (( 10#$1 > 0 ))
}

url_host() {
    local url="$1" authority

    authority="${url#*://}"
    authority="${authority%%/*}"
    [[ "$authority" != *"@"* ]] || return 1

    if [[ "$authority" == *":"* ]]; then
        authority="${authority%%:*}"
    fi

    [[ -n "$authority" ]] || return 1
    printf '%s' "$authority"
}

valid_http_url() {
    local url="$1" authority host port

    [[ "$url" =~ ^https?://[^[:space:]/?#]+(/[^[:space:]?#]*)?$ ]] || return 1
    authority="${url#*://}"
    authority="${authority%%/*}"
    if [[ "$authority" == *":"* ]]; then
        port="${authority##*:}"
        valid_port "$port" || return 1
    fi
    host="$(url_host "$url")" || return 1
    valid_local_hostname "$host"
}

read_env() {
    local key="$1" file="${2:-$ENV_FILE}" value

    value="$(sed -n "s/^[[:space:]]*${key}=//p" "$file" | sed -n '1p')"
    value="${value#\"}"
    value="${value%\"}"
    value="${value#\'}"
    value="${value%\'}"
    printf '%s' "$value"
}

set_env() {
    local key="$1" value="$2" file="${3:-$ENV_FILE}" escaped

    escaped="$(printf '%s' "$value" | sed 's/[&|\\]/\\&/g')"

    if grep -Eq "^[[:space:]]*#?[[:space:]]*${key}=" "$file"; then
        sed -E -i.bak "s|^[[:space:]]*#?[[:space:]]*${key}=.*|${key}=${escaped}|" "$file"
        rm -f "${file}.bak"
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}

dotenv_quote() {
    local value="$1"

    value="${value//\\/\\\\}"
    value="${value//\"/\\\"}"
    value="${value//\$/\\\$}"
    printf '"%s"' "$value"
}

wait_for_health() {
    local base_url="${1%/}" attempts="${2:-90}" delay="${3:-2}" response attempt

    for (( attempt = 1; attempt <= attempts; attempt++ )); do
        if response="$(curl -fsS --max-time 5 "${base_url}/healthcheck" 2>/dev/null)"; then
            case "$response" in
                [Tt][Rr][Uu][Ee]|[Oo][Kk]) return 0 ;;
            esac
        fi
        sleep "$delay"
    done

    return 1
}

onlyoffice_package_installed() {
    [[ "$(dpkg-query -W -f='${db:Status-Status}' onlyoffice-documentserver 2>/dev/null || true)" == "installed" ]]
}

read_package_jwt_secret() {
    local config_file="/etc/onlyoffice/documentserver/local.json" secret=""

    [[ -f "$config_file" ]] || return 0

    secret="$(sudo jq -r '.services.CoAuthoring.secret.session.string // empty' "$config_file" 2>/dev/null || true)"
    if [[ -z "$secret" ]]; then
        secret="$(sudo python3 - "$config_file" <<'PY'
import json
import sys
from pathlib import Path

try:
    config = json.loads(Path(sys.argv[1]).read_text())
    print(config.get('services', {}).get('CoAuthoring', {}).get('secret', {}).get('session', {}).get('string', ''))
except (OSError, ValueError, TypeError):
    pass
PY
)"
    fi

    printf '%s' "$secret"
}

validate_jwt_secret() {
    [[ -n "$JWT_SECRET" ]] || die "ONLYOFFICE JWT secret is empty"
    [[ "${#JWT_SECRET}" -ge 32 ]] || die "ONLYOFFICE JWT secret must contain at least 32 characters"
    [[ "$JWT_SECRET" != *$'\n'* && "$JWT_SECRET" != *$'\r'* ]] \
        || die "ONLYOFFICE JWT secret cannot contain newlines"
    [[ "$JWT_SECRET" != *[[:space:]]* ]] \
        || die "ONLYOFFICE JWT secret cannot contain whitespace"
    [[ "$JWT_SECRET" =~ ^[A-Za-z0-9._~+/=-]+$ ]] \
        || die "ONLYOFFICE JWT secret must use letters, numbers, or base64-safe punctuation"
}

resolve_app_values() {
    local app_url_value office_host app_host app_port app_scheme
    local env_public_url env_document_url env_internal_url env_secret env_instance
    local env_max_file_size env_max_download

    [[ -d "$APP_DIR" ]] || die "Application directory does not exist: $APP_DIR"
    APP_DIR="$(CDPATH= cd -- "$APP_DIR" && pwd -P)"
    ENV_FILE="${ENV_FILE:-$APP_DIR/.env}"

    if [[ ! -f "$ENV_FILE" ]]; then
        [[ -f "$APP_DIR/.env.example" ]] || die "Could not find $ENV_FILE or $APP_DIR/.env.example"
        install -m 0600 "$APP_DIR/.env.example" "$ENV_FILE"
        log "Created $ENV_FILE from .env.example"
    fi
    if [[ "$PLATFORM" == "macos" ]]; then
        chmod go-rwx "$ENV_FILE" || die "Could not secure $ENV_FILE"
    else
        chmod o-rwx "$ENV_FILE" || die "Could not secure $ENV_FILE"
    fi

    app_url_value="$(read_env APP_URL)"
    env_public_url="$(read_env ONLYOFFICE_PUBLIC_URL)"
    env_document_url="$(read_env ONLYOFFICE_DOCUMENT_SERVER_URL)"
    env_internal_url="$(read_env APP_ONLYOFFICE_INTERNAL_URL)"
    env_secret="$(read_env ONLYOFFICE_JWT_SECRET)"
    env_instance="$(read_env ONLYOFFICE_INSTANCE_ID)"
    env_max_file_size="$(read_env ONLYOFFICE_MAX_FILE_SIZE_BYTES)"
    env_max_download="$(read_env ONLYOFFICE_DOCUMENT_SERVER_MAX_DOWNLOAD_BYTES)"

    [[ -n "$TARGET_DOCUMENT_SERVER_URL" ]] || TARGET_DOCUMENT_SERVER_URL="$env_document_url"
    if [[ -z "$TARGET_INTERNAL_URL" ]]; then
        if [[ "$PLATFORM" == "ubuntu" && "$env_internal_url" == "http://host.container.internal:8000" ]]; then
            env_internal_url=""
        fi
        TARGET_INTERNAL_URL="$env_internal_url"
    fi
    [[ -n "$JWT_SECRET" ]] || JWT_SECRET="$env_secret"
    if [[ -z "$INSTANCE_ID" ]]; then
        if [[ "$PLATFORM" == "ubuntu" && "$env_instance" == "jin-local" ]]; then
            env_instance=""
        fi
        INSTANCE_ID="$env_instance"
    fi
    [[ -n "$MAX_FILE_SIZE_BYTES" ]] || MAX_FILE_SIZE_BYTES="$env_max_file_size"
    [[ -n "$TARGET_MAX_DOWNLOAD_BYTES" ]] || TARGET_MAX_DOWNLOAD_BYTES="$env_max_download"

    if [[ $PORT_EXPLICIT -eq 1 && $DOCUMENT_SERVER_URL_EXPLICIT -eq 0 ]]; then
        TARGET_DOCUMENT_SERVER_URL="http://127.0.0.1:${ONLYOFFICE_PORT}"
    elif [[ $PORT_EXPLICIT -eq 0 && "$TARGET_DOCUMENT_SERVER_URL" =~ ^https?://(127\.0\.0\.1|localhost):([0-9]+)$ ]]; then
        ONLYOFFICE_PORT="${BASH_REMATCH[2]}"
    fi
    valid_port "$ONLYOFFICE_PORT" || die "Invalid OnlyOffice port: $ONLYOFFICE_PORT"

    TARGET_DOCUMENT_SERVER_URL="${TARGET_DOCUMENT_SERVER_URL:-http://127.0.0.1:${ONLYOFFICE_PORT}}"

    if [[ -n "$OFFICE_DOMAIN" && $PUBLIC_URL_EXPLICIT -eq 0 ]]; then
        if [[ $NO_SSL -eq 0 ]]; then
            TARGET_PUBLIC_URL="https://${OFFICE_DOMAIN}"
        else
            TARGET_PUBLIC_URL="http://${OFFICE_DOMAIN}"
        fi
    else
        TARGET_PUBLIC_URL="${TARGET_PUBLIC_URL:-$env_public_url}"
        TARGET_PUBLIC_URL="${TARGET_PUBLIC_URL:-http://127.0.0.1:${ONLYOFFICE_PORT}}"
    fi

    if [[ $PUBLIC_URL_EXPLICIT -eq 0 && "$TARGET_PUBLIC_URL" =~ ^(https?)://(127\.0\.0\.1|localhost):[0-9]+$ ]]; then
        TARGET_PUBLIC_URL="${BASH_REMATCH[1]}://127.0.0.1:${ONLYOFFICE_PORT}"
    fi

    if [[ -z "$OFFICE_DOMAIN" ]]; then
        office_host="$(url_host "$TARGET_PUBLIC_URL" 2>/dev/null || true)"
        if [[ -n "$office_host" ]] && valid_hostname "$office_host"; then
            OFFICE_DOMAIN="$office_host"
        fi
    fi

    if [[ -z "$APP_DOMAIN" ]]; then
        app_host="$(url_host "$app_url_value" 2>/dev/null || true)"
        if [[ -n "$app_host" ]]; then
            APP_DOMAIN="$app_host"
        fi
    fi

    if [[ -z "$OFFICE_DOMAIN" ]] && [[ -n "$APP_DOMAIN" ]] && valid_hostname "$APP_DOMAIN"; then
        OFFICE_DOMAIN="office.${APP_DOMAIN}"
    fi

    if [[ -n "$OFFICE_DOMAIN" && $PUBLIC_URL_EXPLICIT -eq 0 ]]; then
        if [[ $NO_SSL -eq 0 ]]; then
            TARGET_PUBLIC_URL="https://${OFFICE_DOMAIN}"
        else
            TARGET_PUBLIC_URL="http://${OFFICE_DOMAIN}"
        fi
    fi

    if [[ -z "$TARGET_INTERNAL_URL" ]]; then
        if [[ "$PLATFORM" == "macos" ]]; then
            app_port=8000
            if [[ "$app_url_value" =~ :([0-9]+)$ ]]; then
                app_port="${BASH_REMATCH[1]}"
            fi
            TARGET_INTERNAL_URL="http://host.container.internal:${app_port}"
        elif [[ -n "$APP_DOMAIN" && "$APP_DOMAIN" != "localhost" ]] && ! valid_ipv4 "$APP_DOMAIN"; then
            app_scheme="https"
            [[ $NO_SSL -eq 1 ]] && app_scheme="http"
            TARGET_INTERNAL_URL="${app_scheme}://${APP_DOMAIN}"
        elif valid_http_url "$app_url_value"; then
            TARGET_INTERNAL_URL="$app_url_value"
        else
            TARGET_INTERNAL_URL="http://127.0.0.1:8000"
        fi
    fi

    [[ -n "$INSTANCE_ID" ]] || {
        if [[ "$PLATFORM" == "macos" ]]; then
            INSTANCE_ID="jin-local"
        else
            INSTANCE_ID="jin-production"
        fi
    }

    [[ "$INSTANCE_ID" =~ ^[A-Za-z0-9._-]{1,64}$ ]] \
        || die "Invalid ONLYOFFICE_INSTANCE_ID: $INSTANCE_ID"

    MAX_FILE_SIZE_BYTES="${MAX_FILE_SIZE_BYTES:-209715200}"
    TARGET_MAX_DOWNLOAD_BYTES="${TARGET_MAX_DOWNLOAD_BYTES:-$MAX_FILE_SIZE_BYTES}"
    valid_positive_integer "$MAX_FILE_SIZE_BYTES" \
        || die "ONLYOFFICE_MAX_FILE_SIZE_BYTES must be a positive integer"
    valid_positive_integer "$TARGET_MAX_DOWNLOAD_BYTES" \
        || die "ONLYOFFICE_DOCUMENT_SERVER_MAX_DOWNLOAD_BYTES must be a positive integer"
    (( 10#$TARGET_MAX_DOWNLOAD_BYTES >= 10#$MAX_FILE_SIZE_BYTES )) \
        || die "DocumentServer maxDownloadBytes cannot be smaller than the application file limit"

    valid_http_url "$TARGET_DOCUMENT_SERVER_URL" \
        || die "Invalid ONLYOFFICE_DOCUMENT_SERVER_URL: $TARGET_DOCUMENT_SERVER_URL"
    valid_http_url "$TARGET_PUBLIC_URL" \
        || die "Invalid ONLYOFFICE_PUBLIC_URL: $TARGET_PUBLIC_URL"
    valid_http_url "$TARGET_INTERNAL_URL" \
        || die "Invalid APP_ONLYOFFICE_INTERNAL_URL: $TARGET_INTERNAL_URL"

    TARGET_DOCUMENT_SERVER_URL="${TARGET_DOCUMENT_SERVER_URL%/}"
    TARGET_PUBLIC_URL="${TARGET_PUBLIC_URL%/}"
    TARGET_INTERNAL_URL="${TARGET_INTERNAL_URL%/}"

    if [[ "$CONFIGURE_NGINX" == "auto" ]]; then
        if [[ "$PLATFORM" == "ubuntu" && -n "$OFFICE_DOMAIN" && "$OFFICE_DOMAIN" != "localhost" ]] \
            && ! valid_ipv4 "$OFFICE_DOMAIN"; then
            CONFIGURE_NGINX=1
        else
            CONFIGURE_NGINX=0
        fi
    fi

    if [[ "$CONFIGURE_NGINX" -eq 1 ]]; then
        office_host="$(url_host "$TARGET_PUBLIC_URL")"
        [[ "$PLATFORM" == "ubuntu" ]] || die "Nginx configuration is supported only on Ubuntu"
        valid_hostname "$OFFICE_DOMAIN" || die "Nginx setup requires a public OnlyOffice domain"
        [[ "$office_host" == "$OFFICE_DOMAIN" ]] \
            || die "ONLYOFFICE_PUBLIC_URL must use the Nginx domain $OFFICE_DOMAIN"
        if [[ $NO_SSL -eq 0 && "$TARGET_PUBLIC_URL" != https://* ]]; then
            die "ONLYOFFICE_PUBLIC_URL must use HTTPS when Let's Encrypt is enabled"
        fi
    fi
}

configure_app_env() {
    local allow_download allow_print allow_copy

    allow_download="$(read_env ONLYOFFICE_ALLOW_DOWNLOAD)"
    allow_print="$(read_env ONLYOFFICE_ALLOW_PRINT)"
    allow_copy="$(read_env ONLYOFFICE_ALLOW_COPY)"
    allow_download="${allow_download:-false}"
    allow_print="${allow_print:-false}"
    allow_copy="${allow_copy:-true}"

    set_env ONLYOFFICE_ENABLED true
    set_env ONLYOFFICE_DOCUMENT_SERVER_URL "$TARGET_DOCUMENT_SERVER_URL"
    set_env ONLYOFFICE_PUBLIC_URL "$TARGET_PUBLIC_URL"
    set_env APP_ONLYOFFICE_INTERNAL_URL "$TARGET_INTERNAL_URL"
    set_env ONLYOFFICE_JWT_SECRET "$(dotenv_quote "$JWT_SECRET")"
    set_env ONLYOFFICE_INSTANCE_ID "$INSTANCE_ID"
    set_env ONLYOFFICE_MAX_FILE_SIZE_BYTES "$MAX_FILE_SIZE_BYTES"
    set_env ONLYOFFICE_DOCUMENT_SERVER_MAX_DOWNLOAD_BYTES "$DOCUMENT_SERVER_MAX_DOWNLOAD_BYTES"
    set_env ONLYOFFICE_ALLOW_DOWNLOAD "$allow_download"
    set_env ONLYOFFICE_ALLOW_PRINT "$allow_print"
    set_env ONLYOFFICE_ALLOW_COPY "$allow_copy"

    if [[ -f "$APP_DIR/artisan" ]] && command -v php >/dev/null 2>&1; then
        log "Clearing Laravel configuration cache..."
        (cd "$APP_DIR" && php artisan config:clear >/dev/null) \
            || die "Could not clear the Laravel configuration cache"
    else
        warn "Laravel artisan or PHP is unavailable; run 'php artisan config:clear' after setup"
    fi
}

configure_macos_local_json() {
    local configured

    configured="$(container exec -i "$ONLYOFFICE_CONTAINER_NAME" node <<NODE
const fs = require('fs');

const path = '/etc/onlyoffice/documentserver/local.json';
const requested = ${TARGET_MAX_DOWNLOAD_BYTES};
const config = JSON.parse(fs.readFileSync(path, 'utf8'));
config.FileConverter = config.FileConverter || {};
config.FileConverter.converter = config.FileConverter.converter || {};

const current = Number(config.FileConverter.converter.maxDownloadBytes) || 0;
const actual = Math.max(current, requested);
config.FileConverter.converter.maxDownloadBytes = actual;
fs.writeFileSync(path, JSON.stringify(config, null, 2) + '\\n');
process.stdout.write(String(actual));
NODE
)"

    valid_positive_integer "$configured" \
        || die "Could not read OnlyOffice maxDownloadBytes from the container"
    DOCUMENT_SERVER_MAX_DOWNLOAD_BYTES="$configured"
}

has_macos_managed_label() {
    grep -Eq '"com\.jin\.setup"[[:space:]]*:[[:space:]]*"onlyoffice"'
}

is_existing_onlyoffice_container() {
    container inspect "$ONLYOFFICE_CONTAINER_NAME" 2>/dev/null \
        | grep -Eq '"reference"[[:space:]]*:[[:space:]]*".*onlyoffice\\?/documentserver:'
}

ensure_macos_volume() {
    local volume_name="$1"

    if container volume inspect "$volume_name" >/dev/null 2>&1; then
        if ! container volume inspect "$volume_name" | has_macos_managed_label; then
            is_existing_onlyoffice_container \
                && container inspect "$ONLYOFFICE_CONTAINER_NAME" \
                    | grep -Fq "\"name\" : \"${volume_name}\"" \
                || die "Refusing to use unmanaged Apple Container volume: $volume_name"
            warn "Reusing existing unlabelled volume: $volume_name"
        fi
        return 0
    fi

    container volume create --label "com.jin.setup=onlyoffice" "$volume_name" >/dev/null \
        || die "Could not create Apple Container volume: $volume_name"
}

configure_macos_host_dns() {
    local internal_host container_cli

    internal_host="$(url_host "$TARGET_INTERNAL_URL")"
    [[ "$internal_host" == "host.container.internal" ]] || return 0

    container_cli="$(command -v container)"
    command -v sudo >/dev/null 2>&1 || die "sudo is required to configure Apple Container host DNS"
    sudo -v || die "sudo authentication failed"

    log "Configuring host.container.internal for Apple Container..."
    sudo "$container_cli" system dns delete host.container.internal >/dev/null 2>&1 || true
    sudo "$container_cli" system dns create host.container.internal \
        --localhost "$MACOS_HOST_REDIRECT_IP" >/dev/null \
        || die "Could not configure host.container.internal"
}

setup_macos() {
    local macos_major app_port
    local -a container_run_args

    [[ "$(uname -m)" == "arm64" ]] || die "Apple Container requires an Apple silicon Mac"
    command -v container >/dev/null 2>&1 || die "Apple Container is required; install it from https://github.com/apple/container"
    command -v curl >/dev/null 2>&1 || die "curl is required"
    command -v openssl >/dev/null 2>&1 || die "openssl is required"

    macos_major="$(sw_vers -productVersion | cut -d. -f1)"
    [[ "$macos_major" =~ ^[0-9]+$ && "$macos_major" -ge 26 ]] \
        || die "Apple Container requires macOS 26 or newer"

    [[ -n "$JWT_SECRET" ]] || JWT_SECRET="$(openssl rand -hex 32)"
    validate_jwt_secret

    log "Starting Apple Container system service..."
    container system start
    configure_macos_host_dns

    log "Creating OnlyOffice named volumes..."
    ensure_macos_volume "$ONLYOFFICE_DATA_VOLUME"
    ensure_macos_volume "$ONLYOFFICE_LOGS_VOLUME"
    ensure_macos_volume "$ONLYOFFICE_LIB_VOLUME"
    ensure_macos_volume "$ONLYOFFICE_DB_VOLUME"

    log "Pulling $ONLYOFFICE_IMAGE..."
    container image pull "$ONLYOFFICE_IMAGE" >/dev/null \
        || die "Could not pull $ONLYOFFICE_IMAGE"

    if container inspect "$ONLYOFFICE_CONTAINER_NAME" >/dev/null 2>&1; then
        if ! container inspect "$ONLYOFFICE_CONTAINER_NAME" | has_macos_managed_label; then
            is_existing_onlyoffice_container \
                || die "Refusing to replace unmanaged Apple Container: $ONLYOFFICE_CONTAINER_NAME"
            warn "Adopting existing OnlyOffice container created without a Jin label"
        fi
        warn "Recreating $ONLYOFFICE_CONTAINER_NAME; named volumes will be preserved"
        container stop --time 60 "$ONLYOFFICE_CONTAINER_NAME" >/dev/null 2>&1 || true
        container delete "$ONLYOFFICE_CONTAINER_NAME" >/dev/null \
            || die "Could not remove $ONLYOFFICE_CONTAINER_NAME"
    fi

    container_run_args=(
        --detach
        --name "$ONLYOFFICE_CONTAINER_NAME"
        --label "com.jin.setup=onlyoffice"
        --arch arm64
        --cpus "$CONTAINER_CPUS"
        --memory "$CONTAINER_MEMORY"
        --shm-size "$CONTAINER_SHM_SIZE"
        --publish "127.0.0.1:${ONLYOFFICE_PORT}:80"
        --volume "${ONLYOFFICE_DATA_VOLUME}:/var/www/onlyoffice/Data"
        --volume "${ONLYOFFICE_LOGS_VOLUME}:/var/log/onlyoffice"
        --volume "${ONLYOFFICE_LIB_VOLUME}:/var/lib/onlyoffice"
        --volume "${ONLYOFFICE_DB_VOLUME}:/var/lib/postgresql"
        --env "JWT_ENABLED=true"
        --env JWT_SECRET
        --env "JWT_HEADER=AuthorizationJwt"
        --env "JWT_IN_BODY=true"
        --env "ALLOW_PRIVATE_IP_ADDRESS=true"
        --env "ALLOW_META_IP_ADDRESS=false"
        --env "PLUGINS_ENABLED=false"
        --entrypoint /bin/bash
    )

    log "Starting OnlyOffice DocumentServer on 127.0.0.1:${ONLYOFFICE_PORT}..."
    JWT_SECRET="$JWT_SECRET" container run \
        "${container_run_args[@]}" \
        "$ONLYOFFICE_IMAGE" \
        -lc 'exec /bin/bash /app/ds/run-document-server.sh' \
        >/dev/null \
        || die "Could not start OnlyOffice DocumentServer"

    if ! wait_for_health "http://127.0.0.1:${ONLYOFFICE_PORT}" 90 2; then
        container logs "$ONLYOFFICE_CONTAINER_NAME" || true
        die "OnlyOffice health check failed on 127.0.0.1:${ONLYOFFICE_PORT}"
    fi

    configure_macos_local_json
    container stop --time 60 "$ONLYOFFICE_CONTAINER_NAME" >/dev/null
    container start "$ONLYOFFICE_CONTAINER_NAME" >/dev/null

    wait_for_health "http://127.0.0.1:${ONLYOFFICE_PORT}" 90 2 \
        || die "OnlyOffice did not recover after configuration"

    configure_app_env

    if container exec "$ONLYOFFICE_CONTAINER_NAME" \
        curl -fsS --max-time 5 "${TARGET_INTERNAL_URL}/up" >/dev/null 2>&1; then
        log "OnlyOffice can reach the application at $TARGET_INTERNAL_URL"
    else
        warn "OnlyOffice cannot reach ${TARGET_INTERNAL_URL}/up; start the Laravel server and verify the local URL"
    fi

    app_port="${TARGET_INTERNAL_URL##*:}"
    [[ "$app_port" =~ ^[0-9]+$ ]] || app_port="8000"
    log "OnlyOffice is ready at http://127.0.0.1:${ONLYOFFICE_PORT}"
    log "Start Laravel with 'php artisan serve --host=127.0.0.1 --port=${app_port}' when needed"
}

configure_ubuntu_jwt() {
    local config_file="${1:-/etc/onlyoffice/documentserver/local.json}"

    sudo env JIN_ONLYOFFICE_JWT_SECRET="$JWT_SECRET" python3 - "$config_file" <<'PY'
import json
import os
import sys
from pathlib import Path

path = Path(sys.argv[1])
config = json.loads(path.read_text())
services = config.setdefault('services', {})
coauthoring = services.setdefault('CoAuthoring', {})
secret = coauthoring.setdefault('secret', {})
value = os.environ['JIN_ONLYOFFICE_JWT_SECRET']

for name in ('inbox', 'outbox', 'session'):
    secret.setdefault(name, {})['string'] = value

token = coauthoring.setdefault('token', {})
enable = token.setdefault('enable', {})
enable['browser'] = True
request = enable.setdefault('request', {})
request['inbox'] = True
request['outbox'] = True

for name in ('inbox', 'outbox'):
    token.setdefault(name, {})['header'] = 'AuthorizationJwt'

path.write_text(json.dumps(config, indent=2, ensure_ascii=False) + '\n')
PY
}

configure_ubuntu_max_download_bytes() {
    local config_file="/etc/onlyoffice/documentserver/local.json" configured

    configured="$(sudo env JIN_ONLYOFFICE_MAX_DOWNLOAD_BYTES="$TARGET_MAX_DOWNLOAD_BYTES" \
        python3 - "$config_file" <<'PY'
import json
import os
import sys
from pathlib import Path

path = Path(sys.argv[1])
config = json.loads(path.read_text())
converter = config.setdefault('FileConverter', {}).setdefault('converter', {})
requested = int(os.environ['JIN_ONLYOFFICE_MAX_DOWNLOAD_BYTES'])

try:
    current = int(converter.get('maxDownloadBytes', 0))
except (TypeError, ValueError):
    current = 0

configured = max(current, requested)
converter['maxDownloadBytes'] = configured
path.write_text(json.dumps(config, indent=2, ensure_ascii=False) + '\n')
print(configured)
PY
)"

    valid_positive_integer "$configured" \
        || die "Could not configure OnlyOffice maxDownloadBytes"
    DOCUMENT_SERVER_MAX_DOWNLOAD_BYTES="$configured"
}

restart_ubuntu_onlyoffice() {
    local service

    for service in ds-converter ds-docservice ds-metrics; do
        if sudo systemctl cat "$service" >/dev/null 2>&1; then
            sudo systemctl restart "$service"
        fi
    done

    for service in ds-converter ds-docservice; do
        sudo systemctl is-active --quiet "$service" \
            || die "ONLYOFFICE service is not active: $service"
    done
}

configure_nginx() {
    local site_name="jin-onlyoffice"
    local available="/etc/nginx/sites-available/${site_name}"
    local enabled="/etc/nginx/sites-enabled/${site_name}"
    local preserve_tls=0
    local -a certbot_args

    sudo install -d -m 0755 /etc/nginx/sites-available /etc/nginx/sites-enabled

    if sudo test -e "$available" && ! sudo grep -q 'Managed by jin/setup.sh' "$available"; then
        die "Refusing to overwrite unmanaged nginx configuration: $available"
    fi

    if [[ $NO_SSL -eq 0 ]] && sudo test -e "$available" \
        && sudo grep -Eq 'listen[[:space:]]+443([[:space:]]+ssl)?' "$available" \
        && sudo grep -Fq "server_name ${OFFICE_DOMAIN};" "$available"; then
        preserve_tls=1
        sudo sed -E -i.bak \
            "s|proxy_pass http://127\\.0\\.0\\.1:[0-9]+;|proxy_pass http://127.0.0.1:${ONLYOFFICE_PORT};|g" \
            "$available"
        sudo rm -f "${available}.bak"
    fi

    if [[ $preserve_tls -eq 0 ]]; then
        sudo tee "$available" >/dev/null <<NGINX
# Managed by jin/setup.sh. Re-running setup updates this file.
server {
    listen 80;
    listen [::]:80;
    server_name ${OFFICE_DOMAIN};
    client_max_body_size 512m;

    location / {
        proxy_pass http://127.0.0.1:${ONLYOFFICE_PORT};
        proxy_http_version 1.1;
        proxy_set_header Host \$http_host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        proxy_buffering off;
    }
}
NGINX
    fi

    sudo ln -sfn "$available" "$enabled"
    sudo nginx -t
    sudo systemctl enable --now nginx
    sudo systemctl reload nginx

    if [[ $NO_SSL -eq 1 ]]; then
        warn "Skipping Let's Encrypt; OnlyOffice remains HTTP-only"
        return 0
    fi

    certbot_args=(
        --nginx
        -d "$OFFICE_DOMAIN"
        --redirect
        --non-interactive
        --agree-tos
        --keep-until-expiring
    )
    if [[ -n "$EMAIL" ]]; then
        certbot_args+=(--email "$EMAIL")
    else
        certbot_args+=(--register-unsafely-without-email)
    fi

    log "Requesting Let's Encrypt certificate for $OFFICE_DOMAIN..."
    sudo certbot "${certbot_args[@]}" \
        || die "Certbot failed; verify DNS and TCP port 80 for $OFFICE_DOMAIN"
    sudo systemctl reload nginx
}

setup_ubuntu() {
    local architecture version_id version_codename processor_count memory_mb swap_mb available_disk_mb
    local onlyoffice_installed=0 local_json

    [[ "$(id -u)" -ne 0 ]] || die "Run as a regular sudo-capable user, not root"
    command -v sudo >/dev/null 2>&1 || die "sudo is required"
    sudo -v || die "sudo authentication failed"

    [[ -f /etc/os-release ]] || die "Could not identify the operating system"
    . /etc/os-release
    version_id="${VERSION_ID:-}"
    version_codename="${VERSION_CODENAME:-}"
    [[ "${ID:-}" == "ubuntu" ]] || die "This Linux setup supports Ubuntu only"
    case "$version_id" in
        24.04|26.04) ;;
        *) die "Unsupported Ubuntu version: $version_id (supported: 24.04, 26.04)" ;;
    esac

    command -v dpkg >/dev/null 2>&1 || die "dpkg is required"
    architecture="$(dpkg --print-architecture)"
    [[ "$architecture" == "amd64" ]] \
        || die "ONLYOFFICE DocumentServer APT setup requires amd64; detected $architecture"

    processor_count="$(getconf _NPROCESSORS_ONLN)"
    memory_mb="$(awk '/MemTotal:/ { print int($2 / 1024); exit }' /proc/meminfo)"
    swap_mb="$(awk '/SwapTotal:/ { print int($2 / 1024); exit }' /proc/meminfo)"
    available_disk_mb="$(df -Pm /var | awk 'NR == 2 { print $4 }')"
    [[ "$processor_count" =~ ^[0-9]+$ && "$processor_count" -ge 2 ]] \
        || die "At least 2 CPU cores are required for ONLYOFFICE"
    [[ "$memory_mb" =~ ^[0-9]+$ && "$memory_mb" -ge 4096 ]] \
        || die "At least 4 GB RAM is required for ONLYOFFICE"
    [[ "$available_disk_mb" =~ ^[0-9]+$ && "$available_disk_mb" -ge 40960 ]] \
        || die "At least 40 GB free space under /var is required"
    [[ "$swap_mb" =~ ^[0-9]+$ && "$swap_mb" -ge 4096 ]] \
        || warn "ONLYOFFICE recommends at least 4 GB swap; detected ${swap_mb:-0} MB"
    log "Ubuntu $version_id ($version_codename) on $architecture"
    log "Installing OnlyOffice prerequisites..."
    sudo apt-get update -y
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
        ca-certificates curl gnupg jq openssl python3

    if [[ "$CONFIGURE_NGINX" -eq 1 ]]; then
        sudo DEBIAN_FRONTEND=noninteractive apt-get install -y nginx
        if [[ $NO_SSL -eq 0 ]]; then
            sudo DEBIAN_FRONTEND=noninteractive apt-get install -y certbot python3-certbot-nginx
        fi
    fi

    log "Configuring the official OnlyOffice APT repository..."
    curl -fsSL https://download.onlyoffice.com/GPG-KEY-ONLYOFFICE | \
        sudo gpg --batch --yes --dearmor -o /usr/share/keyrings/onlyoffice.gpg
    printf 'deb [signed-by=/usr/share/keyrings/onlyoffice.gpg] https://download.onlyoffice.com/repo/debian squeeze main\n' | \
        sudo tee /etc/apt/sources.list.d/onlyoffice.list >/dev/null
    sudo apt-get update -y

    if onlyoffice_package_installed; then
        onlyoffice_installed=1
        log "ONLYOFFICE DocumentServer is already installed"
        if [[ -z "$JWT_SECRET" ]]; then
            JWT_SECRET="$(read_package_jwt_secret)"
        fi
        wait_for_health "http://127.0.0.1:${ONLYOFFICE_PORT}" 3 1 \
            || die "Existing ONLYOFFICE is not healthy on port $ONLYOFFICE_PORT; pass its configured port"
    fi

    [[ -n "$JWT_SECRET" ]] || JWT_SECRET="$(openssl rand -hex 32)"
    validate_jwt_secret

    if [[ $onlyoffice_installed -eq 0 ]]; then
        log "Installing ONLYOFFICE DocumentServer on port $ONLYOFFICE_PORT with JWT enabled..."
        printf 'onlyoffice-documentserver onlyoffice/ds-port select %s\n' \
            "$ONLYOFFICE_PORT" | sudo debconf-set-selections
        printf '%s\n' \
            'onlyoffice-documentserver onlyoffice/jwt-enabled boolean true' \
            | sudo debconf-set-selections
        printf 'onlyoffice-documentserver onlyoffice/jwt-secret password %s\n' \
            "$JWT_SECRET" | sudo debconf-set-selections
        printf 'ttf-mscorefonts-installer msttcorefonts/accepted-mscorefonts-eula select true\n' \
            | sudo debconf-set-selections

        if apt-cache show ttf-mscorefonts-installer >/dev/null 2>&1; then
            sudo DEBIAN_FRONTEND=noninteractive apt-get install -y ttf-mscorefonts-installer \
                || warn "Microsoft core fonts could not be installed; continuing with open fonts"
        fi
        sudo DEBIAN_FRONTEND=noninteractive apt-get install -y onlyoffice-documentserver
    fi

    local_json="/etc/onlyoffice/documentserver/local.json"
    [[ -f "$local_json" ]] || die "Could not find $local_json after OnlyOffice installation"

    if [[ "$CONFIGURE_NGINX" -eq 1 ]]; then
        configure_nginx
    fi

    log "Applying JWT and ${TARGET_MAX_DOWNLOAD_BYTES}-byte download limits..."
    configure_ubuntu_jwt
    configure_ubuntu_max_download_bytes
    restart_ubuntu_onlyoffice

    if ! wait_for_health "http://127.0.0.1:${ONLYOFFICE_PORT}" 90 2; then
        sudo journalctl -u ds-converter -u ds-docservice --no-pager -n 80 || true
        die "OnlyOffice health check failed on 127.0.0.1:${ONLYOFFICE_PORT}"
    fi

    configure_app_env
    getent group www-data >/dev/null \
        || die "The www-data group is required to secure $ENV_FILE"
    sudo chown "$(id -un):www-data" "$ENV_FILE"
    sudo chmod 0640 "$ENV_FILE"

    if curl -fsS --max-time 5 "${TARGET_INTERNAL_URL}/up" >/dev/null 2>&1; then
        log "OnlyOffice host can reach the application at $TARGET_INTERNAL_URL"
    else
        warn "The application healthcheck at ${TARGET_INTERNAL_URL}/up did not respond; verify Nginx and the application"
    fi

    warn "Keep TCP port ${ONLYOFFICE_PORT} blocked from external networks; expose OnlyOffice through Nginx only"
    log "OnlyOffice is ready on host port ${ONLYOFFICE_PORT}"
    log "Browser URL: $TARGET_PUBLIC_URL"
}

usage() {
    cat <<'EOF'
Usage: ./setup.sh [options]

Provision ONLYOFFICE DocumentServer and update the Jin .env file.

Options:
  --app-dir <path>             Jin application directory (default: script directory)
  --env-file <path>            Environment file (default: <app-dir>/.env)
  --domain <domain>            Jin application domain (alias: --app-domain)
  --office-domain <domain>     Public OnlyOffice domain
  --email <address>            Let's Encrypt registration / expiry mail
  --onlyoffice-port <port>     DocumentServer host port (default: 8081)
  --onlyoffice-image <image>   macOS container image (default: onlyoffice/documentserver:9.4.0.1)
  --container-name <name>      macOS container name (default: jin-onlyoffice)
  --jwt-secret <secret>        JWT secret; prefer ONLYOFFICE_JWT_SECRET to avoid shell history
  --instance-id <id>           ONLYOFFICE instance id (default: jin-production/local)
  --app-internal-url <url>     DocumentServer-to-Jin URL
  --public-url <url>           Browser-to-DocumentServer URL
  --document-server-url <url>  Jin-to-DocumentServer URL
  --max-file-size-bytes <n>    Jin file limit (default: 209715200)
  --max-download-bytes <n>     DocumentServer limit (must cover file limit)
  --nginx                      Configure an OnlyOffice Nginx virtual host
  --no-nginx                   Do not configure Nginx
  --no-ssl                     Skip Let's Encrypt and use HTTP for derived URLs
  --platform <auto|ubuntu|macos>
                               Override platform detection
  -h, --help                   Show this help

The same settings can be supplied through uppercase environment variables,
including APP_DIR, OFFICE_DOMAIN, ONLYOFFICE_PORT, ONLYOFFICE_IMAGE,
ONLYOFFICE_JWT_SECRET, APP_ONLYOFFICE_INTERNAL_URL, and ONLYOFFICE_PUBLIC_URL.

Ubuntu setup requires Ubuntu 24.04 or 26.04, amd64, sudo, 2 CPU cores,
4 GB RAM, and 40 GB free space under /var. macOS setup requires Apple silicon,
macOS 26 or newer, and Apple's container runtime.
EOF
}

main() {
# -------------------------------------------------------------- defaults ----
APP_DIR="${APP_DIR:-$SCRIPT_DIR}"
ENV_FILE="${ENV_FILE:-}"
APP_DOMAIN="${APP_DOMAIN:-}"
OFFICE_DOMAIN="${OFFICE_DOMAIN:-}"
EMAIL="${EMAIL:-}"
TARGET_DOCUMENT_SERVER_URL="${ONLYOFFICE_DOCUMENT_SERVER_URL:-}"
TARGET_PUBLIC_URL="${ONLYOFFICE_PUBLIC_URL:-}"
TARGET_INTERNAL_URL="${APP_ONLYOFFICE_INTERNAL_URL:-}"
JWT_SECRET="${ONLYOFFICE_JWT_SECRET:-}"
INSTANCE_ID="${ONLYOFFICE_INSTANCE_ID:-}"
MAX_FILE_SIZE_BYTES="${ONLYOFFICE_MAX_FILE_SIZE_BYTES:-}"
TARGET_MAX_DOWNLOAD_BYTES="${ONLYOFFICE_DOCUMENT_SERVER_MAX_DOWNLOAD_BYTES:-}"
ONLYOFFICE_PORT_FROM_ENV="${ONLYOFFICE_PORT:-${ONLYOFFICE_HOST_PORT:-}}"
ONLYOFFICE_PORT="${ONLYOFFICE_PORT_FROM_ENV:-8081}"
ONLYOFFICE_IMAGE="${ONLYOFFICE_IMAGE:-onlyoffice/documentserver:9.4.0.1}"
ONLYOFFICE_CONTAINER_NAME="${ONLYOFFICE_CONTAINER_NAME:-jin-onlyoffice}"
ONLYOFFICE_DATA_VOLUME="${ONLYOFFICE_DATA_VOLUME:-jin-onlyoffice-data}"
ONLYOFFICE_LOGS_VOLUME="${ONLYOFFICE_LOGS_VOLUME:-jin-onlyoffice-logs}"
ONLYOFFICE_LIB_VOLUME="${ONLYOFFICE_LIB_VOLUME:-jin-onlyoffice-lib}"
ONLYOFFICE_DB_VOLUME="${ONLYOFFICE_DB_VOLUME:-jin-onlyoffice-db}"
CONTAINER_CPUS="${ONLYOFFICE_CONTAINER_CPUS:-2}"
CONTAINER_MEMORY="${ONLYOFFICE_CONTAINER_MEMORY:-4G}"
CONTAINER_SHM_SIZE="${ONLYOFFICE_CONTAINER_SHM_SIZE:-2G}"
MACOS_HOST_REDIRECT_IP="${ONLYOFFICE_HOST_REDIRECT_IP:-203.0.113.113}"
PLATFORM="${ONLYOFFICE_PLATFORM:-auto}"
CONFIGURE_NGINX="auto"
NO_SSL=0
PORT_EXPLICIT=0
DOCUMENT_SERVER_URL_EXPLICIT=0
PUBLIC_URL_EXPLICIT=0

if [[ -n "$ONLYOFFICE_PORT_FROM_ENV" ]]; then
    PORT_EXPLICIT=1
fi
if [[ -n "${ONLYOFFICE_DOCUMENT_SERVER_URL:-}" ]]; then
    DOCUMENT_SERVER_URL_EXPLICIT=1
fi
if [[ -n "${ONLYOFFICE_PUBLIC_URL:-}" ]]; then
    PUBLIC_URL_EXPLICIT=1
fi
if [[ "${ONLYOFFICE_CONFIGURE_NGINX:-}" == "true" || "${ONLYOFFICE_CONFIGURE_NGINX:-}" == "1" ]]; then
    CONFIGURE_NGINX=1
elif [[ "${ONLYOFFICE_CONFIGURE_NGINX:-}" == "false" || "${ONLYOFFICE_CONFIGURE_NGINX:-}" == "0" ]]; then
    CONFIGURE_NGINX=0
fi
if [[ "${ONLYOFFICE_NO_SSL:-}" == "true" || "${ONLYOFFICE_NO_SSL:-}" == "1" ]]; then
    NO_SSL=1
fi

while [[ $# -gt 0 ]]; do
    case "$1" in
        --app-dir|--env-file|--domain|--app-domain|--office-domain|--email|--onlyoffice-port|--onlyoffice-image|--container-name|--jwt-secret|--instance-id|--app-internal-url|--public-url|--document-server-url|--max-file-size-bytes|--max-download-bytes|--platform)
            [[ $# -ge 2 ]] || die "$1 requires a value"
            case "$1" in
                --app-dir) APP_DIR="$2" ;;
                --env-file) ENV_FILE="$2" ;;
                --domain|--app-domain) APP_DOMAIN="$2" ;;
                --office-domain) OFFICE_DOMAIN="$2" ;;
                --email) EMAIL="$2" ;;
                --onlyoffice-port) ONLYOFFICE_PORT="$2"; PORT_EXPLICIT=1 ;;
                --onlyoffice-image) ONLYOFFICE_IMAGE="$2" ;;
                --container-name) ONLYOFFICE_CONTAINER_NAME="$2" ;;
                --jwt-secret) JWT_SECRET="$2" ;;
                --instance-id) INSTANCE_ID="$2" ;;
                --app-internal-url) TARGET_INTERNAL_URL="$2" ;;
                --public-url) TARGET_PUBLIC_URL="$2"; PUBLIC_URL_EXPLICIT=1 ;;
                --document-server-url) TARGET_DOCUMENT_SERVER_URL="$2"; DOCUMENT_SERVER_URL_EXPLICIT=1 ;;
                --max-file-size-bytes) MAX_FILE_SIZE_BYTES="$2" ;;
                --max-download-bytes) TARGET_MAX_DOWNLOAD_BYTES="$2" ;;
                --platform) PLATFORM="$2" ;;
            esac
            shift 2
            ;;
        --nginx) CONFIGURE_NGINX=1; shift ;;
        --no-nginx) CONFIGURE_NGINX=0; shift ;;
        --no-ssl) NO_SSL=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "Unknown option: $1 (see --help)" ;;
    esac
done

case "$PLATFORM" in
    auto)
        case "$(uname -s)" in
            Darwin) PLATFORM="macos" ;;
            Linux) PLATFORM="ubuntu" ;;
            *) die "Unsupported operating system: $(uname -s)" ;;
        esac
        ;;
    ubuntu|macos) ;;
    *) die "Invalid platform: $PLATFORM (use auto, ubuntu, or macos)" ;;
esac

[[ -n "$EMAIL" ]] || EMAIL=""
[[ -z "$EMAIL" || "$EMAIL" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]] \
    || die "Invalid email: $EMAIL"

resolve_app_values

case "$PLATFORM" in
    macos) setup_macos ;;
    ubuntu) setup_ubuntu ;;
esac

log "Provisioning complete."
log "Environment: $ENV_FILE"
log "Instance:    $INSTANCE_ID"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    main "$@"
fi
