<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/orders.php';
require_once dirname(__DIR__) . '/includes/whatsapp.php';

$user = require_admin();
if (!can_edit_whatsapp_templates($user)) {
    flash('error', 'Only the Owner, Super Admin or Admin can edit WhatsApp messages.');
    redirect('admin/index.php');
}

whatsapp_ensure_schema();
$events = whatsapp_events();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action');
    $resetEvent = post('reset_event');

    if ($resetEvent !== '' && isset($events[$resetEvent])) {
        $defaults = whatsapp_default_templates();
        db_exec(
            'INSERT INTO whatsapp_templates (event_key, body, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE body = VALUES(body), updated_by = VALUES(updated_by)',
            [$resetEvent, $defaults[$resetEvent], (int) $user['id']]
        );
        audit('whatsapp.reset', 'whatsapp_template', $resetEvent, 'Restored default message');
        flash('success', 'Default message restored for "' . $events[$resetEvent] . '".');
        redirect('admin/whatsapp-templates.php');
    }

    $bodies = $_POST['bodies'] ?? [];
    $active = $_POST['is_active'] ?? [];
    $bodies = is_array($bodies) ? $bodies : [];
    $active = is_array($active) ? $active : [];
    $saved = 0;

    foreach ($events as $event => $label) {
        $body = trim((string) ($bodies[$event] ?? ''));
        if ($body === '') {
            $errors[] = 'Message for "' . $label . '" cannot be empty. Use Reset to restore the default.';
            continue;
        }
        if (mb_strlen($body) > 4000) {
            $errors[] = 'Message for "' . $label . '" is too long (max 4000 characters).';
            continue;
        }

        $isActive = isset($active[$event]) ? 1 : 0;
        db_exec(
            'INSERT INTO whatsapp_templates (event_key, body, is_active, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE body = VALUES(body), is_active = VALUES(is_active), updated_by = VALUES(updated_by)',
            [$event, $body, $isActive, (int) $user['id']]
        );
        $saved++;
    }

    if ($errors === []) {
        audit('whatsapp.update', 'whatsapp_template', null, $saved . ' message(s) updated');
        flash('success', 'WhatsApp messages saved.');
        redirect('admin/whatsapp-templates.php');
    }
}

$templates = whatsapp_templates(true);
$sampleOrder = db_row('SELECT * FROM orders ORDER BY id DESC LIMIT 1');
if ($sampleOrder === null) {
    $sampleOrder = [
        'id' => 0,
        'order_number' => 'LF000000-00000',
        'customer_id' => 0,
        'contact_name' => 'Sample Customer',
        'contact_phone' => '9876543210',
        'order_date' => date('Y-m-d'),
        'scheduled_pickup_date' => date('Y-m-d', strtotime('+1 day')),
        'scheduled_delivery_date' => date('Y-m-d', strtotime('+3 days')),
        'status' => 'new',
        'total_amount' => 450,
        'paid_amount' => 0,
    ];
}

layout_start('WhatsApp Messages', 'app');
?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="panel">
    <div class="panel-head">
        <h2>WhatsApp Messages</h2>
        <span class="badge badge-info">Owner / Super Admin / Admin only</span>
    </div>
    <div class="panel-body">
        <p class="subtle">
            These messages open in WhatsApp with the customer's number and order details already filled in.
            Tap a send button on an order to use them. Edits apply immediately; nothing is sent automatically.
        </p>
        <p class="subtle mb-0">
            <strong>Placeholders:</strong>
            <?php foreach (whatsapp_placeholders() as $token => $description): ?>
                <span class="badge badge-muted" title="<?= e($description) ?>"><?= e($token) ?></span>
            <?php endforeach; ?>
        </p>

        <form method="post" action="<?= e(url('admin/whatsapp-templates.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">

            <?php foreach ($events as $event => $label): ?>
                <?php
                $row = $templates[$event] ?? ['body' => '', 'is_active' => 1];
                $preview = whatsapp_render($event, $sampleOrder);
                ?>
                <section class="wa-card">
                    <div class="wa-card-head">
                        <div>
                            <h3><?= e($label) ?></h3>
                            <span class="subtle">
                                <?= (int) ($row['is_active'] ?? 1) === 1 ? 'Active' : 'Hidden on orders' ?>
                                <?php if (!empty($row['updated_at'])): ?>
                                    &middot; last updated <?= e(format_datetime((string) $row['updated_at'])) ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="is_active[<?= e($event) ?>]" value="1"<?= (int) ($row['is_active'] ?? 1) === 1 ? ' checked' : '' ?>>
                            <span>Show send button</span>
                        </label>
                    </div>

                    <textarea class="wa-textarea" name="bodies[<?= e($event) ?>]" rows="6" maxlength="4000" required><?= e((string) ($row['body'] ?? '')) ?></textarea>

                    <details class="wa-preview">
                        <summary>Preview with <?= e((string) $sampleOrder['order_number']) ?></summary>
                        <pre><?= e($preview !== '' ? $preview : 'This message is switched off.') ?></pre>
                    </details>

                    <div class="wa-actions">
                        <button class="btn btn-sm btn-ghost" type="submit" name="reset_event" value="<?= e($event) ?>"
                                onclick="return confirm('Restore the default message for <?= e($label) ?>?');">
                            Reset to default
                        </button>
                    </div>
                </section>
            <?php endforeach; ?>

            <div class="form-actions">
                <button class="btn btn-primary btn-lg" type="submit">Save all messages</button>
            </div>
        </form>
    </div>
</div>
<?php layout_end(); ?>
