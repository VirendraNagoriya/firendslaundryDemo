<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

$t0 = microtime(true);
$pdo = db();
$t1 = microtime(true);
printf("connect+timezone: %.1f ms\n", ($t1 - $t0) * 1000);
echo "dsn host: " . DB_HOST . ":" . DB_PORT . "\n";
echo "server: " . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
echo "tz: " . $pdo->query('SELECT @@session.time_zone')->fetchColumn() . "\n";

$t2 = microtime(true);
$stmt = $pdo->query('SELECT COUNT(*) FROM users');
echo "users: " . $stmt->fetchColumn() . " (" . round((microtime(true)-$t2)*1000,1) . " ms)\n";

$t3 = microtime(true);
$rows = $pdo->query('SELECT id, role FROM users')->fetchAll();
echo "fetch users: " . count($rows) . " (" . round((microtime(true)-$t3)*1000,1) . " ms)\n";

$t4 = microtime(true);
$pdo->query('SELECT 1')->fetchColumn();
echo "ping: " . round((microtime(true)-$t4)*1000,1) . " ms\n";

$t5 = microtime(true);
$hash = password_hash('dummy-password', PASSWORD_DEFAULT);
$t6 = microtime(true);
password_verify('dummy-password', $hash);
$t7 = microtime(true);
printf("password_hash: %.1f ms, password_verify: %.1f ms\n", ($t6-$t5)*1000, ($t7-$t6)*1000);
