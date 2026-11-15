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
require_command awk
require_command grep
require_command sudo

# ------------------------------------------------------------
# Production backup configuration
# ------------------------------------------------------------

BACKUP_MAX_AGE_MINUTES="${DATABASE_BACKUP_MAX_AGE_MINUTES:-30}"

if ! [[ "$BACKUP_MAX_AGE_MINUTES" =~ ^[1-9][0-9]*$ ]]; then
    die "DATABASE_BACKUP_MAX_AGE_MINUTES must be a positive integer."
fi

# The production backup script publishes backups here.
# Keep this consistent with the Ansible backup role.
PRODUCTION_BACKUP_DIRECTORY="/var/backups/adama-revenue-api/production/postgresql"

# ------------------------------------------------------------
# Verify PostgreSQL readiness
# ------------------------------------------------------------

log "Checking PostgreSQL readiness."

bash "$DEPLOYMENT_ROOT/scripts/database-verify.sh" "$APP_ENVIRONMENT"

log "PostgreSQL readiness verification passed."

# ------------------------------------------------------------
# Verify database is genuinely empty
# ------------------------------------------------------------

verify_database_empty() {

    local environment_label="$1"
    local output
    local object_count
    local marker

    marker="${environment_label}_DATABASE_OBJECT_COUNT"

    log_warn "Checking whether the ${environment_label} database is genuinely empty."

    # Count all user-defined PostgreSQL relations, including:
    #   r = ordinary tables
    #   p = partitioned tables
    #   v = views
    #   m = materialized views
    #   S = sequences
    #   f = foreign tables
    #
    # Also count non-system schemas other than the default
    # public schema.
    #
    # This is read-only. It does not modify the database.

    if ! output="$(
        compose run \
            --rm \
            --no-deps \
            "$APP_SERVICE" \
            php artisan tinker --execute="
                \$result = DB::selectOne(\"
                    SELECT
                        (
                            SELECT COUNT(*)
                            FROM pg_class c
                            JOIN pg_namespace n
                                ON n.oid = c.relnamespace
                            WHERE n.nspname NOT IN (
                                'pg_catalog',
                                'information_schema'
                            )
                              AND n.nspname NOT LIKE 'pg_%'
                              AND c.relkind IN (
                                  'r',
                                  'p',
                                  'v',
                                  'm',
                                  'S',
                                  'f'
                              )
                        )
                        +
                        (
                            SELECT COUNT(*)
                            FROM pg_namespace
                            WHERE nspname NOT IN (
                                'pg_catalog',
                                'information_schema',
                                'public'
                            )
                              AND nspname NOT LIKE 'pg_%'
                        ) AS object_count
                \");

                echo \"${marker}=\" . \$result->object_count . PHP_EOL;
            " 2>&1
    )"; then

        printf '%s\n' "$output" >&2

        die "Unable to verify whether the ${environment_label} database is empty."

    fi

    printf '%s\n' "$output"

    object_count="$(
        printf '%s\n' "$output" |
            awk -F= -v marker="$marker" '
                $1 == marker && $2 ~ /^[0-9]+$/ {
                    value = $2
                }
                END {
                    if (value != "") {
                        print value
                    }
                }
            '
    )"

    if [[ ! "$object_count" =~ ^[0-9]+$ ]]; then
        die "Unable to determine the ${environment_label} database object count."
    fi

    if (( object_count != 0 )); then
        die "${environment_label} database is not empty: ${object_count} user-defined database object(s) found. Initial migration is blocked."
    fi

    log "${environment_label} database contains zero user-defined database objects."
}

# ------------------------------------------------------------
# Verify initial production database
# ------------------------------------------------------------

verify_initial_production_database() {

    if [[ "$APP_ENVIRONMENT" != "production" ]]; then
        return 0
    fi

    if [[ "$DATABASE_BACKUP_REQUIRED" != "false" ]]; then
        return 0
    fi

    if [[ "${CONFIRM_PRODUCTION_INITIAL_DEPLOYMENT:-}" != "YES" ]]; then
        die "First-time production initialization confirmation is missing."
    fi

    log_warn "Production backup requirement is disabled for first-time initialization."

    # Initial production initialization is permitted only
    # after verifying that the entire database is empty.

    verify_database_empty "PRODUCTION"

    log "First-time production database initialization is permitted."
}

# ------------------------------------------------------------
# Verify production backup
# ------------------------------------------------------------

