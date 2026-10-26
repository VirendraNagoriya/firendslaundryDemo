<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/orders.php';
require_once dirname(__DIR__) . '/includes/order-tiles.php';

$user = require_customer();
$customer = customer_for_user((int) $user['id']);
if ($customer === null) {
    redirect('index.php');
}
$customerId = (int) $customer['id'];

$status = query('status');
$search = query('q');

$where = ['customer_id = ?'];
$params = [$customerId];

if ($status !== '' && isset(order_statuses()[$status])) {
    $where[] = 'status = ?';
    $params[] = $status;
}
if ($search !== '') {
    $where[] = '(order_number LIKE ? OR contact_phone LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$orders = db_all(
    'SELECT * FROM orders WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 100',
    $params
);

layout_start('My Orders', 'customer');
?>
<div class="panel">
    <div class="panel-head">
        <h2>My Orders</h2>
        <a class="btn btn-sm" href="<?= e(url('customer/schedule-pickup.php')) ?>">+ Schedule Pickup</a>
    </div>
    <div class="panel-body">
        <form method="get" class="filter-form mb-1">
            <div class="form-group">
                <label for="status">Status</label>
                <select name="status" id="status" data-auto-submit>
                    <option value="">All statuses</option>
                    <?php foreach (order_statuses() as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $status === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="q">Order no / phone</label>
                <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Search">
            </div>
            <div class="form-group">
                <button class="btn" type="submit">Filter</button>
            </div>
            <div class="form-group">
                <a class="btn btn-ghost" href="<?= e(url('customer/orders.php')) ?>">Reset</a>
            </div>
        </form>

        <?php if ($orders === []): ?>
            <div class="empty-state">
                <strong>No orders found</strong>
                Adjust the filters or schedule a new pickup.
            </div>
        <?php else: ?>
            <?php foreach ($orders as $order): ?>
                <?php
                echo order_tile($order, [
                    'note' => 'Booked ' . format_date((string) $order['order_date']) . ' · ' . order_items_summary((int) $order['id']),
                    'actions' => '<a class="btn btn-sm btn-ghost" href="' . e(url('customer/order-view.php?id=' . (int) $order['id'])) . '">View details</a>',
                ]);
                ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php layout_end(); ?>
