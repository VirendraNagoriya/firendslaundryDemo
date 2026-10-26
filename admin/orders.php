<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/orders.php';
require_once dirname(__DIR__) . '/includes/order-tiles.php';

$user = require_admin();

$search = query('q');
$status = query('status');
$payment = query('payment');
$from = query('from');
$to = query('to');
$page = max(1, query_int('page', 1));
$perPage = 25;

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(o.order_number LIKE ? OR o.contact_name LIKE ? OR o.contact_phone LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($status !== '' && isset(order_statuses()[$status])) {
    $where[] = 'o.status = ?';
    $params[] = $status;
}
if ($payment !== '' && isset(payment_statuses()[$payment])) {
    $where[] = 'o.payment_status = ?';
    $params[] = $payment;
}
if ($from !== '') {
    $where[] = 'o.order_date >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $where[] = 'o.order_date <= ?';
    $params[] = $to;
}

$whereSql = implode(' AND ', $where);
$total = (int) db_value("SELECT COUNT(*) FROM orders o WHERE {$whereSql}", $params, 0);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$orders = db_all(
    "SELECT o.*, c.name AS customer_name,
            (SELECT name FROM users WHERE id = o.pickup_staff_id) AS pickup_staff_name,
            (SELECT name FROM users WHERE id = o.delivery_staff_id) AS delivery_staff_name
     FROM orders o
     JOIN customers c ON c.id = o.customer_id
     WHERE {$whereSql}
     ORDER BY o.id DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$baseQuery = $_GET;
$pageLink = static function (int $target) use ($baseQuery): string {
    $baseQuery['page'] = $target;
    return '?' . http_build_query($baseQuery);
};

layout_start('Orders', 'app');
?>
<div class="toolbar">
    <form method="get" class="filter-form">
        <div class="form-group">
            <label for="q">Order no / customer / phone</label>
            <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Search">
        </div>
        <div class="form-group">
            <label for="status">Status</label>
            <select name="status" id="status" data-auto-submit>
                <option value="">All</option>
                <?php foreach (order_statuses() as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $status === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="payment">Payment</label>
            <select name="payment" id="payment" data-auto-submit>
                <option value="">All</option>
                <?php foreach (payment_statuses() as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $payment === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="from">From date</label>
            <input type="date" id="from" name="from" value="<?= e($from) ?>">
        </div>
        <div class="form-group">
            <label for="to">To date</label>
            <input type="date" id="to" name="to" value="<?= e($to) ?>">
        </div>
        <div class="form-group">
            <button class="btn" type="submit">Filter</button>
        </div>
        <div class="form-group">
            <a class="btn btn-ghost" href="<?= e(url('admin/orders.php')) ?>">Reset</a>
        </div>
    </form>
    <a class="btn" href="<?= e(url('admin/order-create.php')) ?>">+ New Order</a>
</div>

<div class="panel">
    <div class="panel-head">
        <h2>Orders</h2>
        <span class="subtle"><?= e((string) $total) ?> matching orders</span>
    </div>
    <div class="panel-body tight">
        <?php if ($orders === []): ?>
            <div class="empty-state">
                <strong>No orders found</strong>
                Change the filters or create a new order.
            </div>
        <?php else: ?>
            <?php foreach ($orders as $order): ?>
                <?php
                $otherDues = customer_due_total((int) $order['customer_id'], (int) $order['id']);
                $actions = '<a class="btn btn-sm" href="' . e(url('admin/order-view.php?id=' . (int) $order['id'])) . '">Open</a>'
                    . '<a class="btn btn-sm btn-ghost" href="' . e(url('admin/order-view.php?id=' . (int) $order['id'] . '#staff-assignment')) . '">Reassign</a>';
                echo order_tile($order, [
                    'note' => (string) ($order['pickup_staff_name'] ?? '—') . ' → ' . (string) ($order['delivery_staff_name'] ?? '—'),
                    'old_dues' => $otherDues,
                    'actions' => $actions,
                ]);
                ?>
            <?php endforeach; ?>

            <?php if ($pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?= e($pageLink($page - 1)) ?>">Prev</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="current"><?= e((string) $i) ?></span>
                        <?php else: ?>
                            <a href="<?= e($pageLink($i)) ?>"><?= e((string) $i) ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $pages): ?>
                        <a href="<?= e($pageLink($page + 1)) ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php layout_end(); ?>
