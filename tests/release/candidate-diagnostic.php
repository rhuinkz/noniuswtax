<?php
declare(strict_types=1);
// Read-only file-system diagnostic for the disposable staging Apache container.
// Never include this file in the release ZIP; never publish actual credentials.
$root = realpath(__DIR__ . '/../..');
if ($root !== '/var/www/html') {
    fwrite(STDERR, "Refusing diagnostic outside isolated staging container.\n");
    exit(2);
}
$cfgPath = $root . '/config.local.php';
if (!is_file($cfgPath)) {
    fwrite(STDERR, "Isolated staging application not installed.\n");
    exit(2);
}
$cfg = require $cfgPath;
if (!is_array($cfg) || ($cfg['db']['name'] ?? null) !== 'nonius_release_test'
    || ($cfg['db']['host'] ?? null) !== 'db') {
    fwrite(STDERR, "Refusing diagnostic outside isolated staging database.\n");
    exit(2);
}
$bootstrap = $root . '/lib/bootstrap.php';
$text = file_get_contents($bootstrap);
if (!is_string($text)) {
    fwrite(STDERR, "Unable to read on-disk bootstrap version.\n");
    exit(1);
}
$version = null;
if (preg_match("/const\\s+APP_VERSION\\s*=\\s*'([^']+)'/", $text, $m)) {
    $version = $m[1];
}
$releaseMarker = $root . '/nonius-update.json';
$manifestVersion = null;
if (is_file($releaseMarker)) {
    $manifest = json_decode((string)file_get_contents($releaseMarker), true);
    if (is_array($manifest)) $manifestVersion = $manifest['version'] ?? null;
}
$time = filemtime($bootstrap);
$diag = [
    'environment' => 'disposable-nonius-rc-staging',
    'disk_bootstrap_version' => $version,
    'disk_bootstrap_sha256' => hash_file('sha256', $bootstrap),
    'disk_bootstrap_mtime_utc' => $time === false ? null : gmdate('c', $time),
    'on_disk_update_manifest_version' => $manifestVersion,
    'php_runtime_cli' => PHP_VERSION,
    'opcache_module_loaded_in_cli' => extension_loaded('Zend OPcache'),
    'opcache_validate_timestamps_ini' => ini_get('opcache.validate_timestamps'),
    'opcache_revalidate_freq_ini' => ini_get('opcache.revalidate_freq'),
    'warning' => 'CLI ini readings may differ from Apache OPcache. This is a read-only on-disk diagnostic; not a production test.'
];
echo json_encode($diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
if ($version === null) exit(1);
