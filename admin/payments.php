<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/orders.php';
require_once dirname(__DIR__) . '/includes/order-tiles.php';

$user = require_admin();
$errors = [];
$view = query('view', 'pending');
$method = query('method');
$from = query('from');
$to = query('to');
$focusOrder = (int) query('order');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (post('action') === 'collect') {
        $orderId = (int) post('order_id');
        $amount = (float) post('amount');
        $payMethod = post('method');
        $order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if ($order === null) {
            $errors[] = 'Order not found.';
        } elseif (order_is_closed($order)) {
            $errors[] = 'This order is already fully paid and closed.';
        } elseif ($amount <= 0) {
            $errors[] = 'Please enter a valid collection amount.';
        } elseif (!isset(payment_methods()[$payMethod])) {
            $errors[] = 'Please select a valid payment method.';
        } else {
            record_payment(
                $orderId,
                $amount,
                $payMethod,
                post('reference_no') !== '' ? post('reference_no') : null,
                post('notes') !== '' ? post('notes') : null,
                (int) $user['id']
            );
            flash('success', 'Payment recorded for order ' . $order['order_number'] . '.');
            redirect('admin/payments.php?view=' . urlencode($view));
        }
    }
}

if ($view === 'pending') {
    $where = ["o.payment_status <> 'paid'", "o.status <> 'cancelled'"];
    $params = [];
    if ($method !== '' && isset(payment_methods()[$method])) {
        $where[] = "EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id AND p.method = ? AND p.voided_at IS NULL)";
        $params[] = $method;
    }
    $orders = db_all(
        "SELECT o.*, c.name AS customer_name FROM orders o
         JOIN customers c ON c.id = o.customer_id
         WHERE " . implode(' AND ', $where) . '
         ORDER BY o.order_date ASC, o.id ASC LIMIT 200',
        $params
    );
    $totalDue = outstanding_amount();
    $duesByCustomer = db_all(
        "SELECT c.id, c.name, c.phone, COUNT(o.id) AS invoices,
                SUM(o.total_amount - o.paid_amount) AS due
         FROM orders o JOIN customers c ON c.id = o.customer_id
         WHERE o.payment_status <> 'paid' AND o.status <> 'cancelled'
         GROUP BY c.id, c.name, c.phone
         HAVING due > 0
         ORDER BY due DESC
         LIMIT 100"
    );
    $customerDueMap = [];
    foreach ($duesByCustomer as $row) {
        $customerDueMap[(int) $row['id']] = (float) $row['due'];
    }
} else {
    $where = ['p.voided_at IS NULL'];
    $params = [];
    if ($method !== '' && isset(payment_methods()[$method])) {
        $where[] = 'p.method = ?';
        $params[] = $method;
    }
    if ($from !== '') {
        $where[] = 'DATE(p.collected_at) >= ?';
        $params[] = $from;
    }
    if ($to !== '') {
        $where[] = 'DATE(p.collected_at) <= ?';
        $params[] = $to;
    }
    $payments = db_all(
        'SELECT p.*, o.order_number, o.contact_name, o.contact_phone
         FROM payments p JOIN orders o ON o.id = p.order_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY p.collected_at DESC LIMIT 300',
        $params
    );
    $totalDue = 0.0;
    $orders = [];
    $totalCollected = (float) db_value(
        'SELECT COALESCE(SUM(amount), 0) FROM payments p WHERE ' . implode(' AND ', $where),
        $params,
        0
    );
    $duesByCustomer = [];
    $customerDueMap = [];
}

