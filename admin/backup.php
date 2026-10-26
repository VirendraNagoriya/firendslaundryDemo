<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/backup.php';

$user = require_admin();
$errors = [];
$retention = (int) setting('backup_retention_days', (string) BACKUP_RETENTION_DAYS);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action');

    if ($action === 'backup_now') {
        try {
            $result = dump_database();
            prune_backups($retention);
            audit('backup.create', 'database', DB_NAME, $result['file']);
            flash('success', 'Backup created: ' . basename($result['file']) . ' (' . round($result['size'] / 1024, 1) . ' KB)');
        } catch (Throwable $e) {
            $errors[] = 'Backup failed: ' . $e->getMessage();
        }
        redirect('admin/backup.php');
    }

    if ($action === 'delete') {
        $file = post('file');
        if (delete_backup($file)) {
            audit('backup.delete', 'database', DB_NAME, $file);
            flash('success', 'Backup file deleted.');
        } else {
            $errors[] = 'The backup file could not be deleted.';
        }
        redirect('admin/backup.php');
    }

    if ($action === 'restore') {
        $file = post('file');
        if (restore_backup($file)) {
            audit('backup.restore', 'database', DB_NAME, $file);
            flash('success', 'Database restored from ' . $file . '.');
        } else {
            $errors[] = 'Restore failed. Check the file name and try again.';
        }
        redirect('admin/backup.php');
    }
}

$download = query('download');
if ($download !== '') {
    $path = realpath(BACKUP_DIR . DIRECTORY_SEPARATOR . basename($download));
    $base = realpath(BACKUP_DIR);
    if ($path !== false && $base !== false && strpos($path, $base) === 0 && is_file($path)) {
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    flash('error', 'Backup file not found.');
    redirect('admin/backup.php');
}

$backups = list_backups();
$dbSize = 0.0;
foreach (db_all('SELECT table_name, data_length, index_length FROM information_schema.tables WHERE table_schema = ?', [DB_NAME]) as $table) {
    $dbSize += (float) $table['data_length'] + (float) $table['index_length'];
}

layout_start('Database Backup', 'app');
?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="card-grid mb-1">
    <div class="stat-card accent">
        <div class="label">Database</div>
        <div class="value" style="font-size: 1.15rem;"><?= e(DB_NAME) ?></div>
        <div class="hint"><?= e(number_format($dbSize / 1024, 1)) ?> KB in use</div>
    </div>
    <div class="stat-card green">
        <div class="label">Backups Stored</div>
        <div class="value"><?= e((string) count($backups)) ?></div>
        <div class="hint">Retention: <?= e((string) $retention) ?> day(s)</div>
    </div>
    <div class="stat-card">
        <div class="label">Last Backup</div>
        <div class="value" style="font-size: 1.15rem;"><?= $backups === [] ? '—' : e(format_datetime((string) $backups[0]['created_at'])) ?></div>
        <div class="hint"><?= $backups === [] ? 'No backup yet' : e($backups[0]['file']) ?></div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2>Backups</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="backup_now">
            <button class="btn btn-sm" type="submit">Create Backup Now</button>
        </form>
    </div>
    <div class="panel-body tight">
        <?php if ($backups === []): ?>
            <div class="empty-state">
                <strong>No backups yet</strong>
                Create a backup or schedule the CLI script with a cron job / Windows Task Scheduler.
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Created</th>
                            <th class="num">Size</th>
                            <th class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td><code><?= e($backup['file']) ?></code></td>
                            <td><?= e(format_datetime((string) $backup['created_at'])) ?></td>
                            <td class="num"><?= e(number_format($backup['size'] / 1024, 1)) ?> KB</td>
                            <td class="actions">
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/backup.php?download=' . urlencode((string) $backup['file']))) ?>">Download</a>
                                <form method="post" style="display:inline;" data-confirm="Restore the database from this backup? Current data will be replaced.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="restore">
                                    <input type="hidden" name="file" value="<?= e((string) $backup['file']) ?>">
                                    <button class="btn btn-sm btn-ghost" type="submit">Restore</button>
                                </form>
                                <form method="post" style="display:inline;" data-confirm="Delete this backup file?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="file" value="<?= e((string) $backup['file']) ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h2>Scheduled Backup Setup</h2></div>
    <div class="panel-body">
        <p>Run the backup script on a daily schedule. The script writes a timestamped SQL dump into
            <code><?= e(BACKUP_DIR) ?></code> and deletes files older than the retention period.</p>
        <div class="muted-box">
            <strong>Windows Task Scheduler</strong><br>
            Program: <code>C:\xampp\php\php.exe</code><br>
            Arguments: <code>"C:\xampp\htdocs\LaundryFriendsDemo\database\backup.php"</code><br>
            Trigger: Daily &middot; Start in: <code>C:\xampp\htdocs\LaundryFriendsDemo</code>
        </div>
        <div class="muted-box mt-1">
            <strong>Linux / cPanel cron</strong><br>
            <code>0 2 * * * /usr/bin/php /var/www/laundryfriends/database/backup.php</code><br>
            Adjust the path to your hosting account.
        </div>
        <p class="subtle mt-1">
            Restore test: use the <em>Restore</em> action above on a copy, or run
            <code>php database/restore.php &lt;filename&gt;</code> from the command line.
        </p>
    </div>
</div>
<?php layout_end(); ?>
