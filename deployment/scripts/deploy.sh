#!/usr/bin/env bash

# ============================================================
# ADAMA MUNICIPAL REVENUE API
# Shared Staging / Production Deployment Orchestrator
# ============================================================

set -Eeuo pipefail

# ------------------------------------------------------------
# Load shared deployment functions
# ------------------------------------------------------------

SCRIPT_DIR="$(
    cd -- "$(dirname -- "${BASH_SOURCE[0]}")" &&
    pwd
)"

# shellcheck source=common.sh
source "$SCRIPT_DIR/common.sh"

# ------------------------------------------------------------
# Validate arguments
# ------------------------------------------------------------

if [[ "$#" -ne 1 ]]; then
    echo "Usage: $0 <staging|production>" >&2
    exit 1
fi

ENVIRONMENT="$1"

deployment_init "$ENVIRONMENT"

# ------------------------------------------------------------
# Database migration policy
# ------------------------------------------------------------

RUN_DATABASE_MIGRATIONS="${RUN_DATABASE_MIGRATIONS:-false}"

case "$RUN_DATABASE_MIGRATIONS" in
    true|false)
        ;;
    *)
        die "RUN_DATABASE_MIGRATIONS must be true or false."
        ;;
esac

if [[ "$RUN_DATABASE_MIGRATIONS" == "true" ]]; then

    log_warn "Database migrations are explicitly enabled."

else

    log "Database migrations are disabled. Existing database schema will be preserved."

fi

# ------------------------------------------------------------
# Database seeding policy
# ------------------------------------------------------------

RUN_DATABASE_SEEDERS="${RUN_DATABASE_SEEDERS:-false}"

case "$RUN_DATABASE_SEEDERS" in
    true|false)
        ;;
    *)
        die "RUN_DATABASE_SEEDERS must be true or false."
        ;;
esac

# GitHub Actions controls whether seeding runs.
# The allowlist controls which seeders are permitted.
#
# No REFERENCE_SEEDERS or REFERENCE_SEEDING_ENABLED
# environment variables are required.

SEEDER_ALLOWLIST="$DEPLOYMENT_ROOT/seeder.allowlist"
SEEDER_LIST=()

if [[ "$RUN_DATABASE_SEEDERS" == "true" ]]; then

    log_warn "Approved reference-data seeding is explicitly enabled."

    # Validate the command before reading the allowlist.
    require_command sed

    # The allowlist must exist.
    require_file "$SEEDER_ALLOWLIST" "Seeder allowlist"

    # Read the allowlist, ignoring blank lines and comments.
    mapfile -t SEEDER_LIST < <(
        sed \
            -e 's/#.*//' \
            -e 's/^[[:space:]]*//' \
            -e 's/[[:space:]]*$//' \
            -e '/^$/d' \
            "$SEEDER_ALLOWLIST"
    )

    if [[ "${#SEEDER_LIST[@]}" -eq 0 ]]; then
        die "Seeder allowlist is empty."
    fi

    # Validate every entry before executing anything.
    for ((i = 0; i < ${#SEEDER_LIST[@]}; i++)); do

        seeder="${SEEDER_LIST[$i]}"

        # Validate the seeder class name.
        if [[ ! "$seeder" =~ ^[A-Za-z][A-Za-z0-9]*Seeder$ ]]; then

            die "Invalid seeder entry in allowlist: $seeder"

        fi

        # Reject seeders that require separate authorization.
        case "$seeder" in

            DatabaseSeeder)
                die "DatabaseSeeder is prohibited in the reference seeder allowlist."
                ;;

            SystemAdminSeeder|FieldCollectorSeeder)
                die "User-provisioning seeder is prohibited: $seeder"
                ;;

            RoleSeeder|RolePermissionSeeder)
                die "Authorization-management seeder is prohibited: $seeder"
                ;;

        esac

        # Reject duplicate entries before execution.
        for ((j = 0; j < i; j++)); do

            if [[ "${SEEDER_LIST[$j]}" == "$seeder" ]]; then

                die "Duplicate seeder in allowlist: $seeder"

            fi

        done

        log "Approved seeder: $seeder"

    done

    log "Validated ${#SEEDER_LIST[@]} approved seeders."

