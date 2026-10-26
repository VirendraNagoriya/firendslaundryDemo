<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script can only be run from the command line.');
}

require_once dirname(__DIR__) . '/config/config.php';

$host = DB_HOST;
$port = DB_PORT;
$user = DB_USER;
$pass = DB_PASS;
$name = DB_NAME;

foreach ($argv as $arg) {
    if (strpos($arg, '--db=') === 0) {
        $candidate = substr($arg, 5);
        if (preg_match('/^[A-Za-z0-9_]+$/', $candidate) === 1) {
            $name = $candidate;
        }
    }
}

$dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Database `{$name}` ready.\n";

$pdo->exec("USE `{$name}`");

$fresh = in_array('--fresh', $argv, true);
if ($fresh) {
    $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if ($existing !== []) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($existing as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        echo 'Existing tables dropped (' . count($existing) . ").\n";
    }
}

$schema = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'schema.sql');
if ($schema === false) {
    exit("Unable to read schema.sql\n");
}

foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
    if ($statement === '' || strpos($statement, '--') === 0) {
        continue;
    }
    $pdo->exec($statement);
}
echo "Schema imported.\n";

// Unified staff role: widen the enum on older installs before touching the legacy rows.
$roleColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
$roleType = is_array($roleColumn) ? (string) $roleColumn['Type'] : '';
if ($roleType !== '' && strpos($roleType, "'staff'") === false) {
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('super_admin','admin','staff','pickup','delivery','customer') NOT NULL DEFAULT 'customer'");
    echo "users.role enum extended with 'staff'.\n";
}

// Legacy pickup/delivery accounts become regular staff accounts.
$legacy = $pdo->exec("UPDATE users SET role = 'staff' WHERE role IN ('pickup','delivery')");
if ($legacy > 0) {
    echo "Migrated {$legacy} legacy pickup/delivery account(s) to staff.\n";
}

// Drop the retired role values once no account uses them any more.
if ($roleType !== '' && strpos($roleType, "'pickup'") !== false) {
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('super_admin','admin','staff','customer') NOT NULL DEFAULT 'customer'");
    echo "users.role enum cleaned up.\n";
}

/* ------------------------------------------------------------------
 * Mobile number as the primary identity key
 * ------------------------------------------------------------------ */

function table_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column");
    $stmt->execute(['column' => $column]);
    return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
}

function table_index(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = :index");
    $stmt->execute(['index' => $index]);
    return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
}

if (!table_column($pdo, 'users', 'phone_key')) {
    $pdo->exec('ALTER TABLE users ADD COLUMN phone_key VARCHAR(20) NULL AFTER phone');
    echo "users.phone_key added.\n";
}
if (!table_column($pdo, 'customers', 'phone_key')) {
    $pdo->exec("ALTER TABLE customers ADD COLUMN phone_key VARCHAR(20) NULL AFTER phone");
    echo "customers.phone_key added.\n";
}
if (!table_column($pdo, 'payments', 'voided_at')) {
    $pdo->exec('ALTER TABLE payments ADD COLUMN voided_at DATETIME NULL AFTER collected_at');
    echo "payments.voided_at added.\n";
}

// Google Maps pins for customers and pickup orders.
$locationColumns = [
    ['customers', 'latitude', 'DECIMAL(10,7) NULL AFTER pincode'],
    ['customers', 'longitude', 'DECIMAL(10,7) NULL AFTER latitude'],
    ['customers', 'place_id', 'VARCHAR(255) NULL AFTER longitude'],
    ['orders', 'pickup_latitude', 'DECIMAL(10,7) NULL AFTER delivery_address'],
    ['orders', 'pickup_longitude', 'DECIMAL(10,7) NULL AFTER pickup_latitude'],
    ['orders', 'pickup_place_id', 'VARCHAR(255) NULL AFTER pickup_longitude'],
];
foreach ($locationColumns as $column) {
    if (!table_column($pdo, $column[0], $column[1])) {
        $pdo->exec('ALTER TABLE `' . $column[0] . '` ADD COLUMN `' . $column[1] . '` ' . $column[2]);
        echo "{$column[0]}.{$column[1]} added.\n";
    }
}

