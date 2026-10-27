<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/orders.php';
require_once dirname(__DIR__) . '/includes/order-items.php';
require_once dirname(__DIR__) . '/includes/order-tiles.php';

$user = require_roles(staff_roles());
$staffId = (int) $user['id'];
$errors = [];
$catalog = active_catalog();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action');
    $orderId = (int) post('order_id');
    $order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);
    $knownActions = ['add_items', 'modify_order', 'collect_payment', 'picked_up', 'ready'];

    if ($order === null) {
        $errors[] = 'Order not found.';
    } elseif (!in_array($action, $knownActions, true)) {
        $errors[] = 'Unknown action.';
    } elseif ($action === 'add_items' && !order_accepts_items($order)) {
        $errors[] = 'This order is delivered or cancelled, so its items are locked.';
    } elseif ($action === 'collect_payment' && order_is_closed($order)) {
        $errors[] = 'This order is delivered and fully paid, so it is closed.';
    } else {
        // Shared queue: any staff member may handle any open pickup order. The
        // order is assigned to whoever actually handles it.
        claim_order_for_staff($orderId, $staffId, $action === 'ready' ? 'delivery' : 'pickup');
        $order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);

        if ($order === null) {
            $errors[] = 'Order not found.';
        } elseif ($action === 'add_items') {
            $items = collect_items_from_post(true);
            if ($items === []) {
                $errors[] = 'Please add at least one item.';
            } else {
                $added = append_order_items($orderId, $items);
                if ($added === 0) {
                    $errors[] = 'No items were added. The order may have been closed.';
                } else {
                    audit('order.items_added', 'order', (string) $orderId, $added . ' item(s) added at pickup location');
                    flash('success', $added . ' item(s) added to order ' . $order['order_number'] . '.');
                    redirect('staff/pickup.php');
                }
            }
        } elseif ($action === 'modify_order') {
            // Clear all existing items
            db_exec('DELETE FROM order_items WHERE order_id = ?', [$orderId]);

            // Add new items
            $items = collect_items_from_post(true);
            if ($items === []) {
                $errors[] = 'Please add at least one item.';
            } else {
                $added = append_order_items($orderId, $items);
                if ($added === 0) {
                    $errors[] = 'No items were added. The order may have been closed.';
                } else {
                    audit('order.items_modified', 'order', (string) $orderId, 'Order items modified at pickup location');
                    flash('success', 'Order ' . $order['order_number'] . ' has been modified.');
                    redirect('staff/pickup.php');
                }
            }
        }

        if ($action === 'collect_payment') {
            $amount = (float) post('amount');
            $method = post('method');
            $due = max(0.0, (float) $order['total_amount'] - (float) $order['paid_amount']);
            if ($due <= 0.005) {
                $errors[] = 'This order has no pending balance.';
            } elseif ($amount <= 0) {
                $errors[] = 'Please enter a valid collection amount.';
            } elseif (!isset(payment_methods()[$method])) {
                $errors[] = 'Please select a valid payment method.';
            } else {
                record_payment($orderId, $amount, $method, post('reference_no') !== '' ? post('reference_no') : null, 'Collected at pickup', $staffId);
                $closedNow = order_is_closed((array) db_row('SELECT * FROM orders WHERE id = ?', [$orderId]));
                flash('success', money($amount) . ' collected for order ' . $order['order_number'] . '.'
                    . ($closedNow ? ' The order is now closed because it is delivered and fully paid.' : ''));
                redirect('staff/pickup.php');
            }
        }

        if ($action === 'picked_up') {
            if (!in_array($order['status'], ['new', 'pickup_assigned'], true)) {
                $errors[] = 'Only orders that are pending pickup can be marked as picked up.';
            } else {
                set_order_status($orderId, 'picked_up', 'picked_up', post('notes') !== '' ? post('notes') : null);
                audit('pickup.complete', 'order', (string) $orderId, $order['order_number']);
                flash('success', 'Order ' . $order['order_number'] . ' marked as picked up.');
                redirect('staff/pickup.php');
            }
        }

        if ($action === 'ready') {
            if (!in_array($order['status'], ['picked_up', 'processing'], true)) {
                $errors[] = 'Only orders that are at the facility can be marked ready for delivery.';
            } else {
                set_order_status($orderId, 'ready_for_delivery', 'ready_for_delivery', post('notes') !== '' ? post('notes') : null);
                audit('order.ready', 'order', (string) $orderId, $order['order_number']);
                flash('success', 'Order ' . $order['order_number'] . ' is ready for delivery.');
                redirect('staff/pickup.php');
            }
        }
    }
}

