<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/orders.php';
require_once dirname(__DIR__) . '/includes/whatsapp.php';

$user = require_admin();
$orderId = query_int('id');
$order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);

if ($order === null) {
    flash('error', 'Order not found.');
    redirect('admin/orders.php');
}

$errors = [];
$closed = order_is_closed($order);
$itemsLocked = order_items_locked($order);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action');

    if ($action === 'status') {
        $newStatus = post('status');
        $notes = post('notes');
        if (!isset(order_statuses()[$newStatus])) {
            $errors[] = 'Invalid status selected.';
        } else {
            set_order_status($orderId, $newStatus, 'status_changed', $notes !== '' ? $notes : null);
            audit('order.status', 'order', (string) $orderId, $newStatus);
            flash('success', 'Order status updated to ' . order_status_label($newStatus) . '.');
            redirect('admin/order-view.php?id=' . $orderId);
        }
    }

    if ($action === 'assign') {
        $pickupId = post('pickup_staff_id');
        $deliveryId = post('delivery_staff_id');
        assign_staff(
            $orderId,
            $pickupId !== '' ? (int) $pickupId : null,
            $deliveryId !== '' ? (int) $deliveryId : null
        );
        audit('order.assign', 'order', (string) $orderId, 'Staff assignment updated');
        flash('success', 'Staff assignment updated.');
        redirect('admin/order-view.php?id=' . $orderId);
    }

    if ($action === 'payment') {
        $amount = (float) post('amount');
        $method = post('method');
        if ($closed) {
            $errors[] = 'This order is delivered and fully paid, so it is closed. Mark it unpaid to record another payment.';
        } elseif ($amount <= 0) {
            $errors[] = 'Please enter a valid collection amount.';
        } elseif (!isset(payment_methods()[$method])) {
            $errors[] = 'Please select a valid payment method.';
        } else {
            record_payment($orderId, $amount, $method, post('reference_no') !== '' ? post('reference_no') : null, post('payment_notes') !== '' ? post('payment_notes') : null, (int) $user['id']);
            flash('success', 'Payment of ' . money($amount) . ' recorded.');
            redirect('admin/order-view.php?id=' . $orderId);
        }
    }

    if ($action === 'mark_paid') {
        if ($closed) {
            flash('info', 'This order is delivered and fully paid, so it is closed.');
            redirect('admin/order-view.php?id=' . $orderId);
        }
        $dueNow = (float) $order['total_amount'] - (float) $order['paid_amount'];
        if ($dueNow > 0.005) {
            $method = post('method');
            if (!isset(payment_methods()[$method])) {
                $method = 'cash';
            }
            record_payment($orderId, $dueNow, $method, null, 'Marked paid by owner', (int) $user['id']);
        } else {
            db_exec('UPDATE orders SET payment_status = ? WHERE id = ?', ['paid', $orderId]);
        }
        audit('order.mark_paid', 'order', (string) $orderId, 'Owner marked ' . $order['order_number'] . ' as paid');
        flash('success', 'Order ' . $order['order_number'] . ' marked as paid.');
        redirect('admin/order-view.php?id=' . $orderId);
    }

    if ($action === 'mark_unpaid') {
        db_exec('UPDATE payments SET voided_at = NOW() WHERE order_id = ? AND voided_at IS NULL', [$orderId]);
        recalc_order($orderId);
        audit('order.mark_unpaid', 'order', (string) $orderId, 'Owner marked ' . $order['order_number'] . ' as unpaid');
        flash('success', 'Order ' . $order['order_number'] . ' marked as unpaid - all payments were voided. Delivered orders keep their items locked.');
        redirect('admin/order-view.php?id=' . $orderId);
    }

    if ($action === 'cancel') {
        set_order_status($orderId, 'cancelled', 'cancelled', post('notes') !== '' ? post('notes') : null);
        audit('order.cancel', 'order', (string) $orderId, 'Order cancelled');
        flash('success', 'Order cancelled.');
        redirect('admin/order-view.php?id=' . $orderId);
    }
}

$items = db_all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
$billEstimated = order_weight_estimated($items);
$history = db_all('SELECT * FROM order_status_history WHERE order_id = ? ORDER BY id DESC', [$orderId]);
$payments = db_all('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC', [$orderId]);
$customer = db_row('SELECT * FROM customers WHERE id = ?', [(int) $order['customer_id']]);
$pickupStaff = staff_list('pickup');
$deliveryStaff = staff_list('delivery');
$due = max(0.0, (float) $order['total_amount'] - (float) $order['paid_amount']);
$customerDues = customer_dues((int) $order['customer_id'], (int) $orderId);
$customerDueTotal = 0.0;
foreach ($customerDues as $duesRow) {
    $customerDueTotal += max(0, (float) $duesRow['total_amount'] - (float) $duesRow['paid_amount']);
}
$orderMapUrl = map_link(
    isset($order['pickup_latitude']) && $order['pickup_latitude'] !== null ? (float) $order['pickup_latitude'] : null,
    isset($order['pickup_longitude']) && $order['pickup_longitude'] !== null ? (float) $order['pickup_longitude'] : null,
    (string) $order['delivery_address'],
    true
);

