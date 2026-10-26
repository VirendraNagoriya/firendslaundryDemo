<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        app_log_throwable($e);
        throw new RuntimeException('Database connection failed. Please try again in a moment.', 0, $e);
    }
    return $pdo;
}

/**
 * Run a callback inside a database transaction. Any exception rolls the whole
 * change back, so a half-created customer/order is never left behind.
 */
function db_transaction(callable $callback): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $callback();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function db_exception_is_duplicate(Throwable $e): bool
{
    if (!$e instanceof PDOException) {
        return false;
    }
    $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
    return $driverCode === 1062 || stripos($e->getMessage(), 'Duplicate entry') !== false;
}

function db_exception_is_foreign_key(Throwable $e): bool
{
    if (!$e instanceof PDOException) {
        return false;
    }
    $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
    return in_array($driverCode, [1451, 1452], true) || stripos($e->getMessage(), 'foreign key constraint') !== false;
}

/**
 * A friendly message for a database failure, safe to show on a form.
 */
function db_user_message(Throwable $e): string
{
    if (db_exception_is_duplicate($e)) {
        $message = $e->getMessage();
        if (stripos($message, 'uq_users_email') !== false) {
            return 'That email address is already used by another account.';
        }
        if (stripos($message, 'uq_users_phone_key') !== false) {
            return 'An account already exists with this mobile number. Please sign in instead.';
        }
        if (stripos($message, 'uq_customers_phone_key') !== false) {
            return 'A customer with this mobile number already exists. Please sign in or contact us so we can link your orders.';
        }
        return 'These details are already registered. Please sign in or contact us.';
    }
    if (db_exception_is_foreign_key($e)) {
        return 'Some related data is missing. Please refresh the page and try again.';
    }
    return 'We could not save your details. Please try again in a moment.';
}

/**
 * Message to show on a form for any failure. Database errors are translated to
 * a friendly sentence; validation-style RuntimeExceptions keep their wording.
 */
function app_form_message(Throwable $e): string
{
    if ($e instanceof PDOException) {
        return db_user_message($e);
    }
    if ($e instanceof RuntimeException && trim($e->getMessage()) !== '') {
        return $e->getMessage();
    }
    return 'Something went wrong while saving. Please try again in a moment.';
}

function db_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_row(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function db_value(string $sql, array $params = [], mixed $default = null): mixed
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : $value;
}

function db_exec(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function db_insert(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) db()->lastInsertId();
}

function setting(string $key, string $default = ''): string
{
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $value = db_value('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
        $cache[$key] = $value === null ? null : (string) $value;
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    db_exec(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        [$key, $value]
    );
}

function audit(string $action, ?string $entity = null, ?string $entityId = null, ?string $details = null): void
{
    $user = current_user();
    db_exec(
        'INSERT INTO audit_logs (user_id, user_name, action, entity, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            $user['id'] ?? null,
            $user['name'] ?? 'guest',
            $action,
            $entity,
            $entityId,
            $details === null ? null : mb_substr($details, 0, 250),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]
    );
}