$pickupOrders = db_all(
    "SELECT o.*, c.name AS customer_name, u.name AS pickup_staff_name
     FROM orders o
     JOIN customers c ON c.id = o.customer_id
     LEFT JOIN users u ON u.id = o.pickup_staff_id
     WHERE o.status IN ('new','pickup_assigned','picked_up','processing')
     ORDER BY o.scheduled_pickup_date ASC, o.id ASC"
);
$completed = db_all(
    "SELECT o.* FROM orders o
     WHERE o.pickup_staff_id = ? AND o.pickup_completed_at IS NOT NULL
     ORDER BY o.pickup_completed_at DESC LIMIT 10",
    [$staffId]
);

$pendingCount = 0;
$inProgressCount = 0;
$unassignedCount = 0;
$mineCount = 0;
foreach ($pickupOrders as $row) {
    if (in_array($row['status'], ['new', 'pickup_assigned'], true)) {
        $pendingCount++;
        if (empty($row['pickup_staff_id'])) {
            $unassignedCount++;
        }
    } else {
        $inProgressCount++;
    }
    if ((int) $row['pickup_staff_id'] === $staffId) {
        $mineCount++;
    }
}

layout_start('Pickup Queue', 'app');
?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="card-grid mb-1">
    <div class="stat-card accent">
        <div class="label">To Pick Up</div>
        <div class="value"><?= e((string) $pendingCount) ?></div>
        <div class="hint"><?= e((string) $mineCount) ?> assigned to you</div>
    </div>
    <div class="stat-card amber">
        <div class="label">Unassigned</div>
        <div class="value"><?= e((string) $unassignedCount) ?></div>
        <div class="hint">Claim one by handling it</div>
    </div>
    <div class="stat-card purple">
        <div class="label">With Us</div>
        <div class="value"><?= e((string) $inProgressCount) ?></div>
        <div class="hint">Picked up and being processed</div>
    </div>
    <div class="stat-card green">
        <div class="label">Completed Today</div>
        <div class="value"><?= e((string) (int) db_value('SELECT COUNT(*) FROM orders WHERE pickup_staff_id = ? AND DATE(pickup_completed_at) = ?', [$staffId, today()], 0)) ?></div>
        <div class="hint">Pickups marked by you today</div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2>All Pickup Tasks</h2>
        <span class="subtle"><?= e((string) count($pickupOrders)) ?> open orders &middot; assigned or not</span>
    </div>
    <div class="panel-body tight">
        <?php if ($pickupOrders === []): ?>
            <div class="empty-state">
                <strong>No open pickups</strong>
                New orders will appear here for every staff member, assigned or not.
            </div>
        <?php else: ?>
            <?php foreach ($pickupOrders as $order): ?>
                <?php
                $due = max(0.0, (float) $order['total_amount'] - (float) $order['paid_amount']);
                $closed = order_is_closed($order);
                $locked = order_items_locked($order);
                $assignment = 'Unassigned';
                if ((int) $order['pickup_staff_id'] === $staffId) {
                    $assignment = 'Assigned to you';
                } elseif (!empty($order['pickup_staff_id'])) {
                    $assignment = 'Assigned to ' . (string) ($order['pickup_staff_name'] ?? 'another staff member');
                }
                $note = $assignment . ' · Pickup ' . format_date($order['scheduled_pickup_date'])
                    . ' · ' . order_items_summary((int) $order['id']);
                if (!empty($order['pickup_staff_id']) && (int) $order['pickup_staff_id'] !== $staffId) {
                    $note .= ' · handling it assigns it to you';
                }
                if ($closed) {
                    $note .= ' · Closed (delivered & paid)';
                } elseif ($locked) {
                    $note .= ' · Items locked (' . str_replace('_', ' ', (string) $order['status']) . ')';
                }

                $actions = '<a class="btn btn-sm btn-ghost" href="' . e(url('staff/pos.php?order_id=' . (int) $order['id'])) . '">'
                    . ($locked ? 'View items' : 'Open POS') . '</a>';
                $mapUrl = map_link(
                    isset($order['pickup_latitude']) && $order['pickup_latitude'] !== null ? (float) $order['pickup_latitude'] : null,
                    isset($order['pickup_longitude']) && $order['pickup_longitude'] !== null ? (float) $order['pickup_longitude'] : null,
                    (string) $order['delivery_address'],
                    true
                );
                if ($mapUrl !== '') {
                    $actions .= '<a class="btn btn-sm btn-ghost" href="' . e($mapUrl) . '" target="_blank" rel="noopener">Navigate</a>';
                }

                if ($locked) {
                    $actions .= '<span class="subtle">' . ($closed ? 'Delivered and paid - items are locked' : 'Items are locked after delivery') . '</span>';
                }

                if (in_array($order['status'], ['new', 'pickup_assigned'], true)) {
                    if ($due > 0 && !$closed) {
                        $actions .= '<form method="post" class="tile-collect">'
                            . csrf_field()
                            . '<input type="hidden" name="action" value="collect_payment">'
                            . '<input type="hidden" name="order_id" value="' . e((string) $order['id']) . '">'
                            . '<span class="subtle">Due ' . e(money($due)) . '</span>'
                            . '<input type="number" name="amount" min="0" step="0.01" value="' . e(number_format($due, 2, '.', '')) . '" placeholder="Amount">'
                            . '<select name="method">' . payment_method_options() . '</select>'
                            . '<span class="subtle">Full payment closes the order - add all items first.</span>'
                            . '<button class="btn btn-sm btn-primary" type="submit"'
                            . ' data-confirm="' . e('Collect ' . money($due) . ' for ' . $order['order_number'] . '?') . '"'
                            . ' data-confirm-2="' . e('Second check: confirm the payment for ' . $order['order_number'] . ' has been received.') . '">'
                            . 'Collect</button>'
                            . '</form>';
                    }
                    $actions .= '<form method="post">'
                        . csrf_field()
                        . '<input type="hidden" name="action" value="picked_up">'
                        . '<input type="hidden" name="order_id" value="' . e((string) $order['id']) . '">'
                        . '<input type="text" name="notes" placeholder="Note (optional)">'
                        . '<button class="btn btn-sm btn-green" type="submit"'
                        . ' data-confirm="' . e('Mark ' . $order['order_number'] . ' as picked up?') . '"'
                        . ' data-confirm-2="' . e('Second check: confirm you have collected the order for ' . $order['order_number'] . '.') . '">'
                        . 'Mark Picked Up</button>'
                        . '</form>';
                } elseif (in_array($order['status'], ['picked_up', 'processing'], true)) {
                    $actions .= '<form method="post">'
                        . csrf_field()
                        . '<input type="hidden" name="action" value="ready">'
                        . '<input type="hidden" name="order_id" value="' . e((string) $order['id']) . '">'
                        . '<button class="btn btn-sm" type="submit"'
                        . ' data-confirm="' . e('Mark ' . $order['order_number'] . ' ready for delivery?') . '"'
                        . ' data-confirm-2="' . e('Second check: confirm ' . $order['order_number'] . ' is complete and ready.') . '">'
                        . 'Ready for Delivery</button>'
                        . '</form>';
                }

                if (!$locked) {
                    ob_start();
                    render_item_picker([], $catalog, ['intro' => 'At the customer: add the garments you see and verify the actual kg before saving.']);
                    $pickerHtml = (string) ob_get_clean();
                    $actions .= '<div class="tile-add"><details>'
                        . '<summary>Add items to this order</summary>'
                        . '<form method="post" data-calc-items>'
                        . csrf_field()
                        . '<input type="hidden" name="action" value="add_items">'
                        . '<input type="hidden" name="order_id" value="' . e((string) $order['id']) . '">'
                        . $pickerHtml
                        . '<div class="form-actions mt-1">'
                        . '<button class="btn btn-sm btn-green" type="submit">Add to order</button>'
                        . '</div>'
                        . '</form>'
                        . '</details></div>';

                    // Add modify order button
                    $actions .= '<div class="tile-modify">'
                        . '<form method="post" data-calc-items>'
                        . csrf_field()
                        . '<input type="hidden" name="action" value="modify_order">'
                        . '<input type="hidden" name="order_id" value="' . e((string) $order['id']) . '">'
                        . '<div class="modify-warning">'
                        . '<strong>Warning:</strong> This will replace all items in the order. Use only if the customer has made significant changes.'
                        . '</div>'
                        . $pickerHtml
                        . '<div class="form-actions mt-1">'
                        . '<button class="btn btn-sm btn-warning" type="submit" data-confirm="Are you sure you want to replace all items in this order?" data-confirm-2="This will completely replace the order contents. Confirm only if necessary.">'
                        . 'Modify Entire Order'
                        . '</button>'
                        . '</div>'
                        . '</form>'
                        . '</div>';
                }

                echo order_tile($order, [
                    'note' => $note,
                    'actions' => $actions,
                ]);
                ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h3>Recently Completed Pickups</h3></div>
    <div class="panel-body tight">
        <?php if ($completed === []): ?>
            <div class="empty-state">No completed pickups yet.</div>
        <?php else: ?>
            <?php foreach ($completed as $order): ?>
                <?php order_history_row($order, format_datetime($order['pickup_completed_at']), url('staff/pos.php?order_id=' . (int) $order['id'])); ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php layout_end(); ?>
