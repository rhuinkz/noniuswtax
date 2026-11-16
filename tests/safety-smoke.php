<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$api = file_get_contents($root . '/api.php');
$js = file_get_contents($root . '/assets/app.js');
if ($api === false || $js === false) { fwrite(STDERR, "Missing source file\n"); exit(1); }
if (str_contains($api, "resetTestData") || str_contains($api, 'TRUNCATE TABLE audit')) { fwrite(STDERR, "Destructive reset endpoint detected\n"); exit(1); }
if (str_contains($js, "resetTestData") || str_contains($js, 'RESET TEST DATA')) { fwrite(STDERR, "Destructive reset UI detected\n"); exit(1); }
echo "Safety smoke test passed\n";
