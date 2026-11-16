<?php
declare(strict_types=1);
// Restore code only from the isolated updater-created ZIP; never run on Hostinger.
$root = realpath(__DIR__ . '/../..');
if ($root !== '/var/www/html') {
    fwrite(STDERR, "FAIL: Refusing outside the disposable Docker web root.\n");
    exit(1);
}
$cfgPath = $root . '/config.local.php';
if (!is_file($cfgPath)) {
    fwrite(STDERR, "FAIL: No isolated installed test configuration.\n");
    exit(1);
}
$cfg = require $cfgPath;
if (!is_array($cfg) || ($cfg['db']['name'] ?? null) !== 'nonius_release_test') {
    fwrite(STDERR, "FAIL: Refusing restoration outside the synthetic database.\n");
    exit(1);
}
$backups = glob($root . '/storage/backups/before-0.4.57-rc.1-*.zip') ?: [];
if (count($backups) !== 1) {
    fwrite(STDERR, "FAIL: Expected exactly one updater-created original code backup.\n");
    exit(1);
}
$zip = new ZipArchive();
if ($zip->open($backups[0]) !== true) {
    fwrite(STDERR, "FAIL: Cannot open updater code backup.\n");
    exit(1);
}
try {
    if ($zip->locateName('lib/bootstrap.php') === false) {
        throw new RuntimeException('Backup contains no application version file');
    }
    $oldBootstrap = $zip->getFromName('lib/bootstrap.php');
    if (!is_string($oldBootstrap) || !str_contains($oldBootstrap, "const APP_VERSION = '0.4.56';")) {
        throw new RuntimeException('Backup is not the expected source version');
    }
    if (!$zip->extractTo($root)) {
        throw new RuntimeException('Could not restore source files');
    }
} finally {
    $zip->close();
}
$marker = $root . '/nonius-update.json';
if (is_file($marker) && !unlink($marker)) {
    throw new RuntimeException('Unable to remove new-only release marker after rollback');
}
echo "PASS: Original 0.4.56 application code restored from in-app updater backup.\n";
