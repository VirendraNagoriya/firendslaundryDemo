<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/orders.php';

$user = require_customer();
$customer = customer_for_user((int) $user['id']);
if ($customer === null) {
    flash('error', 'Your customer profile is missing. Please contact the laundry.');
    redirect('login.php');
}
$customerId = (int) $customer['id'];

$counts = order_summary_counts('customer_id = ?', [$customerId]);
$activeStatuses = ['new', 'pickup_assigned', 'picked_up', 'processing', 'ready_for_delivery', 'out_for_delivery'];
$active = (int) db_value(
    "SELECT COUNT(*) FROM orders WHERE customer_id = ? AND status IN ('" . implode("','", $activeStatuses) . "')",
    [$customerId],
    0
);
$totalSpent = (float) db_value("SELECT COALESCE(SUM(paid_amount), 0) FROM orders WHERE customer_id = ?", [$customerId], 0);
$due = (float) db_value(
    "SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM orders WHERE customer_id = ? AND payment_status <> 'paid' AND status <> 'cancelled'",
    [$customerId],
    0
);
$nextPickup = db_row(
    "SELECT order_number, scheduled_pickup_date FROM orders
     WHERE customer_id = ? AND status IN ('new','pickup_assigned') AND scheduled_pickup_date IS NOT NULL
     ORDER BY scheduled_pickup_date ASC LIMIT 1",
    [$customerId]
);
$recentOrders = db_all(
    'SELECT * FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 6',
    [$customerId]
);

layout_start('My Dashboard', 'customer');
?>
<div class="card-grid mb-1">
    <div class="stat-card accent">
        <div class="label">Total Orders</div>
        <div class="value"><?= e((string) $counts['total']) ?></div>
        <div class="hint"><?= e((string) $active) ?> currently in progress</div>
    </div>
    <div class="stat-card green">
        <div class="label">Delivered</div>
        <div class="value"><?= e((string) $counts['delivered']) ?></div>
        <div class="hint"><?= e((string) $counts['cancelled']) ?> cancelled</div>
    </div>
    <div class="stat-card amber">
        <div class="label">Payment Due</div>
        <div class="value"><?= e(money($due)) ?></div>
        <div class="hint">Payable on delivery</div>
    </div>
    <div class="stat-card">
        <div class="label">Total Paid</div>
        <div class="value"><?= e(money($totalSpent)) ?></div>
        <div class="hint"><?= $nextPickup ? 'Next pickup: ' . e(format_date((string) $nextPickup['scheduled_pickup_date'])) : 'No pickup scheduled' ?></div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2>Recent Orders</h2>
        <a class="btn btn-sm" href="<?= e(url('customer/schedule-pickup.php')) ?>">Schedule Pickup</a>
    </div>
    <div class="panel-body tight">
        <?php if ($recentOrders === []): ?>
            <div class="empty-state">
                <strong>No orders yet</strong>
                Schedule your first pickup and we will take care of the rest.
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Order No</th>
                            <th>Booked On</th>
                            <th>Pickup Date</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th class="num">Amount</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentOrders as $order): ?>
                        <tr>
                            <td><strong><?= e($order['order_number']) ?></strong></td>
                            <td><?= e(format_date((string) $order['order_date'])) ?></td>
                            <td><?= e(format_date($order['scheduled_pickup_date'])) ?></td>
                            <td><span class="badge <?= e(status_badge((string) $order['status'])) ?>"><?= e(order_status_label((string) $order['status'])) ?></span></td>
                            <td><span class="badge <?= e(status_badge((string) $order['payment_status'])) ?>"><?= e(payment_status_label((string) $order['payment_status'])) ?></span></td>
                            <td class="num"><?= e(money((float) $order['total_amount'])) ?></td>
                            <td class="actions"><a class="btn btn-sm btn-ghost" href="<?= e(url('customer/order-view.php?id=' . (int) $order['id'])) ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php layout_end(); ?>
