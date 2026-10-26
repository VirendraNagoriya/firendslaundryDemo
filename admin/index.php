<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/orders.php';
require_once dirname(__DIR__) . '/includes/order-tiles.php';

$user = require_admin();

$today = today();
$counts = order_summary_counts('order_date = ?', [$today]);
$allCounts = order_summary_counts();

$unassigned = (int) db_value(
    "SELECT COUNT(*) FROM orders WHERE pickup_staff_id IS NULL AND status IN ('new','pickup_assigned')",
    [],
    0
);
$awaitingReady = (int) db_value(
    "SELECT COUNT(*) FROM orders WHERE status IN ('picked_up','processing')",
    [],
    0
);
$pendingDelivery = (int) db_value(
    "SELECT COUNT(*) FROM orders WHERE status IN ('ready_for_delivery','out_for_delivery')",
    [],
    0
);
$pendingPaymentCount = (int) db_value(
    "SELECT COUNT(*) FROM orders WHERE payment_status <> 'paid' AND status <> 'cancelled'",
    [],
    0
);
$outstanding = outstanding_amount();
$collectedToday = (float) db_value(
    'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE voided_at IS NULL AND DATE(collected_at) = ?',
    [$today],
    0
);
$revenueToday = (float) db_value(
    "SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE order_date = ? AND status <> 'cancelled'",
    [$today],
    0
);
$customerCount = (int) db_value('SELECT COUNT(*) FROM customers', [], 0);
$staffCount = (int) db_value("SELECT COUNT(*) FROM users WHERE role IN ('staff','pickup','delivery') AND is_active = 1", [], 0);

$recentOrders = db_all('SELECT * FROM orders ORDER BY id DESC LIMIT 8');
$pendingPayments = db_all(
    "SELECT o.*, c.name AS customer_name FROM orders o
     JOIN customers c ON c.id = o.customer_id
     WHERE o.payment_status <> 'paid' AND o.status <> 'cancelled'
     ORDER BY o.order_date ASC LIMIT 6"
);
$pickupQueue = db_all(
    "SELECT o.*, c.name AS customer_name FROM orders o
     JOIN customers c ON c.id = o.customer_id
     WHERE o.status IN ('new','pickup_assigned') ORDER BY o.scheduled_pickup_date ASC, o.id ASC LIMIT 6"
);

layout_start('Dashboard', 'app');
?>
<div class="card-grid mb-1">
    <div class="stat-card accent">
        <div class="label">Orders Today</div>
        <div class="value"><?= e((string) $counts['total']) ?></div>
        <div class="hint"><?= e((string) $counts['delivered']) ?> delivered &middot; <?= e((string) $counts['picked_up']) ?> picked up</div>
    </div>
    <div class="stat-card">
        <div class="label">All Orders</div>
        <div class="value"><?= e((string) $allCounts['total']) ?></div>
        <div class="hint"><?= e((string) $customerCount) ?> customers &middot; <?= e((string) $staffCount) ?> active staff</div>
    </div>
    <div class="stat-card amber">
        <div class="label">Pending Deliveries</div>
        <div class="value"><?= e((string) $pendingDelivery) ?></div>
        <div class="hint"><?= e((string) $awaitingReady) ?> still in processing · <a href="<?= e(url('admin/delivery-queue.php')) ?>">open the queue</a></div>
    </div>
    <div class="stat-card red">
        <div class="label">Outstanding Payment</div>
        <div class="value"><?= e(money($outstanding)) ?></div>
        <div class="hint"><?= e((string) $pendingPaymentCount) ?> orders pending</div>
    </div>
    <div class="stat-card green">
        <div class="label">Collected Today</div>
        <div class="value"><?= e(money($collectedToday)) ?></div>
        <div class="hint">Order value today <?= e(money($revenueToday)) ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Unassigned Pickups</div>
        <div class="value"><?= e((string) $unassigned) ?></div>
        <div class="hint">Orders waiting for a pickup staff member</div>
    </div>
</div>

<div class="detail-grid">
    <div>
        <div class="panel">
            <div class="panel-head">
                <h2>Recent Orders</h2>
                <a class="btn btn-sm" href="<?= e(url('admin/orders.php')) ?>">View all</a>
            </div>
            <div class="panel-body tight">
                <?php if ($recentOrders === []): ?>
                    <div class="empty-state">No orders yet.</div>
                <?php else: ?>
                    <?php foreach ($recentOrders as $order): ?>
                        <?php
                        $recentNote = 'Booked ' . format_date((string) $order['order_date']) . ' · ' . order_items_summary((int) $order['id']);
                        order_tile($order, [
                            'note' => $recentNote,
                            'actions' => '<a class="btn btn-sm btn-ghost" href="' . e(url('admin/order-view.php?id=' . (int) $order['id'])) . '">Open</a>',
                        ]);
                        ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div>
        <div class="panel">
            <div class="panel-head">
                <h2>Pickup Queue</h2>
                <span class="badge badge-info"><?= e((string) $unassigned) ?> unassigned</span>
                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/pickup-queue.php')) ?>">Open queue</a>
            </div>
            <div class="panel-body tight">
                <?php if ($pickupQueue === []): ?>
                    <div class="empty-state">No orders waiting for pickup.</div>
                <?php else: ?>
                    <?php foreach ($pickupQueue as $order): ?>
                        <div class="mini-row">
                            <div class="mini-row-main">
                                <strong><?= e($order['order_number']) ?></strong>
                                <span class="subtle"><?= e($order['contact_name']) ?> · <?= e(format_date((string) $order['scheduled_pickup_date'])) ?></span>
                            </div>
                            <div class="mini-row-side">
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/order-view.php?id=' . (int) $order['id'])) ?>">Assign</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <h2>Pending Payments</h2>
                <a class="btn btn-sm" href="<?= e(url('admin/payments.php')) ?>">Manage</a>
            </div>
            <div class="panel-body tight">
                <?php if ($pendingPayments === []): ?>
                    <div class="empty-state">All payments are settled.</div>
                <?php else: ?>
                    <?php foreach ($pendingPayments as $order): ?>
                        <div class="mini-row">
                            <div class="mini-row-main">
                                <strong><?= e($order['order_number']) ?></strong>
                                <span class="subtle"><?= e($order['contact_name']) ?> · due <?= e(money((float) $order['total_amount'] - (float) $order['paid_amount'])) ?></span>
                            </div>
                            <div class="mini-row-side">
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/payments.php?order=' . (int) $order['id'])) ?>">Collect</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php layout_end(); ?>
