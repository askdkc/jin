#!/usr/bin/env bash
#
# jin - one-shot macOS development and Ubuntu Server deployment
#
# Ubuntu installs the Jin runtime, PostgreSQL/PGroonga, Redis, MarkItDown,
# ONLYOFFICE, Nginx, and Supervisor. Apple silicon macOS keeps its existing
# Apple Container flow. Both paths are safe to rerun: existing .env files,
# secrets, private storage, database data, and named volumes are preserved.
#
# Examples:
#   ./setup.sh
#   ./setup.sh --platform ubuntu --domain jin.example.com --no-ssl
#   ./setup.sh --platform macos --app-dir "$PWD" --no-nginx
#
set -euo pipefail

# Keep sudo call sites usable for direct root execution, while retaining the
# regular sudo path for deployment users with delegated privileges.
if [[ "$(id -u)" -eq 0 ]]; then
    sudo() {
        local target

        if [[ "${1:-}" == "-v" ]]; then
            return 0
        fi

        if [[ "${1:-}" == "-u" ]]; then
            [[ $# -ge 3 ]] || return 2
            target="$2"
            shift 2
            [[ "${1:-}" == "--" ]] && shift
            command runuser -u "$target" -- "$@"
            return
        fi

        if [[ "${1:-}" == "-E" ]]; then
            shift
        fi
        if [[ "${1:-}" == *=* ]]; then
            command env "$@"
        else
            command "$@"
        fi
    }
fi

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
MAX_SAFE_INTEGER=9007199254740991
MAX_SAFE_FILE_SIZE_MB=8589934591
SUDO_KEEPALIVE_PID=""
SUDO_NOPASSWD="${SUDO_NOPASSWD:-0}"
APP_PROCESSES_STOPPED=0
APP_MAINTENANCE=0
JIN_NGINX_CHANGED=0
JIN_NGINX_BACKUP_DIR=""
JIN_NGINX_BACKUP_READY=0
JIN_NGINX_WAS_ACTIVE=0
JIN_NGINX_USE_SUDO=0
JIN_NGINX_SITE_PATH=/etc/nginx/sites-available/jin
JIN_NGINX_ENABLED_PATH=/etc/nginx/sites-enabled/jin
JIN_NGINX_DEFAULT_PATH=/etc/nginx/sites-enabled/default

# ---------------------------------------------------------------- helpers --
log()  { printf '\033[1;34m[jin]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[jin:warn]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[jin:error]\033[0m %s\n' "$*" >&2; exit 1; }

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
    local value

    [[ "$1" =~ ^[0-9]+$ ]] || return 1
    value="$(normalize_decimal "$1")"
    [[ "$value" != "0" ]] || return 1
    [[ ${#value} -lt 5 ]] && return 0
    [[ ${#value} -eq 5 ]] || return 1
    (( value <= 65535 ))
}

normalize_decimal() {
    local value="$1"

    while [[ "$value" == 0* && "$value" != "0" ]]; do
        value="${value#0}"
    done

    printf '%s' "$value"
}

valid_positive_integer() {
    local value

    [[ "$1" =~ ^[0-9]+$ ]] || return 1
    value="$(normalize_decimal "$1")"
    [[ "$value" != "0" ]] || return 1
    [[ ${#value} -lt ${#MAX_SAFE_INTEGER} ]] && return 0
    [[ ${#value} -eq ${#MAX_SAFE_INTEGER} ]] || return 1
    (( value <= MAX_SAFE_INTEGER ))
}

valid_upload_max_megabytes() {
    local value

    valid_positive_integer "$1" || return 1
    value="$(normalize_decimal "$1")"
    [[ ${#value} -lt ${#MAX_SAFE_FILE_SIZE_MB} ]] && return 0
    [[ ${#value} -eq ${#MAX_SAFE_FILE_SIZE_MB} ]] || return 1
    (( value <= MAX_SAFE_FILE_SIZE_MB ))
}

normalize_boolean() {
    case "$1" in
        true|1) printf 'true' ;;
        false|0) printf 'false' ;;
        *) return 1 ;;
    esac
}

is_placeholder_value() {
    case "$1" in
        ''|local|local-*|example|example-*|null|none|password|secret|changeme|change-me|replace-me|your-*|jin-local)
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

generate_production_identifier() {
    printf 'jin-%s' "$(openssl rand -hex 16)"
}

set_ubuntu_package_matrix() {
    case "${VERSION_ID:-}" in
        24.04)
            PG_MAJOR=16
            PGROONGA_PACKAGE="postgresql-16-pgroonga"
            ;;
        26.04)
            PG_MAJOR=18
            PGROONGA_PACKAGE="postgresql-18-pgdg-pgroonga"
            ;;
        *)
            return 1
            ;;
    esac
}

canonicalize_ubuntu_app_dir() {
    local canonical

    canonical="$(realpath -m -- "$APP_DIR" 2>/dev/null)" || return 1
    case "$canonical" in
        /var/www/*|/home/*/*) ;;
        *) return 1 ;;
    esac
    [[ "$canonical" != /var/www ]] || return 1
    APP_DIR="$canonical"
}

validate_ubuntu_repository_target() {
    [[ "$APP_DIR" != "$SCRIPT_DIR" || -d "$APP_DIR/.git" ]]
}

validate_ubuntu_env_file() {
    local canonical

    [[ -n "${ENV_FILE:-}" ]] || ENV_FILE="$APP_DIR/.env"
    canonical="$(realpath -m -- "$ENV_FILE" 2>/dev/null)" || return 1
    [[ "$canonical" == "$APP_DIR/.env" ]] || return 1
    ENV_FILE="$canonical"
}

resolve_upload_max_files() {
    local value="${UPLOAD_MAX_FILES:-$(read_env UPLOAD_MAX_FILES "$ENV_FILE")}"

    value="${value:-20}"
    valid_positive_integer "$value" || return 1
    UPLOAD_MAX_FILES="$(normalize_decimal "$value")"
}

resolve_ubuntu_jwt_secret() {
    local existing="${JWT_SECRET:-}"

    if [[ -z "$existing" ]]; then
        existing="$(read_env ONLYOFFICE_JWT_SECRET "$ENV_FILE")"
    fi
    is_placeholder_value "$existing" && existing=""
    JWT_SECRET="$existing"
    if [[ -z "$JWT_SECRET" && -f /etc/onlyoffice/documentserver/local.json ]]; then
        JWT_SECRET="$(read_package_jwt_secret)"
    fi
    JWT_SECRET="${JWT_SECRET:-$(openssl rand -hex 32)}"
}

resolve_ubuntu_instance_id() {
    local existing="${INSTANCE_ID:-}"

    if [[ -z "$existing" ]]; then
        existing="$(read_env ONLYOFFICE_INSTANCE_ID "$ENV_FILE")"
    fi
    is_placeholder_value "$existing" && existing=""
    INSTANCE_ID="${existing:-$(generate_production_identifier)}"
}

resolve_imagemagick_path() {
    local binary candidate

    for binary in magick convert; do
        candidate="$(command -v "$binary" 2>/dev/null || true)"
        if [[ "$candidate" == /* && -x "$candidate" ]]; then
            printf '%s' "$candidate"
            return 0
        fi
    done

    return 1
}

node_major_at_least_24() {
    local major

    command -v node >/dev/null 2>&1 || return 1
    major="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || true)"
    [[ "$major" =~ ^[0-9]+$ ]] && (( major >= 24 ))
}

valid_resource_name() {
    [[ "$1" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$ ]]
}

resource_fingerprint() {
    if command -v shasum >/dev/null 2>&1; then
        printf '%s\037%s' "$1" "$2" | shasum -a 256 | cut -c1-12
    elif command -v sha256sum >/dev/null 2>&1; then
        printf '%s\037%s' "$1" "$2" | sha256sum | cut -c1-12
    else
        printf '%s\037%s' "$1" "$2" | cksum | cut -d ' ' -f1
    fi
}

url_host() {
    local url="$1" authority host

    [[ "$url" =~ ^https?://[^[:space:]/?#]+/?$ ]] || return 1
    authority="${url#*://}"
    authority="${authority%/}"
    [[ "$authority" != *"@"* && -n "$authority" ]] || return 1

    if [[ "$authority" == *":"* ]]; then
        [[ "$authority" =~ ^([^:]+):[0-9]+$ ]] || return 1
        host="${BASH_REMATCH[1]}"
    else
        host="$authority"
    fi

    [[ -n "$host" && "$host" != *":"* ]] || return 1
    printf '%s' "$host"
}

valid_http_url() {
    local url="$1" authority host port

    [[ "$url" =~ ^https?://[^[:space:]/?#]+/?$ ]] || return 1
    authority="${url#*://}"
    authority="${authority%/}"
    [[ "$authority" != *"@"* && -n "$authority" ]] || return 1
    if [[ "$authority" == *":"* ]]; then
        [[ "$authority" =~ ^([^:]+):([0-9]+)$ ]] || return 1
        host="${BASH_REMATCH[1]}"
        port="${BASH_REMATCH[2]}"
        valid_port "$port" || return 1
    else
        host="$authority"
    fi
    [[ "$host" != *":"* && -n "$host" ]] || return 1
    valid_local_hostname "$host"
}

valid_email_address() {
    [[ "$1" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]
}

valid_mail_from_address() {
    valid_email_address "$1" || return 1
    case "$1" in
        *@example.com|*@example.net|*@example.org) return 1 ;;
    esac
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

setup_input_is_interactive() {
    [[ -t 0 ]]
}

smtp_value_is_configured() {
    [[ -n "$1" ]] && ! is_placeholder_value "$1" && [[ "$1" != null ]]
}

load_existing_smtp_settings() {
    TARGET_MAIL_HOST="$(read_env MAIL_HOST "$ENV_FILE")"
    TARGET_MAIL_PORT="$(read_env MAIL_PORT "$ENV_FILE")"
    TARGET_MAIL_SCHEME="$(read_env MAIL_SCHEME "$ENV_FILE")"
    TARGET_MAIL_USERNAME="$(read_env MAIL_USERNAME "$ENV_FILE")"
    TARGET_MAIL_PASSWORD="$(read_env MAIL_PASSWORD "$ENV_FILE")"
    TARGET_MAIL_FROM_ADDRESS="$(read_env MAIL_FROM_ADDRESS "$ENV_FILE")"
    TARGET_MAIL_FROM_NAME="$(read_env MAIL_FROM_NAME "$ENV_FILE")"
}

validate_smtp_settings() {
    smtp_value_is_configured "$TARGET_MAIL_HOST" \
        && [[ "$TARGET_MAIL_HOST" =~ ^[A-Za-z0-9][A-Za-z0-9._:-]*$ ]] \
        && [[ "$TARGET_MAIL_HOST" != 127.0.0.1 ]] \
        && valid_port "$TARGET_MAIL_PORT" \
        && [[ "$TARGET_MAIL_SCHEME" == smtp || "$TARGET_MAIL_SCHEME" == smtps ]] \
        && smtp_value_is_configured "$TARGET_MAIL_USERNAME" \
        && smtp_value_is_configured "$TARGET_MAIL_PASSWORD" \
        && [[ "$TARGET_MAIL_PASSWORD" != *$'\n'* && "$TARGET_MAIL_PASSWORD" != *$'\r'* ]] \
        && valid_mail_from_address "$TARGET_MAIL_FROM_ADDRESS" \
        && [[ -n "$TARGET_MAIL_FROM_NAME" ]] \
        && [[ "$TARGET_MAIL_FROM_NAME" != *$'\n'* && "$TARGET_MAIL_FROM_NAME" != *$'\r'* ]]
}

smtp_environment_is_configured() {
    [[ "$(read_env MAIL_MAILER "$ENV_FILE")" == smtp ]] || return 1
    load_existing_smtp_settings
    validate_smtp_settings
}

prompt_email_verification() {
    local default_enabled="$1" input prompt

    printf '\nEmail verification\n'
    printf '%s\n' 'Email verification requires a working external SMTP account.'
    if [[ "$default_enabled" == true ]]; then
        prompt='Enable email verification? [Y/n]: '
    else
        prompt='Enable email verification? [y/N]: '
    fi

    while true; do
        printf '%s' "$prompt"
        IFS= read -r input || input=""
        case "$input" in
            '')
                TARGET_EMAIL_VERIFICATION_ENABLED="$default_enabled"
                return 0
                ;;
            y|Y|yes|Yes|YES)
                TARGET_EMAIL_VERIFICATION_ENABLED=true
                return 0
                ;;
            n|N|no|No|NO)
                TARGET_EMAIL_VERIFICATION_ENABLED=false
                return 0
                ;;
            *)
                warn "Enter y or n"
                ;;
        esac
    done
}

prompt_smtp_settings() {
    local input default_port
    local existing_password=0

    load_existing_smtp_settings
    if ! smtp_value_is_configured "$TARGET_MAIL_HOST" || [[ "$TARGET_MAIL_HOST" == 127.0.0.1 ]]; then
        TARGET_MAIL_HOST=""
        TARGET_MAIL_PORT=""
    fi
    [[ "$TARGET_MAIL_SCHEME" == smtp || "$TARGET_MAIL_SCHEME" == smtps ]] \
        || TARGET_MAIL_SCHEME=smtp
    valid_port "$TARGET_MAIL_PORT" || TARGET_MAIL_PORT=""
    smtp_value_is_configured "$TARGET_MAIL_USERNAME" || TARGET_MAIL_USERNAME=""
    if smtp_value_is_configured "$TARGET_MAIL_PASSWORD"; then
        existing_password=1
    else
        TARGET_MAIL_PASSWORD=""
    fi
    valid_mail_from_address "$TARGET_MAIL_FROM_ADDRESS" || TARGET_MAIL_FROM_ADDRESS=""
    if [[ -z "$TARGET_MAIL_FROM_NAME" || "$TARGET_MAIL_FROM_NAME" == "\${APP_NAME}" ]]; then
        TARGET_MAIL_FROM_NAME=Jin
    fi

    printf '%s\n' 'Enter the SMTP settings. The password input is hidden and values are stored in .env.'
    while true; do
        if [[ -n "$TARGET_MAIL_HOST" ]]; then
            printf 'SMTP host [%s]: ' "$TARGET_MAIL_HOST"
        else
            printf 'SMTP host: '
        fi
        IFS= read -r input || die "Could not read the SMTP host"
        [[ -z "$input" ]] || TARGET_MAIL_HOST="$input"
        if smtp_value_is_configured "$TARGET_MAIL_HOST" \
            && [[ "$TARGET_MAIL_HOST" =~ ^[A-Za-z0-9][A-Za-z0-9._:-]*$ ]] \
            && [[ "$TARGET_MAIL_HOST" != 127.0.0.1 ]]; then
            break
        fi
        warn "Enter an external SMTP hostname or IP address"
    done

    while true; do
        printf 'SMTP scheme (smtp or smtps) [%s]: ' "$TARGET_MAIL_SCHEME"
        IFS= read -r input || die "Could not read the SMTP scheme"
        [[ -z "$input" ]] || TARGET_MAIL_SCHEME="$input"
        [[ "$TARGET_MAIL_SCHEME" == smtp || "$TARGET_MAIL_SCHEME" == smtps ]] && break
        warn "Enter smtp or smtps"
    done

    default_port=587
    [[ "$TARGET_MAIL_SCHEME" == smtps ]] && default_port=465
    TARGET_MAIL_PORT="${TARGET_MAIL_PORT:-$default_port}"
    while true; do
        printf 'SMTP port [%s]: ' "$TARGET_MAIL_PORT"
        IFS= read -r input || die "Could not read the SMTP port"
        [[ -z "$input" ]] || TARGET_MAIL_PORT="$input"
        valid_port "$TARGET_MAIL_PORT" && break
        warn "Enter an SMTP port from 1 to 65535"
    done

    while true; do
        if [[ -n "$TARGET_MAIL_USERNAME" ]]; then
            printf 'SMTP username [%s]: ' "$TARGET_MAIL_USERNAME"
        else
            printf 'SMTP username: '
        fi
        IFS= read -r input || die "Could not read the SMTP username"
        [[ -z "$input" ]] || TARGET_MAIL_USERNAME="$input"
        smtp_value_is_configured "$TARGET_MAIL_USERNAME" && break
        warn "SMTP username is required"
    done

    while true; do
        if [[ "$existing_password" -eq 1 ]]; then
            printf 'SMTP password [press Enter to keep the current value]: '
        else
            printf 'SMTP password: '
        fi
        IFS= read -r -s input || die "Could not read the SMTP password"
        printf '\n'
        if [[ -n "$input" ]]; then
            TARGET_MAIL_PASSWORD="$input"
            MAIL_PASSWORD_WRITE_REQUIRED=1
        fi
        smtp_value_is_configured "$TARGET_MAIL_PASSWORD" \
            && [[ "$TARGET_MAIL_PASSWORD" != *$'\n'* && "$TARGET_MAIL_PASSWORD" != *$'\r'* ]] \
            && break
        warn "SMTP password is required and cannot contain newlines"
    done

    while true; do
        if [[ -n "$TARGET_MAIL_FROM_ADDRESS" ]]; then
            printf 'From email address [%s]: ' "$TARGET_MAIL_FROM_ADDRESS"
        else
            printf 'From email address: '
        fi
        IFS= read -r input || die "Could not read the From email address"
        [[ -z "$input" ]] || TARGET_MAIL_FROM_ADDRESS="$input"
        valid_mail_from_address "$TARGET_MAIL_FROM_ADDRESS" && break
        warn "Enter a valid non-example From email address"
    done

    while true; do
        printf 'From name [%s]: ' "$TARGET_MAIL_FROM_NAME"
        IFS= read -r input || die "Could not read the From name"
        [[ -z "$input" ]] || TARGET_MAIL_FROM_NAME="$input"
        if [[ -n "$TARGET_MAIL_FROM_NAME" \
            && "$TARGET_MAIL_FROM_NAME" != *$'\n'* \
            && "$TARGET_MAIL_FROM_NAME" != *$'\r'* ]]; then
            break
        fi
        warn "From name is required and cannot contain newlines"
    done

    validate_smtp_settings || die "The SMTP settings are incomplete or invalid"
    SMTP_SETTINGS_WRITE_REQUIRED=1
}

smtp_override_is_requested() {
    [[ -n "${MAIL_HOST:-}${MAIL_PORT:-}${MAIL_SCHEME:-}${MAIL_USERNAME:-}${MAIL_PASSWORD:-}${MAIL_FROM_ADDRESS:-}${MAIL_FROM_NAME:-}" ]]
}

load_smtp_overrides() {
    [[ -n "${MAIL_HOST:-}" && -n "${MAIL_PORT:-}" && -n "${MAIL_USERNAME:-}" \
        && -n "${MAIL_PASSWORD:-}" && -n "${MAIL_FROM_ADDRESS:-}" ]] \
        || die "MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, and MAIL_FROM_ADDRESS are required when enabling email verification non-interactively"

    TARGET_MAIL_HOST="$MAIL_HOST"
    TARGET_MAIL_PORT="$MAIL_PORT"
    TARGET_MAIL_SCHEME="${MAIL_SCHEME:-smtp}"
    TARGET_MAIL_USERNAME="$MAIL_USERNAME"
    TARGET_MAIL_PASSWORD="$MAIL_PASSWORD"
    TARGET_MAIL_FROM_ADDRESS="$MAIL_FROM_ADDRESS"
    TARGET_MAIL_FROM_NAME="${MAIL_FROM_NAME:-Jin}"
    validate_smtp_settings || die "The SMTP environment overrides are incomplete or invalid"
    SMTP_SETTINGS_WRITE_REQUIRED=1
    MAIL_PASSWORD_WRITE_REQUIRED=1
}

resolve_email_verification() {
    local configured_value default_enabled=false selection_was_prompted=0

    TARGET_EMAIL_VERIFICATION_ENABLED=""
    SMTP_SETTINGS_WRITE_REQUIRED=0
    MAIL_PASSWORD_WRITE_REQUIRED=0
    configured_value="$(read_env EMAIL_VERIFICATION_ENABLED "$ENV_FILE")"

    if [[ -n "${EMAIL_VERIFICATION_OVERRIDE:-}" ]]; then
        TARGET_EMAIL_VERIFICATION_ENABLED="$(normalize_boolean "$EMAIL_VERIFICATION_OVERRIDE")" \
            || die "EMAIL_VERIFICATION_ENABLED must be true, false, 1, or 0"
    elif [[ -n "$configured_value" ]]; then
        TARGET_EMAIL_VERIFICATION_ENABLED="$(normalize_boolean "$configured_value")" \
            || die "Existing EMAIL_VERIFICATION_ENABLED must be true, false, 1, or 0"
    elif setup_input_is_interactive; then
        smtp_environment_is_configured && default_enabled=true
        prompt_email_verification "$default_enabled"
        selection_was_prompted=1
    elif smtp_environment_is_configured; then
        TARGET_EMAIL_VERIFICATION_ENABLED=true
    else
        TARGET_EMAIL_VERIFICATION_ENABLED=false
    fi

    [[ "$TARGET_EMAIL_VERIFICATION_ENABLED" == true ]] || return 0

    if smtp_override_is_requested; then
        load_smtp_overrides
    elif [[ "$selection_was_prompted" -eq 1 ]]; then
        prompt_smtp_settings
    elif ! smtp_environment_is_configured; then
        if setup_input_is_interactive; then
            printf '\nEmail verification requires SMTP configuration.\n'
            prompt_smtp_settings
        else
            die "Email verification is enabled, but .env does not contain a complete SMTP configuration"
        fi
    fi
}

toggle_user_email_verification_model() {
    local enabled="$1" model_file="${USER_MODEL_FILE:-$APP_DIR/app/Models/User.php}"

    [[ -f "$model_file" ]] || return 1
    if [[ "$enabled" == true ]]; then
        sed -E -i.bak 's|implements[[:space:]]+/\*[[:space:]]*MustVerifyEmail,[[:space:]]*\*/[[:space:]]+PasskeyUser|implements MustVerifyEmail, PasskeyUser|' "$model_file" \
            || { rm -f "${model_file}.bak"; return 1; }
        rm -f "${model_file}.bak"
        [[ "$(grep -Ec '^class User extends Authenticatable implements MustVerifyEmail, PasskeyUser$' "$model_file")" -eq 1 ]] \
            || return 1
    else
        sed -E -i.bak 's|implements[[:space:]]+MustVerifyEmail,[[:space:]]+PasskeyUser|implements /* MustVerifyEmail, */ PasskeyUser|' "$model_file" \
            || { rm -f "${model_file}.bak"; return 1; }
        rm -f "${model_file}.bak"
        [[ "$(grep -Ec '^class User extends Authenticatable implements /\* MustVerifyEmail, \*/ PasskeyUser$' "$model_file")" -eq 1 ]] \
            || return 1
    fi
}

set_user_email_verification_model() {
    local enabled="$1" model_file="${USER_MODEL_FILE:-$APP_DIR/app/Models/User.php}"

    toggle_user_email_verification_model "$enabled" \
        || die "Could not configure MustVerifyEmail in $model_file"
    if [[ "$enabled" == true ]]; then
        log "Email verification enabled in the User model"
    else
        log "Email verification disabled in the User model"
    fi
}

restore_user_email_verification_for_update() {
    [[ -f "${USER_MODEL_FILE:-$APP_DIR/app/Models/User.php}" ]] || return 0
    toggle_user_email_verification_model true
}

apply_persisted_email_verification_model() {
    local configured_value

    [[ -f "${ENV_FILE:-}" && -f "${USER_MODEL_FILE:-$APP_DIR/app/Models/User.php}" ]] || return 0
    configured_value="$(read_env EMAIL_VERIFICATION_ENABLED "$ENV_FILE")" || return 1
    [[ -n "$configured_value" ]] || return 0
    configured_value="$(normalize_boolean "$configured_value")" || return 1
    toggle_user_email_verification_model "$configured_value"
}

configure_email_verification() {
    set_env EMAIL_VERIFICATION_ENABLED "$TARGET_EMAIL_VERIFICATION_ENABLED" "$ENV_FILE"

    if [[ "$TARGET_EMAIL_VERIFICATION_ENABLED" == true && "$SMTP_SETTINGS_WRITE_REQUIRED" -eq 1 ]]; then
        set_env MAIL_MAILER smtp "$ENV_FILE"
        set_env MAIL_SCHEME "$TARGET_MAIL_SCHEME" "$ENV_FILE"
        set_env MAIL_HOST "$(dotenv_quote "$TARGET_MAIL_HOST")" "$ENV_FILE"
        set_env MAIL_PORT "$TARGET_MAIL_PORT" "$ENV_FILE"
        set_env MAIL_USERNAME "$(dotenv_quote "$TARGET_MAIL_USERNAME")" "$ENV_FILE"
        if [[ "$MAIL_PASSWORD_WRITE_REQUIRED" -eq 1 ]]; then
            set_env MAIL_PASSWORD "$(dotenv_quote "$TARGET_MAIL_PASSWORD")" "$ENV_FILE"
        fi
        set_env MAIL_FROM_ADDRESS "$(dotenv_quote "$TARGET_MAIL_FROM_ADDRESS")" "$ENV_FILE"
        set_env MAIL_FROM_NAME "$(dotenv_quote "$TARGET_MAIL_FROM_NAME")" "$ENV_FILE"
    fi

    set_user_email_verification_model "$TARGET_EMAIL_VERIFICATION_ENABLED"
}

normalize_ip_allowlist() {
    local raw_value="$1"

    python3 - "$raw_value" <<'PY'
import ipaddress
import sys

raw_value = sys.argv[1]
values = [value.strip() for value in raw_value.split(',')]

if not values or any(not value or '%' in value for value in values):
    raise SystemExit(1)

normalized = ['127.0.0.1', '::1']
try:
    for value in values:
        if '/' in value:
            normalized_value = str(ipaddress.ip_network(value, strict=True))
        else:
            normalized_value = str(ipaddress.ip_address(value))
        if normalized_value not in normalized:
            normalized.append(normalized_value)
except ValueError:
    raise SystemExit(1)

print(','.join(normalized), end='')
PY
}

application_locale_from_choice() {
    case "$1" in
        ''|1|ja) printf 'ja' ;;
        2|en) printf 'en' ;;
        3|fr) printf 'fr' ;;
        4|de) printf 'de' ;;
        5|zh_CN) printf 'zh_CN' ;;
        6|zh_TW) printf 'zh_TW' ;;
        7|es) printf 'es' ;;
        8|pt_BR) printf 'pt_BR' ;;
        9|pt_PT) printf 'pt_PT' ;;
        10|ko) printf 'ko' ;;
        *) return 1 ;;
    esac
}

prompt_allowed_ip_ranges() {
    local input normalized

    printf '%s\n' 'Enter allowed IP addresses or CIDR ranges separated by commas.'
    printf '%s\n' 'Example: 192.168.1.0/24, 10.0.0.0/8'
    printf '%s\n' 'Loopback addresses 127.0.0.1 and ::1 are added automatically.'
    while true; do
        printf 'Allowed IP addresses/CIDR ranges: '
        IFS= read -r input || input=""
        if normalized="$(normalize_ip_allowlist "$input")"; then
            TARGET_ALLOWED_IPS="$normalized"
            return 0
        fi
        warn "Enter one or more valid IP addresses or strict CIDR ranges"
    done
}

prompt_ip_restriction() {
    local input

    printf '\nIP address restriction\n'
    printf '%s\n' 'Leave this blank to allow access from every IP address.'
    while true; do
        printf 'Enable IP address restriction? [y/N]: '
        IFS= read -r input || input=""
        case "$input" in
            ''|n|N|no|No|NO)
                TARGET_IP_RESTRICTION_ENABLED=false
                TARGET_ALLOWED_IPS=""
                return 0
                ;;
            y|Y|yes|Yes|YES)
                TARGET_IP_RESTRICTION_ENABLED=true
                prompt_allowed_ip_ranges
                return 0
                ;;
            *)
                warn "Enter y or n; leave blank to allow all access"
                ;;
        esac
    done
}

