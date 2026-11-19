-- This file contains SQL SOURCE CODE ONLY, no real record values.
-- Returns aggregate counts only from a disposable restored database.
-- Never emit IDs, users, TINs, emails, names, financial values or row contents.
SELECT JSON_OBJECT(
  'format','NONIUS_PRIVATE_RESTORE_METADATA_V1',
  'tables',(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'),
  'columns',(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()),
  'triggers',(SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()),
  'foreign_key_columns',(SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL),
  'composite_foreign_keys',(SELECT COUNT(*) FROM (
     SELECT CONSTRAINT_NAME,TABLE_NAME
     FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
     GROUP BY CONSTRAINT_NAME,TABLE_NAME HAVING COUNT(*)>1
  ) AS composite_list),
  'migration037_rows',(SELECT COUNT(*) FROM schema_migrations WHERE id='037_checked_hash_period_guard'),
  'migration037_status',(SELECT status FROM schema_migrations WHERE id='037_checked_hash_period_guard' LIMIT 1),
  'checked_hash_period_trigger',(SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='protect_checked_hash_period_update'),
  'transaction_rows',(SELECT COUNT(*) FROM transactions),
  'checked_transaction_rows',(SELECT COUNT(*) FROM transactions WHERE checked_snapshot_hash IS NOT NULL),
  'history_rows',(SELECT COUNT(*) FROM transaction_history),
  'audit_rows',(SELECT COUNT(*) FROM audit),
  'migration_rows',(SELECT COUNT(*) FROM schema_migrations),
  'period_rows',(SELECT COUNT(*) FROM transaction_periods),
  'period_history_rows',(SELECT COUNT(*) FROM transaction_period_history),
  'update_history_rows',(SELECT COUNT(*) FROM update_history)
) AS private_restore_report;
