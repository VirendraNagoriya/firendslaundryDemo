<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/../bootstrap.php';
require_once dirname(__DIR__) . '/../includes/layout.php';
require_once dirname(__DIR__) . '/../includes/orders.php';
require_once dirname(__DIR__) . '/../includes/reports.php';
require_once dirname(__DIR__) . '/../includes/order-tiles.php';

$user = require_admin();

$date = query('date', today());
if (strtotime($date) === false) {
    $date = today();
}
$date = date('Y-m-d', strtotime($date));

if (query('export') === 'csv') {
    $filename = 'daily_report_' . $date . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF" . daily_report_csv($date);
    exit;
}

$data = daily_report_data($date);

layout_start('Daily Report', 'app');
?>
<div class="toolbar">
    <form method="get" class="filter-form">
        <div class="form-group">
            <label for="date">Report date</label>
            <input type="date" id="date" name="date" value="<?= e($date) ?>" data-auto-submit>
        </div>
        <div class="form-group">
            <button class="btn" type="submit">Show</button>
        </div>
    </form>
    <div class="row-between">
        <a class="btn btn-ghost" href="<?= e(url('admin/reports/daily.php?date=' . date('Y-m-d', strtotime($date . ' -1 day')))) ?>">&larr; Previous</a>
        <a class="btn btn-ghost" href="<?= e(url('admin/reports/daily.php?date=' . date('Y-m-d', strtotime($date . ' +1 day')))) ?>">Next &rarr;</a>
        <a class="btn btn-soft" href="<?= e(url('admin/reports/daily.php?date=' . $date . '&export=csv')) ?>">Download CSV</a>
        <a class="btn" href="<?= e(url('admin/reports/daily-print.php?date=' . $date)) ?>" target="_blank">Print / Save as PDF</a>
    </div>
</div>

<div class="card-grid mb-1">
    <div class="stat-card accent">
        <div class="label">Orders Booked</div>
        <div class="value"><?= e((string) $data['orders_created']) ?></div>
        <div class="hint">Value <?= e(money((float) $data['order_value'])) ?></div>
    </div>
    <div class="stat-card purple">
        <div class="label">Pickups Completed</div>
        <div class="value"><?= e((string) $data['pickups_completed']) ?></div>
        <div class="hint"><?= e((string) $data['deliveries_completed']) ?> deliveries completed</div>
    </div>
    <div class="stat-card green">
        <div class="label">Collected</div>
        <div class="value"><?= e(money((float) $data['collected'])) ?></div>
        <div class="hint"><?= e((string) $data['collection_count']) ?> transactions</div>
    </div>
    <div class="stat-card amber">
        <div class="label">Pending Deliveries</div>
        <div class="value"><?= e((string) $data['pending_delivery_count']) ?></div>
        <div class="hint">Ready or out for delivery</div>
    </div>
    <div class="stat-card red">
        <div class="label">Pending Payments</div>
        <div class="value"><?= e((string) $data['pending_payment_count']) ?></div>
        <div class="hint">Outstanding <?= e(money((float) $data['outstanding'])) ?></div>
    </div>
    <div class="stat-card">
        <div class="label">New Customers</div>
        <div class="value"><?= e((string) $data['new_customers']) ?></div>
        <div class="hint"><?= e((string) $data['cancelled']) ?> orders cancelled</div>
    </div>
</div>

<div class="detail-grid">
    <div>
        <div class="panel">
            <div class="panel-head"><h2>Status Breakdown</h2><span class="subtle">Orders booked on <?= e(format_date($date)) ?></span></div>
            <div class="panel-body tight">
                <?php $shownStatuses = 0; ?>
                <?php foreach ($data['by_status'] as $key => $row): ?>
                    <?php if ((int) $row['count'] === 0) { continue; } $shownStatuses++; ?>
                    <div class="total-row">
                        <span><span class="badge <?= e(status_badge($key)) ?>"><?= e($row['label']) ?></span></span>
                        <span><?= e((string) $row['count']) ?> orders · <?= e(money((float) $row['value'])) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if ($shownStatuses === 0): ?>
                    <p class="subtle">No orders were booked on this date.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><h2>Pending Delivery List</h2><span class="subtle"><?= e((string) $data['pending_delivery_count']) ?> orders</span></div>
            <div class="panel-body tight">
                <?php if ($data['pending_delivery'] === []): ?>
                    <div class="empty-state">No pending deliveries.</div>
                <?php else: ?>
                    <?php foreach ($data['pending_delivery'] as $row): ?>
                        <?php
                        order_tile($row, [
                            'note' => 'Delivery date ' . format_date((string) $row['scheduled_delivery_date']) . ' · ' . order_items_summary((int) $row['id']),
                            'actions' => '<a class="btn btn-sm btn-ghost" href="' . e(url('admin/order-view.php?id=' . (int) $row['id'])) . '">Open</a>',
                        ]);
                        ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><h2>Pending Payment List</h2><span class="subtle">Outstanding <?= e(money((float) $data['outstanding'])) ?></span></div>
            <div class="panel-body tight">
                <?php if ($data['pending_payment'] === []): ?>
                    <div class="empty-state">All payments are settled.</div>
                <?php else: ?>
                    <?php foreach ($data['pending_payment'] as $row): ?>
                        <?php
                        order_tile($row, [
                            'note' => 'Ordered ' . format_date((string) $row['order_date']) . ' · ' . order_items_summary((int) $row['id']),
                            'actions' => '<a class="btn btn-sm btn-ghost" href="' . e(url('admin/order-view.php?id=' . (int) $row['id'])) . '">Open</a>'
                                . '<a class="btn btn-sm" href="' . e(url('admin/payments.php?order=' . (int) $row['id'])) . '">Collect</a>',
                        ]);
                        ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div>
        <div class="panel">
            <div class="panel-head"><h3>Collections by Method</h3></div>
            <div class="panel-body">
                <?php if ($data['by_method'] === []): ?>
                    <p class="subtle">No collections recorded on this date.</p>
                <?php else: ?>
                    <div class="total-row"><span>Cash</span><span><?= e(money((float) (db_value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(collected_at) = ? AND method = 'cash'", [$date], 0)))) ?></span></div>
                    <div class="total-row"><span>UPI</span><span><?= e(money((float) (db_value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(collected_at) = ? AND method = 'upi'", [$date], 0)))) ?></span></div>
                    <div class="total-row"><span>Card</span><span><?= e(money((float) (db_value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(collected_at) = ? AND method = 'card'", [$date], 0)))) ?></span></div>
                    <div class="total-row grand"><span>Total collected</span><span><?= e(money((float) $data['collected'])) ?></span></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><h3>Staff Performance</h3></div>
            <div class="panel-body tight">
                <?php $shownStaff = 0; ?>
                <?php foreach ($data['staff_performance'] as $row): ?>
                    <?php if ((int) $row['pickups'] === 0 && (int) $row['deliveries'] === 0 && (float) $row['collected'] <= 0) { continue; } $shownStaff++; ?>
                    <div class="total-row">
                        <span><strong><?= e($row['name']) ?></strong> <span class="subtle"><?= e(role_label((string) $row['role'])) ?></span></span>
                        <span class="subtle"><?= e((string) $row['pickups']) ?> pickups · <?= e((string) $row['deliveries']) ?> deliveries · <?= e(money((float) $row['collected'])) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if ($shownStaff === 0): ?>
                    <p class="subtle">No staff activity recorded on this date.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php layout_end(); ?>
