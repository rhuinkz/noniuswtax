-- Synthetic-only / structure-only metadata report.
-- Never query application data rows, TINs, passwords, JSON values, or finance records.
SET SESSION group_concat_max_len = 16777216;
SELECT JSON_OBJECT(
  'format', 'NONIUS_SCHEMA_PREFLIGHT_V1',
  'source', 'isolated_structure_only_sql_export',
  'actual_existing_data_inspected', FALSE,
  'production_approval', 'PENDING',
  'tables', COALESCE((
    SELECT JSON_ARRAYAGG(JSON_OBJECT(
      'name', TABLE_NAME,
      'kind', TABLE_TYPE,
      'engine', ENGINE
    )) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
  ), JSON_ARRAY()),
  'columns', COALESCE((
    SELECT JSON_ARRAYAGG(JSON_OBJECT(
      'table', TABLE_NAME,
      'name', COLUMN_NAME,
      'type', COLUMN_TYPE,
      'nullable', IS_NULLABLE,
      'key', COLUMN_KEY,
      'extra', EXTRA
    )) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
  ), JSON_ARRAY()),
  'foreign_keys', COALESCE((
    SELECT JSON_ARRAYAGG(JSON_OBJECT(
      'table', TABLE_NAME,
      'column', COLUMN_NAME,
      'references_table', REFERENCED_TABLE_NAME,
      'references_column', REFERENCED_COLUMN_NAME
    )) FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND REFERENCED_TABLE_NAME IS NOT NULL
  ), JSON_ARRAY()),
  'indexes', COALESCE((
    SELECT JSON_ARRAYAGG(JSON_OBJECT(
      'table', TABLE_NAME,
      'index', INDEX_NAME,
      'column', COLUMN_NAME,
      'position', SEQ_IN_INDEX,
      'non_unique', NON_UNIQUE
    )) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
  ), JSON_ARRAY()),
  'triggers', COALESCE((
    SELECT JSON_ARRAYAGG(JSON_OBJECT(
      'name', TRIGGER_NAME,
      'table', EVENT_OBJECT_TABLE,
      'timing', ACTION_TIMING,
      'event', EVENT_MANIPULATION
    )) FROM information_schema.TRIGGERS
    WHERE TRIGGER_SCHEMA = DATABASE()
  ), JSON_ARRAY()),
  'migration_history_from_live_data_available', FALSE
) AS schema_report;
