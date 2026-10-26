<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';

$user = require_admin();
$errors = [];

$fields = [
    'business_name' => 'Laundry Friends',
    'business_tagline' => 'Wash. Dry. Fold. Delivered.',
    'contact_phone' => APP_CONTACT_PHONE,
    'contact_email' => APP_CONTACT_EMAIL,
    'default_delivery_charge' => '40',
    'tax_percent' => '0',
    'backup_retention_days' => '7',
    'report_footer_note' => 'Thank you for your business.',
    'google_maps_api_key' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    foreach ($fields as $key => $default) {
        $value = post($key, $default);
        if ($key === 'tax_percent' && (!is_numeric($value) || (float) $value < 0)) {
            $errors[] = 'Tax percentage must be a positive number.';
            continue;
        }
        if ($key === 'default_delivery_charge' && !is_numeric($value)) {
            $errors[] = 'Delivery charge must be a number.';
            continue;
        }
        if ($key === 'backup_retention_days' && (!ctype_digit($value) || (int) $value < 1)) {
            $errors[] = 'Backup retention must be at least 1 day.';
            continue;
        }
        set_setting($key, $value);
    }
    if ($errors === []) {
        audit('settings.update', 'settings', null, 'Settings updated');
        flash('success', 'Settings saved.');
        redirect('admin/settings.php');
    }
}

$values = [];
foreach ($fields as $key => $default) {
    $values[$key] = setting($key, $default);
}

layout_start('Settings', 'app');
?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="panel">
    <div class="panel-head"><h2>Application Settings</h2></div>
    <div class="panel-body">
        <form method="post" action="<?= e(url('admin/settings.php')) ?>">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="form-group">
                    <label for="business_name">Business name</label>
                    <input type="text" id="business_name" name="business_name" value="<?= e($values['business_name']) ?>">
                </div>
                <div class="form-group">
                    <label for="business_tagline">Tagline</label>
                    <input type="text" id="business_tagline" name="business_tagline" value="<?= e($values['business_tagline']) ?>">
                </div>
                <div class="form-group">
                    <label for="contact_phone">Contact phone</label>
                    <input type="text" id="contact_phone" name="contact_phone" value="<?= e($values['contact_phone']) ?>">
                </div>
                <div class="form-group">
                    <label for="contact_email">Contact email</label>
                    <input type="email" id="contact_email" name="contact_email" value="<?= e($values['contact_email']) ?>">
                </div>
                <div class="form-group">
                    <label for="default_delivery_charge">Default delivery charge (<?= e(APP_CURRENCY) ?>)</label>
                    <input type="number" id="default_delivery_charge" name="default_delivery_charge" min="0" step="0.01" value="<?= e($values['default_delivery_charge']) ?>">
                </div>
                <div class="form-group">
                    <label for="tax_percent">Tax percentage</label>
                    <input type="number" id="tax_percent" name="tax_percent" min="0" step="0.01" value="<?= e($values['tax_percent']) ?>">
                </div>
                <div class="form-group">
                    <label for="backup_retention_days">Backup retention (days)</label>
                    <input type="number" id="backup_retention_days" name="backup_retention_days" min="1" step="1" value="<?= e($values['backup_retention_days']) ?>">
                </div>
                <div class="form-group">
                    <label for="report_footer_note">Report footer note</label>
                    <input type="text" id="report_footer_note" name="report_footer_note" value="<?= e($values['report_footer_note']) ?>">
                </div>
            </div>

            <h3>Google Maps location picker</h3>
            <div class="form-group">
                <label for="google_maps_api_key">Google Maps JavaScript API key</label>
                <input type="text" id="google_maps_api_key" name="google_maps_api_key" value="<?= e($values['google_maps_api_key']) ?>" autocomplete="off" spellcheck="false">
                <div class="help">
                    Enable <strong>Maps JavaScript API</strong> and <strong>Places API</strong> in Google Cloud, then paste the key here.
                    Restrict the key to your website domain (HTTP referrers) and to those two APIs so it cannot be misused.
                    Leave empty to keep manual address entry and the browser "current location" pin without a map.
                </div>
            </div>
            <div class="form-actions">
                <button class="btn" type="submit">Save Settings</button>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Audit Trail (latest activity)</h3></div>
    <div class="panel-body tight">
        <?php
        $logs = db_all('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 25');
        ?>
        <?php if ($logs === []): ?>
            <div class="empty-state">No activity recorded yet.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr><th>When</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="nowrap"><?= e(format_datetime((string) $log['created_at'])) ?></td>
                            <td><?= e((string) ($log['user_name'] ?? 'guest')) ?></td>
                            <td><code><?= e($log['action']) ?></code></td>
                            <td><?= e((string) ($log['entity'] ?? '—')) ?><?= $log['entity_id'] ? ' #' . e((string) $log['entity_id']) : '' ?></td>
                            <td><?= e((string) ($log['details'] ?? '—')) ?></td>
                            <td><?= e((string) ($log['ip_address'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php layout_end(); ?>
