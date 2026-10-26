<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/orders.php';
require_once dirname(__DIR__, 2) . '/includes/reports.php';

$user = require_admin();

$date = query('date', today());
if (strtotime($date) === false) {
    $date = today();
}
$date = date('Y-m-d', strtotime($date));
$data = daily_report_data($date);

layout_start('Daily Report', 'app');
?>
<style>
    .print-page { max-width: 1000px; margin: 0 auto; padding: 24px; }
    .print-head { border-bottom: 2px solid #16202f; padding-bottom: 10px; margin-bottom: 18px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 10px; }
    .print-head h1 { margin: 0; font-size: 1.5rem; }
    .print-head .meta { text-align: right; color: #5b6880; font-size: .85rem; }
    .print-table { width: 100%; border-collapse: collapse; font-size: .85rem; margin-bottom: 18px; }
    .print-table th, .print-table td { border: 1px solid #d7dde8; padding: 5px 8px; text-align: left; }
    .print-table th { background: #f2f5fa; }
    .print-table td.num, .print-table th.num { text-align: right; }
    .print-h2 { font-size: 1rem; margin: 18px 0 6px; text-transform: uppercase; letter-spacing: .5px; color: #34405a; }
    .print-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 18px; }
    .print-kpi { border: 1px solid #d7dde8; border-radius: 8px; padding: 8px 10px; }
    .print-kpi .l { font-size: .72rem; text-transform: uppercase; color: #5b6880; letter-spacing: .4px; }
    .print-kpi .v { font-size: 1.15rem; font-weight: 700; }
    .print-actions { margin-bottom: 18px; }
    .sign { margin-top: 30px; display: flex; justify-content: space-between; gap: 20px; font-size: .85rem; color: #5b6880; }
    .print-table-wrap { margin-bottom: 18px; }
    @media screen { .print-table-wrap { overflow-x: auto; } }
    @media (max-width: 640px) {
        .print-page { padding: .7rem .55rem; }
        .print-grid { grid-template-columns: 1fr 1fr; }
        .print-actions .btn { flex: 1 1 calc(50% - .4rem); text-align: center; }
        .print-actions { display: flex; gap: .4rem; flex-wrap: wrap; }
        .print-head { flex-direction: column; align-items: flex-start; }
        .print-head .meta { text-align: left; }
        .sign { flex-direction: column; gap: .4rem; }
    }
    @media print { .print-actions { display: none; } }
</style>
<div class="print-page">
    <div class="print-actions">
        <a class="btn btn-ghost" href="<?= e(url('admin/reports/daily.php?date=' . $date)) ?>">&larr; Back to report</a>
        <button class="btn" type="button" onclick="window.print()">Print / Save as PDF</button>
    </div>

    <div class="print-head">
        <div>
            <h1><?= e(APP_NAME) ?> &mdash; Daily Report</h1>
            <div class="subtle"><?= e(APP_TAGLINE) ?></div>
        </div>
        <div class="meta">
            <strong>Date: <?= e(format_date($date)) ?></strong><br>
            Generated on <?= e(format_datetime(now())) ?><br>
            By: <?= e($user['name']) ?> (<?= e(role_label((string) $user['role'])) ?>)
        </div>
    </div>

    <div class="print-grid">
        <div class="print-kpi"><div class="l">Orders booked</div><div class="v"><?= e((string) $data['orders_created']) ?></div></div>
        <div class="print-kpi"><div class="l">Order value</div><div class="v"><?= e(money((float) $data['order_value'])) ?></div></div>
        <div class="print-kpi"><div class="l">Pickups completed</div><div class="v"><?= e((string) $data['pickups_completed']) ?></div></div>
        <div class="print-kpi"><div class="l">Deliveries completed</div><div class="v"><?= e((string) $data['deliveries_completed']) ?></div></div>
        <div class="print-kpi"><div class="l">Collected</div><div class="v"><?= e(money((float) $data['collected'])) ?></div></div>
        <div class="print-kpi"><div class="l">New customers</div><div class="v"><?= e((string) $data['new_customers']) ?></div></div>
        <div class="print-kpi"><div class="l">Pending deliveries</div><div class="v"><?= e((string) $data['pending_delivery_count']) ?></div></div>
        <div class="print-kpi"><div class="l">Pending payments</div><div class="v"><?= e((string) $data['pending_payment_count']) ?></div></div>
        <div class="print-kpi"><div class="l">Outstanding amount</div><div class="v"><?= e(money((float) $data['outstanding'])) ?></div></div>
    </div>

    <h2 class="print-h2">Status breakdown</h2>
    <div class="print-table-wrap"><table class="print-table">
        <thead><tr><th>Status</th><th class="num">Orders</th><th class="num">Value</th></tr></thead>
        <tbody>
        <?php foreach ($data['by_status'] as $row): ?>
            <tr>
                <td><?= e($row['label']) ?></td>
                <td class="num"><?= e((string) $row['count']) ?></td>
                <td class="num"><?= e(money((float) $row['value'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>

    <h2 class="print-h2">Collections by method</h2>
    <div class="print-table-wrap"><table class="print-table">
        <thead><tr><th>Method</th><th class="num">Transactions</th><th class="num">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($data['by_method'] as $row): ?>
            <tr>
                <td><?= e(payment_methods()[(string) $row['method']] ?? $row['method']) ?></td>
                <td class="num"><?= e((string) $row['total']) ?></td>
                <td class="num"><?= e(money((float) $row['value'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td><strong>Total</strong></td>
            <td class="num"><strong><?= e((string) $data['collection_count']) ?></strong></td>
            <td class="num"><strong><?= e(money((float) $data['collected'])) ?></strong></td>
        </tr>
        </tbody>
    </table></div>

    <h2 class="print-h2">Pending delivery list (<?= e((string) $data['pending_delivery_count']) ?>)</h2>
    <div class="print-table-wrap"><table class="print-table">
        <thead><tr><th>Order No</th><th>Customer</th><th>Phone</th><th>Status</th><th class="num">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($data['pending_delivery'] as $row): ?>
            <tr>
                <td><?= e($row['order_number']) ?></td>
                <td><?= e($row['contact_name']) ?></td>
                <td><?= e($row['contact_phone']) ?></td>
                <td><?= e(order_status_label((string) $row['status'])) ?></td>
                <td class="num"><?= e(money((float) $row['total_amount'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($data['pending_delivery'] === []): ?>
            <tr><td colspan="5">No pending deliveries.</td></tr>
        <?php endif; ?>
        </tbody>
    </table></div>

    <h2 class="print-h2">Pending payment list (<?= e((string) $data['pending_payment_count']) ?>) &mdash; outstanding <?= e(money((float) $data['outstanding'])) ?></h2>
    <div class="print-table-wrap"><table class="print-table">
        <thead><tr><th>Order No</th><th>Customer</th><th>Phone</th><th>Status</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Due</th></tr></thead>
        <tbody>
        <?php foreach ($data['pending_payment'] as $row): ?>
            <?php $due = (float) $row['total_amount'] - (float) $row['paid_amount']; ?>
            <tr>
                <td><?= e($row['order_number']) ?></td>
                <td><?= e($row['contact_name']) ?></td>
                <td><?= e($row['contact_phone']) ?></td>
                <td><?= e(payment_status_label((string) $row['payment_status'])) ?></td>
                <td class="num"><?= e(money((float) $row['total_amount'])) ?></td>
                <td class="num"><?= e(money((float) $row['paid_amount'])) ?></td>
                <td class="num"><?= e(money($due)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($data['pending_payment'] === []): ?>
            <tr><td colspan="7">All payments are settled.</td></tr>
        <?php endif; ?>
        </tbody>
    </table></div>

    <h2 class="print-h2">Staff performance</h2>
    <div class="print-table-wrap"><table class="print-table">
        <thead><tr><th>Name</th><th>Role</th><th class="num">Pickups</th><th class="num">Deliveries</th><th class="num">Collected</th></tr></thead>
        <tbody>
        <?php foreach ($data['staff_performance'] as $row): ?>
            <tr>
                <td><?= e($row['name']) ?></td>
                <td><?= e(role_label((string) $row['role'])) ?></td>
                <td class="num"><?= e((string) $row['pickups']) ?></td>
                <td class="num"><?= e((string) $row['deliveries']) ?></td>
                <td class="num"><?= e(money((float) $row['collected'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>

    <div class="sign">
        <span>Prepared by: <?= e($user['name']) ?></span>
        <span>Approved by: ______________________</span>
    </div>
</div>
<?php layout_end(); ?>