prompt_application_locale() {
    local input locale

    printf '\nDefault language\n'
    printf '%s\n' \
        '  1) 日本語 (ja)' \
        '  2) English (en)' \
        '  3) Français (fr)' \
        '  4) Deutsch (de)' \
        '  5) 简体中文 (zh_CN)' \
        '  6) 繁體中文 (zh_TW)' \
        '  7) Español (es)' \
        '  8) Português (Brasil) (pt_BR)' \
        '  9) Português (Portugal) (pt_PT)' \
        ' 10) 한국어 (ko)'
    while true; do
        printf 'Select the default language [1]: '
        IFS= read -r input || input=""
        if locale="$(application_locale_from_choice "$input")"; then
            TARGET_APP_LOCALE="$locale"
            return 0
        fi
        warn "Enter a number from 1 to 10 or one of the displayed locale codes"
    done
}

resolve_ubuntu_access_and_locale() {
    local env_file_created="$1"
    local enabled_value allowed_value locale_value normalized

    TARGET_IP_RESTRICTION_ENABLED=""
    TARGET_ALLOWED_IPS=""
    TARGET_APP_LOCALE=""

    if [[ -n "${IP_RESTRICTION_OVERRIDE:-}" ]]; then
        enabled_value="$(normalize_boolean "$IP_RESTRICTION_OVERRIDE")" \
            || die "IP_RESTRICTION_ENABLED must be true, false, 1, or 0"
        allowed_value="${ALLOWED_IPS_OVERRIDE:-}"
    elif [[ -n "${ALLOWED_IPS_OVERRIDE:-}" ]]; then
        enabled_value=true
        allowed_value="$ALLOWED_IPS_OVERRIDE"
    elif [[ "$env_file_created" -eq 1 ]] && setup_input_is_interactive; then
        prompt_ip_restriction
        enabled_value="$TARGET_IP_RESTRICTION_ENABLED"
        allowed_value="$TARGET_ALLOWED_IPS"
    elif [[ "$env_file_created" -eq 1 ]]; then
        enabled_value=false
        allowed_value=""
    else
        enabled_value="$(read_env IP_RESTRICTION_ENABLED "$ENV_FILE")"
        enabled_value="${enabled_value:-false}"
        enabled_value="$(normalize_boolean "$enabled_value")" \
            || die "Existing IP_RESTRICTION_ENABLED must be true, false, 1, or 0"
        allowed_value="$(read_env ALLOWED_IPS "$ENV_FILE")"
    fi

    if [[ "$enabled_value" == true ]]; then
        if [[ -z "$allowed_value" ]] && setup_input_is_interactive; then
            prompt_allowed_ip_ranges
            allowed_value="$TARGET_ALLOWED_IPS"
        fi
        [[ -n "$allowed_value" ]] \
            || die "ALLOWED_IPS is required when IP_RESTRICTION_ENABLED is true"
        normalized="$(normalize_ip_allowlist "$allowed_value")" \
            || die "ALLOWED_IPS must contain comma-separated IP addresses or strict CIDR ranges"
        TARGET_ALLOWED_IPS="$normalized"
    else
        [[ -z "${ALLOWED_IPS_OVERRIDE:-}" ]] \
            || die "ALLOWED_IPS cannot be set when IP_RESTRICTION_ENABLED is false"
        TARGET_ALLOWED_IPS=""
    fi
    TARGET_IP_RESTRICTION_ENABLED="$enabled_value"

    if [[ -n "${APP_LOCALE_OVERRIDE:-}" ]]; then
        locale_value="$(application_locale_from_choice "$APP_LOCALE_OVERRIDE")" \
            || die "APP_LOCALE is unsupported: $APP_LOCALE_OVERRIDE"
    elif [[ "$env_file_created" -eq 1 ]] && setup_input_is_interactive; then
        prompt_application_locale
        locale_value="$TARGET_APP_LOCALE"
    elif [[ "$env_file_created" -eq 1 ]]; then
        locale_value=ja
    else
        locale_value="$(read_env APP_LOCALE "$ENV_FILE")"
        locale_value="${locale_value:-ja}"
        locale_value="$(application_locale_from_choice "$locale_value")" \
            || die "Existing APP_LOCALE is unsupported: $locale_value"
    fi
    TARGET_APP_LOCALE="$locale_value"
}

configure_access_and_locale_environment() {
    set_env IP_RESTRICTION_ENABLED "$TARGET_IP_RESTRICTION_ENABLED" "$ENV_FILE"
    set_env ALLOWED_IPS "$TARGET_ALLOWED_IPS" "$ENV_FILE"
    set_env APP_LOCALE "$TARGET_APP_LOCALE" "$ENV_FILE"
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
            response="$(printf '%s' "$response" | tr -d '[:space:]')"
            case "$response" in
                [Tt][Rr][Uu][Ee]|[Oo][Kk]) return 0 ;;
            esac
        fi
        sleep "$delay"
    done

    return 1
}

wait_for_editor_api() {
    local base_url="${1%/}" attempts="${2:-90}" delay="${3:-2}" attempt

    for (( attempt = 1; attempt <= attempts; attempt++ )); do
        if curl -fsS --max-time 10 "${base_url}/web-apps/apps/api/documents/api.js" >/dev/null 2>&1; then
            return 0
        fi
        sleep "$delay"
    done

    return 1
}

