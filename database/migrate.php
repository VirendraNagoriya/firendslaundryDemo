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

/* ------------------------------------------------------------------
 * Owner-managed services, item mapping and weight verification
 * ------------------------------------------------------------------ */

if (!table_column($pdo, 'laundry_items', 'service_id')) {
    $pdo->exec('ALTER TABLE laundry_items ADD COLUMN service_id INT UNSIGNED NULL AFTER id');
    echo "laundry_items.service_id added.\n";
}
if (!table_column($pdo, 'orders', 'delivery_assigned_at')) {
    $pdo->exec('ALTER TABLE orders ADD COLUMN delivery_assigned_at DATETIME NULL AFTER pickup_assigned_at');
    echo "orders.delivery_assigned_at added.\n";
}
foreach ([
    ['service_id', 'INT UNSIGNED NULL AFTER order_id'],
    ['service_name', 'VARCHAR(120) NULL AFTER item_name'],
    ['weight_verified', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER weight_kg'],
    ['weight_verified_by', 'INT UNSIGNED NULL AFTER weight_verified'],
    ['weight_verified_at', 'DATETIME NULL AFTER weight_verified_by'],
] as [$column, $definition]) {
    if (!table_column($pdo, 'order_items', $column)) {
        $pdo->exec('ALTER TABLE order_items ADD COLUMN `' . $column . '` ' . $definition);
        echo "order_items.{$column} added.\n";
    }
}

// Build one service per legacy service_type + billing mode combination. A type
// that was used both per piece and per kg gets two clearly named services.
$legacyGroups = $pdo->query(
    "SELECT service_type, price_mode, COUNT(*) AS total,
            MIN(NULLIF(CASE WHEN price_mode = 'per_kg' THEN rate_per_kg ELSE rate_per_piece END, 0)) AS sample_rate
     FROM laundry_items
     WHERE service_id IS NULL
     GROUP BY service_type, price_mode
     ORDER BY service_type, price_mode"
)->fetchAll(PDO::FETCH_ASSOC);

$modeCounts = [];
foreach ($legacyGroups as $group) {
    $type = (string) $group['service_type'];
    $modeCounts[$type] = ($modeCounts[$type] ?? 0) + 1;
}

$serviceIds = [];
foreach ($legacyGroups as $group) {
    $type = (string) $group['service_type'];
    $mode = (string) $group['price_mode'] === 'per_kg' ? 'per_kg' : 'per_piece';
    $label = service_type_label($type);
    // Keep the plain label for the per-piece variant so it matches the demo
    // seed data; only the per-kg sibling gets a suffix.
    if (($modeCounts[$type] ?? 1) > 1 && $mode === 'per_kg') {
        $label .= ' (kg)';
    }
    $rate = (float) ($group['sample_rate'] ?? 0);

    $existing = $pdo->prepare('SELECT id FROM services WHERE service_name = ? LIMIT 1');
    $existing->execute([$label]);
    $serviceId = $existing->fetchColumn();
    if ($serviceId === false) {
        $insert = $pdo->prepare(
            'INSERT INTO services (service_name, price_mode, rate, is_active) VALUES (?, ?, ?, 1)'
        );
        $insert->execute([$label, $mode, $rate]);
        $serviceId = (int) $pdo->lastInsertId();
    } else {
        $serviceId = (int) $serviceId;
    }
    $serviceIds[$type . '|' . $mode] = $serviceId;

    $link = $pdo->prepare(
        'UPDATE laundry_items SET service_id = ? WHERE service_id IS NULL AND service_type = ? AND price_mode = ?'
    );
    $link->execute([$serviceId, $type, $mode]);
}
if ($serviceIds !== []) {
    echo 'Mapped legacy catalog into ' . count(array_unique($serviceIds)) . " service(s).\n";
}

// Normalise an older naming choice: "Wash & Fold (piece)" and "Wash & Fold"
// describe the same service, so merge them when both exist.
foreach ($pdo->query("SELECT id, service_name FROM services WHERE service_name LIKE '% (piece)'")->fetchAll(PDO::FETCH_ASSOC) as $pieceService) {
    $plainName = substr((string) $pieceService['service_name'], 0, -strlen(' (piece)'));
    $plainStmt = $pdo->prepare('SELECT id FROM services WHERE service_name = ? LIMIT 1');
    $plainStmt->execute([$plainName]);
    $plainId = $plainStmt->fetchColumn();
    if ($plainId === false) {
        continue;
    }
    $pieceId = (int) $pieceService['id'];
    $plainId = (int) $plainId;
    $pdo->prepare(
        'DELETE i FROM laundry_items i
         JOIN laundry_items keep ON keep.service_id = ? AND keep.item_name = i.item_name
         WHERE i.service_id = ?'
    )->execute([$plainId, $pieceId]);
    $pdo->prepare('UPDATE laundry_items SET service_id = ? WHERE service_id = ?')->execute([$plainId, $pieceId]);
    $pdo->prepare('UPDATE order_items SET service_id = ?, service_name = ? WHERE service_id = ?')->execute([$plainId, $plainName, $pieceId]);
    $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([$pieceId]);
    echo "Merged {$pieceService['service_name']} into {$plainName}.\n";
}

// Snapshot the service on existing order lines so old invoices stay readable.
$legacyItemGroups = $pdo->query(
    "SELECT service_type, price_mode FROM order_items WHERE service_id IS NULL GROUP BY service_type, price_mode"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($legacyItemGroups as $group) {
    $type = (string) $group['service_type'];
    $mode = (string) $group['price_mode'] === 'per_kg' ? 'per_kg' : 'per_piece';
    $serviceId = $serviceIds[$type . '|' . $mode] ?? null;
    $serviceName = null;
    if ($serviceId !== null) {
        $nameStmt = $pdo->prepare('SELECT service_name FROM services WHERE id = ?');
        $nameStmt->execute([$serviceId]);
        $serviceName = $nameStmt->fetchColumn() ?: null;
    }
    $update = $pdo->prepare(
        'UPDATE order_items SET service_id = ?, service_name = ? WHERE service_id IS NULL AND service_type = ? AND price_mode = ?'
    );
    $update->execute([$serviceId, $serviceName, $type, $mode]);
}

// Older per-kg lines with a recorded weight were already weighed by staff.
$pdo->exec(
    "UPDATE order_items
     SET weight_verified = 1, weight_verified_at = COALESCE(weight_verified_at, NOW())
     WHERE price_mode = 'per_kg' AND weight_kg > 0 AND weight_verified = 0"
);

// Item names are only unique inside a service now.
if (table_index($pdo, 'laundry_items', 'uq_laundry_item')) {
    $pdo->exec('ALTER TABLE laundry_items DROP INDEX uq_laundry_item');
    echo "laundry_items.uq_laundry_item dropped.\n";
}
if (!table_index($pdo, 'laundry_items', 'uq_laundry_item_service')) {
    $pdo->exec('ALTER TABLE laundry_items ADD UNIQUE KEY uq_laundry_item_service (service_id, item_name)');
    echo "laundry_items.uq_laundry_item_service added.\n";
}
if (!table_index($pdo, 'laundry_items', 'idx_laundry_items_service')) {
    $pdo->exec('ALTER TABLE laundry_items ADD KEY idx_laundry_items_service (service_id)');
    echo "laundry_items.idx_laundry_items_service added.\n";
}
if (!table_index($pdo, 'order_items', 'idx_order_items_service')) {
    $pdo->exec('ALTER TABLE order_items ADD KEY idx_order_items_service (service_id)');
    echo "order_items.idx_order_items_service added.\n";
}

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