else

    log "Reference seeding is disabled for this deployment."

fi

# ------------------------------------------------------------
# Validate required commands
# ------------------------------------------------------------

require_command docker
require_command tar
require_command find
require_command cp
require_command rm
require_command mkdir
require_command mktemp
require_command sleep
require_command seq
require_command grep
require_command sed
require_command date
require_command hostname

# ------------------------------------------------------------
# Deployment state
# ------------------------------------------------------------

EXTRACT_DIR=""

DEPLOYMENT_STARTED_AT="$(date -Is)"

PREVIOUS_DEPLOYMENT_EXISTS=false
PRODUCTION_WORKERS_STOPPED=false
NGINX_REMOVED=false

# ------------------------------------------------------------
# Cleanup and failure recovery
# ------------------------------------------------------------

cleanup() {

    local exit_code=$?

    # Prevent cleanup from being executed more than once.
    trap - EXIT

    # --------------------------------------------------------
    # Best-effort service recovery after a failed deployment
    # --------------------------------------------------------

    if [[ "$exit_code" -ne 0 ]]; then

        log_warn "Deployment failed. Starting best-effort service recovery."

        # Restore internal Nginx if it was removed.

        if [[ "$NGINX_REMOVED" == "true" ]]; then

            log_warn "Attempting to restore the internal Nginx service."

            if ! compose up -d "$NGINX_SERVICE"; then
                log_error "WARNING: Automatic Nginx recovery failed."
            fi

        fi

        # Restart production worker and scheduler if stopped.

        if [[ "$PRODUCTION_WORKERS_STOPPED" == "true" ]]; then

            log_warn "Attempting to restart production worker and scheduler."

            if ! compose start \
                "$WORKER_SERVICE" \
                "$SCHEDULER_SERVICE"; then

                log_error "WARNING: Automatic production worker/scheduler recovery failed."

            fi

        fi

    fi

    # --------------------------------------------------------
    # Remove temporary extraction directory
    # --------------------------------------------------------

    if [[ -n "$EXTRACT_DIR" && -d "$EXTRACT_DIR" ]]; then
        rm -rf -- "$EXTRACT_DIR" || true
    fi

    # --------------------------------------------------------
    # Preserve source archive on failure
    # --------------------------------------------------------

    if [[ "$exit_code" -eq 0 ]]; then

        rm -f -- "$SOURCE_ARCHIVE" || \
            log_warn "Could not remove the successful deployment source archive."

    else

        log_warn "Deployment failed. Source archive retained for investigation: $SOURCE_ARCHIVE"

    fi

    return "$exit_code"
}

trap cleanup EXIT

# ------------------------------------------------------------
# Failure diagnostics
# ------------------------------------------------------------

handle_error() {

    local exit_code=$?
    local failed_command="$BASH_COMMAND"

    trap - ERR

    log_error "=========================================="
    log_error "DEPLOYMENT FAILED"
    log_error "=========================================="

    log_error "Environment: $APP_ENVIRONMENT"
    log_error "Exit code: $exit_code"
    log_error "Failed command: $failed_command"

    log_error "Docker Compose status:"

    compose ps -a || true

    for service in \
        "$APP_SERVICE" \
        "$NGINX_SERVICE" \
        "$WORKER_SERVICE" \
        "$SCHEDULER_SERVICE" \
        "$POSTGRES_SERVICE" \
        "$REDIS_SERVICE"
    do

        log_error "Logs: $service"

        compose logs --tail=60 "$service" || true

    done

    exit "$exit_code"
}

trap handle_error ERR

# ------------------------------------------------------------
# Deployment summary
# ------------------------------------------------------------

log "=========================================="
log "ADAMA MUNICIPAL REVENUE DEPLOYMENT"
log "=========================================="

