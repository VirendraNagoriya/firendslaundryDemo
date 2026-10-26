<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/orders.php';

$user = require_customer();
$customer = customer_for_user((int) $user['id']);
$orderId = query_int('id');
$order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);

if ($order === null || (int) $order['customer_id'] !== (int) $customer['id']) {
    flash('error', 'Order not found.');
    redirect('customer/orders.php');
}

$items = db_all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
$history = db_all('SELECT * FROM order_status_history WHERE order_id = ? ORDER BY id DESC', [$orderId]);
$payments = db_all('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC', [$orderId]);
$due = (float) $order['total_amount'] - (float) $order['paid_amount'];

layout_start('Order ' . $order['order_number'], 'customer');
?>
<div class="detail-grid">
    <div>
        <div class="panel">
            <div class="panel-head">
                <h2>Order <?= e($order['order_number']) ?></h2>
                <span class="badge <?= e(status_badge((string) $order['status'])) ?>"><?= e(order_status_label((string) $order['status'])) ?></span>
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
                </div>

                <div class="mt-1" style="max-width: 320px; margin-left:auto;">
                    <div class="total-row"><span>Subtotal</span><span><?= e(money((float) $order['subtotal'])) ?></span></div>
                    <?php if ((float) $order['discount_amount'] > 0): ?>
                        <div class="total-row"><span>Discount</span><span>- <?= e(money((float) $order['discount_amount'])) ?></span></div>
                    <?php endif; ?>
                    <?php if ((float) $order['delivery_charge'] > 0): ?>
                        <div class="total-row"><span>Delivery charge</span><span><?= e(money((float) $order['delivery_charge'])) ?></span></div>
                    <?php endif; ?>
                    <?php if ((float) $order['tax_amount'] > 0): ?>
                        <div class="total-row"><span>Tax</span><span><?= e(money((float) $order['tax_amount'])) ?></span></div>
                    <?php endif; ?>
                    <div class="total-row grand"><span>Total</span><span><?= e(money((float) $order['total_amount'])) ?></span></div>
                    <div class="total-row"><span>Paid</span><span><?= e(money((float) $order['paid_amount'])) ?></span></div>
                    <div class="total-row"><strong>Amount due</strong><strong><?= e(money($due)) ?></strong></div>
                </div>
            </div>
        </div>

        <?php if ($payments !== []): ?>
            <div class="panel">
                <div class="panel-head"><h3>Payment History</h3></div>
                <div class="panel-body tight">
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
                                    <td><?= e(money((float) $payment['amount'])) ?></td>
                                    <td><?= e(payment_methods()[(string) $payment['method']] ?? $payment['method']) ?></td>
                                    <td><?= e((string) ($payment['reference_no'] ?? '—')) ?></td>
                                    <td><?= e((string) ($payment['collected_by_name'] ?? '—')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <div class="panel">
            <div class="panel-head"><h3>Delivery Details</h3></div>
            <div class="panel-body">
                <dl class="kv">
                    <dt>Contact</dt>
                    <dd><?= e($order['contact_name']) ?></dd>
                    <dt>Phone</dt>
                    <dd><?= e($order['contact_phone']) ?></dd>
                    <dt>Address</dt>
                    <dd><?= e($order['delivery_address']) ?></dd>
                    <dt>Booked on</dt>
                    <dd><?= e(format_date((string) $order['order_date'])) ?></dd>
                    <dt>Pickup date</dt>
                    <dd><?= e(format_date($order['scheduled_pickup_date'])) ?></dd>
                    <dt>Payment</dt>
                    <dd><span class="badge <?= e(status_badge((string) $order['payment_status'])) ?>"><?= e(payment_status_label((string) $order['payment_status'])) ?></span></dd>
                </dl>
                <?php if (!empty($order['notes'])): ?>
                    <div class="muted-box mt-1">Note: <?= e($order['notes']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><h3>Progress</h3></div>
            <div class="panel-body">
                <ul class="timeline">
                    <?php foreach ($history as $entry): ?>
                        <li>
                            <div class="who"><?= e(order_status_label((string) $entry['to_status'])) ?></div>
                            <div class="when"><?= e(format_datetime((string) $entry['created_at'])) ?> &middot; <?= e((string) ($entry['user_name'] ?? 'System')) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php layout_end(); ?>
