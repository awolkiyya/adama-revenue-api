#!/usr/bin/env bash

# ============================================================
# ADAMA MUNICIPAL REVENUE API
# CONTROLLED DATABASE SEEDING
#
# Usage:
#   bash database-seed.sh staging PermissionSeeder
#   CONFIRM_PRODUCTION_SEEDING=YES \
#       bash database-seed.sh production PermissionSeeder
#
# Security:
#   - Only explicitly allowlisted seeders can execute.
#   - DatabaseSeeder is permanently prohibited.
#   - Production seeding requires explicit confirmation.
#   - Production seeding requires a fresh verified backup.
#   - Laravel migrations must already be completed.
#   - Exactly one seeder executes per invocation.
# ============================================================

set -Eeuo pipefail
umask 077

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

if [[ "$#" -ne 2 ]]; then
    printf 'Usage: %s <staging|production> <SeederName>\n' "$0" >&2
    exit 1
fi

ENVIRONMENT="$1"
SEEDER_NAME="$2"

deployment_init "$ENVIRONMENT"

# ------------------------------------------------------------
# Validate required commands
# ------------------------------------------------------------

require_command docker
require_command grep
require_command sed
require_command awk
require_command date

# ------------------------------------------------------------
# Production protection
# ------------------------------------------------------------

if [[ "$APP_ENVIRONMENT" == "production" ]]; then

    require_command sudo

    if [[ "${CONFIRM_PRODUCTION_SEEDING:-}" != "YES" ]]; then
        die "Production seeding requires CONFIRM_PRODUCTION_SEEDING=YES."
    fi

    log_warn "Production seeding explicitly authorized."

fi

# ------------------------------------------------------------
# Validate requested seeder name
# ------------------------------------------------------------

if [[ ! "$SEEDER_NAME" =~ ^[A-Za-z][A-Za-z0-9]*Seeder$ ]]; then
    die "Invalid seeder class name: $SEEDER_NAME"
fi

# ------------------------------------------------------------
# Permanently prohibit unrestricted database seeding
# ------------------------------------------------------------

if [[ "$SEEDER_NAME" == "DatabaseSeeder" ]]; then
    die "DatabaseSeeder is prohibited. Execute approved seeders individually."
fi

# ------------------------------------------------------------
# Load approved seeder allowlist
# ------------------------------------------------------------

ALLOWLIST_FILE="$DEPLOYMENT_ROOT/seeder.allowlist"

require_file "$ALLOWLIST_FILE" "Seeder allowlist"

log "Loading approved seeder allowlist."

mapfile -t APPROVED_SEEDERS < <(
    sed \
        -e 's/#.*//' \
        -e 's/^[[:space:]]*//' \
        -e 's/[[:space:]]*$//' \
        -e '/^$/d' \
        "$ALLOWLIST_FILE"
)

if [[ "${#APPROVED_SEEDERS[@]}" -eq 0 ]]; then
    die "Seeder allowlist is empty."
fi

# ------------------------------------------------------------
# Validate every allowlist entry
# ------------------------------------------------------------

