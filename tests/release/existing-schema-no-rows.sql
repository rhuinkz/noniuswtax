-- Before recording any schema metadata, verify EVERY imported base table is empty.
-- This check uses only EXISTS booleans. It cannot expose any record contents.
SET SESSION group_concat_max_len = 16777216;
SET @checks = (
  SELECT CONCAT(
    'SELECT (',
    GROUP_CONCAT(CONCAT(
      'EXISTS(SELECT 1 FROM `',
      REPLACE(TABLE_NAME, '`', '``'),
      '` LIMIT 1)'
    ) SEPARATOR ' + '),
    ') AS tables_containing_any_rows'
  )
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_TYPE = 'BASE TABLE'
);
PREPARE preflight_empty_check FROM @checks;
EXECUTE preflight_empty_check;
DEALLOCATE PREPARE preflight_empty_check;
