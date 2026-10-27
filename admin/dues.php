<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/orders.php';
require_once dirname(__DIR__) . '/includes/order-tiles.php';

$user = require_admin();
$search = query('q');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (post('action') === 'collect_dues') {
        $orderId = (int) post('order_id');
        $order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if ($order === null) {
            $errors[] = 'Order not found.';
        } elseif (order_is_closed($order)) {
            $errors[] = 'This order is delivered and fully paid, so it is closed.';
        } else {
            $amount = (float) post('amount');
            $method = post('method');

            // Allow admin to modify due amounts
            if ($amount <= 0) {
                $errors[] = 'Please enter a valid collection amount.';
            } elseif (!isset(payment_methods()[$method])) {
                $errors[] = 'Please select a valid payment method.';
            } else {
                record_payment(
                    $orderId,
                    $amount,
                    $method,
                    post('reference_no') !== '' ? post('reference_no') : null,
                    'Collected from dues',
                    (int) $user['id']
                );
                audit('dues.collect', 'order', (string) $orderId, $order['order_number']);
                flash('success', 'Payment of ' . money($amount) . ' recorded for order ' . $order['order_number'] . '.');
                redirect('admin/dues.php' . ($search !== '' ? '?' . http_build_query(['q' => $search]) : ''));
            }
        }
    }
}

$duesCustomers = all_customer_dues($search);
$total = 0.0;
$invoiceCount = 0;
foreach ($duesCustomers as $entry) {
    $total += (float) $entry['total'];
    $invoiceCount += count($entry['orders']);
}

layout_start('Dues', 'app');
?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="card-grid mb-1">
    <div class="stat-card red">
        <div class="label">Total Outstanding</div>
        <div class="value"><?= e(money($total)) ?></div>
        <div class="hint">All unpaid invoices</div>
    </div>
    <div class="stat-card accent">
        <div class="label">Open Invoices</div>
        <div class="value"><?= e((string) $invoiceCount) ?></div>
        <div class="hint">Across <?= e((string) count($duesCustomers)) ?> customers</div>
    </div>
</div>

<div class="toolbar">
    <form method="get" class="filter-form">
        <div class="form-group">
            <label for="q">Customer name / phone</label>
            <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Search">
        </div>
        <div class="form-group">
            <button class="btn" type="submit">Search</button>
        </div>
        <div class="form-group">
            <a class="btn btn-ghost" href="<?= e(url('admin/dues.php')) ?>">Reset</a>
        </div>
    </form>
</div>

<div class="panel">
    <div class="panel-head">
        <h2>Pending Payments</h2>
        <span class="subtle">Tap an invoice to collect</span>
    </div>
    <div class="panel-body tight">
        <?php if ($duesCustomers === []): ?>
            <div class="empty-state">
                <strong>No pending dues</strong>
                <?= $search !== '' ? 'No customer matches this search.' : 'Every invoice is paid up.' ?>
            </div>
        <?php else: ?>
            <?php foreach ($duesCustomers as $entry): ?>
                <div class="dues-customer">
                    <div class="dues-customer-head">
                        <strong><?= e((string) $entry['customer']['name']) ?></strong>
                        <a class="subtle" href="tel:<?= e((string) $entry['customer']['phone']) ?>"><?= e((string) $entry['customer']['phone']) ?></a>
                        <span class="tile-old-dues"><?= e(money((float) $entry['total'])) ?> due</span>
                    </div>
                    <?php foreach ($entry['orders'] as $invoice): ?>
                        <?php
                        $invoiceDue = max(0.0, (float) $invoice['total_amount'] - (float) $invoice['paid_amount']);
                        if ($invoiceDue <= 0) {
                            continue;
                        }
                        $actions = '<form method="post">'
                            . csrf_field()
                            . '<input type="hidden" name="action" value="collect_dues">'
                            . '<input type="hidden" name="order_id" value="' . e((string) $invoice['id']) . '">'
                            . '<input type="number" name="amount" min="0" step="0.01" value="' . e(number_format($invoiceDue, 2, '.', '')) . '" placeholder="Amount">'
                            . '<select name="method">' . payment_method_options() . '</select>'
                            . '<input type="text" name="reference_no" placeholder="Ref">'
                            . '<button class="btn btn-sm btn-green" type="submit">Collect</button>'
                            . '</form>'
                            . '<a class="btn btn-sm btn-ghost" href="' . e(url('admin/order-view.php?id=' . (int) $invoice['id'])) . '">Open</a>';
                        echo order_tile($invoice, [
                            'note' => 'Order ' . format_date((string) $invoice['order_date']) . ' · ' . order_items_summary((int) $invoice['id']),
                            'actions' => $actions,
                        ]);
                        ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php layout_end(); ?>