for ((i = 0; i < ${#APPROVED_SEEDERS[@]}; i++)); do

    approved_seeder="${APPROVED_SEEDERS[$i]}"

    # Validate class-name format.
    if [[ ! "$approved_seeder" =~ ^[A-Za-z][A-Za-z0-9]*Seeder$ ]]; then
        die "Invalid seeder entry in allowlist: $approved_seeder"
    fi

    # DatabaseSeeder must never be allowlisted.
    if [[ "$approved_seeder" == "DatabaseSeeder" ]]; then
        die "DatabaseSeeder is prohibited in the seeder allowlist."
    fi

    # Prevent duplicate entries.
    for ((j = 0; j < i; j++)); do

        if [[ "${APPROVED_SEEDERS[$j]}" == "$approved_seeder" ]]; then
            die "Duplicate seeder in allowlist: $approved_seeder"
        fi

    done

    log "Approved seeder: $approved_seeder"

done

log "Validated ${#APPROVED_SEEDERS[@]} approved seeders."

# ------------------------------------------------------------
# Verify requested seeder is approved
# ------------------------------------------------------------

if ! printf '%s\n' "${APPROVED_SEEDERS[@]}" |
    grep -Fxq -- "$SEEDER_NAME"; then

    die "Seeder is not approved: $SEEDER_NAME"

fi

log "Seeder authorization verified: $SEEDER_NAME"

# ------------------------------------------------------------
# Validate seeder implementation
# ------------------------------------------------------------

SEEDER_FILE="$SOURCE_DIR/database/seeders/${SEEDER_NAME}.php"

require_file "$SEEDER_FILE" "Seeder implementation"

log "Seeder implementation verified: $SEEDER_FILE"

# ------------------------------------------------------------
# Verify application image
# ------------------------------------------------------------

if ! docker image inspect "$APP_IMAGE" >/dev/null 2>&1; then
    die "Laravel application image does not exist: $APP_IMAGE"
fi

log "Laravel application image verified: $APP_IMAGE"

# ------------------------------------------------------------
# Verify PostgreSQL readiness
# ------------------------------------------------------------

log "Checking PostgreSQL readiness."

bash "$DEPLOYMENT_ROOT/scripts/database-verify.sh" "$APP_ENVIRONMENT"

log "PostgreSQL verification completed."

# ------------------------------------------------------------
# Verify Laravel migrations
# ------------------------------------------------------------

log "Checking Laravel migration status."

if ! MIGRATION_OUTPUT="$(
    compose run \
        --rm \
        --no-deps \
        "$APP_SERVICE" \
        php artisan migrate:status \
        --no-ansi
)"; then

    printf '%s\n' "$MIGRATION_OUTPUT" >&2
    die "Unable to verify Laravel migration status."

fi

printf '%s\n' "$MIGRATION_OUTPUT"

if printf '%s\n' "$MIGRATION_OUTPUT" |
    grep -Eiq 'Pending|Migration table not found'; then

    die "Database migrations are missing or pending. Complete migrations before seeding."

fi

log "Laravel migration status verified."

# ------------------------------------------------------------
# Production PostgreSQL backup verification
# ------------------------------------------------------------

if [[ "$APP_ENVIRONMENT" == "production" ]]; then

    BACKUP_SCRIPT="/usr/local/sbin/adama-revenue-production-backup"

    BACKUP_DIRECTORY="/var/backups/adama-revenue-api/production/postgresql"

    BACKUP_MAX_AGE_MINUTES="${DATABASE_BACKUP_MAX_AGE_MINUTES:-30}"

    log "Production database backup is mandatory."

    # Validate backup age configuration.
    if [[ ! "$BACKUP_MAX_AGE_MINUTES" =~ ^[1-9][0-9]*$ ]]; then
        die "DATABASE_BACKUP_MAX_AGE_MINUTES must be a positive integer."
    fi

    require_command sudo

    # The script is root-owned and executable through the
    # explicitly authorized sudoers command.
    if [[ ! -f "$BACKUP_SCRIPT" ]]; then
        die "Production backup script does not exist: $BACKUP_SCRIPT"
    fi

    log "Creating a fresh production PostgreSQL backup."

    if ! BACKUP_OUTPUT="$(
        sudo -n "$BACKUP_SCRIPT" 2>&1
    )"; then

        printf '%s\n' "$BACKUP_OUTPUT" >&2
        die "Production PostgreSQL backup failed. Seeding aborted."

    fi

    # Preserve backup script output for audit logs.
    printf '%s\n' "$BACKUP_OUTPUT"

    # Extract machine-readable backup metadata.
    mapfile -t BACKUP_FILE_LINES < <(
        printf '%s\n' "$BACKUP_OUTPUT" |
            grep '^BACKUP_FILE=' || true
    )

    mapfile -t BACKUP_CREATED_LINES < <(
        printf '%s\n' "$BACKUP_OUTPUT" |
            grep '^BACKUP_CREATED_AT=' || true
    )

    if [[ "${#BACKUP_FILE_LINES[@]}" -ne 1 ]]; then
        die "Backup script did not return exactly one BACKUP_FILE value."
    fi

    if [[ "${#BACKUP_CREATED_LINES[@]}" -ne 1 ]]; then
        die "Backup script did not return exactly one BACKUP_CREATED_AT value."
    fi

    BACKUP_FILE="${BACKUP_FILE_LINES[0]#BACKUP_FILE=}"

    BACKUP_CREATED_AT="${BACKUP_CREATED_LINES[0]#BACKUP_CREATED_AT=}"

    # Validate backup path.
    if [[ "$BACKUP_FILE" != "$BACKUP_DIRECTORY/"* ]]; then
        die "Backup file is outside the expected production backup directory."
    fi

    BACKUP_FILENAME="${BACKUP_FILE##*/}"

    # Validate exact backup filename format.
    if [[ ! "$BACKUP_FILENAME" =~ ^adama-revenue-production_([0-9]{8}T[0-9]{6}Z)\.dump$ ]]; then
        die "Invalid production backup filename: $BACKUP_FILENAME"
    fi

    # Validate backup timestamp.
    if [[ ! "$BACKUP_CREATED_AT" =~ ^[0-9]+$ ]]; then
        die "Invalid BACKUP_CREATED_AT value."
    fi

    FILENAME_TIMESTAMP="${BASH_REMATCH[1]}"

    FILENAME_ISO="${FILENAME_TIMESTAMP:0:4}-${FILENAME_TIMESTAMP:4:2}-${FILENAME_TIMESTAMP:6:2}T${FILENAME_TIMESTAMP:9:2}:${FILENAME_TIMESTAMP:11:2}:${FILENAME_TIMESTAMP:13:2}Z"

    if ! FILENAME_EPOCH="$(date -u -d "$FILENAME_ISO" +%s)"; then
        die "Unable to parse production backup filename timestamp."
    fi

    CURRENT_EPOCH="$(date +%s)"

    # Prevent timestamps significantly in the future.
    if (( BACKUP_CREATED_AT > CURRENT_EPOCH + 60 )); then
        die "Production backup timestamp is in the future."
    fi

    # Ensure the filename and machine-readable timestamps agree.
    TIMESTAMP_DIFFERENCE=$(( BACKUP_CREATED_AT - FILENAME_EPOCH ))

    if (( TIMESTAMP_DIFFERENCE < -60 || TIMESTAMP_DIFFERENCE > 60 )); then
        die "Production backup timestamps do not match."
    fi

    # Verify backup freshness.
    BACKUP_AGE_SECONDS=$(( CURRENT_EPOCH - BACKUP_CREATED_AT ))

    if (( BACKUP_AGE_SECONDS < 0 )); then
        BACKUP_AGE_SECONDS=0
    fi

    BACKUP_MAX_AGE_SECONDS=$(( BACKUP_MAX_AGE_MINUTES * 60 ))

    if (( BACKUP_AGE_SECONDS > BACKUP_MAX_AGE_SECONDS )); then
        die "Production backup is older than the allowed ${BACKUP_MAX_AGE_MINUTES}-minute limit."
    fi

    log "Production backup verification passed."
    log "Backup file: $BACKUP_FILE"
    log "Backup age: $(( BACKUP_AGE_SECONDS / 60 )) minutes."

fi

# ------------------------------------------------------------
# Execute exactly one approved seeder
# ------------------------------------------------------------

log "=========================================="
log "DATABASE SEEDING"
log "=========================================="

log "Environment: $APP_ENVIRONMENT"
log "Seeder: $SEEDER_NAME"
log "Compose project: $COMPOSE_PROJECT_NAME"
log "Application image: $APP_IMAGE"

compose run \
    --rm \
    --no-deps \
    "$APP_SERVICE" \
    php artisan db:seed \
    --class="Database\\Seeders\\${SEEDER_NAME}" \
    --force

# ------------------------------------------------------------
# Finished
# ------------------------------------------------------------

log "=========================================="
log "DATABASE SEEDING COMPLETED"
log "=========================================="

log "Environment: $APP_ENVIRONMENT"
log "Seeder: $SEEDER_NAME"

log "=========================================="