layout_start('Order ' . $order['order_number'], 'app');
?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="detail-grid">
    <div>
        <div class="panel">
            <div class="panel-head">
                <h2>Order <?= e($order['order_number']) ?></h2>
                <div class="row-between">
                    <span class="badge <?= e(status_badge((string) $order['status'])) ?>"><?= e(order_status_label((string) $order['status'])) ?></span>
                    <span class="badge <?= e(status_badge((string) $order['payment_status'])) ?>"><?= e(payment_status_label((string) $order['payment_status'])) ?></span>
                    <?php if ($closed): ?>
                        <span class="badge badge-success" title="Delivered and fully paid - no more items can be added">Closed</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="panel-body">
                <div class="table-wrap">
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Service</th>
                                <th>Billing</th>
                                <th class="num">Qty</th>
                                <th class="num">Weight</th>
                                <th class="num">Rate</th>
                                <th class="num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= e($item['item_name']) ?></td>
                                <td><?= e(order_item_service_label($item)) ?></td>
                                <td><?= $item['price_mode'] === 'per_kg' ? 'Per kg' : 'Per piece' ?></td>
                                <td class="num"><?= e((string) $item['qty']) ?></td>
                                <td class="num">
                                    <?= e(number_format((float) $item['weight_kg'], 3)) ?> kg
                                    <?php if ((string) $item['price_mode'] === 'per_kg'): ?>
                                        <span class="badge <?= (int) $item['weight_verified'] === 1 ? 'badge-success' : 'badge-warn' ?>"><?= (int) $item['weight_verified'] === 1 ? 'Verified' : 'Estimated' ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="num">
                                    <?= e(money((float) $item['rate'])) ?>
                                    <?php $rateChange = order_item_rate_change($item); ?>
                                    <?php if ($rateChange !== null): ?>
                                        <span class="rate-old" title="Catalog rate"><?= e(money((float) $rateChange['from'])) ?></span>
                                        <span class="badge badge-warn">Override<?= $rateChange['by'] !== '' ? ' &middot; ' . e($rateChange['by']) : '' ?></span>
                                        <?php if ($rateChange['reason'] !== ''): ?>
                                            <span class="rate-reason"><?= e($rateChange['reason']) ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="num"><?= e(money((float) $item['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-1" style="max-width: 340px; margin-left:auto;">
                    <div class="total-row"><span>Subtotal</span><span><?= e(money((float) $order['subtotal'])) ?></span></div>
                    <div class="total-row"><span>Discount</span><span>- <?= e(money((float) $order['discount_amount'])) ?></span></div>
                    <div class="total-row"><span>Delivery charge</span><span><?= e(money((float) $order['delivery_charge'])) ?></span></div>
                    <div class="total-row"><span>Tax</span><span><?= e(money((float) $order['tax_amount'])) ?></span></div>
                    <div class="total-row grand"><span>Total
                        <span class="badge <?= $billEstimated ? 'badge-warn' : 'badge-success' ?>"><?= $billEstimated ? 'Estimated' : 'Final' ?></span>
                    </span><span><?= e(money((float) $order['total_amount'])) ?></span></div>
                    <div class="total-row"><span>Paid</span><span><?= e(money((float) $order['paid_amount'])) ?></span></div>
                    <div class="total-row"><strong>Balance due</strong><strong><?= e(money($due)) ?></strong></div>
                </div>
            </div>
            <div class="panel-head">
                <h3>Actions</h3>
                <div class="row-between">
                    <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/order-edit.php?id=' . (int) $orderId)) ?>">Edit Order</a>
                    <?php if ($order['status'] !== 'cancelled' && $order['status'] !== 'delivered'): ?>
                        <form method="post" class="row-between" data-confirm="Cancel this order?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cancel">
                            <button class="btn btn-sm btn-outline-danger" type="submit">Cancel Order</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($itemsLocked): ?>
                <div class="panel-body tight">
                    <div class="subtle"><?= $closed
                        ? 'This order is delivered and fully paid, so it is closed. Items are locked to keep the final bill stable.'
                        : 'This order is delivered, so its items are locked. The balance can still be collected.' ?></div>
                </div>
            <?php endif; ?>
        </div>

        <div class="panel">
            <div class="panel-head"><h3>Payments</h3></div>
            <div class="panel-body">
                <?php if ($due > 0 && !$closed && $order['status'] !== 'cancelled'): ?>
                    <form method="post" class="filter-form mb-1">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="payment">
                        <div class="form-group">
                            <label for="amount">Amount (<?= e(APP_CURRENCY) ?>)</label>
                            <input type="number" id="amount" name="amount" min="0" step="0.01" value="<?= e(number_format($due, 2, '.', '')) ?>">
                        </div>
                        <div class="form-group">
                            <label for="method">Method</label>
                            <select id="method" name="method">
                                <?php foreach (payment_methods() as $key => $label): ?>
                                    <option value="<?= e($key) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="reference_no">Reference no</label>
                            <input type="text" id="reference_no" name="reference_no">
                        </div>
                        <div class="form-group">
                            <button class="btn btn-green" type="submit">Record Payment</button>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if ($order['status'] !== 'cancelled' && ((string) $order['payment_status'] !== 'paid' || (float) $order['paid_amount'] > 0)): ?>
                    <div class="pay-mark-row">
                        <?php if ((string) $order['payment_status'] !== 'paid'): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="mark_paid">
                                <select name="method" aria-label="Payment method">
                                    <?php foreach (payment_methods() as $key => $label): ?>
                                        <option value="<?= e($key) ?>"><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-green" type="submit"
                                    data-confirm="<?= e('Settle ' . money($due) . ' for ' . $order['order_number'] . ' and mark it paid?') ?>">Mark Paid</button>
                            </form>
                        <?php endif; ?>
                        <?php if ((float) $order['paid_amount'] > 0): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="mark_unpaid">
                                <button class="btn btn-sm btn-outline-danger" type="submit"
                                    data-confirm="<?= e('Void all payments for ' . $order['order_number'] . ' and mark it unpaid?') ?>">Mark Unpaid</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($payments === []): ?>
                    <div class="empty-state">No payment recorded yet.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Reference</th>
                                    <th>Collected by</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?= e(format_datetime((string) $payment['collected_at'])) ?></td>
                                    <td><?php if (($payment['voided_at'] ?? null) !== null): ?><span class="badge badge-danger">Voided</span> <?php endif; ?><?= e(money((float) $payment['amount'])) ?></td>
                                    <td><?= e(payment_methods()[(string) $payment['method']] ?? $payment['method']) ?></td>
                                    <td><?= e((string) ($payment['reference_no'] ?? '—')) ?></td>
                                    <td><?= e((string) ($payment['collected_by_name'] ?? '—')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div>
        <div class="panel">
            <div class="panel-head"><h3>Customer</h3></div>
            <div class="panel-body">
                <dl class="kv">
                    <dt>Name</dt><dd><?= e($order['contact_name']) ?></dd>
                    <dt>Phone</dt><dd><?= e($order['contact_phone']) ?></dd>
                    <dt>Address</dt><dd><?= e($order['delivery_address']) ?><?php if ($orderMapUrl !== ''): ?> <a class="btn btn-sm btn-ghost" href="<?= e($orderMapUrl) ?>" target="_blank" rel="noopener">Navigate</a><?php endif; ?></dd>
                    <dt>Booked on</dt><dd><?= e(format_date((string) $order['order_date'])) ?></dd>
                    <dt>Pickup date</dt><dd><?= e(format_date($order['scheduled_pickup_date'])) ?></dd>
                    <dt>Delivery date</dt><dd><?= e(format_date($order['scheduled_delivery_date'])) ?></dd>
                    <dt>Source</dt><dd><?= $order['source'] === 'customer' ? 'Customer website' : 'Created by staff' ?></dd>
                    <dt>Customer ID</dt><dd><?= e((string) $order['customer_id']) ?></dd>
                </dl>
                <?php if (!empty($order['notes'])): ?>
                    <div class="muted-box mt-1"><strong>Order note:</strong> <?= e($order['notes']) ?></div>
                <?php endif; ?>
                <?php if (!empty($order['staff_notes'])): ?>
                    <div class="muted-box mt-1"><strong>Internal note:</strong> <?= e($order['staff_notes']) ?></div>
                <?php endif; ?>

                <h4 class="mt-1">Old Invoice Dues</h4>
                <?php if ($customerDues === []): ?>
                    <div class="subtle">No other pending invoices for this customer.</div>
                <?php else: ?>
                    <div class="subtle mb-0">
                        <strong><?= e(money($customerDueTotal)) ?></strong> pending across <?= e((string) count($customerDues)) ?> old invoice(s).
                    </div>
                    <div class="table-wrap">
                        <table class="data">
                            <thead><tr><th>Invoice</th><th>Date</th><th>Status</th><th class="num">Due</th></tr></thead>
                            <tbody>
                            <?php foreach ($customerDues as $duesRow): ?>
                                <?php $rowDue = max(0, (float) $duesRow['total_amount'] - (float) $duesRow['paid_amount']); ?>
                                <tr>
                                    <td><a href="<?= e(url('admin/order-view.php?id=' . (int) $duesRow['id'])) ?>"><?= e($duesRow['order_number']) ?></a></td>
                                    <td><?= e(format_date((string) $duesRow['order_date'])) ?></td>
                                    <td><span class="badge <?= e(status_badge((string) $duesRow['payment_status'])) ?>"><?= e(payment_status_label((string) $duesRow['payment_status'])) ?></span></td>
                                    <td class="num"><?= e(money($rowDue)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><h3>WhatsApp Updates</h3></div>
            <div class="panel-body">
                <?php
                $waSuggested = whatsapp_event_for_status((string) $order['status']);
                $waSent = 0;
                ?>
                <p class="subtle">Opens WhatsApp with the order details filled in. Edit the wording in
                    <a href="<?= e(url('admin/whatsapp-templates.php')) ?>">WhatsApp Messages</a>.</p>
                <div class="wa-send-list">
                    <?php foreach (whatsapp_events() as $waEvent => $waLabel): ?>
                        <?php
                        $waLink = whatsapp_link($order, $waEvent);
                        if ($waLink === null) {
                            continue;
                        }
                        $waSent++;
                        $isSuggested = $waEvent === $waSuggested;
                        ?>
                        <a class="btn btn-sm <?= $isSuggested ? 'btn-primary' : 'btn-ghost' ?> whatsapp-btn"
                           href="<?= e($waLink) ?>" target="_blank" rel="noopener">
                            <span aria-hidden="true">&#128241;</span> <?= e($waLabel) ?><?= $isSuggested ? ' (suggested)' : '' ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php if ($waSent === 0): ?>
                    <div class="empty-state">
                        <?php if (whatsapp_phone((string) $order['contact_phone']) === null): ?>
                            This order has no valid 10-digit customer mobile number, so WhatsApp messages cannot be opened.
                        <?php else: ?>
                            All WhatsApp messages are switched off. Turn them on in WhatsApp Messages.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel" id="staff-assignment">
            <div class="panel-head"><h3>Staff Assignment</h3></div>
            <div class="panel-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="assign">
                    <div class="form-group">
                        <label for="pickup_staff_id">Pickup staff</label>
                        <select name="pickup_staff_id" id="pickup_staff_id">
                            <option value="">Not assigned</option>
                            <?php foreach ($pickupStaff as $staff): ?>
                                <option value="<?= e((string) $staff['id']) ?>"<?= (int) $order['pickup_staff_id'] === (int) $staff['id'] ? ' selected' : '' ?>>
                                    <?= e($staff['name']) ?><?= $staff['is_active'] ? '' : ' (disabled)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="delivery_staff_id">Delivery staff</label>
                        <select name="delivery_staff_id" id="delivery_staff_id">
                            <option value="">Not assigned</option>
                            <?php foreach ($deliveryStaff as $staff): ?>
                                <option value="<?= e((string) $staff['id']) ?>"<?= (int) $order['delivery_staff_id'] === (int) $staff['id'] ? ' selected' : '' ?>>
                                    <?= e($staff['name']) ?><?= $staff['is_active'] ? '' : ' (disabled)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-block" type="submit">Save Assignment</button>
                </form>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><h3>Update Status</h3></div>
            <div class="panel-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="status">
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select name="status" id="status">
                            <?php foreach (order_statuses() as $key => $label): ?>
                                <option value="<?= e($key) ?>"<?= $order['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="notes">Note</label>
                        <input type="text" name="notes" id="notes" placeholder="Optional note for the log">
                    </div>
                    <button class="btn btn-block" type="submit">Update Status</button>
                </form>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><h3>Activity Log</h3></div>
            <div class="panel-body">
                <ul class="timeline">
                    <?php foreach ($history as $entry): ?>
                        <li>
                            <div class="who"><?= e(order_status_label((string) $entry['to_status'])) ?></div>
                            <div class="when"><?= e(format_datetime((string) $entry['created_at'])) ?> &middot; <?= e((string) ($entry['user_name'] ?? 'System')) ?> (<?= e(role_label((string) ($entry['user_role'] ?? 'system'))) ?>)</div>
                            <?php if (!empty($entry['notes'])): ?>
                                <div class="when"><?= e($entry['notes']) ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php layout_end(); ?>