log "Started: $DEPLOYMENT_STARTED_AT"
log "Server: $(hostname)"
log "Environment: $APP_ENVIRONMENT"

deployment_summary

log "Database migrations enabled: $RUN_DATABASE_MIGRATIONS"
log "Reference seeders enabled: $RUN_DATABASE_SEEDERS"

# ------------------------------------------------------------
# Step 1: Validate source archive
# ------------------------------------------------------------

log "[1/10] Validating source archive."

require_file "$SOURCE_ARCHIVE" "Laravel source archive"

if [[ ! -s "$SOURCE_ARCHIVE" ]]; then
    die "Source archive is empty."
fi

tar -tzf "$SOURCE_ARCHIVE" >/dev/null

log "Source archive is valid."

# ------------------------------------------------------------
# Step 2: Extract and validate source
# ------------------------------------------------------------

log "[2/10] Extracting source archive."

EXTRACT_DIR="$(mktemp -d "/tmp/adama-revenue-${APP_ENVIRONMENT}.XXXXXX")"

tar -xzf "$SOURCE_ARCHIVE" -C "$EXTRACT_DIR"

# The archive contains the project root directly.

require_file "$EXTRACT_DIR/docker/Dockerfile"
require_file "$EXTRACT_DIR/docker/compose.yml"
require_file "$EXTRACT_DIR/$COMPOSE_OVERRIDE_FILE"
require_file "$EXTRACT_DIR/nginx/default.conf"
require_file "$EXTRACT_DIR/public/index.php"
require_file "$EXTRACT_DIR/composer.json"
require_file "$EXTRACT_DIR/composer.lock"

# Deployment scripts are installed separately.

rm -rf -- "$EXTRACT_DIR/deployment"

log "Laravel source structure validated."

# ------------------------------------------------------------
# Step 3: Ensure Docker networks exist
# ------------------------------------------------------------

log "[3/10] Checking Docker networks."

for network in "$STAGING_NETWORK" "$PRODUCTION_NETWORK"; do

    if docker network inspect "$network" >/dev/null 2>&1; then

        log "Network already exists: $network"

    else

        log "Creating network: $network"

        docker network create "$network"

    fi

done

# ------------------------------------------------------------
# Step 4: Detect existing deployment
# ------------------------------------------------------------

log "[4/10] Checking existing application deployment."

if [[ -f "$SOURCE_DIR/docker/compose.yml" ]] &&
   [[ -f "$SOURCE_DIR/$COMPOSE_OVERRIDE_FILE" ]]; then

    PREVIOUS_DEPLOYMENT_EXISTS=true

    log "Existing application Compose deployment detected."

    log "Existing services will remain running during:"
    log "- Source replacement"
    log "- Image build"
    log "- PostgreSQL verification"
    log "- Database migrations"
    log "- Approved reference seeding"

else

    PREVIOUS_DEPLOYMENT_EXISTS=false

    log_warn "No previous application Compose deployment found."

fi

# ------------------------------------------------------------
# Step 5: Replace application source
# ------------------------------------------------------------

log "[5/10] Replacing application source."

# Preserve the existing Laravel storage directory.
# Deployment scripts remain outside SOURCE_DIR.

mkdir -p "$SOURCE_DIR"

find "$SOURCE_DIR" \
    -mindepth 1 \
    -maxdepth 1 \
    ! -name storage \
    -exec rm -rf -- {} +

cp -a "$EXTRACT_DIR/." "$SOURCE_DIR/"

# Do not retain an application .env in the source tree.

rm -f "$SOURCE_DIR/.env"

require_file "$SOURCE_DIR/docker/Dockerfile"
require_file "$SOURCE_DIR/docker/compose.yml"
require_file "$SOURCE_DIR/$COMPOSE_OVERRIDE_FILE"
require_file "$SOURCE_DIR/nginx/default.conf"
require_file "$SOURCE_DIR/public/index.php"
require_file "$SOURCE_DIR/composer.json"
require_file "$SOURCE_DIR/composer.lock"

log "Application source replaced."

