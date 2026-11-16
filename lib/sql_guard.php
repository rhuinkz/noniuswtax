<?php
declare(strict_types=1);

/**
 * Conservative guard for Project Owner SQL diagnostics.
 * Defense in depth; not a substitute for least-privilege DB credentials.
 */
function validated_diagnostic_sql(string $sql): string {
    $sql = trim($sql);
    if ($sql === '') throw new RuntimeException('Enter a SQL statement.');
    $sql = rtrim($sql, " \t\r\n;");
    if ($sql === '' || str_contains($sql, ';')) throw new RuntimeException('Run one SQL statement at a time.');
    if (preg_match('/\/\*|--[\s\x00-\x1f]|#/u', $sql)) {
        throw new RuntimeException('SQL comments are not supported in the diagnostic console.');
    }
    if (!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql)) {
        throw new RuntimeException('Production SQL Console is read-only. Use application workflows or a reviewed database migration for changes.');
    }
    if (preg_match('/\b(?:INTO|OUTFILE|DUMPFILE|LOAD_FILE|SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK|FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE)\b/i', $sql)) {
        throw new RuntimeException('This diagnostic query contains a potentially unsafe operation.');
    }
    return $sql;
}
