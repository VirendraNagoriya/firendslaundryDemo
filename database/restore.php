<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script can only be run from the command line.');
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/backup.php';

$file = $argv[1] ?? '';
if ($file === '') {
    $backups = list_backups();
    if ($backups === []) {
        echo "No backups found.\n";
        exit(0);
    }
    echo "Available backups:\n";
    foreach ($backups as $backup) {
        echo '  ' . $backup['file'] . '  (' . round($backup['size'] / 1024, 1) . " KB, " . $backup['created_at'] . ")\n";
    }
    echo "\nUsage: php database/restore.php filename\n";
    exit(0);
}

if (restore_backup($file)) {
    echo 'Restored from ' . $file . "\nDone.\n";
    exit(0);
}

fwrite(STDERR, "Restore failed: file not found or not a valid backup.\n");
exit(1);
