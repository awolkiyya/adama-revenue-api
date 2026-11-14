
#!/usr/bin/env bash

# ============================================================
# Adama Municipal Revenue API
# PostgreSQL Database Verification
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

ENVIRONMENT="${1:-}"

deployment_init "$ENVIRONMENT"

require_command docker
require_command sleep
require_command seq

# ------------------------------------------------------------
# PostgreSQL readiness configuration
# ------------------------------------------------------------

MAX_ATTEMPTS="${DB_VERIFY_MAX_ATTEMPTS:-30}"
RETRY_INTERVAL="${DB_VERIFY_RETRY_INTERVAL:-5}"

if ! [[ "$MAX_ATTEMPTS" =~ ^[1-9][0-9]*$ ]]; then
    die "DB_VERIFY_MAX_ATTEMPTS must be a positive integer."
fi

if ! [[ "$RETRY_INTERVAL" =~ ^[1-9][0-9]*$ ]]; then
    die "DB_VERIFY_RETRY_INTERVAL must be a positive integer."
fi

# ------------------------------------------------------------
# Verify PostgreSQL container
# ------------------------------------------------------------

verify_postgres() {

    local container_id
    local container_status
    local attempt
    local database_result

    log "Starting PostgreSQL verification."
    log "Environment: $APP_ENVIRONMENT"
    log "Compose project: $COMPOSE_PROJECT_NAME"
    log "PostgreSQL service: $POSTGRES_SERVICE"

    for attempt in $(seq 1 "$MAX_ATTEMPTS"); do

        container_id="$(compose ps -q "$POSTGRES_SERVICE")"

        if [[ -z "$container_id" ]]; then

            log_warn "PostgreSQL container has not been created yet ($attempt/$MAX_ATTEMPTS)."

            sleep "$RETRY_INTERVAL"

            continue

        fi

        # ----------------------------------------------------
        # Check container status
        # ----------------------------------------------------

        container_status="$(
            docker inspect \
                --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' \
                "$container_id"
        )"

        log "PostgreSQL status: $container_status ($attempt/$MAX_ATTEMPTS)"

        case "$container_status" in
            healthy|running)
                ;;
            *)
                sleep "$RETRY_INTERVAL"
                continue
                ;;
        esac

        # ----------------------------------------------------
        # Check database readiness and execute SQL
        # ----------------------------------------------------

        if database_result="$(
            docker exec "$container_id" sh -ec '
                : "${POSTGRES_USER:?POSTGRES_USER is not configured}"
                : "${POSTGRES_DB:?POSTGRES_DB is not configured}"

                pg_isready \
                    --username="$POSTGRES_USER" \
                    --dbname="$POSTGRES_DB"

                result="$(
                    psql \
                        --username="$POSTGRES_USER" \
                        --dbname="$POSTGRES_DB" \
                        --no-align \
                        --tuples-only \
                        --set=ON_ERROR_STOP=1 \
                        --command="SELECT 1"
                )"

                test "$result" = "1"

                printf "%s\n" "$result"
            ' 2>&1
        )"; then

            if [[ "$database_result" == "1" ]]; then

                log "PostgreSQL connection successful."
                log "Database SQL verification successful."
                log "PostgreSQL is ready."

                return 0

            fi

        fi

        log_warn "PostgreSQL is not ready for database queries yet."

        sleep "$RETRY_INTERVAL"

    done

    die "PostgreSQL verification failed after $MAX_ATTEMPTS attempts."

}

# ------------------------------------------------------------
# Execute verification
# ------------------------------------------------------------

echo ""
log "=========================================="
log "DATABASE VERIFICATION"
log "=========================================="

verify_postgres

echo ""
log "=========================================="
log "DATABASE VERIFICATION PASSED"
log "=========================================="

log "Environment: $APP_ENVIRONMENT"
log "Project: $COMPOSE_PROJECT_NAME"
log "PostgreSQL service: $POSTGRES_SERVICE"