$tabs = ['pending' => 'Pending Collection', 'collected' => 'Collection History'];
layout_start('Payments', 'app');
?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="<?= $view === $key ? 'active' : '' ?>" href="<?= e(url('admin/payments.php?view=' . $key)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($view === 'pending'): ?>
    <div class="card-grid mb-1">
        <div class="stat-card red">
            <div class="label">Total Outstanding</div>
            <div class="value"><?= e(money($totalDue)) ?></div>
            <div class="hint">Across <?= e((string) count($orders)) ?> orders</div>
        </div>
        <div class="stat-card amber">
            <div class="label">By Customer</div>
            <div class="value"><?= e((string) count($duesByCustomer)) ?></div>
            <div class="hint">Customers owing money · <a href="<?= e(url('admin/dues.php')) ?>">open the dues list</a></div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2>Pending Payment Collection</h2>
            <span class="subtle">Tap an invoice to record a collection</span>
        </div>
        <div class="panel-body tight">
            <?php if ($orders === []): ?>
                <div class="empty-state"><strong>Nothing pending</strong>All orders are fully paid.</div>
            <?php else: ?>
                <?php foreach ($orders as $order): ?>
                    <?php
                    $due = (float) $order['total_amount'] - (float) $order['paid_amount'];
                    $customerDue = $customerDueMap[(int) $order['customer_id']] ?? $due;
                    $otherDues = max(0.0, $customerDue - $due);
                    $actions = '<form method="post">'
                        . csrf_field()
                        . '<input type="hidden" name="action" value="collect">'
                        . '<input type="hidden" name="order_id" value="' . e((string) $order['id']) . '">'
                        . '<input type="number" name="amount" min="0" step="0.01" value="' . e(number_format($due, 2, '.', '')) . '" placeholder="Amount">'
                        . '<select name="method">' . payment_method_options() . '</select>'
                        . '<input type="text" name="reference_no" placeholder="Ref">'
                        . '<button class="btn btn-sm btn-green" type="submit">Collect</button>'
                        . '</form>'
                        . '<a class="btn btn-sm btn-ghost" href="' . e(url('admin/order-view.php?id=' . (int) $order['id'])) . '">Open</a>';
                    order_tile($order, [
                        'note' => 'Ordered ' . format_date((string) $order['order_date']) . ' · ' . order_items_summary((int) $order['id']),
                        'old_dues' => $otherDues > 0 ? $otherDues : null,
                        'details' => ['Customer Dues' => money($customerDue)],
                        'actions' => $actions,
                        'open' => $focusOrder === (int) $order['id'],
                    ]);
                    ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="card-grid mb-1">
        <div class="stat-card green">
            <div class="label">Collected (filtered)</div>
            <div class="value"><?= e(money($totalCollected)) ?></div>
            <div class="hint"><?= e((string) count($payments)) ?> transactions</div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><h2>Collection History</h2></div>
        <div class="panel-body">
            <form method="get" class="filter-form mb-1">
                <input type="hidden" name="view" value="collected">
                <div class="form-group">
                    <label for="method">Method</label>
                    <select name="method" id="method" data-auto-submit>
                        <option value="">All</option>
                        <?php foreach (payment_methods() as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= $method === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="from">From</label>
                    <input type="date" id="from" name="from" value="<?= e($from) ?>">
                </div>
                <div class="form-group">
                    <label for="to">To</label>
                    <input type="date" id="to" name="to" value="<?= e($to) ?>">
                </div>
                <div class="form-group">
                    <button class="btn" type="submit">Filter</button>
                </div>
                <div class="form-group">
                    <a class="btn btn-ghost" href="<?= e(url('admin/payments.php?view=collected')) ?>">Reset</a>
                </div>
            </form>

            <?php if ($payments === []): ?>
                <div class="empty-state"><strong>No collections found</strong>Change the filters to see more.</div>
            <?php else: ?>
                <?php foreach ($payments as $payment): ?>
                    <?php
                    $bits = [payment_methods()[(string) $payment['method']] ?? (string) $payment['method']];
                    if ((string) ($payment['reference_no'] ?? '') !== '') {
                        $bits[] = 'Ref ' . (string) $payment['reference_no'];
                    }
                    $bits[] = (string) ($payment['collected_by_name'] ?? '—');
                    ?>
                    <div class="mini-row">
                        <div class="mini-row-main">
                            <strong><?= e(money((float) $payment['amount'])) ?></strong>
                            <span class="subtle"><?= e($payment['order_number']) ?></span>
                            <span class="subtle"><?= e($payment['contact_name']) ?> · <?= e($payment['contact_phone']) ?></span>
                            <span class="subtle"><?= e(implode(' · ', $bits)) ?></span>
                        </div>
                        <div class="mini-row-side">
                            <span class="subtle"><?= e(format_datetime((string) $payment['collected_at'])) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?php layout_end(); ?>
