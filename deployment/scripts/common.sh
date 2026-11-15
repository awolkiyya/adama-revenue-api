#!/usr/bin/env bash

# ============================================================
# ADAMA MUNICIPAL REVENUE API
# Shared Deployment Functions
# ============================================================

# This file is sourced by other deployment scripts.
# It must be executed using Bash.

# ------------------------------------------------------------
# Logging
# ------------------------------------------------------------

log() {
    printf '[%s] INFO: %s\n' \
        "$(date '+%Y-%m-%d %H:%M:%S')" \
        "$*"
}

log_warn() {
    printf '[%s] WARNING: %s\n' \
        "$(date '+%Y-%m-%d %H:%M:%S')" \
        "$*" >&2
}

log_error() {
    printf '[%s] ERROR: %s\n' \
        "$(date '+%Y-%m-%d %H:%M:%S')" \
        "$*" >&2
}

die() {
    log_error "$*"
    exit 1
}

# ------------------------------------------------------------
# Required command validation
# ------------------------------------------------------------

require_command() {
    local command_name="$1"

    if ! command -v "$command_name" >/dev/null 2>&1; then
        die "Required command not found: $command_name"
    fi
}

# ------------------------------------------------------------
# Required file validation
# ------------------------------------------------------------

require_file() {
    local file_path="$1"
    local description="${2:-Required file}"

    if [[ ! -f "$file_path" ]]; then
        die "$description not found: $file_path"
    fi

    if [[ ! -r "$file_path" ]]; then
        die "$description is not readable: $file_path"
    fi
}

# ------------------------------------------------------------
# Required directory validation
# ------------------------------------------------------------

require_directory() {
    local directory_path="$1"
    local description="${2:-Required directory}"

    if [[ ! -d "$directory_path" ]]; then
        die "$description not found: $directory_path"
    fi
}

# ------------------------------------------------------------
# Environment initialization
# ------------------------------------------------------------

deployment_init() {

    local requested_environment="${1:-}"

    if [[ -z "$requested_environment" ]]; then
        die "Deployment environment is required. Use: staging or production."
    fi

    case "$requested_environment" in
        staging|production)
            ;;
        *)
            die "Unsupported environment: $requested_environment"
            ;;
    esac

    # Resolve deployment directory from this script's location.
    DEPLOYMENT_SCRIPT_DIR="$(
        cd -- "$(dirname -- "${BASH_SOURCE[0]}")" &&
        pwd
    )"

    DEPLOYMENT_ROOT="$(
        cd -- "$DEPLOYMENT_SCRIPT_DIR/.." &&
        pwd
    )"

    # Load environment-specific configuration.
    CONFIG_FILE="$DEPLOYMENT_ROOT/config/${requested_environment}.env"

    require_file "$CONFIG_FILE" "Deployment configuration"

    log "Loading deployment configuration: $requested_environment"

    # shellcheck disable=SC1090
    source "$CONFIG_FILE"

    # --------------------------------------------------------
    # Validate environment identity
    # --------------------------------------------------------

    if [[ "${APP_ENVIRONMENT:-}" != "$requested_environment" ]]; then
        die "Environment mismatch. Requested '$requested_environment', configuration contains '${APP_ENVIRONMENT:-unset}'."
    fi

    # --------------------------------------------------------
    # Validate required configuration values
    # --------------------------------------------------------

    local required_variables=(
        APP_ROOT
        SOURCE_DIR
        ENV_FILE
        COMPOSE_PROJECT_NAME
        COMPOSE_FILE
        COMPOSE_OVERRIDE_FILE
        APP_IMAGE
        SOURCE_ARCHIVE
        GATEWAY_COMPOSE_FILE
        STAGING_NETWORK
        PRODUCTION_NETWORK
        POSTGRES_SERVICE
        REDIS_SERVICE
        APP_SERVICE
        NGINX_SERVICE
        WORKER_SERVICE
        SCHEDULER_SERVICE
        DATABASE_BACKUP_REQUIRED
    )

    local variable_name

    for variable_name in "${required_variables[@]}"; do

        if [[ -z "${!variable_name:-}" ]]; then
            die "Required configuration variable is missing: $variable_name"
        fi

    done

    # --------------------------------------------------------
    # Validate boolean configuration values
    # --------------------------------------------------------

    case "$DATABASE_BACKUP_REQUIRED" in
        true|false)
            ;;
        *)
            die "DATABASE_BACKUP_REQUIRED must be true or false."
            ;;
    esac

    # --------------------------------------------------------
    # Validate environment-specific backup configuration
    # --------------------------------------------------------

    if [[ "$APP_ENVIRONMENT" == "production" ]]; then

        if [[ -z "${DATABASE_BACKUP_SCRIPT:-}" ]]; then
            die "Production backup script configuration is missing."
        fi

        if [[ "$DATABASE_BACKUP_REQUIRED" != "true" ]]; then
            die "Production deployment must require a database backup."
        fi

    fi

    # --------------------------------------------------------
    # Validate filesystem paths
    # --------------------------------------------------------

    require_directory "$APP_ROOT" "Application root directory"
    require_directory "$SOURCE_DIR" "Application source directory"

    require_file "$ENV_FILE" "Laravel environment file"
    require_file "$GATEWAY_COMPOSE_FILE" "Gateway Compose file"

    require_file \
        "$SOURCE_DIR/$COMPOSE_FILE" \
        "Base Docker Compose file"

    require_file \
        "$SOURCE_DIR/$COMPOSE_OVERRIDE_FILE" \
        "Environment Docker Compose file"

    # --------------------------------------------------------
    # Docker image configuration
    # --------------------------------------------------------

    IMAGE="$APP_IMAGE"
    export IMAGE

    # --------------------------------------------------------
    # Docker Compose command
    # --------------------------------------------------------

    COMPOSE=(
        docker compose
        --env-file "$ENV_FILE"
        --project-directory "$SOURCE_DIR"
        -p "$COMPOSE_PROJECT_NAME"
        -f "$SOURCE_DIR/$COMPOSE_FILE"
        -f "$SOURCE_DIR/$COMPOSE_OVERRIDE_FILE"
    )

    log "Deployment configuration loaded successfully."

    log "Environment: $APP_ENVIRONMENT"
    log "Project: $COMPOSE_PROJECT_NAME"
    log "Source: $SOURCE_DIR"
    log "Image: $APP_IMAGE"

}

# ------------------------------------------------------------
# Docker Compose wrapper
# ------------------------------------------------------------

compose() {

    if [[ ${#COMPOSE[@]} -eq 0 ]]; then
        die "Docker Compose has not been initialized."
    fi

    "${COMPOSE[@]}" "$@"
}

# ------------------------------------------------------------
# Docker Compose configuration validation
# ------------------------------------------------------------

validate_compose() {

    log "Validating Docker Compose configuration."

    require_command docker

    docker compose version >/dev/null

    compose config -q

    log "Docker Compose configuration is valid."

}

# ------------------------------------------------------------
# Deployment environment summary
# ------------------------------------------------------------

deployment_summary() {

    log "=========================================="
    log "DEPLOYMENT CONFIGURATION"
    log "=========================================="

    log "Environment: $APP_ENVIRONMENT"
    log "Application root: $APP_ROOT"
    log "Source directory: $SOURCE_DIR"
    log "Compose project: $COMPOSE_PROJECT_NAME"
    log "Docker image: $APP_IMAGE"
    log "Database backup required: $DATABASE_BACKUP_REQUIRED"
    log "Reference seeding controlled by GitHub Actions."

    log "=========================================="

}
