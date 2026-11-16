<?php
declare(strict_types=1);

const APP_VERSION = '0.4.56';
const APP_NAME = 'Non-IUS Withholding Tax Monitoring';
const ROOT_PATH = __DIR__ . '/..';
const CONFIG_FILE = ROOT_PATH . '/config.local.php';

date_default_timezone_set('UTC');

function is_installed(): bool { return is_file(CONFIG_FILE); }
function config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    if (!is_installed()) return [];
    $value = require CONFIG_FILE;
    if (!is_array($value)) throw new RuntimeException('Invalid configuration file.');
    return $cfg = $value;
}

function app_url(string $path = ''): string {
    $cfg = config();
    $base = rtrim((string)($cfg['base_url'] ?? ''), '/');
    if ($base === '') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = ($https ? 'https' : 'http') . '://' . $host;
    }
    return $base . '/' . ltrim($path, '/');
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $cfg = config();
    if (!$cfg) throw new RuntimeException('Application is not installed.');
    $db = $cfg['db'] ?? [];
    foreach (['host','name','user','password'] as $key) if (!array_key_exists($key, $db)) throw new RuntimeException('Database configuration is incomplete.');
    $port = (int)($db['port'] ?? 3306);
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $port, $db['name']);
    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function now_iso(): string { return gmdate('Y-m-d\TH:i:s.') . substr((string)microtime(), 2, 3) . 'Z'; }
function json_encode_safe(mixed $value): string {
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
function json_decode_assoc(?string $value): array {
    if (!$value) return [];
    $x = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    return is_array($x) ? $x : [];
}

function send_no_cache_headers(): void {
    if (headers_sent()) return;
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function request_is_secure(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    $base = strtolower((string)(config()['base_url'] ?? ''));
    return str_starts_with($base, 'https://');
}

function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = request_is_secure();
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_name('nonius_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    $now = time();
    if (!empty($_SESSION['member_id'])) {
        $started = (int)($_SESSION['session_started_at'] ?? $now);
        $seen = (int)($_SESSION['session_last_seen'] ?? $now);
        if (($now - $seen) > 7200 || ($now - $started) > 43200) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
    }
    if (empty($_SESSION['session_started_at'])) $_SESSION['session_started_at'] = $now;
    $_SESSION['session_last_seen'] = $now;
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

function csrf_token(): string { start_secure_session(); return (string)$_SESSION['csrf']; }
function verify_csrf(): void {
    start_secure_session();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (!is_string($token) || !hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) throw new RuntimeException('Your session token expired. Reload the page and try again.');
}

require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/calculation.php';
require_once __DIR__ . '/repository.php';

if (is_installed()) {
    $maintenance=ROOT_PATH.'/storage/maintenance.lock';
    $script=basename((string)($_SERVER['SCRIPT_NAME']??''));
    if(is_file($maintenance) && $script!=='update.php'){
        $age=time()-(int)@filemtime($maintenance);
        if($age>=0 && $age<600){http_response_code(503);if(!headers_sent()){header('Retry-After: 30');header('Cache-Control: no-store');}exit('Application update in progress. Please retry in a moment.');}
        @unlink($maintenance);
    }
    start_secure_session();
    ensure_database();
}
