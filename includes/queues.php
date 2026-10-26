<?php
declare(strict_types=1);

/**
 * Owner's quick access queues (admin/pickup-queue.php and admin/delivery-queue.php):
 * compact tiles for the orders that are waiting right now, plus a small status summary.
 */
function render_admin_queue(string $kind): void
{
    $pickup = $kind === 'pickup';

    if ($pickup) {
        $rows = db_all(
            "SELECT o.* FROM orders o
             WHERE o.status IN ('new','pickup_assigned')
             ORDER BY o.scheduled_pickup_date ASC, o.id ASC LIMIT 100"
        );
        $unassigned = (int) db_value(
            "SELECT COUNT(*) FROM orders WHERE status IN ('new','pickup_assigned') AND pickup_staff_id IS NULL",
            [],
            0
        );
        $scheduledToday = (int) db_value(
            "SELECT COUNT(*) FROM orders WHERE status IN ('new','pickup_assigned') AND scheduled_pickup_date = ?",
            [today()],
            0
        );
        layout_start('Pickup Queue', 'app');
        ?>
        <div class="card-grid mb-1">
            <div class="stat-card accent">
                <div class="label">Waiting for Pickup</div>
                <div class="value"><?= e((string) count($rows)) ?></div>
                <div class="hint">New and assigned, not picked up yet</div>
            </div>
            <div class="stat-card amber">
                <div class="label">Unassigned</div>
                <div class="value"><?= e((string) $unassigned) ?></div>
                <div class="hint">No pickup staff member chosen yet</div>
            </div>
            <div class="stat-card">
                <div class="label">Scheduled Today</div>
                <div class="value"><?= e((string) $scheduledToday) ?></div>
                <div class="hint">Pickup date is today</div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <h2>Pickup Queue</h2>
                <span class="subtle">Tap an order to see the details</span>
            </div>
            <div class="panel-body tight">
                <?php if ($rows === []): ?>
                    <div class="empty-state"><strong>Queue is empty</strong>No orders are waiting for pickup.</div>
                <?php else: ?>
                    <?php foreach ($rows as $order): ?>
                        <?php
                        $id = (int) $order['id'];
                        $phone = (string) ($order['contact_phone'] ?? '');
                        $note = 'Scheduled pickup ' . format_date((string) $order['scheduled_pickup_date'])
                            . ' · ' . order_items_summary($id);
                        if (empty($order['pickup_staff_id'])) {
                            $note .= ' · No pickup staff yet';
                        }
                        $actions = '<a class="btn btn-sm" href="' . e(url('admin/order-view.php?id=' . $id)) . '">Assign / Open</a>';
                        if ($phone !== '') {
                            $actions .= '<a class="btn btn-sm btn-ghost" href="tel:' . e($phone) . '">Call</a>';
                        }
                        order_tile($order, ['note' => $note, 'actions' => $actions]);
                        ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    } else {
        $rows = db_all(
            "SELECT o.* FROM orders o
             WHERE o.status IN ('ready_for_delivery','out_for_delivery')
             ORDER BY o.scheduled_delivery_date ASC, o.id ASC LIMIT 100"
        );
        $outNow = (int) db_value(
            "SELECT COUNT(*) FROM orders WHERE status = 'out_for_delivery'",
            [],
            0
        );
        $inLaundry = (int) db_value(
            "SELECT COUNT(*) FROM orders WHERE status IN ('picked_up','processing')",
            [],
            0
        );
        $laundryRows = db_all(
            "SELECT o.* FROM orders o
             WHERE o.status IN ('picked_up','processing')
             ORDER BY o.scheduled_delivery_date ASC, o.id ASC LIMIT 50"
        );
        $deliveryDueToday = (int) db_value(
            "SELECT COUNT(*) FROM orders WHERE status IN ('ready_for_delivery','out_for_delivery') AND scheduled_delivery_date = ?",
            [today()],
            0
        );
        layout_start('Delivery Queue', 'app');
        ?>
        <div class="card-grid mb-1">
            <div class="stat-card accent">
                <div class="label">To Deliver</div>
                <div class="value"><?= e((string) count($rows)) ?></div>
                <div class="hint">Ready or already out for delivery</div>
            </div>
            <div class="stat-card green">
                <div class="label">Out Now</div>
                <div class="value"><?= e((string) $outNow) ?></div>
                <div class="hint">With a delivery boy right now</div>
            </div>
            <div class="stat-card">
                <div class="label">Due Today</div>
                <div class="value"><?= e((string) $deliveryDueToday) ?></div>
                <div class="hint">Delivery date is today</div>
            </div>
            <div class="stat-card amber">
                <div class="label">In Laundry</div>
                <div class="value"><?= e((string) $inLaundry) ?></div>
                <div class="hint">Picked up, not ready for delivery</div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <h2>Delivery Queue</h2>
                <span class="subtle">Tap an order to see the details</span>
            </div>
            <div class="panel-body tight">
                <?php if ($rows === []): ?>
                    <div class="empty-state"><strong>Queue is empty</strong>No orders are waiting for delivery.</div>
                <?php else: ?>
                    <?php foreach ($rows as $order): ?>
                        <?php
                        $id = (int) $order['id'];
                        $phone = (string) ($order['contact_phone'] ?? '');
                        $note = 'Scheduled delivery ' . format_date((string) $order['scheduled_delivery_date'])
                            . ' · ' . order_items_summary($id);
                        $actions = '<a class="btn btn-sm" href="' . e(url('admin/order-view.php?id=' . $id)) . '">Open</a>';
                        if ($phone !== '') {
                            $actions .= '<a class="btn btn-sm btn-ghost" href="tel:' . e($phone) . '">Call</a>';
                        }
                        order_tile($order, ['note' => $note, 'actions' => $actions]);
                        ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <h3>In Laundry (not ready yet)</h3>
                <span class="subtle"><?= e((string) count($laundryRows)) ?> shown</span>
            </div>
            <div class="panel-body tight">
                <?php if ($laundryRows === []): ?>
                    <div class="empty-state">Nothing is waiting in processing.</div>
                <?php else: ?>
                    <?php foreach ($laundryRows as $order): ?>
                        <?php
                        $id = (int) $order['id'];
                        $note = 'Delivery date ' . format_date((string) $order['scheduled_delivery_date'])
                            . ' · ' . order_items_summary($id);
                        order_tile($order, [
                            'note' => $note,
                            'actions' => '<a class="btn btn-sm btn-ghost" href="' . e(url('admin/order-view.php?id=' . $id)) . '">Open</a>',
                        ]);
                        ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    layout_end();
}
