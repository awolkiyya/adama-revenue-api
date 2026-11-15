#!/usr/bin/env bash

# ============================================================
# Adama Municipal Revenue API
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

require_command docker

# ------------------------------------------------------------
# Require explicit authorization
# ------------------------------------------------------------

if [[ "${RUN_REFERENCE_SEEDERS:-false}" != "true" ]]; then
    die "Seeding is disabled. Set RUN_REFERENCE_SEEDERS=true to authorize it explicitly."
fi

if [[ "$REFERENCE_SEEDING_ENABLED" != "true" ]]; then
    die "Reference seeding is disabled in the $APP_ENVIRONMENT configuration."
fi

# ------------------------------------------------------------
# Production protection
# ------------------------------------------------------------

if [[ "$APP_ENVIRONMENT" == "production" ]]; then

    if [[ "${CONFIRM_PRODUCTION_SEEDING:-}" != "YES" ]]; then
        die "Production seeding requires CONFIRM_PRODUCTION_SEEDING=YES."
    fi

fi

# ------------------------------------------------------------
# Validate seeder name
# ------------------------------------------------------------

if [[ ! "$SEEDER_NAME" =~ ^[A-Za-z][A-Za-z0-9]*Seeder$ ]]; then
    die "Invalid seeder class name: $SEEDER_NAME"
fi

if [[ "$SEEDER_NAME" == "DatabaseSeeder" ]]; then
    die "DatabaseSeeder is prohibited. Execute approved seeders individually."
fi

case "$SEEDER_NAME" in
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

ALLOWLIST_FILE="$DEPLOYMENT_ROOT/config/seeders.allowlist"

require_file "$ALLOWLIST_FILE" "Seeder allowlist"

if ! grep -Fxq "$SEEDER_NAME" "$ALLOWLIST_FILE"; then
    die "Seeder is not approved: $SEEDER_NAME"
fi

# ------------------------------------------------------------
# Validate seeder implementation
# ------------------------------------------------------------

SEEDER_FILE="$SOURCE_DIR/database/seeders/${SEEDER_NAME}.php"

require_file "$SEEDER_FILE" "Seeder implementation"

# ------------------------------------------------------------
# Verify PostgreSQL readiness
# ------------------------------------------------------------

log "Checking PostgreSQL readiness."

bash "$DEPLOYMENT_ROOT/scripts/database-verify.sh" "$APP_ENVIRONMENT"

# ------------------------------------------------------------
# Validate application image
# ------------------------------------------------------------

if ! docker image inspect "$APP_IMAGE" >/dev/null 2>&1; then
    die "Laravel application image does not exist: $APP_IMAGE"
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
