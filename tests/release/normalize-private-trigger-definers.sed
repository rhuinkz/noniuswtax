# Isolated import only: rewrite mysqldump's trigger DDL DEFINER metadata.
# This NEVER alters INSERT data lines. The original SQL file remains unchanged.
/^\/\*!50003 CREATE\*\/[[:space:]]+\/\*!50017 DEFINER=/ s#\/\*!50017 DEFINER=[^*]*\*\/#/*!50017 DEFINER=`nonius_historical_local`@`%`*/#
