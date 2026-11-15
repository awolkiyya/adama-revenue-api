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

        # ----------------------------------------------------
        # Find PostgreSQL container
        # ----------------------------------------------------

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
                log_warn "PostgreSQL container is not running or healthy yet."
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

                # Suppress pg_isready output.
                # Otherwise its readiness message gets captured
                # together with the SQL result.

                if ! pg_isready \
                    --username="$POSTGRES_USER" \
                    --dbname="$POSTGRES_DB" \
                    >/dev/null 2>&1; then

                    echo "pg_isready could not verify PostgreSQL readiness." >&2
                    exit 1

                fi

                # Execute a real SQL query.

                result="$(
                    psql \
                        --no-psqlrc \
                        --username="$POSTGRES_USER" \
                        --dbname="$POSTGRES_DB" \
                        --no-align \
                        --tuples-only \
                        --quiet \
                        --set=ON_ERROR_STOP=1 \
                        --command="SELECT 1"
                )"

                # Verify the exact SQL result.

                if [ "$result" != "1" ]; then

                    echo "SQL verification returned an unexpected result: $result" >&2

                    exit 1

                fi

                printf "%s\n" "$result"

            ' 2>&1
        )"; then

            if [[ "$database_result" == "1" ]]; then

                log "PostgreSQL connection successful."
                log "Database SQL verification successful."
                log "PostgreSQL is ready."

                return 0

            fi

            log_warn "PostgreSQL verification returned an unexpected response."

            if [[ -n "$database_result" ]]; then
                log_warn "Verification output: $database_result"
            fi

        else

            log_warn "PostgreSQL database verification failed ($attempt/$MAX_ATTEMPTS)."

            if [[ -n "$database_result" ]]; then
                log_warn "Database verification error: $database_result"
            fi

        fi

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
