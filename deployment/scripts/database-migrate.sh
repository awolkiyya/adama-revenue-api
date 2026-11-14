
#!/usr/bin/env bash

# ============================================================
# ADAMA MUNICIPAL REVENUE API
# Database Migration
# Supports: Staging and Production
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
# Initialize deployment environment
# ------------------------------------------------------------

if [[ "$#" -ne 1 ]]; then
    echo "Usage: $0 <staging|production>" >&2
    exit 1
fi

ENVIRONMENT="$1"

deployment_init "$ENVIRONMENT"

# ------------------------------------------------------------
# Validate required commands
# ------------------------------------------------------------

require_command docker
require_command date
require_command sha256sum
require_command awk
require_command find
require_command sort
require_command head
require_command basename
require_command grep
require_command stat

# ------------------------------------------------------------
# Production backup configuration
# ------------------------------------------------------------

BACKUP_MAX_AGE_MINUTES="${DATABASE_BACKUP_MAX_AGE_MINUTES:-30}"

if ! [[ "$BACKUP_MAX_AGE_MINUTES" =~ ^[1-9][0-9]*$ ]]; then
    die "DATABASE_BACKUP_MAX_AGE_MINUTES must be a positive integer."
fi

# ------------------------------------------------------------
# Verify PostgreSQL readiness
# ------------------------------------------------------------

log "Checking PostgreSQL readiness."

bash "$DEPLOYMENT_ROOT/scripts/database-verify.sh" "$APP_ENVIRONMENT"

log "PostgreSQL readiness verification passed."

# ------------------------------------------------------------
# Verify production backup
# ------------------------------------------------------------

verify_production_backup() {

    local backup_script
    local backup_directory
    local backup_file
    local checksum_file
    local backup_timestamp
    local backup_age
    local backup_basename
    local current_timestamp
    local backup_started_at

    # Staging does not require a production backup.
    if [[ "$APP_ENVIRONMENT" != "production" ]]; then
        log "Production backup verification is not required for staging."
        return 0
    fi

    # Production must never bypass the backup requirement.
    if [[ "$DATABASE_BACKUP_REQUIRED" != "true" ]]; then
        die "Production database backup requirement is disabled."
    fi

    backup_script="${DATABASE_BACKUP_SCRIPT:-}"

    if [[ -z "$backup_script" ]]; then
        die "Production database backup script is not configured."
    fi

    require_file "$backup_script" "Production database backup script"

    if [[ ! -x "$backup_script" ]]; then
        die "Production database backup script is not executable: $backup_script"
    fi

    backup_directory="/var/backups/adama-revenue-api/production/postgresql"

    require_directory "$backup_directory" "Production backup directory"

    # Record the start time before invoking the backup script.
    # The resulting archive must be newer than this timestamp.
    backup_started_at="$(date +%s)"

    log "Starting a new production PostgreSQL backup."
    log "Backup start timestamp: $backup_started_at"

    # A concurrent backup now returns exit code 75.
    # Any non-zero result blocks the migration.
    if ! "$backup_script"; then
        die "Production backup script failed. Migration is blocked."
    fi

    log "Backup script completed. Checking for a newly created archive."

    # Find the newest published production backup.
    backup_file="$(
        find "$backup_directory" \
            -maxdepth 1 \
            -type f \
            -name 'adama-revenue-production_*.dump' \
            -printf '%T@ %p\n' |
            sort -nr |
            head -n 1 |
            awk '{$1=""; sub(/^ /, ""); print}'
    )"

    if [[ -z "$backup_file" ]]; then
        die "No production PostgreSQL backup archive was found."
    fi

    checksum_file="${backup_file}.sha256"

    require_file "$backup_file" "Production backup archive"
    require_file "$checksum_file" "Production backup checksum"

    if [[ ! -s "$backup_file" ]]; then
        die "Production backup archive is empty."
    fi

    # Ensure this backup was created during this deployment attempt.
    backup_timestamp="$(stat -c '%Y' "$backup_file")"

    if (( backup_timestamp < backup_started_at )); then
        die "The newest backup predates this deployment attempt. Migration is blocked."
    fi

    backup_basename="$(basename "$backup_file")"

    # Ensure the checksum references this exact archive.
    if ! grep -Fq "  ${backup_basename}" "$checksum_file"; then
        die "Backup checksum does not reference the selected backup archive."
    fi

    log "Verifying production backup checksum."

    (
        cd "$backup_directory"
        sha256sum --check "$backup_basename.sha256"
    ) || die "Production backup checksum verification failed."

    # Check backup age.
    current_timestamp="$(date +%s)"

    backup_age=$(( (current_timestamp - backup_timestamp) / 60 ))

    if (( backup_age < 0 )); then
        die "Production backup timestamp is in the future."
    fi

    if (( backup_age > BACKUP_MAX_AGE_MINUTES )); then
        die "Production backup is too old: ${backup_age} minutes."
    fi

    log "Production backup verification passed."
    log "Backup archive: $backup_basename"
    log "Backup age: ${backup_age} minutes."
    log "Backup created during this deployment attempt: YES"

}

# ------------------------------------------------------------
# Run production backup verification
# ------------------------------------------------------------

verify_production_backup

# ------------------------------------------------------------
# Validate application container image
# ------------------------------------------------------------

log "Checking Laravel application image."

if ! docker image inspect "$APP_IMAGE" >/dev/null 2>&1; then
    die "Laravel application image does not exist: $APP_IMAGE"
fi

# ------------------------------------------------------------
# Check Laravel migration status
# ------------------------------------------------------------

log "Checking current Laravel migration status."

MIGRATION_STATUS_OUTPUT=""

if MIGRATION_STATUS_OUTPUT="$(
    compose run \
        --rm \
        --no-deps \
        "$APP_SERVICE" \
        php artisan migrate:status 2>&1
)"; then

    printf '%s\n' "$MIGRATION_STATUS_OUTPUT"

else

    if grep -Fq "Migration table not found" <<< "$MIGRATION_STATUS_OUTPUT"; then

        log_warn "Laravel migration table does not exist yet."
        log "This is acceptable for a fresh database."

    else

        printf '%s\n' "$MIGRATION_STATUS_OUTPUT" >&2

        die "Unable to retrieve migration status. Database migration is blocked."

    fi

fi

# ------------------------------------------------------------
# Execute Laravel migrations
# ------------------------------------------------------------

log "Starting Laravel database migrations."

compose run \
    --rm \
    --no-deps \
    "$APP_SERVICE" \
    php artisan migrate --force

log "Laravel database migrations completed successfully."

# ------------------------------------------------------------
# Verify migration status after execution
# ------------------------------------------------------------

log "Verifying Laravel migration status after execution."

compose run \
    --rm \
    --no-deps \
    "$APP_SERVICE" \
    php artisan migrate:status

# ------------------------------------------------------------
# Finished
# ------------------------------------------------------------

echo ""

log "=========================================="
log "DATABASE MIGRATION COMPLETED"
log "=========================================="

log "Environment: $APP_ENVIRONMENT"
log "Compose project: $COMPOSE_PROJECT_NAME"
log "Application service: $APP_SERVICE"

if [[ "$APP_ENVIRONMENT" == "production" ]]; then
    log "Production backup verification: PASSED"
fi

log "Database migrations: SUCCESSFUL"

log "=========================================="