verify_production_backup() {

    local backup_script
    local backup_result
    local backup_file
    local backup_created_at
    local backup_started_at
    local current_timestamp
    local backup_age
    local expected_prefix

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

        verify_initial_production_database

        return 0
    fi

    # --------------------------------------------------------
    # Normal production deployment
    # --------------------------------------------------------

    if [[ "$DATABASE_BACKUP_REQUIRED" != "true" ]]; then
        die "Invalid production database backup policy. DATABASE_BACKUP_REQUIRED must be true or false."
    fi

    backup_script="${DATABASE_BACKUP_SCRIPT:-}"

    if [[ -z "$backup_script" ]]; then
        die "Production database backup script is not configured."
    fi

    if [[ "$backup_script" != /* ]]; then
        die "Production database backup script must use an absolute path: $backup_script"
    fi

    # The backup script is intentionally root-owned and
    # root-readable with restrictive permissions.
    #
    # Do not use sudo for test, find, stat, grep, or other
    # separate commands. The sudoers rule authorizes only
    # execution of the backup script itself.

    backup_started_at="$(date +%s)"

    log "Starting a new production PostgreSQL backup."
    log "Backup start timestamp: $backup_started_at"

    # The backup script must return:
    #
    # BACKUP_FILE=/absolute/path/to/backup.dump
    # BACKUP_CREATED_AT=unix_timestamp
    #
    # Its normal logs remain in its configured log file.

    if ! backup_result="$(sudo -n "$backup_script")"; then
        die "Production backup script failed. Migration is blocked."
    fi

    if [[ -z "$backup_result" ]]; then
        die "Production backup script returned no backup result. Migration is blocked."
    fi

    backup_file="$(
        printf '%s\n' "$backup_result" |
            awk -F= '
                $1 == "BACKUP_FILE" && NF >= 2 {
                    value = substr($0, index($0, "=") + 1)
                }
                END {
                    if (value != "") {
                        print value
                    }
                }
            '
    )"

    backup_created_at="$(
        printf '%s\n' "$backup_result" |
            awk -F= '
                $1 == "BACKUP_CREATED_AT" && $2 ~ /^[0-9]+$/ {
                    value = $2
                }
                END {
                    if (value != "") {
                        print value
                    }
                }
            '
    )"

    if [[ -z "$backup_file" ]]; then
        die "Backup script did not return BACKUP_FILE. Migration is blocked."
    fi

    if [[ ! "$backup_created_at" =~ ^[0-9]+$ ]]; then
        die "Backup script did not return a valid BACKUP_CREATED_AT timestamp. Migration is blocked."
    fi

    # --------------------------------------------------------
    # Validate returned backup location
    # --------------------------------------------------------

    expected_prefix="${PRODUCTION_BACKUP_DIRECTORY}/adama-revenue-production_"

    if [[ "$backup_file" != "${expected_prefix}"*.dump ]]; then
        die "Backup script returned an unexpected backup path: $backup_file"
    fi

    # --------------------------------------------------------
    # Validate backup filename
    # --------------------------------------------------------

    local backup_basename
    local filename_timestamp
    local filename_epoch

    backup_basename="${backup_file##*/}"

    if [[ ! "$backup_basename" =~ ^adama-revenue-production_([0-9]{8}T[0-9]{6}Z)\.dump$ ]]; then
        die "Backup script returned an invalid production backup filename: $backup_basename"
    fi

    filename_timestamp="${BASH_REMATCH[1]}"

    if ! filename_epoch="$(
        date -u -d "${filename_timestamp:0:8} ${filename_timestamp:9:6}" +%s 2>/dev/null
    )"; then
        die "Unable to parse production backup filename timestamp."
    fi

    # --------------------------------------------------------
    # Verify backup was created during this deployment
    # --------------------------------------------------------

    if (( backup_created_at < backup_started_at )); then
        die "The production backup predates this deployment attempt. Migration is blocked."
    fi

    if (( filename_epoch < backup_started_at )); then
        die "The production backup filename indicates an older backup. Migration is blocked."
    fi

    # --------------------------------------------------------
    # Verify backup age
    # --------------------------------------------------------

    current_timestamp="$(date +%s)"

    backup_age=$(( (current_timestamp - backup_created_at) / 60 ))

    if (( backup_age < 0 )); then
        die "Production backup timestamp is in the future."
    fi

    if (( backup_age > BACKUP_MAX_AGE_MINUTES )); then
        die "Production backup is too old: ${backup_age} minutes."
    fi

    # --------------------------------------------------------
    # Backup integrity verification
    # --------------------------------------------------------

    # The root-owned backup script is responsible for:
    #   - Creating the PostgreSQL custom-format archive.
    #   - Verifying the archive with pg_restore --list.
    #   - Creating the SHA-256 checksum.
    #   - Publishing the archive and checksum.
    #   - Verifying the saved checksum.
    #
    # A zero exit code is required before this script
    # accepts the returned backup result.

    log "Production backup script completed successfully."
    log "Backup archive: $backup_basename"
    log "Backup creation timestamp: $backup_created_at"
    log "Backup age: ${backup_age} minutes."
    log "Backup created during this deployment attempt: YES"
    log "Production backup verification passed."
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
        # the entire database has been verified as empty.
        #
        # Production first-time initialization already
        # performs this verification.
        #
        # Normal production deployments and staging must
        # also pass this check if the migration table is absent.

        if [[ "$APP_ENVIRONMENT" == "production" ]]; then

            if [[ "$DATABASE_BACKUP_REQUIRED" == "true" ]]; then
                verify_database_empty "PRODUCTION"
            fi

        elif [[ "$APP_ENVIRONMENT" == "staging" ]]; then

            verify_database_empty "STAGING"

        fi

        log "Empty database verification passed. Initial migrations are permitted."

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