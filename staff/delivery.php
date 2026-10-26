<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/orders.php';
require_once dirname(__DIR__) . '/includes/order-tiles.php';
require_once dirname(__DIR__) . '/includes/location-picker.php';

$user = require_roles(staff_roles());
$staffId = (int) $user['id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action');
    $orderId = (int) post('order_id');
    $order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);
    $knownActions = ['out_for_delivery', 'delivered'];

    if ($order === null) {
        $errors[] = 'Order not found.';
    } elseif (!in_array($action, $knownActions, true)) {
        $errors[] = 'Unknown action.';
    } else {
        // Shared queue: any staff member may deliver any order. The order is
        // assigned to whoever actually handles it.
        claim_order_for_staff($orderId, $staffId);
        $order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);

        if ($order === null) {
            $errors[] = 'Order not found.';
        } elseif ($action === 'out_for_delivery') {
            if (!in_array($order['status'], ['ready_for_delivery'], true)) {
                $errors[] = 'Only orders ready for delivery can be marked out for delivery.';
            } else {
                set_order_status($orderId, 'out_for_delivery', 'out_for_delivery', post('notes') !== '' ? post('notes') : null);
                flash('success', 'Order ' . $order['order_number'] . ' is out for delivery.');
                redirect('staff/delivery.php');
            }
        } elseif ($action === 'delivered') {
            if (!in_array($order['status'], ['ready_for_delivery', 'out_for_delivery'], true)) {
                $errors[] = 'Only orders ready for delivery can be marked as delivered.';
            } else {
                $amount = (float) post('amount');
                $method = post('method');
                $collect = post('collect_payment') === '1';
                $notes = post('notes');
                $collectError = '';

                if ($collect) {
                    $due = max(0.0, (float) $order['total_amount'] - (float) $order['paid_amount']);
                    if (order_is_closed($order)) {
                        $collectError = 'This order is already fully paid and closed.';
                    } elseif ($due <= 0.005) {
                        $collectError = 'This order has no pending balance.';
                    } elseif ($amount <= 0) {
                        $collectError = 'Please enter a valid collection amount.';
                    } elseif (!isset(payment_methods()[$method])) {
                        $collectError = 'Please select a valid payment method.';
                    } elseif (abs($amount - $due) > 0.005 && $notes === '') {
                        $collectError = 'The payment amount ' . money($amount) . ' differs from the pending due ' . money($due)
                            . '. Add a note explaining the changed amount before delivering.';
                    }
                }

                if ($collectError !== '') {
                    $errors[] = $collectError;
                } else {
                    set_order_status($orderId, 'delivered', 'delivered', $notes !== '' ? $notes : null);
                    audit('delivery.complete', 'order', (string) $orderId, $order['order_number']);

                    if ($collect) {
                        record_payment($orderId, $amount, $method, post('reference_no') !== '' ? post('reference_no') : null, 'Collected on delivery', $staffId);
                    }

                    flash('success', 'Order ' . $order['order_number'] . ' marked as delivered.');
                    redirect('staff/delivery.php');
                }
            }
        }
    }
}

$deliveries = db_all(
    "SELECT o.*, c.name AS customer_name, u.name AS delivery_staff_name
     FROM orders o
     JOIN customers c ON c.id = o.customer_id
     LEFT JOIN users u ON u.id = o.delivery_staff_id
     WHERE o.status IN ('ready_for_delivery','out_for_delivery')
     ORDER BY o.scheduled_delivery_date ASC, o.id ASC"
);
$completed = db_all(
    "SELECT o.* FROM orders o
     WHERE o.delivery_staff_id = ? AND o.delivery_completed_at IS NOT NULL
     ORDER BY o.delivery_completed_at DESC LIMIT 10",
    [$staffId]
);

$dueTotal = 0.0;
$unassignedCount = 0;
$mineCount = 0;
foreach ($deliveries as $row) {
    $dueTotal += max(0.0, (float) $row['total_amount'] - (float) $row['paid_amount']);
    if (empty($row['delivery_staff_id'])) {
        $unassignedCount++;
    }
    if ((int) $row['delivery_staff_id'] === $staffId) {
        $mineCount++;
    }
}

$duesCustomers = staff_customer_dues($staffId);
$oldDuesTotal = 0.0;
foreach ($duesCustomers as $entry) {
    $oldDuesTotal += (float) $entry['total'];
}

