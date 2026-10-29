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

$orderId = query_int('order_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action');
    $orderId = (int) post('order_id');
    $order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);

    if ($order === null) {
        $errors[] = 'Order not found.';
    } elseif (!in_array($action, ['add_items', 'verify_weights'], true)) {
        $errors[] = 'Unknown action.';
    } elseif (!order_accepts_items($order)) {
        $errors[] = 'This order is delivered or cancelled, so its items are locked.';
    } else {
        // Shared queue: any staff member may add items to any open order.
        claim_order_for_staff($orderId, $staffId, 'pickup');
        $order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);

        if ($order === null) {
            $errors[] = 'Order not found.';
        } elseif ($action === 'verify_weights') {
            $weights = $_POST['weights'] ?? [];
            $updated = is_array($weights) ? update_order_item_weights($orderId, $weights, $staffId) : 0;
            if ($updated === 0) {
                $errors[] = 'No per-kg items were updated.';
            } else {
                audit('order.weights_verified', 'order', (string) $orderId, $updated . ' weight(s) verified');
                flash('success', 'Verified weights saved for ' . $updated . ' item(s).');
                redirect('staff/pos.php?order_id=' . $orderId);
            }
        } else {
            $items = collect_items_from_post(true, $errors);
            if ($items === []) {
                $errors[] = 'Please add at least one item.';
            } else {
                $added = append_order_items($orderId, $items);
                if ($added === 0) {
                    $errors[] = 'No items were added. The order may have been closed.';
                } else {
                    audit('order.items_added', 'order', (string) $orderId, $added . ' item(s) added at location');
                    flash('success', $added . ' item(s) added to order ' . $order['order_number'] . '.');
                    redirect('staff/pos.php?order_id=' . $orderId);
                }
            }
        }
    }
}

$orders = db_all(
    "SELECT o.*, c.name AS customer_name,
            pu.name AS pickup_staff_name, du.name AS delivery_staff_name
     FROM orders o
     JOIN customers c ON c.id = o.customer_id
     LEFT JOIN users pu ON pu.id = o.pickup_staff_id
     LEFT JOIN users du ON du.id = o.delivery_staff_id
     WHERE o.status NOT IN ('delivered','cancelled')
     ORDER BY o.order_date ASC, o.id ASC
     LIMIT 200"
);