// Backfill the normalised mobile number for every account and customer.
$users = $pdo->query('SELECT id, phone, phone_key FROM users')->fetchAll(PDO::FETCH_ASSOC);
$updated = 0;
foreach ($users as $row) {
    $key = phone_key((string) ($row['phone'] ?? ''));
    if ($key === '') {
        if (($row['phone_key'] ?? null) !== null) {
            $pdo->prepare('UPDATE users SET phone_key = NULL WHERE id = ?')->execute([(int) $row['id']]);
            $updated++;
        }
        continue;
    }
    if ((string) ($row['phone_key'] ?? '') !== $key) {
        $pdo->prepare('UPDATE users SET phone_key = ? WHERE id = ?')->execute([$key, (int) $row['id']]);
        $updated++;
    }
}
if ($updated > 0) {
    echo "Backfilled phone_key for {$updated} user account(s).\n";
}

$customers = $pdo->query('SELECT id, phone, phone_key FROM customers')->fetchAll(PDO::FETCH_ASSOC);
$updated = 0;
foreach ($customers as $row) {
    $key = phone_key((string) ($row['phone'] ?? ''));
    if ($key === '') {
        if (($row['phone_key'] ?? null) !== null) {
            $pdo->prepare('UPDATE customers SET phone_key = NULL WHERE id = ?')->execute([(int) $row['id']]);
            $updated++;
        }
        continue;
    }
    if ((string) ($row['phone_key'] ?? '') !== $key) {
        $pdo->prepare('UPDATE customers SET phone_key = ? WHERE id = ?')->execute([$key, (int) $row['id']]);
        $updated++;
    }
}
if ($updated > 0) {
    echo "Backfilled phone_key for {$updated} customer(s).\n";
}

// Merge customers that share the same mobile number into a single record.
$duplicates = $pdo->query(
    "SELECT phone_key, MIN(id) AS keep_id, COUNT(*) AS total
     FROM customers WHERE phone_key IS NOT NULL AND phone_key <> '' GROUP BY phone_key HAVING total > 1"
)->fetchAll(PDO::FETCH_ASSOC);
$merged = 0;
foreach ($duplicates as $duplicate) {
    $keepId = (int) $duplicate['keep_id'];
    $key = (string) $duplicate['phone_key'];
    $others = $pdo->prepare('SELECT id, user_id FROM customers WHERE phone_key = ? AND id <> ?');
    $others->execute([$key, $keepId]);
    foreach ($others->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $oldId = (int) $row['id'];
        $oldUserId = $row['user_id'] !== null ? (int) $row['user_id'] : null;
        $keepUserId = $pdo->query('SELECT user_id FROM customers WHERE id = ' . $keepId)->fetchColumn();
        if ($oldUserId !== null && ($keepUserId === null || $keepUserId === false)) {
            $pdo->prepare('UPDATE customers SET user_id = ? WHERE id = ?')->execute([$oldUserId, $keepId]);
        }
        $pdo->prepare('UPDATE orders SET customer_id = ? WHERE customer_id = ?')->execute([$keepId, $oldId]);
        $pdo->prepare('UPDATE customers SET user_id = NULL WHERE id = ?')->execute([$oldId]);
        $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$oldId]);
        $merged++;
    }
    echo "Merged duplicate customer(s) for mobile {$key}.\n";
}
if ($merged > 0) {
    echo "Merged {$merged} duplicate customer record(s).\n";
}

// The same mobile may only be used by one login.
$userDuplicates = $pdo->query(
    "SELECT phone_key, MIN(id) AS keep_id, COUNT(*) AS total
     FROM users WHERE phone_key IS NOT NULL AND phone_key <> '' GROUP BY phone_key HAVING total > 1"
)->fetchAll(PDO::FETCH_ASSOC);
$released = 0;
foreach ($userDuplicates as $duplicate) {
    $keepId = (int) $duplicate['keep_id'];
    $key = (string) $duplicate['phone_key'];
    $others = $pdo->prepare('SELECT id, email, name FROM users WHERE phone_key = ? AND id <> ?');
    $others->execute([$key, $keepId]);
    foreach ($others->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pdo->prepare('UPDATE users SET phone_key = NULL WHERE id = ?')->execute([(int) $row['id']]);
        $released++;
        echo "  mobile {$key} shared by account '{$row['name']}' ({$row['email']}) - kept the oldest one.\n";
    }
}
if ($released > 0) {
    echo "Released {$released} duplicate user mobile number(s).\n";
}

if (!table_index($pdo, 'users', 'uq_users_phone_key')) {
    $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_phone_key (phone_key)');
    echo "users.uq_users_phone_key added.\n";
}
if (!table_index($pdo, 'customers', 'uq_customers_phone_key')) {
    $pdo->exec('ALTER TABLE customers ADD UNIQUE KEY uq_customers_phone_key (phone_key)');
    echo "customers.uq_customers_phone_key added.\n";
}

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo 'Tables: ' . implode(', ', $tables) . "\n";
echo "Done.\n";
