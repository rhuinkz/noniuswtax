#!/usr/bin/env bash
# Restore updater-created SQL backup in the disposable Docker test DB ONLY.
set -Eeuo pipefail
DB='nonius_release_test'
if [[ "${MARIADB_DATABASE:-}" != "$DB" || "${MARIADB_ROOT_PASSWORD:-}" != "local-release-root-only" ]]; then
    echo 'FAIL: Refusing to restore any non-test database' >&2
    exit 1
fi
mapfile -t dumps < <(find /staging-backups -maxdepth 1 -type f -name 'before-0.4.57-rc.1-*-db.sql' | sort)
if [[ "${#dumps[@]}" -ne 1 ]]; then
    echo 'FAIL: Expected exactly one updater-created staging DB backup' >&2
    exit 1
fi
if ! grep -q '^-- BACKUP COMPLETE tables=' "${dumps[0]}"; then
    echo 'FAIL: Database backup is incomplete' >&2
    exit 1
fi
export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
# Only the dedicated compose project/database is affected; no network DB port is exposed.
mariadb -u root -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mariadb -u root "$DB" < "${dumps[0]}"
n=$(mariadb -u root -N "$DB" -e 'SELECT COUNT(*) FROM transactions')
t=$(mariadb -u root -N -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$DB'")
if [[ "$n" -lt 10003 || "$t" -lt 15 ]]; then
    echo 'FAIL: Rollback did not restore expected synthetic records or trigger protections' >&2
    exit 1
fi
echo "PASS: Restored pre-update disposable database from the updater backup: $n transactions, $t triggers."
