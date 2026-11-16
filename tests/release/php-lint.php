<?php
declare(strict_types=1);

/**
 * Lint every PHP source file via PHP itself, avoiding Windows PowerShell
 * native argument quoting issues with shell wildcard expressions.
 */
$root = dirname(__DIR__, 2);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
$count = 0;
$failures = 0;
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (preg_match('~^(?:vendor|node_modules|storage|temp|test-results|\\.git)/~', $relative)) continue;
    $lines = [];
    $exit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $lines, $exit);
    $count++;
    if ($exit !== 0) {
        $failures++;
        fwrite(STDERR, "FAIL PHP syntax: $relative\n" . implode("\n", $lines) . "\n");
    }
}
if ($count === 0) {
    fwrite(STDERR, "FAIL: No PHP source files found for lint\n");
    exit(1);
}
if ($failures > 0) {
    fwrite(STDERR, "FAIL: $failures / $count PHP sources failed syntax checking\n");
    exit(1);
}
echo "PASS: Syntax checked $count PHP source files\n";