wait_for_document_server() {
    local base_url="$1" attempts="${2:-90}" delay="${3:-2}"

    wait_for_health "$base_url" "$attempts" "$delay" \
        && wait_for_editor_api "$base_url" "$attempts" "$delay"
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
    [[ "$JWT_SECRET" =~ ^[A-Za-z0-9._~+/#=-]+$ ]] \
        || die "ONLYOFFICE JWT secret must use letters, numbers, or safe punctuation (._~+/#=-)"
}

resolve_app_values() {
    local app_url_value office_host app_host app_port app_scheme
    local env_public_url env_document_url env_internal_url env_secret env_instance
    local env_max_file_size env_max_download env_upload_max_file_mb upload_max_file_bytes
    local resource_checksum

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
    env_upload_max_file_mb="${UPLOAD_MAX_FILE_MB:-$(read_env UPLOAD_MAX_FILE_MB)}"

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
    MAX_FILE_SIZE_BYTES="$(normalize_decimal "$MAX_FILE_SIZE_BYTES")"
    TARGET_MAX_DOWNLOAD_BYTES="$(normalize_decimal "$TARGET_MAX_DOWNLOAD_BYTES")"
    (( TARGET_MAX_DOWNLOAD_BYTES >= MAX_FILE_SIZE_BYTES )) \
        || die "DocumentServer maxDownloadBytes cannot be smaller than the application file limit"

    env_upload_max_file_mb="${env_upload_max_file_mb:-200}"
    valid_upload_max_megabytes "$env_upload_max_file_mb" \
        || die "UPLOAD_MAX_FILE_MB must be a positive integer no larger than ${MAX_SAFE_FILE_SIZE_MB}"
    env_upload_max_file_mb="$(normalize_decimal "$env_upload_max_file_mb")"
    upload_max_file_bytes="$((env_upload_max_file_mb * 1024 * 1024))"
    (( MAX_FILE_SIZE_BYTES <= upload_max_file_bytes )) \
        || die "ONLYOFFICE_MAX_FILE_SIZE_BYTES must not exceed UPLOAD_MAX_FILE_MB"

    resource_checksum="$(resource_fingerprint "$APP_DIR" "$INSTANCE_ID")"
    [[ "$resource_checksum" =~ ^([0-9a-f]{12}|[0-9]+)$ ]] \
        || die "Could not derive a stable Apple Container resource id"
    MACOS_RESOURCE_ID="jin-${resource_checksum}"
    ONLYOFFICE_CONTAINER_NAME="${ONLYOFFICE_CONTAINER_NAME:-jin-onlyoffice-${MACOS_RESOURCE_ID}}"
    ONLYOFFICE_DATA_VOLUME="${ONLYOFFICE_DATA_VOLUME:-jin-onlyoffice-data-${MACOS_RESOURCE_ID}}"
    ONLYOFFICE_LOGS_VOLUME="${ONLYOFFICE_LOGS_VOLUME:-jin-onlyoffice-logs-${MACOS_RESOURCE_ID}}"
    ONLYOFFICE_LIB_VOLUME="${ONLYOFFICE_LIB_VOLUME:-jin-onlyoffice-lib-${MACOS_RESOURCE_ID}}"
    ONLYOFFICE_DB_VOLUME="${ONLYOFFICE_DB_VOLUME:-jin-onlyoffice-db-${MACOS_RESOURCE_ID}}"
    valid_resource_name "$ONLYOFFICE_CONTAINER_NAME" \
        || die "Invalid ONLYOFFICE_CONTAINER_NAME: $ONLYOFFICE_CONTAINER_NAME"
    valid_resource_name "$ONLYOFFICE_DATA_VOLUME" \
        || die "Invalid ONLYOFFICE_DATA_VOLUME: $ONLYOFFICE_DATA_VOLUME"
    valid_resource_name "$ONLYOFFICE_LOGS_VOLUME" \
        || die "Invalid ONLYOFFICE_LOGS_VOLUME: $ONLYOFFICE_LOGS_VOLUME"
    valid_resource_name "$ONLYOFFICE_LIB_VOLUME" \
        || die "Invalid ONLYOFFICE_LIB_VOLUME: $ONLYOFFICE_LIB_VOLUME"
    valid_resource_name "$ONLYOFFICE_DB_VOLUME" \
        || die "Invalid ONLYOFFICE_DB_VOLUME: $ONLYOFFICE_DB_VOLUME"

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
        [[ $NO_SSL -eq 0 || "$TARGET_PUBLIC_URL" != https://* ]] \
            || die "--no-ssl cannot configure an HTTP-only Nginx site for an HTTPS ONLYOFFICE_PUBLIC_URL"
        if [[ $NO_SSL -eq 0 && "$TARGET_PUBLIC_URL" != https://* ]]; then
            die "ONLYOFFICE_PUBLIC_URL must use HTTPS when Let's Encrypt is enabled"
        fi
    fi
}

configure_app_env() {
    local allow_download allow_print allow_copy

    allow_download="${ONLYOFFICE_ALLOW_DOWNLOAD:-$(read_env ONLYOFFICE_ALLOW_DOWNLOAD)}"
    allow_print="${ONLYOFFICE_ALLOW_PRINT:-$(read_env ONLYOFFICE_ALLOW_PRINT)}"
    allow_copy="${ONLYOFFICE_ALLOW_COPY:-$(read_env ONLYOFFICE_ALLOW_COPY)}"
    allow_download="${allow_download:-false}"
    allow_print="${allow_print:-false}"
    allow_copy="${allow_copy:-true}"
    if ! allow_download="$(normalize_boolean "$allow_download")"; then
        die "ONLYOFFICE_ALLOW_DOWNLOAD must be true, false, 1, or 0"
    fi
    if ! allow_print="$(normalize_boolean "$allow_print")"; then
        die "ONLYOFFICE_ALLOW_PRINT must be true, false, 1, or 0"
    fi
    if ! allow_copy="$(normalize_boolean "$allow_copy")"; then
        die "ONLYOFFICE_ALLOW_COPY must be true, false, 1, or 0"
    fi

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
const temporaryPath = path + '.jin-tmp-' + process.pid;

try {
    const mode = fs.statSync(path).mode & 0o7777;
    fs.writeFileSync(temporaryPath, JSON.stringify(config, null, 2) + '\\n', { mode });
    fs.chmodSync(temporaryPath, mode);
    fs.renameSync(temporaryPath, path);
} catch (error) {
    if (fs.existsSync(temporaryPath)) {
        fs.unlinkSync(temporaryPath);
    }
    throw error;
}
process.stdout.write(String(actual));
NODE
)"

    valid_positive_integer "$configured" \
        || die "Could not read OnlyOffice maxDownloadBytes from the container"
    DOCUMENT_SERVER_MAX_DOWNLOAD_BYTES="$configured"
}

configure_macos_jwt() {
    printf '%s' "$JWT_SECRET" | container exec -i "$ONLYOFFICE_CONTAINER_NAME" node -e '
const fs = require("fs");

const path = "/etc/onlyoffice/documentserver/local.json";
const value = fs.readFileSync(0, "utf8");
const config = JSON.parse(fs.readFileSync(path, "utf8"));
const services = config.services = config.services || {};
const service = services.CoAuthoring = services.CoAuthoring || {};
const secret = service.secret = service.secret || {};

for (const name of ["inbox", "outbox", "session"]) {
    secret[name] = secret[name] || {};
    secret[name].string = value;
}

const token = service.token = service.token || {};
const enable = token.enable = token.enable || {};
enable.browser = true;
enable.request = enable.request || {};
enable.request.inbox = true;
enable.request.outbox = true;

for (const name of ["inbox", "outbox"]) {
    token[name] = token[name] || {};
    token[name].header = "AuthorizationJwt";
}

const temporaryPath = path + ".jin-jwt-tmp-" + process.pid;

try {
    const mode = fs.statSync(path).mode & 0o7777;
    fs.writeFileSync(temporaryPath, JSON.stringify(config, null, 2) + "\\n", { mode });
    fs.chmodSync(temporaryPath, mode);
    fs.renameSync(temporaryPath, path);
} catch (error) {
    if (fs.existsSync(temporaryPath)) {
        fs.unlinkSync(temporaryPath);
    }
    throw error;
}
'
}

has_macos_managed_label() {
    grep -Eq "\"com\\.jin\\.setup\"[[:space:]]*:[[:space:]]*\"onlyoffice:${MACOS_RESOURCE_ID}\""
}

is_existing_onlyoffice_container() {
    container inspect "$ONLYOFFICE_CONTAINER_NAME" 2>/dev/null \
        | grep -Eq '"reference"[[:space:]]*:[[:space:]]*".*onlyoffice\\?/documentserver:'
}

macos_container_uses_volume() {
    local volume_name="$1"

    container inspect "$ONLYOFFICE_CONTAINER_NAME" 2>/dev/null \
        | grep -Eq "\"name\"[[:space:]]*:[[:space:]]*\"${volume_name}\""
}

macos_container_matches_configuration() {
    local inspection

    inspection="$(container inspect "$ONLYOFFICE_CONTAINER_NAME" 2>/dev/null)" || return 1
    grep -Fq "\"com.jin.image\"" <<< "$inspection" \
        && grep -Fq "$ONLYOFFICE_IMAGE" <<< "$inspection" \
        && grep -Fq "\"com.jin.port\"" <<< "$inspection" \
        && grep -Fq "\"$ONLYOFFICE_PORT\"" <<< "$inspection"
}

ensure_macos_volume() {
    local volume_name="$1"

    if container volume inspect "$volume_name" >/dev/null 2>&1; then
        if ! container volume inspect "$volume_name" | has_macos_managed_label; then
            [[ "$ALLOW_LEGACY_MACOS_RESOURCES" -eq 1 ]] \
                && is_existing_onlyoffice_container \
                && macos_container_uses_volume "$volume_name" \
                || die "Refusing to use unmanaged Apple Container volume: $volume_name"
            warn "Reusing legacy Apple Container volume: $volume_name"
        fi
        return 0
    fi

    container volume create \
        --label "com.jin.setup=onlyoffice:${MACOS_RESOURCE_ID}" \
        "$volume_name" >/dev/null \
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
    local macos_major app_port volume_name reuse_container=0
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
        for volume_name in \
            "$ONLYOFFICE_DATA_VOLUME" \
            "$ONLYOFFICE_LOGS_VOLUME" \
            "$ONLYOFFICE_LIB_VOLUME" \
            "$ONLYOFFICE_DB_VOLUME"; do
            macos_container_uses_volume "$volume_name" \
                || die "Refusing to use $ONLYOFFICE_CONTAINER_NAME with an unexpected volume: $volume_name"
        done

        if container inspect "$ONLYOFFICE_CONTAINER_NAME" | has_macos_managed_label; then
            if [[ "$RECREATE_CONTAINER" -eq 0 ]] && macos_container_matches_configuration; then
                reuse_container=1
                log "Reusing existing OnlyOffice container: $ONLYOFFICE_CONTAINER_NAME"
            elif [[ "$RECREATE_CONTAINER" -eq 0 ]]; then
                die "Existing Apple Container configuration differs; pass --recreate-container only after confirming the volumes are backed up"
            fi
        else
            is_existing_onlyoffice_container \
                && [[ "$ALLOW_LEGACY_MACOS_RESOURCES" -eq 1 ]] \
                || die "Refusing to replace unmanaged Apple Container: $ONLYOFFICE_CONTAINER_NAME"
            if [[ "$RECREATE_CONTAINER" -eq 0 ]]; then
                reuse_container=1
                warn "Reusing explicitly selected legacy OnlyOffice container without a Jin identity label"
            fi
        fi

        if [[ "$reuse_container" -eq 0 ]]; then
            warn "Recreating $ONLYOFFICE_CONTAINER_NAME; named volumes will be preserved"
            container stop --time 60 "$ONLYOFFICE_CONTAINER_NAME" >/dev/null 2>&1 || true
            container delete "$ONLYOFFICE_CONTAINER_NAME" >/dev/null \
                || die "Could not remove $ONLYOFFICE_CONTAINER_NAME"
        fi
    fi

    container_run_args=(
        --detach
        --name "$ONLYOFFICE_CONTAINER_NAME"
        --label "com.jin.setup=onlyoffice:${MACOS_RESOURCE_ID}"
        --label "com.jin.image=${ONLYOFFICE_IMAGE}"
        --label "com.jin.port=${ONLYOFFICE_PORT}"
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

    if [[ "$reuse_container" -eq 1 ]]; then
        container start "$ONLYOFFICE_CONTAINER_NAME" >/dev/null 2>&1 || true
    else
        log "Starting OnlyOffice DocumentServer on 127.0.0.1:${ONLYOFFICE_PORT}..."
        JWT_SECRET="$JWT_SECRET" container run \
            "${container_run_args[@]}" \
            "$ONLYOFFICE_IMAGE" \
            -lc 'exec /bin/bash /app/ds/run-document-server.sh' \
            >/dev/null \
            || die "Could not start OnlyOffice DocumentServer"
    fi

    if ! wait_for_document_server "http://127.0.0.1:${ONLYOFFICE_PORT}" 90 2; then
        container logs "$ONLYOFFICE_CONTAINER_NAME" || true
        die "OnlyOffice health check failed on 127.0.0.1:${ONLYOFFICE_PORT}"
    fi

    configure_macos_jwt
    configure_macos_local_json
    container stop --time 60 "$ONLYOFFICE_CONTAINER_NAME" >/dev/null
    container start "$ONLYOFFICE_CONTAINER_NAME" >/dev/null

    wait_for_document_server "http://127.0.0.1:${ONLYOFFICE_PORT}" 90 2 \
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
    log "Container: $ONLYOFFICE_CONTAINER_NAME"
    log "Start Laravel with 'php artisan serve --host=127.0.0.1 --port=${app_port}' when needed"
}

configure_ubuntu_jwt() {
    local config_file="${1:-/etc/onlyoffice/documentserver/local.json}"

    printf '%s' "$JWT_SECRET" | sudo python3 -c '
import json
import os
import stat
import sys
import tempfile
from pathlib import Path

path = Path(sys.argv[1])
config = json.loads(path.read_text(encoding="utf-8"))
services = config.setdefault("services", {})
coauthoring = services.setdefault("CoAuthoring", {})
secret = coauthoring.setdefault("secret", {})
value = sys.stdin.read()

for name in ("inbox", "outbox", "session"):
    secret.setdefault(name, {})["string"] = value

token = coauthoring.setdefault("token", {})
enable = token.setdefault("enable", {})
enable["browser"] = True
request = enable.setdefault("request", {})
request["inbox"] = True
request["outbox"] = True

for name in ("inbox", "outbox"):
    token.setdefault(name, {})["header"] = "AuthorizationJwt"

temporary = None
try:
    descriptor, temporary = tempfile.mkstemp(dir=path.parent, prefix="." + path.name + ".", text=True)
    with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
        json.dump(config, handle, indent=2, ensure_ascii=False)
        handle.write("\n")
    os.chmod(temporary, stat.S_IMODE(path.stat().st_mode))
    os.replace(temporary, path)
except BaseException:
    if temporary is not None:
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass
    raise
' "$config_file"
}

configure_ubuntu_max_download_bytes() {
    local config_file="${1:-/etc/onlyoffice/documentserver/local.json}" configured

    configured="$(sudo env JIN_ONLYOFFICE_MAX_DOWNLOAD_BYTES="$TARGET_MAX_DOWNLOAD_BYTES" \
        python3 - "$config_file" <<'PY'
import json
import os
import stat
import sys
import tempfile
from pathlib import Path

path = Path(sys.argv[1])
config = json.loads(path.read_text(encoding='utf-8'))
converter = config.setdefault('FileConverter', {}).setdefault('converter', {})
requested = int(os.environ['JIN_ONLYOFFICE_MAX_DOWNLOAD_BYTES'])

try:
    current = int(converter.get('maxDownloadBytes', 0))
except (TypeError, ValueError):
    current = 0

configured = max(current, requested)
converter['maxDownloadBytes'] = configured
temporary = None
try:
    descriptor, temporary = tempfile.mkstemp(dir=path.parent, prefix='.' + path.name + '.', text=True)
    with os.fdopen(descriptor, 'w', encoding='utf-8') as handle:
        json.dump(config, handle, indent=2, ensure_ascii=False)
        handle.write('\n')
    os.chmod(temporary, stat.S_IMODE(path.stat().st_mode))
    os.replace(temporary, path)
except BaseException:
    if temporary is not None:
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass
    raise
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
    local site_name="jin-onlyoffice-${MACOS_RESOURCE_ID}"
    local legacy_site_name="jin-onlyoffice"
    local available="/etc/nginx/sites-available/${site_name}"
    local enabled="/etc/nginx/sites-enabled/${site_name}"
    local legacy_available="/etc/nginx/sites-available/${legacy_site_name}"
    local preserve_tls=0
    local -a certbot_args

    sudo install -d -m 0755 /etc/nginx/sites-available /etc/nginx/sites-enabled

    if [[ "$site_name" != "$legacy_site_name" ]] && sudo test -e "$legacy_available"; then
        if sudo grep -q 'Managed by jin/setup.sh' "$legacy_available" \
            && sudo grep -Fq "server_name ${OFFICE_DOMAIN};" "$legacy_available"; then
            site_name="$legacy_site_name"
            available="$legacy_available"
            enabled="/etc/nginx/sites-enabled/${site_name}"
            warn "Reusing the legacy managed nginx site for $OFFICE_DOMAIN"
        elif sudo grep -Fq "server_name ${OFFICE_DOMAIN};" "$legacy_available"; then
            die "Refusing to use an unmanaged nginx configuration for $OFFICE_DOMAIN: $legacy_available"
        fi
    fi

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
# Resource: ${MACOS_RESOURCE_ID}
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

    if sudo test -L "$enabled"; then
        [[ "$(sudo readlink "$enabled")" == "$available" ]] \
            || die "Refusing to replace an unmanaged nginx enabled entry: $enabled"
    elif sudo test -e "$enabled"; then
        die "Refusing to replace an unmanaged nginx enabled entry: $enabled"
    else
        sudo ln -s "$available" "$enabled"
    fi
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


valid_postgresql_identifier() {
    [[ "$1" =~ ^[A-Za-z_][A-Za-z0-9_]*$ && "${#1}" -le 63 ]]
}

apt_get_with_lock_wait() {
    sudo env DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 "$@"
}

apt_package_available() {
    apt-cache show "$1" >/dev/null 2>&1
}

run_as_deploy() {
    if [[ "$(id -u)" -eq 0 || "$(id -un)" != "$DEPLOY_USER" ]]; then
        sudo -u "$DEPLOY_USER" -- "$@"
    else
        "$@"
    fi
}

announce_passwordless_sudo_option() {
    [[ "$(id -u)" -ne 0 && "$SUDO_NOPASSWD" != "1" ]] || return 0

    if sudo -n true >/dev/null 2>&1; then
        log "Passwordless sudo detected; rerun with: ./setup.sh --sudo-nopasswd"
        exit 0
    fi
}

resolve_ubuntu_operator_inputs() {
    if [[ -z "$APP_DOMAIN" ]]; then
        setup_input_is_interactive \
            || die "--domain is required for a non-interactive Ubuntu deployment"
        while [[ -z "$APP_DOMAIN" ]]; do
            printf 'Jin public domain (e.g. jin.example.com): '
            IFS= read -r APP_DOMAIN || die "Could not read the Jin public domain"
        done
    fi

    if [[ "$NO_SSL" -eq 0 && -z "$EMAIL" ]] && setup_input_is_interactive; then
        printf "Let's Encrypt email (optional): "
        IFS= read -r EMAIL || die "Could not read the Let's Encrypt email"
    fi

    if ! valid_hostname "$APP_DOMAIN"; then
        if [[ "$NO_SSL" -ne 1 ]] || ! valid_local_hostname "$APP_DOMAIN"; then
            die "Invalid application domain: $APP_DOMAIN"
        fi
    fi
    [[ -z "$EMAIL" ]] || valid_email_address "$EMAIL" \
        || die "Invalid email: $EMAIL"
}

configure_deploy_postgresql_peer_authentication() {
    local hba_directory hba_file hba_temp_file include_rule legacy_include_rule peer_file peer_rule_count peer_rule_errors

    hba_file="$(sudo -u postgres psql -p "$PG_PORT" -Atqc 'SHOW hba_file' postgres)"
    [[ "$hba_file" == /* ]] || die "PostgreSQL returned an invalid hba_file path: $hba_file"
    sudo test -f "$hba_file" || die "PostgreSQL hba_file does not exist: $hba_file"
    hba_directory="$(dirname -- "$hba_file")"
    peer_file="$hba_directory/jin-pg_hba.conf"
    include_rule='include_if_exists "jin-pg_hba.conf"'
    legacy_include_rule="include_if_exists 'jin-pg_hba.conf'"

    printf '# Managed by Jin setup.sh\nlocal all %s peer\n' "$DEPLOY_USER" \
        | sudo tee "$peer_file" >/dev/null
    sudo chown postgres:postgres "$peer_file"
    sudo chmod 0640 "$peer_file"

    if [[ "$(sudo sed -n '1p' "$hba_file")" != "$include_rule" ]] \
        || sudo grep -Fqx "$legacy_include_rule" "$hba_file"; then
        hba_temp_file="$(sudo mktemp "${hba_file}.jin.XXXXXX")"
        if ! {
            printf '%s\n' "$include_rule"
            sudo awk \
                -v include_rule="$include_rule" \
                -v legacy_include_rule="$legacy_include_rule" \
                '$0 != include_rule && $0 != legacy_include_rule { print }' \
                "$hba_file"
        } | sudo tee "$hba_temp_file" >/dev/null; then
            sudo rm -f "$hba_temp_file"
            die "Could not add the Jin peer authentication include to $hba_file"
        fi
        sudo chown postgres:postgres "$hba_temp_file"
        sudo chmod 0640 "$hba_temp_file"
        sudo mv -f "$hba_temp_file" "$hba_file"
    fi

    peer_rule_errors="$(sudo -u postgres psql \
        -p "$PG_PORT" \
        --set=peer_file="$peer_file" \
        -Atq postgres <<'SQL'
SELECT coalesce(string_agg(format('%s: %s', line_number, error), E'\n'), '')
FROM pg_hba_file_rules
WHERE file_name = :'peer_file' AND error IS NOT NULL;
SQL
    )"
    [[ -z "$peer_rule_errors" ]] \
        || die "Invalid Jin PostgreSQL peer authentication rule: $peer_rule_errors"
    peer_rule_count="$(sudo -u postgres psql \
        -p "$PG_PORT" \
        --set=deploy_user="$DEPLOY_USER" \
        --set=peer_file="$peer_file" \
        -Atq postgres <<'SQL'
SELECT count(*)
FROM pg_hba_file_rules
WHERE file_name = :'peer_file'
  AND type = 'local'
  AND 'all' = ANY(database)
  AND :'deploy_user' = ANY(user_name)
  AND auth_method = 'peer'
  AND error IS NULL;
SQL
    )"
    [[ "$peer_rule_count" == "1" ]] \
        || die "Jin PostgreSQL peer authentication rule was not loaded from $peer_file"
    sudo systemctl reload "$PG_SERVICE"
}

ensure_deploy_postgresql_role() {
    local connected_role installer_role_is_superuser

    log "Ensuring PostgreSQL superuser role '$DEPLOY_USER' exists for passwordless local administration"
    sudo -u postgres psql \
        -p "$PG_PORT" \
        --set=ON_ERROR_STOP=1 \
        --set=installer_role="$DEPLOY_USER" \
        postgres <<'SQL'
SELECT format('CREATE ROLE %I LOGIN SUPERUSER', :'installer_role')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'installer_role') \gexec
SELECT format('ALTER ROLE %I WITH LOGIN SUPERUSER', :'installer_role') \gexec
SQL
    installer_role_is_superuser="$(sudo -u postgres psql \
        -p "$PG_PORT" \
        --set=installer_role="$DEPLOY_USER" \
        -Atq postgres <<'SQL'
SELECT rolsuper FROM pg_roles WHERE rolname = :'installer_role';
SQL
    )"
    [[ "$installer_role_is_superuser" == "t" ]] \
        || die "PostgreSQL superuser role was not created for $DEPLOY_USER"

    connected_role="$(run_as_deploy psql --no-password -p "$PG_PORT" -Atqc 'SELECT current_user' postgres)" \
        || die "PostgreSQL peer authentication failed for $DEPLOY_USER"
    [[ "$connected_role" == "$DEPLOY_USER" ]] \
        || die "PostgreSQL peer authentication connected as '$connected_role', expected '$DEPLOY_USER'"
}

endpoint_returns_ok() {
    local response

    response="$(curl -fsS --max-time 5 "${1%/}/up" 2>/dev/null)" || return 1
    [[ "$response" == "ok" || "$response" == "OK" ]]
}

wait_for_supervisor() {
    local attempt

    for attempt in {1..30}; do
        if sudo supervisorctl pid >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done

    sudo systemctl --no-pager --full status supervisor >&2 || true
    sudo journalctl --no-pager -u supervisor -n 80 >&2 || true
    return 1
}

configure_rabbitmq() {
    local config=/etc/rabbitmq/rabbitmq.conf env_file=/etc/rabbitmq/rabbitmq-env.conf
    local epmd_socket_drop_in=/etc/systemd/system/epmd.socket.d/jin.conf unit

    sudo install -d -m 0755 /etc/rabbitmq
    if sudo test -f "$config"; then
        sudo sed -E -i.bak \
            -e '/^# Managed by jin\/setup\.sh$/d' \
            -e '/^[[:space:]]*listeners\.tcp\./d' \
            -e '/^[[:space:]]*distribution\.listener\.interface[[:space:]]*=/d' \
            "$config"
        sudo rm -f "$config.bak"
    else
        sudo touch "$config"
    fi
    printf '\n# Managed by jin/setup.sh\nlisteners.tcp.1 = 127.0.0.1:5672\ndistribution.listener.interface = 127.0.0.1\n' \
        | sudo tee -a "$config" >/dev/null

    if sudo test -f "$env_file"; then
        sudo sed -E -i.bak \
            -e '/^# Managed by jin\/setup\.sh$/d' \
            -e '/^[[:space:]]*ERL_EPMD_ADDRESS=/d' \
            -e '/^[[:space:]]*NODENAME=/d' \
            "$env_file"
        sudo rm -f "$env_file.bak"
    else
        sudo touch "$env_file"
    fi
    printf '\n# Managed by jin/setup.sh\nERL_EPMD_ADDRESS=127.0.0.1\nNODENAME=rabbit@localhost\n' \
        | sudo tee -a "$env_file" >/dev/null

    sudo install -d -m 0755 "$(dirname "$epmd_socket_drop_in")"
    printf '# Managed by jin/setup.sh\n[Socket]\nListenStream=\nListenStream=127.0.0.1:4369\nListenStream=[::1]:4369\n' \
        | sudo tee "$epmd_socket_drop_in" >/dev/null

    sudo systemctl daemon-reload
    sudo systemctl stop rabbitmq-server epmd.service epmd.socket || true
    for unit in rabbitmq-server epmd.service epmd.socket; do
        sudo systemctl is-active --quiet "$unit" \
            && die "Could not stop ${unit} before rebinding EPMD"
    done
    sudo systemctl enable --now epmd.socket
    sudo systemctl enable rabbitmq-server
    sudo systemctl restart rabbitmq-server
    sudo systemctl is-active --quiet rabbitmq-server \
        || die "RabbitMQ is not active"
}

verify_rabbitmq_loopback() {
    local port

    for port in 5672 25672 4369; do
        sudo ss -H -ltn | awk -v port=":${port}" '
            $4 ~ (port "$" ) {
                found = 1
                if ($4 !~ /^127\.0\.0\.1:/ && $4 !~ /^\[::1\]:/) {
                    public = 1
                }
            }
            END { exit !(found && !public) }
        ' || die "RabbitMQ listener ${port} is not loopback-only"
    done
}

grant_web_traverse() {
    local path="$APP_DIR"

    while [[ "$path" != "/" ]]; do
        sudo setfacl -m u:www-data:--x "$path"
        path="$(dirname "$path")"
    done
}

install_nodesource_repository() {
    local keyring=/usr/share/keyrings/nodesource.gpg
    local fingerprints

    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
        | gpg --dearmor | sudo tee "$keyring" >/dev/null
    fingerprints="$(gpg --no-default-keyring --keyring "$keyring" --with-colons --fingerprint 2>/dev/null \
        | awk -F: '$1 == "fpr" { print $10 }')"
    grep -qx '6F71F525282841EEDAF851B42F59B5F99B1BE0B4' <<< "$fingerprints" \
        || die "NodeSource signing key fingerprint check failed"
    printf 'deb [signed-by=%s] https://deb.nodesource.com/node_24.x nodistro main\n' "$keyring" \
        | sudo tee /etc/apt/sources.list.d/nodesource.list >/dev/null
    apt_get_with_lock_wait update
}

install_pgroonga_repository() {
    local source_package="/tmp/groonga-apt-source-${VERSION_CODENAME}.deb"
    local source_url="https://packages.groonga.org/ubuntu/groonga-apt-source-latest-${VERSION_CODENAME}.deb"

    log "Installing the Groonga APT source for PostgreSQL ${PG_MAJOR}..."
    rm -f "$source_package"
    wget -q -O "$source_package" "$source_url" \
        || die "Could not download the Groonga APT source package for Ubuntu ${VERSION_CODENAME}"
    apt_get_with_lock_wait install -y -V "$source_package" \
        || die "Could not install the Groonga APT source package"
    rm -f "$source_package"

    if [[ "$VERSION_ID" == "26.04" ]]; then
        apt_get_with_lock_wait install -y postgresql-common
        sudo /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y \
            || die "Could not configure the PostgreSQL Global Development Group repository"
    fi

    apt_get_with_lock_wait update
}

validate_ubuntu_database_packages() {
    local package

    for package in \
        "postgresql-${PG_MAJOR}" \
        "postgresql-client-${PG_MAJOR}" \
        "$PGROONGA_PACKAGE"; do
        apt_package_available "$package" \
            || die "Required official Ubuntu package is unavailable: ${package} (Ubuntu ${VERSION_ID}); refusing a source build"
    done
}

ensure_jin_repository() {
    sudo install -d -m 0755 "$APP_DIR"
    sudo chown -R "$DEPLOY_USER":"$DEPLOY_USER" "$APP_DIR"

    if [[ -d "$APP_DIR/.git" ]]; then
        log "Updating Jin with fast-forward-only Git"
        run_as_deploy git -C "$APP_DIR" pull --ff-only
        return 0
    fi

    if [[ -n "$(find "$APP_DIR" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]]; then
        die "Refusing to clone into a non-empty directory without a Git repository: $APP_DIR"
    fi

    log "Cloning Jin from $REPO_URL"
    if ! run_as_deploy git clone "$REPO_URL" "$APP_DIR"; then
        if [[ "$REPO_URL" == git@github.com:* ]]; then
            local https_repo="https://github.com/${REPO_URL#git@github.com:}"
            warn "SSH clone failed; retrying over HTTPS"
            run_as_deploy git clone "$https_repo" "$APP_DIR"
        else
            die "Could not clone Jin repository: $REPO_URL"
        fi
    fi
}

prepare_jin_update() {
    if [[ -f "$APP_DIR/artisan" ]]; then
        if run_as_deploy php "$APP_DIR/artisan" down --render=errors::503 >/dev/null 2>&1; then
            APP_MAINTENANCE=1
        fi
    fi

    if sudo systemctl is-active --quiet supervisor 2>/dev/null; then
        sudo supervisorctl reread >/dev/null 2>&1 || true
        sudo supervisorctl update >/dev/null 2>&1 || true
        sudo supervisorctl stop 'jin-queue:*' jin-reverb jin-scheduler >/dev/null 2>&1 || true
        APP_PROCESSES_STOPPED=1
    fi
}

jin_nginx_privileged() {
    if [[ "$JIN_NGINX_USE_SUDO" -eq 1 ]]; then
        sudo "$@"
    else
        "$@"
    fi
}

snapshot_jin_nginx_entry() {
    local label="$1"
    local path="$2"
    local backup_dir="$3"

    if [[ -L "$path" ]]; then
        printf 'symlink\n' > "$backup_dir/${label}.type"
        jin_nginx_privileged cp -d -- "$path" "$backup_dir/${label}.state"
    elif [[ -f "$path" ]]; then
        printf 'file\n' > "$backup_dir/${label}.type"
        jin_nginx_privileged cp -d -- "$path" "$backup_dir/${label}.state"
    elif [[ ! -e "$path" ]]; then
        printf 'absent\n' > "$backup_dir/${label}.type"
    else
        warn "Refusing to snapshot unsupported Nginx path type: $path"
        return 1
    fi
}

snapshot_jin_nginx_state() {
    local backup_dir="${1:-}"
    local site="${2:-$JIN_NGINX_SITE_PATH}"
    local enabled="${3:-$JIN_NGINX_ENABLED_PATH}"
    local default_link="${4:-$JIN_NGINX_DEFAULT_PATH}"
    local created=0

    if [[ -z "$backup_dir" ]]; then
        backup_dir="$(mktemp -d "${TMPDIR:-/tmp}/jin-nginx-backup.XXXXXX")" || return 1
        created=1
    else
        [[ "$backup_dir" == /* ]] || return 1
        [[ ! -e "$backup_dir" && ! -L "$backup_dir" ]] || return 1
        (umask 077 && mkdir -m 0700 -- "$backup_dir") || return 1
    fi

    if [[ "$created" -eq 0 ]]; then
        chmod 0700 -- "$backup_dir" || return 1
    fi
    [[ -d "$backup_dir" && ! -L "$backup_dir" ]] || return 1
    printf 'jin-nginx-backup-v1\n' > "$backup_dir/.jin-nginx-backup"

    if ! snapshot_jin_nginx_entry site "$site" "$backup_dir" \
        || ! snapshot_jin_nginx_entry enabled "$enabled" "$backup_dir" \
        || ! snapshot_jin_nginx_entry default "$default_link" "$backup_dir"; then
        rm -f -- \
            "$backup_dir/.jin-nginx-backup" \
            "$backup_dir/site.type" "$backup_dir/site.state" \
            "$backup_dir/enabled.type" "$backup_dir/enabled.state" \
            "$backup_dir/default.type" "$backup_dir/default.state"
        rmdir -- "$backup_dir" 2>/dev/null || true
        return 1
    fi

    JIN_NGINX_BACKUP_DIR="$backup_dir"
    JIN_NGINX_BACKUP_READY=1
}

remove_jin_nginx_path() {
    local path="$1"

    if [[ -L "$path" || -f "$path" ]]; then
        jin_nginx_privileged rm -f -- "$path"
    elif [[ ! -e "$path" ]]; then
        return 0
    else
        warn "Refusing to replace unsupported Nginx path type during rollback: $path"
        return 1
    fi
}

restore_jin_nginx_state() {
    local backup_dir="${1:-$JIN_NGINX_BACKUP_DIR}"
    local site="${2:-$JIN_NGINX_SITE_PATH}"
    local enabled="${3:-$JIN_NGINX_ENABLED_PATH}"
    local default_link="${4:-$JIN_NGINX_DEFAULT_PATH}"
    local label path parent state

    [[ -d "$backup_dir" && ! -L "$backup_dir" ]] || return 1
    [[ "$(<"$backup_dir/.jin-nginx-backup")" == jin-nginx-backup-v1 ]] || return 1

    for label in site enabled default; do
        case "$label" in
            site) path="$site" ;;
            enabled) path="$enabled" ;;
            default) path="$default_link" ;;
        esac
        parent="$(dirname -- "$path")"
        [[ -d "$parent" && ! -L "$parent" ]] || return 1
        remove_jin_nginx_path "$path" || return 1
        state="$(<"$backup_dir/${label}.type")"
        case "$state" in
            file|symlink)
                jin_nginx_privileged cp -d -- "$backup_dir/${label}.state" "$path" || return 1
                ;;
            absent)
                ;;
            *)
                return 1
                ;;
        esac
    done
}

discard_jin_nginx_backup() {
    local backup_dir="${1:-$JIN_NGINX_BACKUP_DIR}"

    [[ -n "$backup_dir" ]] || return 0
    [[ -d "$backup_dir" && ! -L "$backup_dir" ]] || return 1
    [[ "$(<"$backup_dir/.jin-nginx-backup")" == jin-nginx-backup-v1 ]] || return 1
    rm -f -- \
        "$backup_dir/.jin-nginx-backup" \
        "$backup_dir/site.type" "$backup_dir/site.state" \
        "$backup_dir/enabled.type" "$backup_dir/enabled.state" \
        "$backup_dir/default.type" "$backup_dir/default.state"
    rmdir -- "$backup_dir" || return 1
    JIN_NGINX_BACKUP_DIR=""
    JIN_NGINX_BACKUP_READY=0
}

diagnose_jin_nginx_failure() {
    local effective_config

    warn "Nginx diagnostics before Jin configuration rollback"
    effective_config="$(mktemp "${TMPDIR:-/tmp}/jin-nginx-effective.XXXXXX")" || return 0
    sudo nginx -T >"$effective_config" 2>&1 || true
    if grep -Fq '# Managed by jin/setup.sh' "$effective_config"; then
        printf 'jin_nginx_effective_config=present\n' >&2
    else
        printf 'jin_nginx_effective_config=absent\n' >&2
    fi
    awk '
        /^# configuration file \/etc\/nginx\// { print }
        /^[[:space:]]*listen[[:space:]]/ { print }
    ' "$effective_config" >&2
    rm -f -- "$effective_config"
    printf 'jin_nginx_enabled_target=' >&2
    sudo readlink -f "$JIN_NGINX_ENABLED_PATH" >&2 || printf 'unresolved\n' >&2
    sudo ss -H -ltnp >&2 || true
    sudo systemctl --no-pager --full status nginx >&2 || true
    if sudo test -f /var/log/nginx/error.log; then
        sudo tail -n 200 /var/log/nginx/error.log >&2 || true
    fi
}

cleanup_jin_deployment() {
    local status=$?

    set +e
    apply_persisted_email_verification_model \
        || warn "Could not restore the configured User email-verification state"
    if [[ "$status" -ne 0 && "$JIN_NGINX_BACKUP_READY" -eq 1 ]]; then
        if [[ "${JIN_SETUP_DIAGNOSTICS:-0}" == 1 ]]; then
            diagnose_jin_nginx_failure
        fi
        if restore_jin_nginx_state; then
            if sudo nginx -t >/dev/null 2>&1; then
                if [[ "$JIN_NGINX_WAS_ACTIVE" -eq 1 ]]; then
                    sudo systemctl reload nginx >/dev/null 2>&1 \
                        || warn "Could not reload Nginx after restoring its Jin state"
                fi
            else
                warn "Restored Jin Nginx state failed nginx -t"
            fi
        else
            warn "Could not restore the Jin-owned Nginx state"
        fi
        discard_jin_nginx_backup \
            || warn "Could not remove the temporary Jin Nginx backup: $JIN_NGINX_BACKUP_DIR"
    fi
    if [[ "$status" -ne 0 && "$APP_PROCESSES_STOPPED" -eq 1 ]]; then
        sudo supervisorctl reread >/dev/null 2>&1 || true
        sudo supervisorctl update >/dev/null 2>&1 || true
        sudo supervisorctl start 'jin-queue:*' jin-reverb jin-scheduler >/dev/null 2>&1 || true
    fi
    if [[ "$APP_MAINTENANCE" -eq 1 && -f "${APP_DIR:-}/artisan" ]]; then
        run_as_deploy php "$APP_DIR/artisan" up >/dev/null 2>&1 || true
    fi
    [[ -z "$SUDO_KEEPALIVE_PID" ]] || kill "$SUDO_KEEPALIVE_PID" 2>/dev/null || true
    trap - EXIT
    return "$status"
}

resolve_ubuntu_deployment_values() {
    local existing_document_url existing_internal_url upload_max_file_bytes

    valid_port "$ONLYOFFICE_PORT" || die "Invalid ONLYOFFICE_PORT: $ONLYOFFICE_PORT"
    valid_port "$APP_INTERNAL_PORT" || die "Invalid APP_INTERNAL_PORT: $APP_INTERNAL_PORT"
    valid_hostname "$APP_DOMAIN" || {
        [[ "$NO_SSL" -eq 1 ]] && valid_local_hostname "$APP_DOMAIN" \
            || die "Invalid application domain: $APP_DOMAIN"
    }

    existing_document_url="$(read_env ONLYOFFICE_DOCUMENT_SERVER_URL "$ENV_FILE")"
    if [[ "$PORT_EXPLICIT" -eq 0 && "$existing_document_url" =~ ^http://127\.0\.0\.1:([0-9]+)$ ]]; then
        ONLYOFFICE_PORT="${BASH_REMATCH[1]}"
        valid_port "$ONLYOFFICE_PORT" || die "Invalid existing ONLYOFFICE_DOCUMENT_SERVER_URL port"
    fi

    existing_internal_url="$(read_env APP_ONLYOFFICE_INTERNAL_URL "$ENV_FILE")"
    if [[ "$INTERNAL_PORT_EXPLICIT" -eq 0 && "$existing_internal_url" =~ ^http://127\.0\.0\.1:([0-9]+)$ ]]; then
        APP_INTERNAL_PORT="${BASH_REMATCH[1]}"
        valid_port "$APP_INTERNAL_PORT" || die "Invalid existing APP_ONLYOFFICE_INTERNAL_URL port"
    fi

    PUBLIC_SCHEME=https
    PUBLIC_PORT=443
    if [[ "$NO_SSL" -eq 1 ]]; then
        PUBLIC_SCHEME=http
        PUBLIC_PORT=80
    fi

    TARGET_DOCUMENT_SERVER_URL="http://127.0.0.1:${ONLYOFFICE_PORT}"
    TARGET_PUBLIC_URL="${PUBLIC_SCHEME}://${APP_DOMAIN}${ONLYOFFICE_PUBLIC_PATH}"
    TARGET_INTERNAL_URL="http://127.0.0.1:${APP_INTERNAL_PORT}"

    UPLOAD_MAX_FILE_MB="${UPLOAD_MAX_FILE_MB:-$(read_env UPLOAD_MAX_FILE_MB "$ENV_FILE")}"
    UPLOAD_MAX_REQUEST_MB="${UPLOAD_MAX_REQUEST_MB:-$(read_env UPLOAD_MAX_REQUEST_MB "$ENV_FILE")}"
    UPLOAD_MAX_FILE_MB="${UPLOAD_MAX_FILE_MB:-200}"
    UPLOAD_MAX_REQUEST_MB="${UPLOAD_MAX_REQUEST_MB:-500}"
    valid_upload_max_megabytes "$UPLOAD_MAX_FILE_MB" \
        || die "UPLOAD_MAX_FILE_MB must be a positive integer no larger than ${MAX_SAFE_FILE_SIZE_MB}"
    valid_upload_max_megabytes "$UPLOAD_MAX_REQUEST_MB" \
        || die "UPLOAD_MAX_REQUEST_MB must be a positive integer no larger than ${MAX_SAFE_FILE_SIZE_MB}"
    upload_max_file_bytes="$(( $(normalize_decimal "$UPLOAD_MAX_FILE_MB") * 1024 * 1024 ))"

    MAX_FILE_SIZE_BYTES="${MAX_FILE_SIZE_BYTES:-$(read_env ONLYOFFICE_MAX_FILE_SIZE_BYTES "$ENV_FILE")}"
    MAX_FILE_SIZE_BYTES="${MAX_FILE_SIZE_BYTES:-$upload_max_file_bytes}"
    TARGET_MAX_DOWNLOAD_BYTES="${TARGET_MAX_DOWNLOAD_BYTES:-$(read_env ONLYOFFICE_DOCUMENT_SERVER_MAX_DOWNLOAD_BYTES "$ENV_FILE")}"
    TARGET_MAX_DOWNLOAD_BYTES="${TARGET_MAX_DOWNLOAD_BYTES:-$MAX_FILE_SIZE_BYTES}"
    valid_positive_integer "$MAX_FILE_SIZE_BYTES" \
        || die "ONLYOFFICE_MAX_FILE_SIZE_BYTES must be a positive integer"
    valid_positive_integer "$TARGET_MAX_DOWNLOAD_BYTES" \
        || die "ONLYOFFICE_DOCUMENT_SERVER_MAX_DOWNLOAD_BYTES must be a positive integer"
    MAX_FILE_SIZE_BYTES="$(normalize_decimal "$MAX_FILE_SIZE_BYTES")"
    TARGET_MAX_DOWNLOAD_BYTES="$(normalize_decimal "$TARGET_MAX_DOWNLOAD_BYTES")"
    if (( MAX_FILE_SIZE_BYTES > upload_max_file_bytes )); then
        die "ONLYOFFICE_MAX_FILE_SIZE_BYTES must not exceed UPLOAD_MAX_FILE_MB"
    fi
    if (( TARGET_MAX_DOWNLOAD_BYTES < MAX_FILE_SIZE_BYTES )); then
        TARGET_MAX_DOWNLOAD_BYTES="$MAX_FILE_SIZE_BYTES"
    fi
    resolve_upload_max_files \
        || die "UPLOAD_MAX_FILES must be a positive integer"
}

configure_ubuntu_postgresql() {
    local cluster_line
    local db_credential_file=/etc/jin/database-password
    local db_password_from_env
    local db_password_generated=0
    local role_is_privileged

    sudo systemctl enable --now postgresql
    mapfile -t PG_CLUSTERS < <(pg_lsclusters --no-header)
    if [[ "${#PG_CLUSTERS[@]}" -eq 0 ]]; then
        sudo pg_createcluster "$PG_MAJOR" main --start
        mapfile -t PG_CLUSTERS < <(pg_lsclusters --no-header)
    fi
    [[ "${#PG_CLUSTERS[@]}" -eq 1 ]] \
        || die "Expected exactly one PostgreSQL cluster; found ${#PG_CLUSTERS[@]}"
    cluster_line="${PG_CLUSTERS[0]}"
    read -r PG_VERSION PG_CLUSTER PG_PORT PG_STATUS PG_OWNER PG_DATA PG_LOG <<< "$cluster_line"
    [[ "$PG_VERSION" == "$PG_MAJOR" ]] \
        || die "Expected PostgreSQL ${PG_MAJOR}; found cluster ${PG_VERSION}"
    PG_SERVICE="postgresql@${PG_VERSION}-${PG_CLUSTER}"
    [[ "$PG_STATUS" == online ]] || sudo systemctl start "$PG_SERVICE"
    sudo -u postgres pg_isready -p "$PG_PORT" -q \
        || die "PostgreSQL is not ready on port $PG_PORT"

    dpkg-query -W -f='${db:Status-Status}' "$PGROONGA_PACKAGE" 2>/dev/null | grep -qx installed \
        || die "PGroonga package is not installed: $PGROONGA_PACKAGE"

    db_password_from_env="$(read_env DB_PASSWORD "$ENV_FILE")"
    is_placeholder_value "$db_password_from_env" && db_password_from_env=""
    DB_PASSWORD="${DB_PASSWORD:-$db_password_from_env}"
    if [[ -z "$DB_PASSWORD" ]] && sudo test -r "$db_credential_file"; then
        DB_PASSWORD="$(sudo sh -c "tr -d '\\r\\n' < '$db_credential_file'")"
        is_placeholder_value "$DB_PASSWORD" && DB_PASSWORD=""
    fi
    if [[ -z "$DB_PASSWORD" ]] && setup_input_is_interactive; then
        read -r -s -p "PostgreSQL password (leave blank to generate): " DB_PASSWORD
        printf '\n'
    fi
    if [[ -z "$DB_PASSWORD" ]]; then
        DB_PASSWORD="$(openssl rand -hex 32)"
        db_password_generated=1
    fi
    [[ "$DB_PASSWORD" != *$'\n'* && "$DB_PASSWORD" != *$'\r'* ]] \
        || die "PostgreSQL password cannot contain newlines"
    sudo install -d -o root -g root -m 0700 /etc/jin
    printf '%s\n' "$DB_PASSWORD" | sudo tee "$db_credential_file" >/dev/null
    sudo chown root:root "$db_credential_file"
    sudo chmod 0600 "$db_credential_file"
    if [[ "$db_password_generated" -eq 1 ]]; then
        log "Generated PostgreSQL password saved to $db_credential_file (root-only; view with: sudo cat $db_credential_file)"
    fi

    configure_deploy_postgresql_peer_authentication
    ensure_deploy_postgresql_role

    role_is_privileged="$(sudo -u postgres psql -p "$PG_PORT" --set=db_user="$DB_USER" -Atq postgres <<'SQL'
SELECT EXISTS (
    SELECT 1 FROM pg_roles
    WHERE rolname = :'db_user'
      AND (rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls)
);
SQL
    )"
    [[ "$role_is_privileged" != "t" ]] || die "Refusing to use privileged PostgreSQL role: $DB_USER"

    sudo -u postgres env JIN_DB_PASSWORD="$DB_PASSWORD" psql -p "$PG_PORT" \
        --set=ON_ERROR_STOP=1 --set=db_name="$DB_NAME" --set=db_user="$DB_USER" postgres <<'SQL'
\getenv db_password JIN_DB_PASSWORD
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'db_user', :'db_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'db_user') \gexec
SELECT format('ALTER ROLE %I WITH LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS', :'db_user', :'db_password') \gexec
SELECT format('CREATE DATABASE %I OWNER %I ENCODING %L TEMPLATE template0', :'db_name', :'db_user', 'UTF8')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'db_name') \gexec
SELECT format('ALTER DATABASE %I OWNER TO %I', :'db_name', :'db_user') \gexec
SQL
    sudo -u postgres psql -p "$PG_PORT" -d "$DB_NAME" \
        --set=ON_ERROR_STOP=1 -c 'CREATE EXTENSION IF NOT EXISTS pgroonga;'
    PGROONGA_EXTENSION_VERSION="$(sudo -u postgres psql -p "$PG_PORT" -d "$DB_NAME" -Atqc \
        "SELECT extversion FROM pg_extension WHERE extname = 'pgroonga'")"
    [[ -n "$PGROONGA_EXTENSION_VERSION" ]] \
        || die "PGroonga extension could not be created in $DB_NAME"
}

install_composer() {
    local expected_checksum actual_checksum installer=/tmp/jin-composer-setup.php

    if command -v composer >/dev/null 2>&1; then
        return 0
    fi

    log "Installing Composer with checksum verification"
    expected_checksum="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o "$installer"
    actual_checksum="$(php -r "echo hash_file('sha384', '$installer');")"
    [[ "$expected_checksum" == "$actual_checksum" ]] \
        || die "Composer installer checksum mismatch"
    sudo php "$installer" --install-dir=/usr/local/bin --filename=composer
    rm -f "$installer"
}

configure_php_uploads() {
    local ini_file=/etc/php/8.5/fpm/conf.d/99-jin-uploads.ini
    local post_max_size

    post_max_size="$(( $(normalize_decimal "$UPLOAD_MAX_REQUEST_MB") + 12 ))"
    sudo tee "$ini_file" >/dev/null <<PHPINI
upload_max_filesize=${UPLOAD_MAX_FILE_MB}M
post_max_size=${post_max_size}M
max_file_uploads=${UPLOAD_MAX_FILES}
max_input_time=3600
max_execution_time=3600
PHPINI
    sudo systemctl restart php8.5-fpm
}

configure_jin_environment() {
    local app_key reverb_key reverb_secret reverb_id imagemagick_path poppler_path
    local session_secure

    set_env APP_NAME Jin "$ENV_FILE"
    set_env APP_ENV production "$ENV_FILE"
    set_env APP_DEBUG false "$ENV_FILE"
    set_env APP_URL "${PUBLIC_SCHEME}://${APP_DOMAIN}" "$ENV_FILE"
    set_env LOG_LEVEL warning "$ENV_FILE"
    set_env DB_CONNECTION pgsql "$ENV_FILE"
    set_env DB_URL '' "$ENV_FILE"
    set_env DB_HOST 127.0.0.1 "$ENV_FILE"
    set_env DB_PORT "$PG_PORT" "$ENV_FILE"
    set_env DB_DATABASE "$DB_NAME" "$ENV_FILE"
    set_env DB_USERNAME "$DB_USER" "$ENV_FILE"
    set_env DB_PASSWORD "$(dotenv_quote "$DB_PASSWORD")" "$ENV_FILE"
    set_env BROADCAST_CONNECTION reverb "$ENV_FILE"
    set_env QUEUE_CONNECTION redis "$ENV_FILE"
    set_env REDIS_QUEUE default "$ENV_FILE"
    set_env REDIS_QUEUE_RETRY_AFTER 300 "$ENV_FILE"
    set_env CACHE_STORE redis "$ENV_FILE"
    set_env SESSION_DRIVER redis "$ENV_FILE"
    session_secure=false
    [[ "$NO_SSL" -eq 0 ]] && session_secure=true
    set_env SESSION_SECURE_COOKIE "$session_secure" "$ENV_FILE"
    set_env STORED_FILE_CONTENT_ENABLED true "$ENV_FILE"
    set_env STORED_FILE_CONTENT_MARKITDOWN_PATH "$APP_DIR/.venv-file-content/bin/markitdown" "$ENV_FILE"
    imagemagick_path="${IMAGEMAGICK_BINARY:-}"
    if [[ -z "$imagemagick_path" ]]; then
        imagemagick_path="$(resolve_imagemagick_path)" \
            || die "ImageMagick is required; neither magick nor convert is executable"
    fi
    poppler_path="$(command -v pdftoppm)"
    set_env IMAGEMAGICK_PATH "$imagemagick_path" "$ENV_FILE"
    set_env POPPLER_PATH "$poppler_path" "$ENV_FILE"
    set_env UPLOAD_MAX_FILES "$UPLOAD_MAX_FILES" "$ENV_FILE"

    reverb_id="$(read_env REVERB_APP_ID "$ENV_FILE")"
    reverb_key="$(read_env REVERB_APP_KEY "$ENV_FILE")"
    reverb_secret="$(read_env REVERB_APP_SECRET "$ENV_FILE")"
    is_placeholder_value "$reverb_id" && reverb_id="$(generate_production_identifier)"
    is_placeholder_value "$reverb_key" && reverb_key="$(openssl rand -hex 16)"
    is_placeholder_value "$reverb_secret" && reverb_secret="$(openssl rand -hex 24)"
    set_env REVERB_APP_ID "$reverb_id" "$ENV_FILE"
    set_env REVERB_APP_KEY "$reverb_key" "$ENV_FILE"
    set_env REVERB_APP_SECRET "$reverb_secret" "$ENV_FILE"
    set_env REVERB_HOST "$APP_DOMAIN" "$ENV_FILE"
    set_env REVERB_PORT "$PUBLIC_PORT" "$ENV_FILE"
    set_env REVERB_SCHEME "$PUBLIC_SCHEME" "$ENV_FILE"
    set_env REVERB_SERVER_HOST 127.0.0.1 "$ENV_FILE"
    set_env REVERB_SERVER_PORT "$REVERB_SERVER_PORT" "$ENV_FILE"
    set_env REVERB_ALLOWED_ORIGINS "$APP_DOMAIN" "$ENV_FILE"
    set_env VITE_REVERB_APP_KEY "$(dotenv_quote "$reverb_key")" "$ENV_FILE"
    set_env VITE_REVERB_HOST "$(dotenv_quote "$APP_DOMAIN")" "$ENV_FILE"
    set_env VITE_REVERB_PORT "$(dotenv_quote "$PUBLIC_PORT")" "$ENV_FILE"
    set_env VITE_REVERB_SCHEME "$(dotenv_quote "$PUBLIC_SCHEME")" "$ENV_FILE"

    set_env ONLYOFFICE_ENABLED true "$ENV_FILE"
    set_env ONLYOFFICE_DOCUMENT_SERVER_URL "$TARGET_DOCUMENT_SERVER_URL" "$ENV_FILE"
    set_env ONLYOFFICE_PUBLIC_URL "$TARGET_PUBLIC_URL" "$ENV_FILE"
    set_env APP_ONLYOFFICE_INTERNAL_URL "$TARGET_INTERNAL_URL" "$ENV_FILE"
    set_env ONLYOFFICE_JWT_SECRET "$(dotenv_quote "$JWT_SECRET")" "$ENV_FILE"
    set_env ONLYOFFICE_INSTANCE_ID "$INSTANCE_ID" "$ENV_FILE"
    set_env ONLYOFFICE_MAX_FILE_SIZE_BYTES "$MAX_FILE_SIZE_BYTES" "$ENV_FILE"
    set_env ONLYOFFICE_DOCUMENT_SERVER_MAX_DOWNLOAD_BYTES "$DOCUMENT_SERVER_MAX_DOWNLOAD_BYTES" "$ENV_FILE"

    app_key="$(read_env APP_KEY "$ENV_FILE")"
    if [[ -z "$app_key" ]]; then
        run_as_deploy php "$APP_DIR/artisan" key:generate --force
    fi
}

configure_jin_nginx() {
    local site="$JIN_NGINX_SITE_PATH"
    local enabled="$JIN_NGINX_ENABLED_PATH"
    local acme_webroot=/var/www/letsencrypt
    local default_link="$JIN_NGINX_DEFAULT_PATH"
    local request_limit

    snapshot_jin_nginx_state "" "$site" "$enabled" "$default_link" \
        || die "Could not snapshot the app-owned Nginx state"
    JIN_NGINX_CHANGED=1
    if sudo systemctl is-active --quiet nginx 2>/dev/null; then
        JIN_NGINX_WAS_ACTIVE=1
    else
        JIN_NGINX_WAS_ACTIVE=0
    fi

    request_limit="$(( $(normalize_decimal "$UPLOAD_MAX_REQUEST_MB") + 12 ))"
    if sudo test -L "$site"; then
        die "Refusing to overwrite an unmanaged Nginx symlink: $site"
    elif sudo test -e "$site" && ! sudo test -f "$site"; then
        die "Refusing to overwrite an unmanaged Nginx path: $site"
    elif sudo test -f "$site" && ! sudo grep -q 'Managed by jin/setup.sh' "$site"; then
        die "Refusing to overwrite unmanaged nginx configuration: $site"
    fi
    if sudo test -e "$enabled" && ! sudo test -L "$enabled"; then
        die "Refusing to overwrite unmanaged nginx enabled entry: $enabled"
    fi
    if sudo test -L "$default_link" && [[ "$(sudo readlink "$default_link")" == "/etc/nginx/sites-available/default" ]]; then
        sudo rm -f "$default_link"
    elif sudo test -e "$default_link"; then
        die "Refusing to disable unmanaged nginx enabled entry: $default_link"
    fi

    sudo install -d -m 0755 /etc/nginx/sites-available /etc/nginx/sites-enabled
    sudo install -d -o root -g root -m 0755 "$acme_webroot/.well-known/acme-challenge"
    sudo tee "$site" >/dev/null <<NGINX
# Managed by jin/setup.sh. Re-running setup updates this file.
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    location / { return 444; }
}

server {
    listen 80;
    listen [::]:80;
    server_name ${APP_DOMAIN};
    root ${APP_DIR}/public;
    index index.php;
    client_max_body_size ${request_limit}M;
    client_body_timeout 3600s;

    location ^~ /.well-known/acme-challenge/ {
        root ${acme_webroot};
        default_type text/plain;
        try_files \$uri =404;
    }

    location = ${ONLYOFFICE_PUBLIC_PATH} {
        return 301 ${ONLYOFFICE_PUBLIC_PATH}/;
    }

    location ^~ ${ONLYOFFICE_PUBLIC_PATH}/ {
        client_max_body_size ${request_limit}M;
        proxy_pass http://127.0.0.1:${ONLYOFFICE_PORT}/;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-Host \$host${ONLYOFFICE_PUBLIC_PATH};
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 600s;
        proxy_send_timeout 600s;
        proxy_buffering off;
    }

    location ~ ^/(app|apps)/ {
        proxy_pass http://127.0.0.1:${REVERB_SERVER_PORT};
        proxy_http_version 1.1;
        proxy_set_header Host \$http_host;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 120s;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~* \.(?:css|js|jpg|jpeg|png|gif|svg|ico|webp|woff2?|ttf|eot|mp4|webm)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        try_files \$uri =404;
    }

    location ~ ^/index\\.php(/|\$) {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_pass unix:${PHP_FPM_SOCK};
        fastcgi_read_timeout 3600s;
        internal;
    }

    location ~ /\\.(?!well-known).* { deny all; }
}

# ONLYOFFICE retrieves signed originals through this loopback-only listener.
server {
    listen 127.0.0.1:${APP_INTERNAL_PORT};
    server_name localhost;
    root ${APP_DIR}/public;
    index index.php;
    client_max_body_size ${request_limit}M;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ ^/index\\.php(/|\$) {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_pass unix:${PHP_FPM_SOCK};
        fastcgi_read_timeout 3600s;
    }
    location ~ /\\. { deny all; }
}
NGINX
    sudo ln -sfn "$site" "$enabled"
    sudo nginx -t
    sudo systemctl enable --now nginx
    sudo systemctl reload nginx
}

configure_jin_supervisor() {
    sudo tee /etc/supervisor/conf.d/jin-queue.conf >/dev/null <<SUPERVISOR
[program:jin-queue]
process_name=%(program_name)s_%(process_num)02d
directory=${APP_DIR}
command=${PHP_BINARY} artisan queue:work redis --sleep=3 --tries=5 --max-time=3600
numprocs=10
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=300
redirect_stderr=true
stdout_logfile=/var/log/jin-queue_%(process_num)02d.log
SUPERVISOR

    sudo tee /etc/supervisor/conf.d/jin-reverb.conf >/dev/null <<SUPERVISOR
[program:jin-reverb]
directory=${APP_DIR}
command=${PHP_BINARY} artisan reverb:start --host=127.0.0.1 --port=${REVERB_SERVER_PORT}
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=30
redirect_stderr=true
stdout_logfile=/var/log/jin-reverb.log
SUPERVISOR

    sudo tee /etc/supervisor/conf.d/jin-scheduler.conf >/dev/null <<SUPERVISOR
[program:jin-scheduler]
directory=${APP_DIR}
command=${PHP_BINARY} artisan schedule:work
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=60
redirect_stderr=true
stdout_logfile=/var/log/jin-scheduler.log
SUPERVISOR

    sudo systemctl enable --now supervisor
    wait_for_supervisor || die "Supervisor did not expose its control socket"
    sudo supervisorctl reread
    sudo supervisorctl update
    sudo supervisorctl restart 'jin-queue:*' jin-reverb jin-scheduler
}

restrict_ubuntu_onlyoffice_listener() {
    local -a listener_configs local_configs
    local config index

    mapfile -t listener_configs < <(
        sudo grep -RIl --include='*.conf' -E \
            "^[[:space:]]*listen[[:space:]]+((0\\.0\\.0\\.0:)?${ONLYOFFICE_PORT}|\\[::(1)?\\]:${ONLYOFFICE_PORT})([[:space:]].*)?;" \
            /etc/nginx 2>/dev/null || true
    )
    for index in "${!listener_configs[@]}"; do
        config="${listener_configs[$index]}"
        sudo sed -E -i.bak \
            -e "s|^([[:space:]]*)listen[[:space:]]+(0\\.0\\.0\\.0:)?${ONLYOFFICE_PORT}([[:space:]].*)?;[[:space:]]*$|\\1listen 127.0.0.1:${ONLYOFFICE_PORT}\\3;|" \
            -e "/^[[:space:]]*listen[[:space:]]+\\[::(1)?\\]:${ONLYOFFICE_PORT}([[:space:]].*)?;[[:space:]]*$/d" \
            "$config"
        sudo rm -f "$config.bak"
    done
    mapfile -t local_configs < <(
        sudo grep -RIl --include='*.conf' -E \
            "^[[:space:]]*listen[[:space:]]+127\\.0\\.0\\.1:${ONLYOFFICE_PORT}([[:space:]].*)?;" \
            /etc/nginx 2>/dev/null || true
    )
    [[ "${#local_configs[@]}" -gt 0 ]] \
        || die "Could not configure ONLYOFFICE on IPv4 loopback port $ONLYOFFICE_PORT"
}

maintain_onlyoffice_package() {
    local action="$1"

    [[ "$action" == install || "$action" == repair ]] \
        || die "Unsupported ONLYOFFICE package maintenance action: $action"
    sudo env DEBIAN_FRONTEND=noninteractive bash -s -- "$action" <<'ROOT'
set -euo pipefail

action="$1"
sudoers_file=/etc/sudoers.d/documentserver

if [[ "$action" == install ]]; then
    apt-get -o DPkg::Lock::Timeout=300 install -y onlyoffice-documentserver
fi

if [[ -f "$sudoers_file" ]]; then
    if grep -Eq '^[[:space:]]*Defaults:ds[[:space:]]+!requiretty[[:space:]]*$' "$sudoers_file"; then
        cp -a "$sudoers_file" "${sudoers_file}.jin.bak"
        sed -E -i '/^[[:space:]]*Defaults:ds[[:space:]]+!requiretty[[:space:]]*$/d' "$sudoers_file"
    fi

    if ! /usr/sbin/visudo -cf "$sudoers_file" >/dev/null; then
        if [[ -f "${sudoers_file}.jin.bak" ]]; then
            mv -f "${sudoers_file}.jin.bak" "$sudoers_file"
        fi
        exit 1
    fi
    rm -f "${sudoers_file}.jin.bak"
fi
ROOT
}

configure_ubuntu_onlyoffice_deployment() {
    local local_json=/etc/onlyoffice/documentserver/local.json
    local keyring=/usr/share/keyrings/onlyoffice.gpg
    local key_fingerprints

    curl -fsSL https://download.onlyoffice.com/GPG-KEY-ONLYOFFICE \
        | sudo gpg --batch --yes --dearmor -o "$keyring"
    key_fingerprints="$(gpg --no-default-keyring --keyring "$keyring" --with-colons --fingerprint 2>/dev/null \
        | awk -F: '$1 == "fpr" { print $10 }')"
    grep -qx 'E09CA29F6E178040EF22B4098320CA65CB2DE8E5' <<< "$key_fingerprints" \
        || die "ONLYOFFICE signing key fingerprint check failed"
    printf 'deb [signed-by=%s] https://download.onlyoffice.com/repo/debian squeeze main\n' "$keyring" \
        | sudo tee /etc/apt/sources.list.d/onlyoffice.list >/dev/null
    apt_get_with_lock_wait update

    if ! onlyoffice_package_installed; then
        printf 'onlyoffice-documentserver onlyoffice/ds-port select %s\n' "$ONLYOFFICE_PORT" \
            | sudo debconf-set-selections
        printf 'onlyoffice-documentserver onlyoffice/jwt-enabled boolean true\n' \
            | sudo debconf-set-selections
        printf 'onlyoffice-documentserver onlyoffice/jwt-secret password %s\n' "$JWT_SECRET" \
            | sudo debconf-set-selections
        maintain_onlyoffice_package install
    fi

    [[ -f "$local_json" ]] || die "Could not find $local_json after ONLYOFFICE installation"
    TARGET_MAX_DOWNLOAD_BYTES="$TARGET_MAX_DOWNLOAD_BYTES" \
        configure_ubuntu_jwt "$local_json"
    configure_ubuntu_max_download_bytes "$local_json"
    restrict_ubuntu_onlyoffice_listener
    sudo nginx -t
    # A graceful reload cannot narrow an existing wildcard 8081 listener because
    # the old workers retain that socket. Restart Nginx to release it first.
    sudo systemctl restart nginx
    sudo systemctl is-active --quiet nginx \
        || die "Nginx did not restart after restricting ONLYOFFICE to loopback"
    restart_ubuntu_onlyoffice
    wait_for_document_server "http://127.0.0.1:${ONLYOFFICE_PORT}" 90 2 \
        || die "ONLYOFFICE readiness failed on 127.0.0.1:${ONLYOFFICE_PORT}"
}

configure_jin_tls() {
    local acme_webroot=/var/www/letsencrypt
    local token body_file response code
    local -a certbot_args

    if [[ "$NO_SSL" -eq 1 ]]; then
        warn "Skipping Let's Encrypt; HTTP-only deployment requested"
        return 0
    fi

    sudo install -d -m 0755 "$acme_webroot/.well-known/acme-challenge"
    if sudo test -f "/etc/letsencrypt/live/$APP_DOMAIN/fullchain.pem" \
        && sudo test -f "/etc/letsencrypt/live/$APP_DOMAIN/privkey.pem"; then
        sudo certbot install --cert-name "$APP_DOMAIN" --nginx --redirect --non-interactive \
            || die "Certbot could not install the existing certificate for $APP_DOMAIN"
    else
        token="jin-$(openssl rand -hex 12)"
        body_file="$(mktemp "${TMPDIR:-/tmp}/jin-acme-response.XXXXXX")"
        printf '%s\n' "$token" | sudo tee "$acme_webroot/.well-known/acme-challenge/$token" >/dev/null
        sudo chown root:root "$acme_webroot/.well-known/acme-challenge/$token"
        sudo chmod 0644 "$acme_webroot/.well-known/acme-challenge/$token"
        response="$(curl --noproxy '*' --silent --show-error --connect-timeout 5 --max-time 10 \
            --header "Host: $APP_DOMAIN" --output "$body_file" --write-out '%{http_code}' \
            "http://127.0.0.1/.well-known/acme-challenge/$token" || true)"
        code="$response"
        response="$(head -c 512 "$body_file")"
        sudo rm -f "$acme_webroot/.well-known/acme-challenge/$token"
        rm -f "$body_file"
        [[ "$code" == 200 && "$response" == "$token" ]] \
            || die "Nginx ACME webroot preflight failed for $APP_DOMAIN"

        certbot_args=(run --authenticator webroot --installer nginx --webroot-path "$acme_webroot" \
            -d "$APP_DOMAIN" --redirect --non-interactive --agree-tos)
        if [[ -n "$EMAIL" ]]; then
            certbot_args+=(--email "$EMAIL")
        else
            certbot_args+=(--register-unsafely-without-email)
        fi
        sudo certbot "${certbot_args[@]}" \
            || die "Certbot failed; verify DNS and TCP port 80 for $APP_DOMAIN"
    fi
    sudo systemctl reload nginx
    sudo systemctl enable --now certbot.timer
}

verify_jin_deployment() {
    local queue_status queue_running office_health
    local markitdown_path

    sudo nginx -t
    sudo systemctl is-active --quiet nginx
    sudo systemctl is-active --quiet php8.5-fpm
    sudo systemctl is-active --quiet postgresql
    sudo systemctl is-active --quiet redis-server
    sudo systemctl is-active --quiet rabbitmq-server
    sudo systemctl is-active --quiet supervisor
    sudo -u postgres pg_isready -p "$PG_PORT" -q
    [[ "$(redis-cli --raw ping)" == PONG ]] || die "Redis did not respond to ping"
    verify_rabbitmq_loopback
    markitdown_path="$APP_DIR/.venv-file-content/bin/markitdown"
    [[ -x "$markitdown_path" ]] || die "MarkItDown executable was not installed: $markitdown_path"
    "$IMAGEMAGICK_BINARY" -version >/dev/null 2>&1 \
        || die "ImageMagick could not be executed: $IMAGEMAGICK_BINARY"
    wait_for_document_server "http://127.0.0.1:${ONLYOFFICE_PORT}" 30 2 \
        || die "ONLYOFFICE final readiness check failed"

    queue_status="$(sudo supervisorctl status 'jin-queue:*')"
    queue_running="$(printf '%s\n' "$queue_status" | awk '$2 == "RUNNING" { count++ } END { print count + 0 }')"
    [[ "$queue_running" -eq 10 ]] || die "Expected 10 running Jin queue workers, found $queue_running"
    sudo supervisorctl status jin-reverb jin-scheduler >/dev/null

    if [[ "$NO_SSL" -eq 0 ]]; then
        curl --resolve "$APP_DOMAIN:443:127.0.0.1" --connect-timeout 5 --max-time 15 \
            -fsS -o /dev/null "https://${APP_DOMAIN}/up" \
            || die "Application HTTPS health check failed"
        office_health="$(curl --resolve "$APP_DOMAIN:443:127.0.0.1" --connect-timeout 5 --max-time 15 \
            -fsS "https://${APP_DOMAIN}${ONLYOFFICE_PUBLIC_PATH}/healthcheck")" \
            || die "ONLYOFFICE public HTTPS health request failed"
    else
        curl -H "Host: $APP_DOMAIN" --connect-timeout 5 --max-time 15 \
            -fsS -o /dev/null "http://127.0.0.1/up" \
            || die "Application HTTP health check failed"
        office_health="$(curl -H "Host: $APP_DOMAIN" --connect-timeout 5 --max-time 15 \
            -fsS "http://127.0.0.1${ONLYOFFICE_PUBLIC_PATH}/healthcheck")" \
            || die "ONLYOFFICE public HTTP health request failed"
    fi
    grep -Eiq 'true|ok' <<< "$office_health" \
        || die "Unexpected ONLYOFFICE public health response"
    if [[ "$NO_SSL" -eq 0 ]]; then
        curl --resolve "$APP_DOMAIN:443:127.0.0.1" --connect-timeout 5 --max-time 15 \
            -fsS -o /dev/null "https://${APP_DOMAIN}${ONLYOFFICE_PUBLIC_PATH}/web-apps/apps/api/documents/api.js" \
            || die "ONLYOFFICE editor API is not reachable through ${ONLYOFFICE_PUBLIC_PATH}"
    else
        curl -H "Host: $APP_DOMAIN" --connect-timeout 5 --max-time 15 \
            -fsS -o /dev/null "http://127.0.0.1${ONLYOFFICE_PUBLIC_PATH}/web-apps/apps/api/documents/api.js" \
            || die "ONLYOFFICE editor API is not reachable through ${ONLYOFFICE_PUBLIC_PATH}"
    fi

    if [[ "$NO_SSL" -eq 0 && "$(read_env MAIL_MAILER "$ENV_FILE")" != log && "$(read_env MAIL_MAILER "$ENV_FILE")" != array ]]; then
        run_as_deploy php "$APP_DIR/artisan" app:production-check \
            || die "Jin production configuration check failed"
    else
        warn "Skipping app:production-check until HTTPS and external SMTP settings are supplied"
    fi
    log "Verified Jin, Nginx, PHP-FPM, PostgreSQL/PGroonga, Redis, RabbitMQ, Supervisor, and ONLYOFFICE"
}

setup_ubuntu() {
    local architecture processor_count memory_mb swap_mb available_disk_mb
    local -a php_packages postgres_packages
    local env_file_created=0 php_version_id

    announce_passwordless_sudo_option
    if [[ "$(id -u)" -eq 0 ]]; then
        command -v runuser >/dev/null || die "runuser is required for direct root execution"
    else
        command -v sudo >/dev/null || die "sudo is required"
        if [[ "$SUDO_NOPASSWD" != "1" ]]; then
            sudo -v || die "sudo authentication failed"
        fi
    fi
    [[ -f /etc/os-release ]] || die "Could not identify the operating system"
    . /etc/os-release
    [[ "${ID:-}" == ubuntu ]] || die "This Linux setup supports Ubuntu only"
    set_ubuntu_package_matrix \
        || die "Unsupported Ubuntu version: ${VERSION_ID:-unknown} (supported: 24.04, 26.04)"
    maintain_onlyoffice_package repair \
        || die "Could not repair the ONLYOFFICE sudoers configuration"
    VERSION_CODENAME="${VERSION_CODENAME:-$(lsb_release --codename --short 2>/dev/null || true)}"
    architecture="$(dpkg --print-architecture)"
    [[ "$architecture" == amd64 ]] \
        || die "ONLYOFFICE DocumentServer APT setup requires amd64; detected $architecture"
    processor_count="$(getconf _NPROCESSORS_ONLN)"
    memory_mb="$(awk '/MemTotal:/ { print int($2 / 1024); exit }' /proc/meminfo)"
    swap_mb="$(awk '/SwapTotal:/ { print int($2 / 1024); exit }' /proc/meminfo)"
    available_disk_mb="$(df -Pm /var | awk 'NR == 2 { print $4 }')"
    [[ "$processor_count" =~ ^[0-9]+$ && "$processor_count" -ge 2 ]] \
        || die "At least 2 CPU cores are required for ONLYOFFICE"
    [[ "$memory_mb" =~ ^[0-9]+$ && "$memory_mb" -ge 2048 ]] \
        || die "At least 2 GB RAM is required for ONLYOFFICE"
    [[ "$available_disk_mb" =~ ^[0-9]+$ ]] \
        || die "Could not determine free disk space under /var"
    (( available_disk_mb >= 30720 )) \
        || die "At least 30 GB free space under /var is required; detected $((available_disk_mb / 1024)) GB"
    (( available_disk_mb >= 40960 )) \
        || warn "ONLYOFFICE officially recommends at least 40 GB free space; continuing with $((available_disk_mb / 1024)) GB"
    (( swap_mb >= 4096 )) \
        || warn "ONLYOFFICE recommends at least 4 GB swap; detected ${swap_mb:-0} MB"

    DEPLOY_USER="${DEPLOY_USER:-$(id -un)}"
    id "$DEPLOY_USER" >/dev/null 2>&1 || die "Deployment user does not exist: $DEPLOY_USER"
    valid_postgresql_identifier "$DEPLOY_USER" \
        || die "Deployment user must be a valid PostgreSQL role name: $DEPLOY_USER"
    [[ "$DB_USER" != "$DEPLOY_USER" ]] \
        || die "DB_USER must differ from DEPLOY_USER because the deployment role is a PostgreSQL superuser"
    resolve_ubuntu_operator_inputs
    valid_postgresql_identifier "$DB_USER" || die "Invalid PostgreSQL role name: $DB_USER"
    valid_postgresql_identifier "$DB_NAME" || die "Invalid PostgreSQL database name: $DB_NAME"
    [[ "$DB_USER" != postgres && "$DB_NAME" != postgres && "$DB_NAME" != template0 && "$DB_NAME" != template1 ]] \
        || die "Reserved PostgreSQL role or database name"
    canonicalize_ubuntu_app_dir \
        || die "--app-dir must resolve under /var/www or /home and not escape through a symlink"
    validate_ubuntu_repository_target \
        || die "The setup script must be run from a cloned Git repository"
    validate_ubuntu_env_file \
        || die "Ubuntu --env-file is confined to exactly ${APP_DIR}/.env"
    [[ "$REPO_URL" != *$'\n'* && "$REPO_URL" != *$'\r'* && "$REPO_URL" != *[[:space:]]* ]] \
        || die "--repo cannot contain whitespace or newlines"
    [[ -z "$OFFICE_DOMAIN" ]] \
        || die "Ubuntu deployment uses the Jin origin and ${ONLYOFFICE_PUBLIC_PATH}; do not pass --office-domain"
    [[ "$CONFIGURE_NGINX" != 0 ]] \
        || die "Ubuntu deployment requires Nginx; remove --no-nginx"

    if [[ "$(id -u)" -ne 0 && "$SUDO_NOPASSWD" != "1" ]]; then
        (
            while sleep 60; do sudo -n -v || exit; done
        ) &
        SUDO_KEEPALIVE_PID=$!
    fi
    umask 022
    JIN_NGINX_USE_SUDO=1
    trap cleanup_jin_deployment EXIT

    log "Ubuntu ${VERSION_ID} (${VERSION_CODENAME}) on ${architecture}"
    log "Installing Jin runtime packages"
    apt_get_with_lock_wait update
    apt_get_with_lock_wait install -y \
        ca-certificates curl gnupg lsb-release software-properties-common ubuntu-keyring wget
    sudo add-apt-repository -y --no-update universe
    apt_get_with_lock_wait update
    apt_get_with_lock_wait install -y nginx-extras

    install_pgroonga_repository
    validate_ubuntu_database_packages
    postgres_packages=(
        "postgresql-${PG_MAJOR}" "postgresql-client-${PG_MAJOR}" "$PGROONGA_PACKAGE"
    )
    apt_get_with_lock_wait install -y \
        apt-transport-https git unzip zip rsync acl jq openssl supervisor certbot python3-certbot-nginx \
        unattended-upgrades python3 python3-venv build-essential pkg-config iproute2 \
        "${postgres_packages[@]}" redis-server rabbitmq-server \
        imagemagick poppler-utils ghostscript fonts-dejavu-core fonts-liberation fonts-noto-cjk

    echo "ttf-mscorefonts-installer msttcorefonts/accepted-mscorefonts-eula select true" \
        | sudo debconf-set-selections
    apt_get_with_lock_wait install -y ttf-mscorefonts-installer \
        || warn "Microsoft core fonts could not be installed; continuing with open fonts"

    IMAGEMAGICK_BINARY="$(resolve_imagemagick_path)" \
        || die "ImageMagick was installed but neither magick nor convert is executable"
    "$IMAGEMAGICK_BINARY" -version >/dev/null 2>&1 \
        || die "ImageMagick could not be executed: $IMAGEMAGICK_BINARY"
    log "ImageMagick executable: $IMAGEMAGICK_BINARY"

    dpkg-query -W -f='${db:Status-Status}' nginx-extras 2>/dev/null | grep -qx installed \
        || die "Ubuntu nginx-extras was not installed"
    NGINX_VERSION="$(nginx -v 2>&1 | cut -d/ -f2)"
    nginx -V 2>&1 | grep -q -- '--with-http_secure_link_module' \
        || die "The installed nginx build lacks the http_secure_link module required by ONLYOFFICE"
    log "Using Ubuntu nginx-extras (nginx $NGINX_VERSION)"
    sudo systemctl enable --now redis-server
    configure_rabbitmq
    verify_rabbitmq_loopback
    sudo systemctl enable --now supervisor
    wait_for_supervisor || die "Supervisor did not expose its control socket before ONLYOFFICE installation"

    if [[ -d "$APP_DIR/.git" ]]; then
        prepare_jin_update
        restore_user_email_verification_for_update
    fi
    ensure_jin_repository
    APP_DIR="$(CDPATH= cd -- "$APP_DIR" && pwd -P)"
    ENV_FILE="$APP_DIR/.env"
    if [[ ! -f "$ENV_FILE" ]]; then
        [[ -f "$APP_DIR/.env.example" ]] || die "Could not find $ENV_FILE or $APP_DIR/.env.example"
        run_as_deploy cp "$APP_DIR/.env.example" "$ENV_FILE"
        env_file_created=1
    fi
    sudo chmod o-rwx "$ENV_FILE"
    resolve_ubuntu_access_and_locale "$env_file_created"
    configure_access_and_locale_environment
    resolve_email_verification
    configure_email_verification

    DB_NAME="${DB_NAME:-jin}"
    DB_USER="${DB_USER:-jin}"
    resolve_ubuntu_instance_id
    [[ "$INSTANCE_ID" =~ ^[A-Za-z0-9._-]{1,64}$ ]] || die "Invalid ONLYOFFICE_INSTANCE_ID"
    resolve_ubuntu_jwt_secret
    ONLYOFFICE_PORT="${ONLYOFFICE_PORT:-8081}"
    APP_INTERNAL_PORT="${APP_INTERNAL_PORT:-8090}"
    REVERB_SERVER_PORT="${REVERB_SERVER_PORT:-8080}"
    resolve_ubuntu_deployment_values
    validate_jwt_secret

    configure_ubuntu_postgresql
    configure_ubuntu_onlyoffice_deployment
    configure_rabbitmq
    verify_rabbitmq_loopback

    case "$VERSION_ID" in
        24.04)
            log "Enabling the maintained PHP repository for PHP 8.5 on Ubuntu $VERSION_ID"
            sudo add-apt-repository -y --no-update ppa:ondrej/php
            apt_get_with_lock_wait update
            ;;
        26.04)
            log "Using Ubuntu packages for PHP 8.5 on Ubuntu $VERSION_ID"
            ;;
    esac
    php_packages=(
        php8.5-cli php8.5-fpm php8.5-common php8.5-curl php8.5-mbstring
        php8.5-xml php8.5-zip php8.5-bcmath php8.5-intl
        php8.5-pgsql php8.5-gd php8.5-redis
    )
    apt_package_available php8.5-opcache && php_packages+=(php8.5-opcache)
    apt_get_with_lock_wait install -y "${php_packages[@]}"
    sudo update-alternatives --install /usr/bin/php php /usr/bin/php8.5 85
    sudo update-alternatives --set php /usr/bin/php8.5
    PHP_BINARY=/usr/bin/php8.5
    PHP_FPM_SOCK=/run/php/php8.5-fpm.sock
    php_version_id="$($PHP_BINARY -r 'echo PHP_VERSION_ID;')"
    (( php_version_id >= 80500 )) || die "PHP 8.5 or newer is required"
    [[ "$($PHP_BINARY -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" == 8.5 ]] \
        || die "PHP CLI was not switched to /usr/bin/php8.5"
    for extension in curl mbstring xml zip bcmath intl pgsql gd redis; do
        "$PHP_BINARY" -r "exit(extension_loaded('$extension') ? 0 : 1);" \
            || die "PHP 8.5 extension is missing: $extension"
    done
    "$PHP_BINARY" -r 'exit(in_array("pgsql", PDO::getAvailableDrivers(), true) ? 0 : 1);' \
        || die "PHP 8.5 PDO PostgreSQL driver is missing"
    sudo systemctl enable --now php8.5-fpm
    sudo systemctl is-active --quiet php8.5-fpm || die "PHP 8.5 FPM is not active"
    [[ -S "$PHP_FPM_SOCK" ]] || die "PHP-FPM socket was not created at $PHP_FPM_SOCK"

    install_composer
    composer --version
    if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1 \
        || ! node_major_at_least_24 \
        || ! grep -Fq 'https://deb.nodesource.com/node_24.x nodistro main' /etc/apt/sources.list.d/nodesource.list 2>/dev/null; then
        install_nodesource_repository
        apt_get_with_lock_wait install -y nodejs
    fi
    node_major_at_least_24 \
        || die "Node.js 24 or newer is required"
    node --version
    npm --version

    prepare_jin_update
    log "Installing production PHP dependencies"
    (cd "$APP_DIR" && run_as_deploy composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader)
    log "Installing the pinned MarkItDown environment"
    run_as_deploy python3 -m venv --upgrade-deps "$APP_DIR/.venv-file-content"
    run_as_deploy "$APP_DIR/.venv-file-content/bin/python" -m pip install --requirement "$APP_DIR/requirements-file-content.txt"
    configure_jin_environment
    configure_php_uploads

    log "Building Jin frontend"
    (cd "$APP_DIR" && run_as_deploy npm ci --no-audit --no-fund)
    (cd "$APP_DIR" && run_as_deploy npm run build)
    (cd "$APP_DIR" && run_as_deploy php artisan optimize:clear)
    (cd "$APP_DIR" && run_as_deploy php artisan migrate --force)
    (cd "$APP_DIR" && run_as_deploy php artisan optimize)

    log "Securing Jin application paths"
    sudo chown -R "$DEPLOY_USER":www-data "$APP_DIR"
    sudo chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
    sudo chmod -R ug+rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
    sudo setfacl -R -m "u:${DEPLOY_USER}:rwx,u:www-data:rwx" \
        -m "d:u:${DEPLOY_USER}:rwx,d:u:www-data:rwx" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
    sudo chown "$DEPLOY_USER":www-data "$ENV_FILE"
    sudo chmod 0640 "$ENV_FILE"
    grant_web_traverse

    configure_jin_nginx
    configure_jin_supervisor
    configure_jin_tls
    if [[ "$APP_MAINTENANCE" -eq 1 ]]; then
        run_as_deploy php "$APP_DIR/artisan" up
        APP_MAINTENANCE=0
    fi
    APP_PROCESSES_STOPPED=0
    verify_jin_deployment
    discard_jin_nginx_backup \
        || die "Could not discard the temporary Jin Nginx backup"
    trap - EXIT
    [[ -z "$SUDO_KEEPALIVE_PID" ]] || kill "$SUDO_KEEPALIVE_PID" 2>/dev/null || true
    log "Provisioning complete. Jin: ${PUBLIC_SCHEME}://${APP_DOMAIN}; ONLYOFFICE: ${TARGET_PUBLIC_URL}"
}

usage() {
    cat <<'EOF'
Usage: ./setup.sh [options]

Provision and deploy Jin on Ubuntu Server, or start the existing macOS Apple
Container DocumentServer development environment.

Ubuntu defaults:
  Repository: https://github.com/askdkc/jin
  App path:   cloned repository containing setup.sh
  Public Office path: <domain>/onlyoffice
  Laravel -> DocumentServer: http://127.0.0.1:<onlyoffice-port>
  DocumentServer -> Laravel: http://127.0.0.1:<app-internal-port>
  IP access:   unrestricted when the first-run prompt is left blank
  Language:    Japanese when the first-run prompt is left blank

Options:
  --domain <domain>            Jin public domain (prompted when interactive)
  --app-domain <domain>        Alias for --domain
  --repo <url>                 Git repository (default: https://github.com/askdkc/jin)
  --app-dir <path>             Jin application directory (default: cloned repository)
  --env-file <path>            macOS environment file; Ubuntu requires <app-dir>/.env
  --db-name <name>             PostgreSQL database (default: jin)
  --db-user <name>             PostgreSQL application role (default: jin)
  --db-password <password>     PostgreSQL password; generated values are stored root-only
  --email <address>            Let's Encrypt registration / expiry mail
  --onlyoffice-port <port>     Loopback DocumentServer port (default: 8081)
  --app-internal-port <port>   Loopback Laravel callback port (default: 8090)
  --jwt-secret <secret>        ONLYOFFICE JWT secret; prefer ONLYOFFICE_JWT_SECRET
  --instance-id <id>           ONLYOFFICE instance id (Ubuntu: generated if local/blank)
  --max-file-size-bytes <n>    Jin Office file limit
  --max-download-bytes <n>     DocumentServer download limit
  --sudo-nopasswd              Use a passwordless sudo user and skip sudo -v validation
  --no-ssl                     Skip Let's Encrypt and use HTTP
  --platform <auto|ubuntu|macos>
                               Override platform detection
  --office-domain <domain>     macOS-only legacy public DocumentServer domain
  --onlyoffice-image <image>   macOS Apple Container image
  --container-name <name>      macOS Apple Container name
  --recreate-container         Explicitly replace an existing macOS container
  --nginx                      Accepted for macOS; Ubuntu always configures Nginx
  --no-nginx                   macOS-only; Ubuntu requires Nginx
  -h, --help                   Show this help

Non-interactive Ubuntu environment overrides:
  IP_RESTRICTION_ENABLED       true/false; defaults to false on first setup
  ALLOWED_IPS                  Comma-separated IP/CIDR list; implies true when set
  APP_LOCALE                   ja, en, fr, de, zh_CN, zh_TW, es, pt_BR, pt_PT, or ko
  EMAIL_VERIFICATION_ENABLED   true/false; defaults to false without interactive input
  MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS
                               Required when enabling email verification non-interactively
  MAIL_SCHEME                  smtp (default) or smtps
  MAIL_FROM_NAME               Sender name (default: Jin)

Ubuntu installs PHP 8.5/FPM, Composer, Node.js 24+, PostgreSQL 16 with
postgresql-16-pgroonga on Ubuntu 24.04 or PostgreSQL 18 with
postgresql-18-pgdg-pgroonga on Ubuntu 26.04, Redis, RabbitMQ, the pinned
requirements-file-content.txt MarkItDown venv, ONLYOFFICE, Nginx, and
Supervisor queue/Reverb/scheduler processes. It uses fast-forward-only Git
updates and preserves an existing .env, secrets, private storage, and database
data. SMTP credentials remain operator-supplied. Ubuntu requires 24.04 or
26.04 amd64, at least 2 CPU cores, 2 GB RAM, and 30 GB free space; 40 GB and
4 GB swap are recommended. macOS requires Apple silicon, macOS 26 or newer,
and Apple's container runtime.
EOF
}

main() {
    APP_DIR="${APP_DIR:-${JIN_PATH:-$SCRIPT_DIR}}"
    ENV_FILE="${ENV_FILE:-}"
    REPO_URL="${REPO_URL:-${JIN_REPOSITORY:-https://github.com/askdkc/jin}}"
    APP_DOMAIN="${APP_DOMAIN:-${DOMAIN:-}}"
    OFFICE_DOMAIN="${OFFICE_DOMAIN:-}"
    EMAIL="${EMAIL:-}"
    DB_NAME="${DB_NAME:-jin}"
    DB_USER="${DB_USER:-jin}"
    DB_PASSWORD="${DB_PASSWORD:-}"
    IP_RESTRICTION_OVERRIDE="${IP_RESTRICTION_ENABLED:-}"
    ALLOWED_IPS_OVERRIDE="${ALLOWED_IPS:-}"
    APP_LOCALE_OVERRIDE="${APP_LOCALE:-}"
    EMAIL_VERIFICATION_OVERRIDE="${EMAIL_VERIFICATION_ENABLED:-}"
    TARGET_DOCUMENT_SERVER_URL="${ONLYOFFICE_DOCUMENT_SERVER_URL:-}"
    TARGET_PUBLIC_URL="${ONLYOFFICE_PUBLIC_URL:-}"
    TARGET_INTERNAL_URL="${APP_ONLYOFFICE_INTERNAL_URL:-}"
    JWT_SECRET="${ONLYOFFICE_JWT_SECRET:-}"
    INSTANCE_ID="${ONLYOFFICE_INSTANCE_ID:-}"
    MAX_FILE_SIZE_BYTES="${ONLYOFFICE_MAX_FILE_SIZE_BYTES:-}"
    TARGET_MAX_DOWNLOAD_BYTES="${ONLYOFFICE_DOCUMENT_SERVER_MAX_DOWNLOAD_BYTES:-}"
    ONLYOFFICE_PORT_FROM_ENV="${ONLYOFFICE_PORT:-${ONLYOFFICE_HOST_PORT:-}}"
    ONLYOFFICE_PORT="${ONLYOFFICE_PORT_FROM_ENV:-8081}"
    APP_INTERNAL_PORT_FROM_ENV="${APP_INTERNAL_PORT:-}"
    APP_INTERNAL_PORT="${APP_INTERNAL_PORT_FROM_ENV:-8090}"
    REVERB_SERVER_PORT="${REVERB_SERVER_PORT:-8080}"
    ONLYOFFICE_PUBLIC_PATH=/onlyoffice
    ONLYOFFICE_IMAGE="${ONLYOFFICE_IMAGE:-onlyoffice/documentserver:9.4.0.1}"
    ONLYOFFICE_CONTAINER_NAME="${ONLYOFFICE_CONTAINER_NAME:-}"
    ONLYOFFICE_DATA_VOLUME="${ONLYOFFICE_DATA_VOLUME:-}"
    ONLYOFFICE_LOGS_VOLUME="${ONLYOFFICE_LOGS_VOLUME:-}"
    ONLYOFFICE_LIB_VOLUME="${ONLYOFFICE_LIB_VOLUME:-}"
    ONLYOFFICE_DB_VOLUME="${ONLYOFFICE_DB_VOLUME:-}"
    CONTAINER_CPUS="${ONLYOFFICE_CONTAINER_CPUS:-2}"
    CONTAINER_MEMORY="${ONLYOFFICE_CONTAINER_MEMORY:-4G}"
    CONTAINER_SHM_SIZE="${ONLYOFFICE_CONTAINER_SHM_SIZE:-2G}"
    MACOS_HOST_REDIRECT_IP="${ONLYOFFICE_HOST_REDIRECT_IP:-203.0.113.113}"
    PLATFORM="${ONLYOFFICE_PLATFORM:-auto}"
    CONFIGURE_NGINX=auto
    NO_SSL=0
    PORT_EXPLICIT=0
    DOCUMENT_SERVER_URL_EXPLICIT=0
    PUBLIC_URL_EXPLICIT=0
    INTERNAL_PORT_EXPLICIT=0
    CONTAINER_NAME_EXPLICIT=0
    VOLUME_NAMES_EXPLICIT=0
    ALLOW_LEGACY_MACOS_RESOURCES=0
    RECREATE_CONTAINER=0

    [[ -n "$ONLYOFFICE_PORT_FROM_ENV" ]] && PORT_EXPLICIT=1
    [[ -n "${ONLYOFFICE_DOCUMENT_SERVER_URL:-}" ]] && DOCUMENT_SERVER_URL_EXPLICIT=1
    [[ -n "${ONLYOFFICE_PUBLIC_URL:-}" ]] && PUBLIC_URL_EXPLICIT=1
    [[ -n "${APP_INTERNAL_PORT_FROM_ENV:-}" ]] && INTERNAL_PORT_EXPLICIT=1
    [[ -n "${ONLYOFFICE_CONTAINER_NAME:-}" ]] && CONTAINER_NAME_EXPLICIT=1
    if [[ -n "${ONLYOFFICE_DATA_VOLUME:-}" || -n "${ONLYOFFICE_LOGS_VOLUME:-}" \
        || -n "${ONLYOFFICE_LIB_VOLUME:-}" || -n "${ONLYOFFICE_DB_VOLUME:-}" ]]; then
        VOLUME_NAMES_EXPLICIT=1
    fi
    if [[ "${ONLYOFFICE_NO_SSL:-}" == true || "${ONLYOFFICE_NO_SSL:-}" == 1 ]]; then
        NO_SSL=1
    fi
    if [[ "${ONLYOFFICE_CONFIGURE_NGINX:-}" == true || "${ONLYOFFICE_CONFIGURE_NGINX:-}" == 1 ]]; then
        CONFIGURE_NGINX=1
    elif [[ "${ONLYOFFICE_CONFIGURE_NGINX:-}" == false || "${ONLYOFFICE_CONFIGURE_NGINX:-}" == 0 ]]; then
        CONFIGURE_NGINX=0
    fi
    if [[ -n "${ONLYOFFICE_RECREATE_CONTAINER:-}" ]]; then
        case "$ONLYOFFICE_RECREATE_CONTAINER" in
            true|1) RECREATE_CONTAINER=1 ;;
            false|0) RECREATE_CONTAINER=0 ;;
            *) die "ONLYOFFICE_RECREATE_CONTAINER must be true, false, 1, or 0" ;;
        esac
    fi

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --app-dir|--env-file|--domain|--app-domain|--repo|--db-name|--db-user|--db-password|--office-domain|--email|--onlyoffice-port|--app-internal-port|--onlyoffice-image|--container-name|--jwt-secret|--instance-id|--app-internal-url|--public-url|--document-server-url|--max-file-size-bytes|--max-download-bytes|--platform)
                [[ $# -ge 2 ]] || die "$1 requires a value"
                case "$1" in
                    --app-dir) APP_DIR="$2" ;;
                    --env-file) ENV_FILE="$2" ;;
                    --domain|--app-domain) APP_DOMAIN="$2" ;;
                    --repo) REPO_URL="$2" ;;
                    --db-name) DB_NAME="$2" ;;
                    --db-user) DB_USER="$2" ;;
                    --db-password) DB_PASSWORD="$2" ;;
                    --office-domain) OFFICE_DOMAIN="$2" ;;
                    --email) EMAIL="$2" ;;
                    --onlyoffice-port) ONLYOFFICE_PORT="$2"; PORT_EXPLICIT=1 ;;
                    --app-internal-port) APP_INTERNAL_PORT="$2"; INTERNAL_PORT_EXPLICIT=1 ;;
                    --onlyoffice-image) ONLYOFFICE_IMAGE="$2" ;;
                    --container-name) ONLYOFFICE_CONTAINER_NAME="$2"; CONTAINER_NAME_EXPLICIT=1 ;;
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
            --sudo-nopasswd) SUDO_NOPASSWD=1; shift ;;
            --no-ssl) NO_SSL=1; shift ;;
            --recreate-container) RECREATE_CONTAINER=1; shift ;;
            -h|--help) usage; exit 0 ;;
            *) die "Unknown option: $1 (see --help)" ;;
        esac
    done

    case "$SUDO_NOPASSWD" in
        0|1) ;;
        *) die "SUDO_NOPASSWD must be 0 or 1" ;;
    esac

    if [[ "$CONTAINER_NAME_EXPLICIT" -eq 1 || "$VOLUME_NAMES_EXPLICIT" -eq 1 ]]; then
        ALLOW_LEGACY_MACOS_RESOURCES=1
    fi

    case "$PLATFORM" in
        auto)
            case "$(uname -s)" in
                Darwin) PLATFORM=macos ;;
                Linux) PLATFORM=ubuntu ;;
                *) die "Unsupported operating system: $(uname -s)" ;;
            esac
            ;;
        ubuntu|macos) ;;
        *) die "Invalid platform: $PLATFORM (use auto, ubuntu, or macos)" ;;
    esac

    [[ -n "$EMAIL" ]] || EMAIL=""

    case "$PLATFORM" in
        macos)
            resolve_app_values
            resolve_email_verification
            configure_email_verification
            setup_macos
            log "Provisioning complete."
            log "Environment: $ENV_FILE"
            log "Instance:    $INSTANCE_ID"
            ;;
        ubuntu)
            setup_ubuntu
            ;;
    esac
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    main "$@"
fi
