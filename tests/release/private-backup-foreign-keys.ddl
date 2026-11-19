-- Returns only an aggregate count of orphaned references in restored
-- NONIUSWTAX. Never returns source data or identifiers.
-- Supported initial installed schema has single-column foreign keys.
SET SESSION group_concat_max_len=16777216;
SET @fk_sql=(
  SELECT COALESCE(CONCAT(
    'SELECT ',GROUP_CONCAT(CONCAT(
       '(SELECT COUNT(*) FROM `',REPLACE(TABLE_NAME,'`','``'),'` AS child',
       ' LEFT JOIN `',REPLACE(REFERENCED_TABLE_NAME,'`','``'),'` AS parent',
       ' ON child.`',REPLACE(COLUMN_NAME,'`','``'),'`=parent.`',
                  REPLACE(REFERENCED_COLUMN_NAME,'`','``'),'`',
       ' WHERE child.`',REPLACE(COLUMN_NAME,'`','``'),'` IS NOT NULL',
       ' AND parent.`',REPLACE(REFERENCED_COLUMN_NAME,'`','``'),'` IS NULL)'
    ) SEPARATOR ' + '),' AS orphan_rows'), 'SELECT 0 AS orphan_rows')
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
);
PREPARE offline_fk_query FROM @fk_sql;
EXECUTE offline_fk_query;
DEALLOCATE PREPARE offline_fk_query;
