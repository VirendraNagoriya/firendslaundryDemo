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
    if (post('action') === 'collect_payment') {
        $orderId = (int) post('order_id');
        $order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);
        $amount = (float) post('amount');
        $method = post('method');
        $due = $order !== null ? max(0.0, (float) $order['total_amount'] - (float) $order['paid_amount']) : 0.0;

        if ($order === null) {
            $errors[] = 'Order not found.';
        } elseif ((string) $order['status'] !== 'delivered') {
            $errors[] = 'Only delivered orders collect a pending balance here.';
        } elseif (order_is_closed($order)) {
            $errors[] = 'This order is delivered and fully paid, so it is closed.';
        } elseif ($due <= 0.005) {
            $errors[] = 'This order has no pending balance.';
        } elseif ($amount <= 0) {
            $errors[] = 'Please enter a valid collection amount.';
        } elseif (!isset(payment_methods()[$method])) {
            $errors[] = 'Please select a valid payment method.';
        } else {
            record_payment($orderId, $amount, $method, post('reference_no') !== '' ? post('reference_no') : null, 'Pending balance collected after delivery', $staffId);
            $after = (array) db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);
            flash('success', money($amount) . ' collected for order ' . $order['order_number'] . '.'
                . (order_is_closed($after) ? ' The order is now closed.' : ''));
            redirect('staff/orders.php');
        }
    }
}

$search = query('q');
$status = query('status');
$payment = query('payment');
$view = query('view', 'all');
if (!in_array($view, ['all', 'open', 'delivered', 'pending'], true)) {
    $view = 'all';
}

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
if ($view === 'open') {
    $where[] = "o.status NOT IN ('delivered','cancelled')";
} elseif ($view === 'delivered') {
    $where[] = "o.status = 'delivered'";
} elseif ($view === 'pending') {
    $where[] = "o.status = 'delivered' AND o.payment_status <> 'paid'";
}

$whereSql = implode(' AND ', $where);
$orders = db_all(
    "SELECT o.*, c.name AS customer_name,
            pu.name AS pickup_staff_name, du.name AS delivery_staff_name
     FROM orders o
     JOIN customers c ON c.id = o.customer_id
     LEFT JOIN users pu ON pu.id = o.pickup_staff_id
     LEFT JOIN users du ON du.id = o.delivery_staff_id
     WHERE {$whereSql}
     ORDER BY o.id DESC
     LIMIT 200",
    $params
);

$openCount = (int) db_value("SELECT COUNT(*) FROM orders WHERE status NOT IN ('delivered','cancelled')", [], 0);
$deliveredCount = (int) db_value("SELECT COUNT(*) FROM orders WHERE status = 'delivered'", [], 0);
$pendingCount = (int) db_value("SELECT COUNT(*) FROM orders WHERE status = 'delivered' AND payment_status <> 'paid'", [], 0);

layout_start('All Orders', 'app');
?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="toolbar">
    <form method="get" class="filter-form">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <div class="form-group">
            <label for="q">Order / customer / phone</label>
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
            <button class="btn" type="submit">Filter</button>
        </div>
        <div class="form-group">
            <a class="btn btn-ghost" href="<?= e(url('staff/orders.php')) ?>">Reset</a>
        </div>
    </form>
</div>

<nav class="tab-nav" aria-label="Order views">
    <a class="tab-link<?= $view === 'all' ? ' active' : '' ?>" href="<?= e(url('staff/orders.php?view=all')) ?>">All</a>
    <a class="tab-link<?= $view === 'open' ? ' active' : '' ?>" href="<?= e(url('staff/orders.php?view=open')) ?>">Open (<?= e((string) $openCount) ?>)</a>
    <a class="tab-link<?= $view === 'delivered' ? ' active' : '' ?>" href="<?= e(url('staff/orders.php?view=delivered')) ?>">Delivered (<?= e((string) $deliveredCount) ?>)</a>
    <a class="tab-link<?= $view === 'pending' ? ' active' : '' ?>" href="<?= e(url('staff/orders.php?view=pending')) ?>">Payment Pending (<?= e((string) $pendingCount) ?>)</a>
</nav>

<div class="panel">
    <div class="panel-head">
        <h2>Orders</h2>
        <span class="subtle"><?= e((string) count($orders)) ?> order(s) &middot; visible to all staff</span>
    </div>
    <div class="panel-body tight">
        <?php if ($orders === []): ?>
            <div class="empty-state">
                <strong>No orders found</strong>
                Change the filters or check the Pickup and Delivery queues.
            </div>
        <?php else: ?>
            <?php foreach ($orders as $order): ?>
                <?php
                $due = max(0.0, (float) $order['total_amount'] - (float) $order['paid_amount']);
                $closed = order_is_closed($order);
                $locked = order_items_locked($order);
                $assignment = 'Pickup: ' . ((string) ($order['pickup_staff_name'] ?? '') !== '' ? $order['pickup_staff_name'] : 'unassigned')
                    . ' · Delivery: ' . ((string) ($order['delivery_staff_name'] ?? '') !== '' ? $order['delivery_staff_name'] : 'unassigned');
                $note = $assignment . ' · ' . order_items_summary((int) $order['id'])
                    . ($closed ? ' · Closed (delivered & paid)' : ($order['status'] === 'delivered' ? ' · Payment pending' : ''));

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

                if ($due > 0.005 && !$closed && $order['status'] === 'delivered') {
                    $actions .= '<form method="post">'
                        . csrf_field()
                        . '<input type="hidden" name="action" value="collect_payment">'
                        . '<input type="hidden" name="order_id" value="' . e((string) $order['id']) . '">'
                        . '<span class="subtle">Due ' . e(money($due)) . '</span>'
                        . '<input type="number" name="amount" min="0" step="0.01" value="' . e(number_format($due, 2, '.', '')) . '" placeholder="Amount">'
                        . '<select name="method">' . payment_method_options() . '</select>'
                        . '<input type="text" name="reference_no" placeholder="Ref">'
                        . '<button class="btn btn-sm btn-primary" type="submit"'
                        . ' data-confirm="' . e('Collect ' . money($due) . ' for ' . $order['order_number'] . '?') . '"'
                        . ' data-confirm-2="' . e('Second check: confirm the payment for ' . $order['order_number'] . ' has been received.') . '">'
                        . 'Collect</button>'
                        . '</form>';
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
<?php layout_end(); ?>