layout_start('Add Items (POS)', 'app');
?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if ($orderId > 0): ?>
    <?php
    $order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if ($order === null) {
        flash('error', 'Order not found.');
        redirect('staff/pos.php');
    }
    $orderItems = db_all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
    $due = max(0.0, (float) $order['total_amount'] - (float) $order['paid_amount']);
    $closed = order_is_closed($order);
    $locked = order_items_locked($order);
    $kgItems = array_values(array_filter($orderItems, static fn(array $item): bool => (string) $item['price_mode'] === 'per_kg'));
    ?>
    <div class="panel">
        <div class="panel-head">
            <h2><?= e($order['order_number']) ?> &middot; <?= e($order['contact_name']) ?></h2>
            <div class="row-between">
                <?php if ($closed): ?>
                    <span class="badge badge-success">Closed</span>
                <?php endif; ?>
                <a class="btn btn-sm btn-ghost" href="<?= e(url('staff/pos.php')) ?>">Back to list</a>
            </div>
        </div>
        <div class="panel-body tight">
            <dl class="kv kv-compact">
                <dt>Phone</dt><dd><a href="tel:<?= e($order['contact_phone']) ?>"><?= e($order['contact_phone']) ?></a></dd>
                <dt>Address</dt><dd><?= e($order['delivery_address']) ?></dd>
                <dt>Status</dt><dd><span class="badge <?= e(status_badge((string) $order['status'])) ?>"><?= e(order_status_label((string) $order['status'])) ?></span></dd>
                <dt>Items</dt><dd><?= e(order_items_summary($orderId)) ?></dd>
                <dt>Total</dt><dd><?= e(money((float) $order['total_amount'])) ?> &middot; due <?= e(money($due)) ?></dd>
            </dl>
        </div>
    </div>

    <?php if ($locked): ?>
        <div class="alert alert-warning"><?= $closed
            ? 'This order is delivered and fully paid, so it is closed. Items can no longer be added or changed.'
            : 'This order is delivered, so its items are locked. The pending balance can still be collected from the delivery or dues page.' ?></div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel-head"><h3>Items on this order</h3></div>
        <div class="panel-body tight">
            <?php if ($orderItems === []): ?>
                <div class="empty-state"><strong>No items yet</strong><?= $locked ? 'This order is delivered or cancelled, so its items are locked.' : 'Add the garments below once you reach the customer.' ?></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr><th>Item</th><th>Service</th><th>Billing</th><th class="num">Qty</th><th class="num">Weight</th><th class="num">Rate</th><th class="num">Amount</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($orderItems as $item): ?>
                            <tr>
                                <td><?= e($item['item_name']) ?><?php if (!empty($item['subcategory_name'])): ?><br><span class="subtle"><?= e((string) $item['subcategory_name']) ?></span><?php endif; ?></td>
                                <td><?= e(order_item_service_label($item)) ?></td>
                                <td><?= e(order_item_billing_label($item)) ?></td>
                                <td class="num"><?= e((string) $item['qty']) ?></td>
                                <td class="num">
                                    <?= e(number_format((float) $item['weight_kg'], 3)) ?> kg
                                    <?php if ((string) $item['price_mode'] === 'per_kg'): ?>
                                        <span class="badge <?= (int) $item['weight_verified'] === 1 ? 'badge-success' : 'badge-warn' ?>"><?= (int) $item['weight_verified'] === 1 ? 'Verified' : 'Estimated' ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="num"><?= e(money((float) $item['rate'])) ?></td>
                                <td class="num"><?= e(money((float) $item['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="subtle mt-1" style="font-size: .8rem;">Items already on the order cannot be removed here. <?= $locked ? 'This order is delivered or cancelled, so its items are locked.' : 'Ask the owner if something was added by mistake.' ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$locked && $kgItems !== []): ?>
        <div class="panel">
            <div class="panel-head"><h3>Verify actual weight</h3></div>
            <div class="panel-body">
                <div class="verification-steps">
                    <div class="step active">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h4>Estimate Weight</h4>
                            <p>Customer provides an estimated weight for each item. This was used to calculate the initial bill.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h4>Measure Weight</h4>
                            <p>Physically weigh each item to get the actual weight. Enter the exact weight below.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h4>Confirm & Update</h4>
                            <p>The bill will be automatically updated based on the actual weights. Any difference will be reflected in the total.</p>
                        </div>
                    </div>
                </div>

                <div class="verification-note">
                    <p><strong>Note:</strong> Customer weight is an estimate. Enter the actual kg you measured - the bill updates automatically.</p>
                </div>

                <form method="post" action="<?= e(url('staff/pos.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="verify_weights">
                    <input type="hidden" name="order_id" value="<?= e((string) $orderId) ?>">
                    <div class="table-wrap">
                        <table class="data">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Service</th>
                                    <th class="num">Estimated (kg)</th>
                                    <th class="num">Actual (kg)</th>
                                    <th class="num">Difference</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($kgItems as $item): 
                                $estimatedWeight = (float) $item['weight_kg'];
                                $difference = 0;
                                $statusClass = '';
                                $statusText = 'Not Verified';

                                if ((int) $item['weight_verified'] === 1) {
                                    $actualWeight = (float) $item['weight_kg'];
                                    $difference = $actualWeight - $estimatedWeight;
                                    $statusClass = 'badge-success';
                                    $statusText = 'Verified';
                                }
                            ?>
                                <tr>
                                    <td><?= e((string) $item['item_name']) ?></td>
                                    <td><?= e(order_item_service_label($item)) ?></td>
                                    <td class="num"><?= e(number_format($estimatedWeight, 3)) ?></td>
                                    <td class="num">
                                        <input type="number" name="weights[<?= (int) $item['id'] ?>]" min="0" step="0.5" value="<?= e((string) $estimatedWeight) ?>" inputmode="decimal" style="max-width:110px;">
                                    </td>
                                    <td class="num <?= $difference > 0 ? 'text-success' : ($difference < 0 ? 'text-danger' : '') ?>">
                                        <?= $difference != 0 ? ($difference > 0 ? '+' : '') . number_format($difference, 3) : '-' ?>
                                    </td>
                                    <td><span class="badge <?= $statusClass ?>"><?= $statusText ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="form-actions mt-1">
                        <button class="btn btn-green" type="submit">Save verified weights</button>
                        <button type="button" class="btn btn-ghost" id="verify-all">Verify All</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$locked): ?>
        <div class="panel">
            <div class="panel-head"><h3>Add items</h3></div>
            <div class="panel-body">
                <form method="post" action="<?= e(url('staff/pos.php')) ?>" data-calc-items>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_items">
                    <input type="hidden" name="order_id" value="<?= e((string) $orderId) ?>">

                    <?php render_item_picker([], $catalog, ['intro' => 'Add the garments you received. Enter the actual kg for kg services - the pickup boy verifies the weight.']); ?>

                    <div class="form-actions mt-1">
                        <button class="btn btn-lg btn-green" type="submit">Add to order</button>
                        <a class="btn btn-lg btn-ghost" href="<?= e(url('staff/pos.php')) ?>">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="panel">
        <div class="panel-head">
            <h2>Open Orders</h2>
            <span class="subtle"><?= e((string) count($orders)) ?> open orders &middot; assigned or not</span>
        </div>
        <div class="panel-body tight">
            <?php if ($orders === []): ?>
                <div class="empty-state"><strong>No open orders</strong>New orders will appear here for every staff member.</div>
            <?php else: ?>
                <?php foreach ($orders as $order): ?>
                    <?php
                    $closed = order_is_closed($order);
                    $assignment = 'Pickup: ' . ((string) ($order['pickup_staff_name'] ?? '') !== '' ? $order['pickup_staff_name'] : 'unassigned')
                        . ' · Delivery: ' . ((string) ($order['delivery_staff_name'] ?? '') !== '' ? $order['delivery_staff_name'] : 'unassigned');
                    echo order_tile($order, [
                        'note' => $assignment . ' · ' . order_items_summary((int) $order['id']) . ($closed ? ' · Closed (delivered & paid)' : ''),
                        'actions' => '<a class="btn btn-sm" href="' . e(url('staff/pos.php?order_id=' . (int) $order['id'])) . '">' . ($closed ? 'View items' : 'Add items') . '</a>',
                    ]);
                    ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?php layout_end(); ?>
