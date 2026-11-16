#!/usr/bin/env bash
# Run only inside the dedicated disposable nonius-release-verification DB service.
set -Eeuo pipefail
DB='nonius_release_test'
RESTORE='nonius_release_restore'
test "${MARIADB_DATABASE:-}" = "$DB" || { echo 'FAIL: Refusing non-test MariaDB'; exit 1; }
test -n "${MARIADB_ROOT_PASSWORD:-}" || { echo 'FAIL: No disposable DB root secret'; exit 1; }
export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
trap 'rm -f /tmp/nonius-release-backup.sql' EXIT
mariadb -u root -N -e "CREATE DATABASE IF NOT EXISTS $RESTORE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# Dump all tables, data and triggers. The dump stays inside the disposable DB container.
mariadb-dump -u root --single-transaction --routines --events --triggers "$DB" > /tmp/nonius-release-backup.sql
test -s /tmp/nonius-release-backup.sql || { echo 'FAIL: Empty SQL backup'; exit 1; }
mariadb -u root "$RESTORE" < /tmp/nonius-release-backup.sql
count() { mariadb -u root -N -B "$1" -e "SELECT COUNT(*) FROM $2"; }
src_tx=$(count "$DB" transactions)
dst_tx=$(count "$RESTORE" transactions)
src_audit=$(count "$DB" audit)
dst_audit=$(count "$RESTORE" audit)
src_migration=$(count "$DB" schema_migrations)
dst_migration=$(count "$RESTORE" schema_migrations)
src_triggers=$(mariadb -u root -N -B -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$DB'")
dst_triggers=$(mariadb -u root -N -B -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$RESTORE'")
test "$src_tx" -eq "$dst_tx" && test "$src_tx" -ge 3 || { echo 'FAIL: Transaction restore totals differ'; exit 1; }
test "$src_audit" -eq "$dst_audit" && test "$src_audit" -ge 1 || { echo 'FAIL: Audit restore totals differ'; exit 1; }
test "$src_migration" -eq "$dst_migration" && test "$src_migration" -ge 37 || { echo 'FAIL: Migration restore totals differ'; exit 1; }
test "$src_triggers" -eq "$dst_triggers" && test "$dst_triggers" -ge 6 || { echo 'FAIL: Database triggers were not restored'; exit 1; }
# Check protected data in the restored clone, not just trigger names.
if mariadb -u root "$RESTORE" -e "DELETE FROM audit LIMIT 1" >/dev/null 2>&1; then echo 'FAIL: Restored audit deletion succeeded'; exit 1; fi
mariadb -u root "$RESTORE" -e "UPDATE transaction_periods SET state='closed' WHERE period_month='2026-01' AND target_key='school:synthetic-school-a'"
if mariadb -u root "$RESTORE" -e "UPDATE transactions SET checked_snapshot_hash=SHA2('tamper',256) WHERE id='synthetic-transaction-a'" >/dev/null 2>&1; then echo 'FAIL: Restored closed-month hash tamper succeeded'; exit 1; fi
if mariadb -u root "$RESTORE" -e "DELETE FROM transactions WHERE id='synthetic-transaction-a'" >/dev/null 2>&1; then echo 'FAIL: Restored transaction delete succeeded'; exit 1; fi
mkdir -p /suite-output
printf '{"status":"passed","source_transactions":%s,"restored_transactions":%s,"source_audit_events":%s,"restored_audit_events":%s,"source_migrations":%s,"restored_migrations":%s,"source_triggers":%s,"restored_triggers":%s,"audit_append_only_after_restore":true,"closed_month_guard_after_restore":true}\n' "$src_tx" "$dst_tx" "$src_audit" "$dst_audit" "$src_migration" "$dst_migration" "$src_triggers" "$dst_triggers" > /suite-output/backup-restore.json
echo "PASS: MariaDB full synthetic backup/restore with $src_tx transactions, $src_audit audit entries and $src_triggers triggers; integrity guards verified"