# ------------------------------------------------------------
# Step 6: Validate Compose and build image
# ------------------------------------------------------------

log "[6/10] Validating Docker Compose configuration."

validate_compose

log "Building application image: $APP_IMAGE"

docker build \
    --pull \
    -f "$SOURCE_DIR/docker/Dockerfile" \
    -t "$APP_IMAGE" \
    "$SOURCE_DIR"

docker image inspect "$APP_IMAGE" >/dev/null

log "Application image built successfully."

# ------------------------------------------------------------
# Step 7: Start PostgreSQL and Redis
# ------------------------------------------------------------

log "[7/10] Starting database and cache services."

compose up -d \
    "$POSTGRES_SERVICE" \
    "$REDIS_SERVICE"

# ------------------------------------------------------------
# Wait for healthy service
# ------------------------------------------------------------

wait_for_healthy() {

    local service="$1"
    local attempts="${2:-30}"

    local container_id
    local status
    local attempt

    log "Waiting for $service to become healthy."

    for ((attempt = 1; attempt <= attempts; attempt++)); do

        container_id="$(compose ps -q "$service")"

        if [[ -n "$container_id" ]]; then

            status="$(
                docker inspect \
                    --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' \
                    "$container_id"
            )"

            log "$service status: $status ($attempt/$attempts)"

            if [[ "$status" == "healthy" ]]; then
                return 0
            fi

            if [[ "$status" == "unhealthy" ]]; then
                docker logs --tail 80 "$container_id" || true
            fi

        else

            log_warn "$service container has not been created yet."

        fi

        sleep 5

    done

    die "$service did not become healthy within the allowed time."
}

wait_for_healthy "$POSTGRES_SERVICE" 30
wait_for_healthy "$REDIS_SERVICE" 30

log "PostgreSQL and Redis are healthy."

# ------------------------------------------------------------
# Step 8: Run database migrations when explicitly requested
# ------------------------------------------------------------

if [[ "$RUN_DATABASE_MIGRATIONS" == "true" ]]; then

    log "[8/10] Running database migrations."

    # Production migrations require a verified PostgreSQL backup.
    # database-migrate.sh enforces this requirement.

    bash "$DEPLOYMENT_ROOT/scripts/database-migrate.sh" \
        "$APP_ENVIRONMENT"

    log "Database migrations completed."

else

    log "[8/10] Database migrations skipped."

    log "Database schema remains unchanged."

fi

# ------------------------------------------------------------
# Run approved reference seeders when requested
# ------------------------------------------------------------

if [[ "$RUN_DATABASE_SEEDERS" == "true" ]]; then

    log "Executing approved seeders from the deployment allowlist."

    # Execute seeders in the exact order defined by seeder.allowlist.

    for seeder in "${SEEDER_LIST[@]}"; do

        log "Executing approved seeder: $seeder"

        bash "$DEPLOYMENT_ROOT/scripts/database-seed.sh" \
            "$APP_ENVIRONMENT" \
            "$seeder"

        log "Seeder completed successfully: $seeder"

    done

    log "All approved reference seeders completed successfully."

else

    log "Reference seeding skipped."

fi

# ------------------------------------------------------------
# Prepare existing services for replacement
# ------------------------------------------------------------

log "Preparing existing application services for replacement."

if [[ "$PREVIOUS_DEPLOYMENT_EXISTS" == "true" ]]; then

    # --------------------------------------------------------
    # Stop production background services only now.
    # Migrations and seeders have already completed.
    # --------------------------------------------------------

    if [[ "$APP_ENVIRONMENT" == "production" ]]; then

        log "Stopping production worker and scheduler."

        # Set recovery state before attempting the operation.

        PRODUCTION_WORKERS_STOPPED=true

        compose stop \
            "$WORKER_SERVICE" \
            "$SCHEDULER_SERVICE"

        log "Production worker and scheduler stopped."

    fi

    # --------------------------------------------------------
    # Remove Nginx immediately before application startup.
    # --------------------------------------------------------

    log "Removing previous internal Nginx container."

    # Set recovery state before attempting removal.

    NGINX_REMOVED=true

    compose rm -sf "$NGINX_SERVICE"

    log "Previous internal Nginx container removed."

