<?php
declare(strict_types=1);

function ensure_backup_dir(): string
{
    if (!is_dir(BACKUP_DIR)) {
        mkdir(BACKUP_DIR, 0755, true);
    }
    return BACKUP_DIR;
}

function backup_table_names(): array
{
    $stmt = db()->query('SHOW TABLES');
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function dump_database(?string $file = null): array
{
    ensure_backup_dir();
    if ($file === null) {
        $file = BACKUP_DIR . DIRECTORY_SEPARATOR . BACKUP_FILENAME_PREFIX . date('Ymd_His') . '.sql';
    }

    $handle = fopen($file, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open backup file for writing: ' . $file);
    }

    fwrite($handle, "-- Laundry Friends database backup\n");
    fwrite($handle, '-- Database: ' . DB_NAME . "\n");
    fwrite($handle, '-- Generated: ' . now() . "\n\n");
    fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

    foreach (backup_table_names() as $table) {
        $create = db_row('SHOW CREATE TABLE `' . $table . '`');
        fwrite($handle, 'DROP TABLE IF EXISTS `' . $table . '`;' . "\n");
        fwrite($handle, ($create['Create Table'] ?? '') . ";\n\n");

        $rows = db_all('SELECT * FROM `' . $table . '`');
        if ($rows !== []) {
            $columns = '`' . implode('`, `', array_keys($rows[0])) . '`';
            $values = [];
            foreach ($rows as $row) {
                $cells = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $cells[] = 'NULL';
                    } elseif (is_int($value) || is_float($value)) {
                        $cells[] = (string) $value;
                    } elseif (is_bool($value)) {
                        $cells[] = $value ? '1' : '0';
                    } else {
                        $cells[] = "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
                    }
                }
                $values[] = '(' . implode(', ', $cells) . ')';
            }
            fwrite($handle, 'INSERT INTO `' . $table . '` (' . $columns . ') VALUES' . "\n");
            fwrite($handle, implode(",\n", $values) . ";\n\n");
        }
    }

    fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($handle);

    return ['file' => $file, 'size' => (int) filesize($file)];
}

function list_backups(): array
{
    ensure_backup_dir();
    $files = glob(BACKUP_DIR . DIRECTORY_SEPARATOR . BACKUP_FILENAME_PREFIX . '*.sql') ?: [];
    rsort($files);
    $backups = [];
    foreach ($files as $file) {
        $backups[] = [
            'file' => basename($file),
            'path' => $file,
            'size' => (int) filesize($file),
            'created_at' => date('Y-m-d H:i:s', (int) filemtime($file)),
        ];
    }
    return $backups;
}

function prune_backups(int $retentionDays): int
{
    $removed = 0;
    $cutoff = time() - ($retentionDays * 86400);
    foreach (list_backups() as $backup) {
        if (filemtime((string) $backup['path']) < $cutoff) {
            if (unlink((string) $backup['path'])) {
                $removed++;
            }
        }
    }
    return $removed;
}

function delete_backup(string $file): bool
{
    $path = realpath(BACKUP_DIR . DIRECTORY_SEPARATOR . basename($file));
    $base = realpath(BACKUP_DIR);
    if ($path === false || $base === false || strpos($path, $base) !== 0) {
        return false;
    }
    return unlink($path);
}

function restore_backup(string $file): bool
{
    $path = realpath(BACKUP_DIR . DIRECTORY_SEPARATOR . basename($file));
    $base = realpath(BACKUP_DIR);
    if ($path === false || $base === false || strpos($path, $base) !== 0 || !is_file($path)) {
        return false;
    }
    $sql = file_get_contents($path);
    if ($sql === false) {
        return false;
    }
    db()->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $statement) {
        if ($statement === '' || strpos($statement, '--') === 0) {
            continue;
        }
        db()->exec($statement);
    }
    db()->exec('SET FOREIGN_KEY_CHECKS = 1');
    return true;
}
