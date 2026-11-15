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
# Verify first-time production database is empty
# ------------------------------------------------------------

verify_initial_production_database() {

    local output
    local table_count

    if [[ "$APP_ENVIRONMENT" != "production" ]]; then
        return 0
    fi

    if [[ "$DATABASE_BACKUP_REQUIRED" != "false" ]]; then
        return 0
    fi

    if [[ "${CONFIRM_PRODUCTION_INITIAL_DEPLOYMENT:-}" != "YES" ]]; then
        die "First-time production initialization confirmation is missing."
    fi

    log_warn "Checking whether the production database is genuinely empty."

    # Count existing application tables in the schemas
    # included in PostgreSQL's active search path.
    #
    # This is read-only. It does not create or modify tables.

    if ! output="$(
        compose run \
            --rm \
            --no-deps \
            "$APP_SERVICE" \
            php artisan tinker --execute='
                $result = DB::selectOne("
                    SELECT COUNT(*) AS table_count
                    FROM information_schema.tables
                    WHERE table_type = ?
                      AND table_schema = ANY(current_schemas(false))
                ", ["BASE TABLE"]);

                echo "PRODUCTION_TABLE_COUNT=" . $result->table_count . PHP_EOL;
            ' 2>&1
    )"; then

        printf '%s\n' "$output" >&2

        die "Unable to verify whether the initial production database is empty."

    fi

    printf '%s\n' "$output"

    table_count="$(
        printf '%s\n' "$output" |
            grep -E '^PRODUCTION_TABLE_COUNT=[0-9]+$' |
            tail -n 1 |
            cut -d '=' -f 2
    )"

    if [[ ! "$table_count" =~ ^[0-9]+$ ]]; then
        die "Unable to determine the production database table count."
    fi

    if (( table_count != 0 )); then
        die "First-time production initialization refused: the database already contains ${table_count} table(s). A verified backup is required."
    fi

    log "Production database contains zero tables."
    log "First-time database initialization is permitted."

}

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
    local checksum_basename
    local current_timestamp
    local backup_started_at

    # Staging does not require a production backup.
    if [[ "$APP_ENVIRONMENT" != "production" ]]; then
        log "Production backup verification is not required for staging."
        return 0
    fi

    # --------------------------------------------------------
    # Explicit first-time production initialization
    # --------------------------------------------------------

    if [[ "$DATABASE_BACKUP_REQUIRED" == "false" ]]; then

        if [[ "${CONFIRM_PRODUCTION_INITIAL_DEPLOYMENT:-}" != "YES" ]]; then
            die "Production backup requirement is disabled without explicit first-deployment confirmation."
        fi

        log_warn "Skipping production backup for explicitly confirmed first-time initialization."

        # Never bypass the database-empty verification.
        verify_initial_production_database

        return 0

    fi

    # --------------------------------------------------------
    # Normal production deployment
    # --------------------------------------------------------

    if [[ "$DATABASE_BACKUP_REQUIRED" != "true" ]]; then
        die "Invalid production database backup policy."
    fi

    backup_script="${DATABASE_BACKUP_SCRIPT:-}"

    if [[ -z "$backup_script" ]]; then
        die "Production database backup script is not configured."
    fi

    require_command sudo

    # The backup script is intentionally root-owned and
    # root-readable. Do not weaken its file permissions.
    #
    # Validate access using non-interactive sudo.

    if ! sudo -n test -f "$backup_script"; then
        die "Production database backup script does not exist or is inaccessible to root: $backup_script"
    fi

    if ! sudo -n test -r "$backup_script"; then
        die "Root cannot read the production database backup script: $backup_script"
    fi

    if ! sudo -n test -x "$backup_script"; then
        die "Production database backup script is not executable: $backup_script"
    fi

    backup_directory="/var/backups/adama-revenue-api/production/postgresql"

    if ! sudo -n test -d "$backup_directory"; then
        die "Production backup directory does not exist or is inaccessible: $backup_directory"
    fi

    # Record the start time before invoking the backup script.
    # The resulting archive must be newer than this timestamp.
    backup_started_at="$(date +%s)"

    log "Starting a new production PostgreSQL backup."
    log "Backup start timestamp: $backup_started_at"

    # Execute the root-only backup script without an
    # interactive password prompt.
    #
    # Any non-zero result blocks the migration.

    if ! sudo -n "$backup_script"; then
        die "Production backup script failed. Migration is blocked."
    fi

    log "Backup script completed. Checking for a newly created archive."

    # Find the newest published production backup.
    # The backup directory may be root-only, so use sudo.

    if ! backup_file="$(
        sudo -n find "$backup_directory" \
            -maxdepth 1 \
            -type f \
            -name 'adama-revenue-production_*.dump' \
            -printf '%T@ %p\n' |
            sort -nr |
            head -n 1 |
            awk '{$1=""; sub(/^ /, ""); print}'
    )"; then
        die "Unable to inspect the production backup directory."
    fi

    if [[ -z "$backup_file" ]]; then
        die "No production PostgreSQL backup archive was found."
    fi

    checksum_file="${backup_file}.sha256"

    if ! sudo -n test -f "$backup_file"; then
        die "Production backup archive does not exist."
    fi

    if ! sudo -n test -r "$backup_file"; then
        die "Root cannot read the production backup archive."
    fi

    if ! sudo -n test -f "$checksum_file"; then
        die "Production backup checksum does not exist."
    fi

    if ! sudo -n test -r "$checksum_file"; then
        die "Root cannot read the production backup checksum."
    fi

    if ! sudo -n test -s "$backup_file"; then
        die "Production backup archive is empty."
    fi

    # Ensure this backup was created during this deployment attempt.
    backup_timestamp="$(
        sudo -n stat -c '%Y' "$backup_file"
    )"

    if (( backup_timestamp < backup_started_at )); then
        die "The newest backup predates this deployment attempt. Migration is blocked."
    fi

    backup_basename="$(basename "$backup_file")"
    checksum_basename="$(basename "$checksum_file")"

    # Ensure the checksum references this exact archive.
    if ! sudo -n grep -Fq -- "  ${backup_basename}" "$checksum_file"; then
        die "Backup checksum does not reference the selected backup archive."
    fi

    log "Verifying production backup checksum."

    sudo -n bash -c '
        set -Eeuo pipefail

        backup_directory="$1"
        checksum_basename="$2"

        cd "$backup_directory"

        sha256sum --check "$checksum_basename"
    ' _ "$backup_directory" "$checksum_basename" ||
        die "Production backup checksum verification failed."

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

        # A missing migration table is acceptable only when
        # the database has been verified as empty.
        #
        # Production first-time initialization performs this
        # check above. Apply the same protection to staging.

        if [[ "$APP_ENVIRONMENT" == "staging" ]]; then
            log "Verifying that the staging database is empty."

            STAGING_TABLE_COUNT_OUTPUT="$(
                compose run \
                    --rm \
                    --no-deps \
                    "$APP_SERVICE" \
                    php artisan tinker --execute='
                        $result = DB::selectOne("
                            SELECT COUNT(*) AS table_count
                            FROM information_schema.tables
                            WHERE table_type = ?
                              AND table_schema = ANY(current_schemas(false))
                        ", ["BASE TABLE"]);

                        echo "STAGING_TABLE_COUNT=" . $result->table_count . PHP_EOL;
                    '
            )"

            STAGING_TABLE_COUNT="$(
                printf '%s\n' "$STAGING_TABLE_COUNT_OUTPUT" |
                    grep -E '^STAGING_TABLE_COUNT=[0-9]+$' |
                    tail -n 1 |
                    cut -d '=' -f 2
            )"

            if [[ ! "$STAGING_TABLE_COUNT" =~ ^[0-9]+$ ]] ||
               (( STAGING_TABLE_COUNT != 0 )); then
                die "Staging migration table is missing but the database is not verified empty. Migration is blocked."
            fi

            log "Staging database is empty. Initial migrations are permitted."
        fi

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

    if [[ "$DATABASE_BACKUP_REQUIRED" == "true" ]]; then
        log "Production backup verification: PASSED"
    else
        log_warn "Production backup verification: SKIPPED (confirmed first-time initialization)"
    fi

fi

log "Database migrations: SUCCESSFUL"

log "=========================================="