else

    log "No previous application services require replacement."

fi

# ------------------------------------------------------------
# Step 9: Deploy application services
# ------------------------------------------------------------

log "[9/10] Starting Laravel application services."

compose up \
    -d \
    --remove-orphans \
    --scale "$APP_SERVICE=1"

# Do not clear recovery flags here.
# They remain active until all verification succeeds.

log "Application services started."

# ------------------------------------------------------------
# Verify PHP-FPM
# ------------------------------------------------------------

wait_for_healthy "$APP_SERVICE" 30

APP_ID="$(compose ps -q "$APP_SERVICE")"

if [[ -z "$APP_ID" ]]; then
    die "Laravel application container was not created."
fi

log "PHP-FPM is healthy."

# ------------------------------------------------------------
# Verify remaining application services
# ------------------------------------------------------------

verify_running() {

    local service="$1"
    local container_id
    local status

    container_id="$(compose ps -q "$service")"

    if [[ -z "$container_id" ]]; then
        die "$service container was not created."
    fi

    status="$(
        docker inspect \
            --format '{{.State.Status}}' \
            "$container_id"
    )"

    log "$service status: $status"

    if [[ "$status" != "running" ]]; then

        docker logs --tail 100 "$container_id" || true

        die "$service is not running."

    fi
}

verify_running "$NGINX_SERVICE"
verify_running "$WORKER_SERVICE"
verify_running "$SCHEDULER_SERVICE"

# ------------------------------------------------------------
# Verify internal Nginx
# ------------------------------------------------------------

log "Testing internal Nginx configuration."

NGINX_ID="$(compose ps -q "$NGINX_SERVICE")"

if [[ -z "$NGINX_ID" ]]; then
    die "Internal Nginx container was not created."
fi

docker exec "$NGINX_ID" nginx -t

log "Internal Nginx configuration is valid."

# ------------------------------------------------------------
# Verify shared gateway
# ------------------------------------------------------------

log "Checking shared Nginx gateway."

mapfile -t GATEWAY_IDS < <(
    docker ps \
        --filter "publish=80" \
        --format '{{.ID}}'
)

if [[ "${#GATEWAY_IDS[@]}" -ne 1 ]]; then

    docker ps --format 'table {{.ID}}\t{{.Names}}\t{{.Ports}}'

    die "Expected exactly one container publishing port 80."

fi

GATEWAY_ID="${GATEWAY_IDS[0]}"

docker exec "$GATEWAY_ID" nginx -t

log "Shared gateway configuration is valid."

# ------------------------------------------------------------
# Step 10: Final verification
# ------------------------------------------------------------

log "[10/10] Final deployment verification."

compose ps

# ------------------------------------------------------------
# All deployment checks have passed.
# Recovery is no longer required.
# ------------------------------------------------------------

NGINX_REMOVED=false
PRODUCTION_WORKERS_STOPPED=false

log "All application services passed deployment verification."

log "=========================================="
log "DEPLOYMENT COMPLETED SUCCESSFULLY"
log "=========================================="

log "Environment: $APP_ENVIRONMENT"
log "Project: $COMPOSE_PROJECT_NAME"
log "Image: $APP_IMAGE"
log "Database migrations: $RUN_DATABASE_MIGRATIONS"
log "Reference seeders: $RUN_DATABASE_SEEDERS"
log "Finished: $(date -Is)"

# ------------------------------------------------------------
# Emit exact environment-specific GitHub Actions marker
# ------------------------------------------------------------

case "$APP_ENVIRONMENT" in

    staging)
        printf '%s\n' "STAGING_DEPLOYMENT_SUCCESS"
        ;;

    production)
        printf '%s\n' "PRODUCTION_DEPLOYMENT_SUCCESS"
        ;;

    *)
        die "Cannot emit success marker for unsupported environment."
        ;;

esac
