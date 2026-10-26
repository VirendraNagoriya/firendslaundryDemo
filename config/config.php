<?php
declare(strict_types=1);

define('APP_NAME', 'Laundry Friends');
define('APP_TAGLINE', 'Wash. Dry. Fold. Delivered.');
define('APP_CURRENCY', '₹');
define('APP_TIMEZONE', 'Asia/Kolkata');
define('APP_CONTACT_PHONE', '+91 98765 43210');
define('APP_CONTACT_EMAIL', 'support@laundryfriends.test');
define('APP_ASSET_VERSION', '2026-10-26c');

date_default_timezone_set(APP_TIMEZONE);

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'laundry_friends_demo');
define('DB_USER', 'root');
define('DB_PASS', '');

define('SESSION_NAME', 'LAUNDRYFRDS');
define('CSRF_SESSION_KEY', 'csrf_token');
define('BACKUP_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backups');
define('BACKUP_RETENTION_DAYS', 7);
define('BACKUP_FILENAME_PREFIX', 'laundry_backup_');

$localOverride = __DIR__ . DIRECTORY_SEPARATOR . 'local.php';
if (is_file($localOverride)) {
    require $localOverride;
}

if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', getenv('APP_DEBUG') === '1');
}
if (!defined('APP_LOG_FILE')) {
    define('APP_LOG_FILE', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'errors.php';
app_register_error_handlers();

$appRoot = str_replace('\\', '/', dirname(__DIR__));
$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/') : '';
$baseUrl = '';
if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
    $baseUrl = rtrim(substr($appRoot, strlen($docRoot)), '/');
}
if ($baseUrl === '' && PHP_SAPI === 'cli') {
    $baseUrl = '/LaundryFriendsDemo';
}
define('BASE_URL', $baseUrl);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'auth.php';
