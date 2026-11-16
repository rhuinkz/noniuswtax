<?php
declare(strict_types=1);
require __DIR__ . '/../lib/sql_guard.php';
$allowed = ['SELECT 1', 'SELECT COUNT(*) FROM transactions', 'SHOW TABLES', 'DESCRIBE members', 'EXPLAIN SELECT * FROM schools'];
foreach ($allowed as $query) {
    if (validated_diagnostic_sql($query) !== $query) { fwrite(STDERR, "Rejected safe query: $query\n"); exit(1); }
}
$denied = [
    'DELETE FROM transactions',
    'SELECT 1; DELETE FROM transactions',
    "SELECT 1 INTO OUTFILE '/tmp/pwn'",
    "SELECT LOAD_FILE('/etc/passwd')",
    'SELECT SLEEP(30)',
    'SELECT 1 /*comment*/',
    'SELECT 1 -- comment',
    'SELECT 1 # comment',
    'SELECT * FROM members FOR UPDATE',
    'UPDATE members SET active=0',
];
foreach ($denied as $query) {
    try { validated_diagnostic_sql($query); }
    catch(RuntimeException) { continue; }
    fwrite(STDERR, "Accepted unsafe query: $query\n"); exit(1);
}
echo "PASS diagnostic SQL guard smoke\n";
