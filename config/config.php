<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

// Optional local overrides (git-ignored). Loaded first so a live server can keep
// its database credentials, URL and debug flags outside version control.
$localOverride = __DIR__ . DIRECTORY_SEPARATOR . 'local.php';
if (is_file($localOverride)) {
    require $localOverride;
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'Laundry Friends');
}
if (!defined('APP_TAGLINE')) {
    define('APP_TAGLINE', 'Wash. Dry. Fold. Delivered.');
}
if (!defined('APP_CURRENCY')) {
    define('APP_CURRENCY', '₹');
}
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Kolkata');
}
if (!defined('DB_TIMEZONE')) {
    // Fixed IST offset, so this works even when MySQL's timezone tables are
    // not populated (named zones such as Asia/Kolkata would fail there).
    define('DB_TIMEZONE', '+05:30');
}
if (!defined('APP_CONTACT_PHONE')) {
    define('APP_CONTACT_PHONE', '+91 98765 43210');
}
if (!defined('APP_CONTACT_EMAIL')) {
    define('APP_CONTACT_EMAIL', 'support@laundryfriends.test');
}
if (!defined('APP_ASSET_VERSION')) {
    define('APP_ASSET_VERSION', '2026-10-29d');
}

date_default_timezone_set(APP_TIMEZONE);

// Load environment variables from .env if present
if (is_file(__DIR__ . '/../../.env')) {
    $envLines = file(__DIR__ . '/../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        [$key, $val] = array_map('trim', explode('=', $line, 2));
        if ($key && $val) {
            putenv("$key=$val");
        }
    }
}

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
}
if (!defined('DB_PORT')) {
    define('DB_PORT', getenv('DB_PORT') ?: '3307');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') ?: 'laundry_friends_demo');
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') ?: 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('DB_PASS') ?: '');
}

if (!defined('SESSION_NAME')) {
    define('SESSION_NAME', 'LAUNDRYFRDS');
}
if (!defined('CSRF_SESSION_KEY')) {
    define('CSRF_SESSION_KEY', 'csrf_token');
}
if (!defined('BACKUP_DIR')) {
    define('BACKUP_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups');
}
if (!defined('BACKUP_RETENTION_DAYS')) {
    define('BACKUP_RETENTION_DAYS', 7);
}
if (!defined('BACKUP_FILENAME_PREFIX')) {
    define('BACKUP_FILENAME_PREFIX', 'laundry_backup_');
}

if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', getenv('APP_DEBUG') === '1');
}
if (!defined('APP_LOG_FILE')) {
    define('APP_LOG_FILE', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'errors.php';

if (defined('APP_BYPASS_ERROR_HANDLER') && APP_BYPASS_ERROR_HANDLER
    && defined('APP_DEBUG') && APP_DEBUG) {
    // Temporary production diagnostics. Both APP_DEBUG and
    // APP_BYPASS_ERROR_HANDLER must be set in the git-ignored config/local.php
    // to show native PHP errors instead of the friendly error page. Remove both
    // again after debugging so visitors never see a stack trace.
    error_reporting(E_ALL);
    @ini_set('display_errors', '1');
    @ini_set('display_startup_errors', '1');
    @ini_set('log_errors', '1');
} else {
    app_register_error_handlers();
}

$appRoot = str_replace('\\', '/', dirname(__DIR__));
$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/') : '';
$baseUrl = '';
if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
    $baseUrl = rtrim(substr($appRoot, strlen($docRoot)), '/');
}
if ($baseUrl === '' && PHP_SAPI === 'cli') {
    $baseUrl = '/LaundryFriendsDemo';
}
if (!defined('BASE_URL')) {
    define('BASE_URL', $baseUrl);
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';
