#!/usr/bin/env bash

# ============================================================
# ADAMA MUNICIPAL REVENUE API
# Controlled Reference Database Seeding
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

# ------------------------------------------------------------
# Production protection
# ------------------------------------------------------------

if [[ "$APP_ENVIRONMENT" == "production" ]]; then

    if [[ "${CONFIRM_PRODUCTION_SEEDING:-}" != "YES" ]]; then
        die "Production seeding requires CONFIRM_PRODUCTION_SEEDING=YES."
    fi

    log_warn "Production reference-data seeding is explicitly authorized."

fi

# ------------------------------------------------------------
# Validate requested seeder name
# ------------------------------------------------------------

if [[ ! "$SEEDER_NAME" =~ ^[A-Za-z][A-Za-z0-9]*Seeder$ ]]; then
    die "Invalid seeder class name: $SEEDER_NAME"
fi

# ------------------------------------------------------------
# Reject prohibited seeders
# ------------------------------------------------------------

case "$SEEDER_NAME" in

    DatabaseSeeder)
        die "DatabaseSeeder is prohibited. Execute approved seeders individually."
        ;;

    SystemAdminSeeder|FieldCollectorSeeder)
        die "$SEEDER_NAME is a user-provisioning seeder and cannot run through this script."
        ;;

    RoleSeeder|RolePermissionSeeder)
        die "$SEEDER_NAME requires a dedicated authorization-management procedure."
        ;;

esac

# ------------------------------------------------------------
# Load approved seeder allowlist
# ------------------------------------------------------------

ALLOWLIST_FILE="$DEPLOYMENT_ROOT/seeder.allowlist"

require_file "$ALLOWLIST_FILE" "Seeder allowlist"

log "Loading approved seeder allowlist."

# Ignore blank lines and comments.
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

    # Reject prohibited seeders.
    case "$approved_seeder" in

        DatabaseSeeder)
            die "DatabaseSeeder is prohibited in the seeder allowlist."
            ;;

        SystemAdminSeeder|FieldCollectorSeeder)
            die "User-provisioning seeder is prohibited in the allowlist: $approved_seeder"
            ;;

        RoleSeeder|RolePermissionSeeder)
            die "Authorization-management seeder is prohibited in the allowlist: $approved_seeder"
            ;;

    esac

    # Prevent duplicate seeder execution.
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

log "Seeder implementation verified."

# ------------------------------------------------------------
# Verify PostgreSQL readiness
# ------------------------------------------------------------

log "Checking PostgreSQL readiness."

bash "$DEPLOYMENT_ROOT/scripts/database-verify.sh" "$APP_ENVIRONMENT"

log "PostgreSQL verification completed."

# ------------------------------------------------------------
# Validate application image
# ------------------------------------------------------------

if ! docker image inspect "$APP_IMAGE" >/dev/null 2>&1; then
    die "Laravel application image does not exist: $APP_IMAGE"
fi

log "Laravel application image verified: $APP_IMAGE"

# ------------------------------------------------------------
# Execute exactly one approved seeder
# ------------------------------------------------------------

log "=========================================="
log "DATABASE SEEDING"
log "=========================================="

log "Environment: $APP_ENVIRONMENT"
log "Seeder: $SEEDER_NAME"
log "Compose project: $COMPOSE_PROJECT_NAME"

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