layout_start('Delivery Queue', 'app');
?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="card-grid mb-1">
    <div class="stat-card accent">
        <div class="label">To Deliver</div>
        <div class="value"><?= e((string) count($deliveries)) ?></div>
        <div class="hint"><?= e((string) $mineCount) ?> assigned to you</div>
    </div>
    <div class="stat-card amber">
        <div class="label">Unassigned</div>
        <div class="value"><?= e((string) $unassignedCount) ?></div>
        <div class="hint">Claim one by handling it</div>
    </div>
    <div class="stat-card red">
        <div class="label">Amount To Collect</div>
        <div class="value"><?= e(money($dueTotal)) ?></div>
        <div class="hint">Across all open deliveries</div>
    </div>
    <div class="stat-card green">
        <div class="label">Delivered Today</div>
        <div class="value"><?= e((string) (int) db_value('SELECT COUNT(*) FROM orders WHERE delivery_staff_id = ? AND DATE(delivery_completed_at) = ?', [$staffId, today()], 0)) ?></div>
        <div class="hint">Deliveries marked by you today</div>
    </div>
    <div class="stat-card">
        <div class="label">Old Invoice Dues</div>
        <div class="value"><?= e(money($oldDuesTotal)) ?></div>
        <div class="hint">Unpaid bills of your customers · <a href="<?= e(url('staff/dues.php')) ?>">collect them</a></div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2>All Delivery Tasks</h2>
        <span class="subtle"><?= e((string) count($deliveries)) ?> open orders &middot; assigned or not</span>
    </div>
    <div class="panel-body tight">
        <?php if ($deliveries === []): ?>
            <div class="empty-state">
                <strong>No open deliveries</strong>
                Orders marked ready for delivery will appear here for every staff member, assigned or not.
            </div>
        <?php else: ?>
            <?php foreach ($deliveries as $order): ?>
                <?php
                $due = max(0.0, (float) $order['total_amount'] - (float) $order['paid_amount']);
                $closed = order_is_closed($order);
                $otherDues = customer_due_total((int) $order['customer_id'], (int) $order['id']);
                $assignment = 'Unassigned';
                if ((int) $order['delivery_staff_id'] === $staffId) {
                    $assignment = 'Assigned to you';
                } elseif (!empty($order['delivery_staff_id'])) {
                    $assignment = 'Assigned to ' . (string) ($order['delivery_staff_name'] ?? 'another staff member');
                }
                $note = $assignment . ' · Delivery ' . format_date($order['scheduled_delivery_date'])
                    . ' · ' . order_items_summary((int) $order['id']);
                if (!empty($order['delivery_staff_id']) && (int) $order['delivery_staff_id'] !== $staffId) {
                    $note .= ' · handling it assigns it to you';
                }
                if ($closed) {
                    $note .= ' · Closed (fully paid)';
                }

                $actions = '<a class="btn btn-sm btn-ghost" href="' . e(url('staff/pos.php?order_id=' . (int) $order['id'])) . '">'
                    . ($closed ? 'View items' : 'Open POS') . '</a>';
                $mapUrl = map_link(
                    isset($order['pickup_latitude']) && $order['pickup_latitude'] !== null ? (float) $order['pickup_latitude'] : null,
                    isset($order['pickup_longitude']) && $order['pickup_longitude'] !== null ? (float) $order['pickup_longitude'] : null,
                    (string) $order['delivery_address'],
                    true
                );
                if ($mapUrl !== '') {
                    $actions .= '<a class="btn btn-sm btn-ghost" href="' . e($mapUrl) . '" target="_blank" rel="noopener">Navigate</a>';
                }

                if ($order['status'] === 'ready_for_delivery') {
                    $actions .= '<form method="post">'
                        . csrf_field()
                        . '<input type="hidden" name="action" value="out_for_delivery">'
                        . '<input type="hidden" name="order_id" value="' . e((string) $order['id']) . '">'
                        . '<button class="btn btn-sm btn-ghost" type="submit"'
                        . ' data-confirm="' . e('Start delivery of ' . $order['order_number'] . '?') . '"'
                        . ' data-confirm-2="' . e('Second check: confirm ' . $order['order_number'] . ' is going out for delivery.') . '">'
                        . 'Start Delivery</button>'
                        . '</form>';
                }
                $actions .= '<form method="post">'
                    . csrf_field()
                    . '<input type="hidden" name="action" value="delivered">'
                    . '<input type="hidden" name="order_id" value="' . e((string) $order['id']) . '">';
                if ($due > 0 && !$closed) {
                    $actions .= '<input type="number" name="amount" min="0" step="0.01" value="' . e(number_format($due, 2, '.', '')) . '" placeholder="Amount">'
                        . '<select name="method">'
                        . payment_method_options()
                        . '</select>'
                        . '<input type="text" name="reference_no" placeholder="Ref">'
                        . '<input type="text" name="notes" placeholder="Note (required if amount changed)">'
                        . '<label class="checkbox-row"><input type="checkbox" name="collect_payment" value="1" checked> collect</label>';
                }
                $actions .= '<button class="btn btn-sm btn-green" type="submit"'
                    . ' data-confirm="' . e('Mark ' . $order['order_number'] . ' as delivered?') . '"'
                    . ' data-confirm-2="' . e('Second check: confirm the order was handed over' . ($due > 0 && !$closed ? ' and the payment received' : '') . '.') . '">'
                    . 'Mark Delivered</button></form>';
                echo order_tile($order, [
                    'note' => $note,
                    'old_dues' => $otherDues,
                    'actions' => $actions,
                ]);
                ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Recently Delivered</h3></div>
    <div class="panel-body tight">
        <?php if ($completed === []): ?>
            <div class="empty-state">No deliveries completed yet.</div>
        <?php else: ?>
            <?php foreach ($completed as $order): ?>
                <?php
                order_history_row(
                    $order,
                    format_datetime($order['delivery_completed_at']) . ' · ' . payment_status_label((string) $order['payment_status'])
                );
                ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php layout_end(); ?>
