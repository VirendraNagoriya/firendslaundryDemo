<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/orders.php';
require_once dirname(__DIR__) . '/includes/order-tiles.php';

$user = require_roles(staff_roles());
$staffId = (int) $user['id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $orderId = (int) post('order_id');
    $amount = (float) post('amount');
    $method = post('method');
    $order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);

    if ($order === null) {
        $errors[] = 'Order not found.';
    } elseif (!staff_may_collect($staffId, $order) && (string) $order['status'] !== 'delivered') {
        $errors[] = 'This invoice is not linked to any of your customers.';
    } elseif (order_is_closed($order)) {
        $errors[] = 'This order is delivered and fully paid, so it is closed.';
    } elseif ($amount <= 0) {
        $errors[] = 'Please enter a valid collection amount.';
    } elseif (!isset(payment_methods()[$method])) {
        $errors[] = 'Please select a valid payment method.';
    } else {
        record_payment(
            $orderId,
            $amount,
            $method,
            post('reference_no') !== '' ? post('reference_no') : null,
            post('notes') !== '' ? post('notes') : null,
            $staffId
        );
        flash('success', 'Payment of ' . money($amount) . ' recorded for order ' . $order['order_number'] . '.');
        redirect('staff/payments.php');
    }
}

$history = db_all(
    'SELECT p.*, o.order_number FROM payments p
     JOIN orders o ON o.id = p.order_id
     WHERE p.collected_by = ? AND p.voided_at IS NULL ORDER BY p.collected_at DESC LIMIT 40',
    [$staffId]
);

$collectedTotal = (float) db_value('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE collected_by = ? AND voided_at IS NULL', [$staffId], 0);
$collectedToday = (float) db_value('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE collected_by = ? AND voided_at IS NULL AND DATE(collected_at) = ?', [$staffId, today()], 0);

$duesCustomers = staff_customer_dues($staffId);
$duesTotal = 0.0;
$duesCount = 0;
foreach ($duesCustomers as $entry) {
    $duesTotal += (float) $entry['total'];
    $duesCount += count($entry['orders']);
}

layout_start('My Collections', 'app');
?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="card-grid mb-1">
    <div class="stat-card green">
        <div class="label">Collected Today</div>
        <div class="value"><?= e(money($collectedToday)) ?></div>
        <div class="hint">Total collected <?= e(money($collectedTotal)) ?></div>
    </div>
    <div class="stat-card red">
        <div class="label">Pending Dues</div>
        <div class="value"><?= e(money($duesTotal)) ?></div>
        <div class="hint"><?= e((string) $duesCount) ?> unpaid invoices · <a href="<?= e(url('staff/dues.php')) ?>">collect them</a></div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2>My Recent Collections</h2>
        <a class="btn btn-sm btn-ghost" href="<?= e(url('staff/dues.php')) ?>">Go to Dues</a>
    </div>
    <div class="panel-body tight">
        <?php if ($history === []): ?>
            <div class="empty-state">No collections recorded yet.</div>
        <?php else: ?>
            <?php foreach ($history as $payment): ?>
                <div class="mini-row">
                    <div class="mini-row-main">
                        <strong><?= e(money((float) $payment['amount'])) ?></strong>
                        <span class="subtle"><?= e($payment['order_number']) ?></span>
                        <span class="subtle"><?= e(payment_methods()[(string) $payment['method']] ?? (string) $payment['method']) ?></span>
                    </div>
                    <div class="mini-row-side">
                        <span class="subtle"><?= e(format_datetime((string) $payment['collected_at'])) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php layout_end(); ?>
