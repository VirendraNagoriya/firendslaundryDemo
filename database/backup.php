<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script can only be run from the command line.');
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/backup.php';

$retention = (int) (getenv('BACKUP_RETENTION_DAYS') ?: BACKUP_RETENTION_DAYS);

try {
    $result = dump_database();
    echo 'Backup created: ' . $result['file'] . "\n";
    echo 'Size: ' . round($result['size'] / 1024, 1) . " KB\n";

    $removed = prune_backups($retention);
    echo "Retention: older than {$retention} day(s) removed: {$removed}\n";
    echo "Done.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
