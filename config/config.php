<?php
/**
 * WealthDash — Main Config Loader
 */
declare(strict_types=1);

if (!defined('WEALTHDASH')) {
    die('Direct access not allowed.');
}

function wd_load_env(string $path): void {
    if (!file_exists($path)) {
        die('ERROR: .env file not found. Copy .env.example to .env and fill credentials.');
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        if (preg_match('/^(["\'])(.*)\\1$/', $value, $m)) $value = $m[2];
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("$key=$value");
        }
    }
}

$envPath = dirname(__DIR__) . '/.env';
wd_load_env($envPath);

function env(string $key, mixed $default = null): mixed {
    $val = $_ENV[$key] ?? getenv($key);
    if ($val === false) return $default;
    return match (strtolower((string)$val)) {
        'true', '1', 'yes' => true,
        'false', '0', 'no' => false,
        default            => $val,
    };
}

define('APP_ROOT',   dirname(__DIR__));
define('APP_URL',    rtrim(env('APP_URL', 'http://localhost/wealthdash'), '/'));
define('APP_ENV',    env('APP_ENV', 'local'));
define('APP_NAME',   env('APP_NAME', 'WealthDash'));
define('APP_SECRET', env('APP_SECRET', ''));
define('IS_LOCAL',   APP_ENV === 'local');

date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Kolkata'));
mb_internal_encoding('UTF-8');

// For API endpoints, always log errors instead of displaying
// (display_errors ON breaks JSON responses)
define('IS_API', str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')); 

if (IS_LOCAL && !IS_API) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', APP_ROOT . '/logs/php_errors.log');
}

if (session_status() === PHP_SESSION_NONE) {
    $lifetime = (int) env('SESSION_LIFETIME', 86400);
    $secure   = !IS_LOCAL;
    session_name(env('SESSION_COOKIE_NAME', 'wd_session'));
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (!isset($_SESSION['_wd_init'])) {
        session_regenerate_id(true);
        $_SESSION['_wd_init'] = time();
    }
}

require_once APP_ROOT . '/config/constants.php';
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/helpers.php';

$composerAutoload = APP_ROOT . '/vendor/autoload.php';
if (file_exists($composerAutoload)) require_once $composerAutoload;