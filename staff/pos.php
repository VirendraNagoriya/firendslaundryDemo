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
    } elseif ($action !== 'add_items') {
        $errors[] = 'Unknown action.';
    } elseif (!order_accepts_items($order)) {
        $errors[] = 'This order is closed because it is fully paid. Items can no longer be added.';
    } else {
        // Shared queue: any staff member may add items to any open order.
        claim_order_for_staff($orderId, $staffId);
        $order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);

        if ($order === null) {
            $errors[] = 'Order not found.';
        } else {
            $items = collect_items_from_post();
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
    ?>
    <div class="panel">
        <div class="panel-head">
            <h2><?= e($order['order_number']) ?> &middot; <?= e($order['contact_name']) ?></h2>
            <div class="row-between">
                <?php if ($closed): ?>
                    <span class="badge badge-success">Paid &middot; Closed</span>
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

    <?php if ($closed): ?>
        <div class="alert alert-warning">This order is closed because it is fully paid. Items can no longer be added. The owner can mark it unpaid to reopen it.</div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel-head"><h3>Items on this order</h3></div>
        <div class="panel-body tight">
            <?php if ($orderItems === []): ?>
                <div class="empty-state"><strong>No items yet</strong><?= $closed ? 'This order is closed.' : 'Add the garments below once you reach the customer.' ?></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr><th>Item</th><th>Service</th><th>Billing</th><th class="num">Qty</th><th class="num">Weight</th><th class="num">Rate</th><th class="num">Amount</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($orderItems as $item): ?>
                            <tr>
                                <td><?= e($item['item_name']) ?></td>
                                <td><?= e(service_type_label((string) $item['service_type'])) ?></td>
                                <td><?= $item['price_mode'] === 'per_kg' ? 'Per kg' : 'Per piece' ?></td>
                                <td class="num"><?= e((string) $item['qty']) ?></td>
                                <td class="num"><?= e(number_format((float) $item['weight_kg'], 3)) ?> kg</td>
                                <td class="num"><?= e(money((float) $item['rate'])) ?></td>
                                <td class="num"><?= e(money((float) $item['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="subtle mt-1" style="font-size: .8rem;">Items already on the order cannot be removed here. <?= $closed ? 'This order is closed.' : 'Ask the owner if something was added by mistake.' ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$closed): ?>
        <div class="panel">
            <div class="panel-head"><h3>Add items</h3></div>
            <div class="panel-body">
                <form method="post" action="<?= e(url('staff/pos.php')) ?>" data-calc-items>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_items">
                    <input type="hidden" name="order_id" value="<?= e((string) $orderId) ?>">

                    <?php render_item_picker([], $catalog, ['intro' => 'Tap a service to add it, then adjust the quantity or weight.']); ?>

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
                        'note' => $assignment . ' · ' . order_items_summary((int) $order['id']) . ($closed ? ' · Closed (fully paid)' : ''),
                        'actions' => '<a class="btn btn-sm" href="' . e(url('staff/pos.php?order_id=' . (int) $order['id'])) . '">' . ($closed ? 'View items' : 'Add items') . '</a>',
                    ]);
                    ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?php layout_end(); ?>
