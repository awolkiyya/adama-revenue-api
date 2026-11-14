
# PostgreSQL Backup Role

Ansible role for automated PostgreSQL backups for the Adama Municipal
Revenue API.

The role supports separate staging and production Docker Compose
projects on the same server.

## Features

- Environment-specific Docker Compose configuration
- PostgreSQL custom-format backups using `pg_dump`
- Backup integrity validation using `pg_restore`
- SHA-256 checksum generation and verification
- Atomic backup file creation
- Concurrent execution protection using `flock`
- Configurable backup retention
- Scheduled backups using cron
- Dedicated backup directories for staging and production
- Automatic cron and script removal when backups are disabled

## Configuration

Environment-specific settings are defined in:

    ansible/inventory/group_vars/staging.yml
    ansible/inventory/group_vars/production.yml

Important variables:

| Variable | Description |
|---|---|
| backup_enabled | Enables or disables backups |
| backup_project_dir | Docker Compose project directory |
| backup_compose_project | Docker Compose project name |
| backup_compose_file | Main Compose file |
| backup_compose_override_file | Environment-specific Compose override |
| backup_env_file | Environment configuration file |
| backup_postgres_service | PostgreSQL Compose service |
| backup_postgres_dir | Backup storage directory |
| backup_schedule_hour | Scheduled backup hour |
| backup_schedule_minute | Scheduled backup minute |
| backup_retention_days | Number of days to retain backups |

## Backup locations

Staging:

    /var/backups/adama-revenue-api/staging/postgresql

Production:

    /var/backups/adama-revenue-api/production/postgresql

## Install or update the backup role

Validate playbook syntax:

    ansible-playbook \
      -i ansible/inventory/production.ini \
      ansible/playbooks/provision.yml \
      --syntax-check

Apply staging:

    ansible-playbook \
      -i ansible/inventory/production.ini \
      ansible/playbooks/provision.yml \
      --limit staging \
      --tags postgres_backup \
      --ask-pass \
      --ask-become-pass

Apply production:

    ansible-playbook \
      -i ansible/inventory/production.ini \
      ansible/playbooks/provision.yml \
      --limit production \
      --tags postgres_backup \
      --ask-pass \
      --ask-become-pass

## Run a manual backup

Staging:

    sudo /usr/local/sbin/adama-revenue-staging-backup

Production:

    sudo /usr/local/sbin/adama-revenue-production-backup

## List backups

Staging:

    sudo ls -lah \
      /var/backups/adama-revenue-api/staging/postgresql/

Production:

    sudo ls -lah \
      /var/backups/adama-revenue-api/production/postgresql/

## Verify a backup checksum

Run from the relevant backup directory:

    sudo sha256sum --check adama-revenue-staging_TIMESTAMP.dump.sha256

Replace the filename with the actual backup and checksum filename.

## Inspect backup contents

Use the matching environment's Compose configuration:

    docker compose \
      --env-file /opt/adama-revenue-api/.env.staging \
      --project-directory /opt/adama-revenue-api/source-staging \
      -p adama-revenue-staging \
      -f /opt/adama-revenue-api/source-staging/docker/compose.yml \
      -f /opt/adama-revenue-api/source-staging/docker/compose.staging.yml \
      exec -T postgres pg_restore --list \
      < /var/backups/adama-revenue-api/staging/postgresql/BACKUP_FILE.dump

## Restore a backup

WARNING: Restoring with --clean can replace existing database
objects. Confirm the target database and take a separate backup
before restoring.

Example for staging:

    docker compose \
      --env-file /opt/adama-revenue-api/.env.staging \
      --project-directory /opt/adama-revenue-api/source-staging \
      -p adama-revenue-staging \
      -f /opt/adama-revenue-api/source-staging/docker/compose.yml \
      -f /opt/adama-revenue-api/source-staging/docker/compose.staging.yml \
      exec -T postgres sh -ec '
        : "${POSTGRES_USER:?}"
        : "${POSTGRES_DB:?}"

        exec pg_restore \
          --exit-on-error \
          --clean \
          --if-exists \
          --no-owner \
          --no-acl \
          --username="$POSTGRES_USER" \
          --dbname="$POSTGRES_DB"
      ' < /path/to/backup.dump

Run this only against the intended restore target.

## Inspect backup logs

Staging:

    sudo tail -f /var/log/adama-revenue-staging-backup.log

Production:

    sudo tail -f /var/log/adama-revenue-production-backup.log

## Inspect scheduled jobs

    sudo crontab -l

## Disable backups

Set the environment-specific variable:

    backup_enabled: false

Then apply the role again using the relevant environment limit.

The role removes the associated cron job and installed script.
Existing backup files are preserved.

## Retention policy

Current configuration:

| Environment | Schedule | Retention |
|---|---|---|
| Staging | Daily at 02:00 | 7 days |
| Production | Daily at 01:00 | 30 days |

Times use the server's local timezone for cron scheduling.

## Operational recommendation

Do not rely exclusively on backups stored on the same server
as the database.

For production, periodically copy encrypted backups to a
separate server or secure object storage and test restoration.
