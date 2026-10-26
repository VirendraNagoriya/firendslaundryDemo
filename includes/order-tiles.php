<?php
declare(strict_types=1);

/**
 * Short one line summary of the garments on an order, e.g. "4 pcs".
 */
function order_items_summary(int $orderId): string
{
    $row = db_row(
        'SELECT COUNT(*) AS line_count, COALESCE(SUM(qty), 0) AS pcs, COALESCE(SUM(weight_kg), 0) AS kg
         FROM order_items WHERE order_id = ?',
        [$orderId]
    );
    if ($row === null || (int) $row['line_count'] === 0) {
        return 'No items yet';
    }
    $pcs = (int) $row['pcs'];
    $kg = (float) $row['kg'];
    $parts = [];
    if ($pcs > 0) {
        $parts[] = $pcs . ' pcs';
    }
    if ($kg > 0) {
        $parts[] = number_format($kg, 2) . ' kg';
    }
    return $parts === [] ? (string) $row['line_count'] . ' item(s)' : implode(' / ', $parts);
}

/**
 * Compact order tile: the summary line shows only the essentials, everything else is
 * hidden behind a tap (native <details>), so the list stays short on a phone.
 *
 * Options:
 *  - note      : short extra line under the customer name
 *  - old_dues  : float, shown as a second money chip when the customer owes more
 *  - details   : extra ['Label' => 'value'] rows merged into the body
 *  - actions   : HTML rendered at the bottom of the expanded body
 *  - open      : expand the tile on render
 */
function order_tile(array $order, array $options = []): void
{
    $orderId = (int) $order['id'];
    $total = (float) ($order['total_amount'] ?? 0);
    $paid = (float) ($order['paid_amount'] ?? 0);
    $due = max(0.0, $total - $paid);
    $paidUp = (string) ($order['payment_status'] ?? '') === 'paid';
    $closed = order_is_closed($order);

    $status = (string) ($order['status'] ?? '');
    $address = trim((string) ($order['delivery_address'] ?? ''));

    $pickupStaff = null;
    if (!empty($order['pickup_staff_id'])) {
        $name = db_value('SELECT name FROM users WHERE id = ?', [(int) $order['pickup_staff_id']], '');
        $pickupStaff = $name !== '' ? (string) $name : 'Assigned';
    }
    $deliveryStaff = null;
    if (!empty($order['delivery_staff_id'])) {
        $name = db_value('SELECT name FROM users WHERE id = ?', [(int) $order['delivery_staff_id']], '');
        $deliveryStaff = $name !== '' ? (string) $name : 'Assigned';
    }

    $details = [
        'Address' => $address !== '' ? $address : '—',
        'Order date' => format_date((string) ($order['order_date'] ?? '')),
        'Pickup date' => format_date((string) ($order['scheduled_pickup_date'] ?? '')),
        'Delivery date' => format_date((string) ($order['scheduled_delivery_date'] ?? '')),
        'Pickup staff' => $pickupStaff ?? 'Not assigned',
        'Delivery staff' => $deliveryStaff ?? 'Not assigned',
        'Items' => order_items_summary($orderId),
        'Total' => money($total),
        'Paid' => money($paid),
    ];
    if (!empty($options['details']) && is_array($options['details'])) {
        $details = array_merge($details, $options['details']);
    }

    $note = (string) ($options['note'] ?? '');
    $oldDues = $options['old_dues'] ?? null;
    ?>
    <article class="tile<?= $oldDues !== null && (float) $oldDues > 0 ? ' tile-has-dues' : '' ?>">
        <details class="tile-fold"<?= !empty($options['open']) ? ' open' : '' ?>>
            <summary class="tile-head">
                <div class="tile-main">
                    <div class="tile-line">
                        <strong class="tile-id"><?= e((string) ($order['order_number'] ?? ('#' . $orderId))) ?></strong>
                        <span class="badge <?= e(status_badge($status)) ?>"><?= e(order_status_label($status)) ?></span>
                        <?php if (!$paidUp): ?>
                            <span class="badge <?= e(status_badge((string) ($order['payment_status'] ?? 'pending'))) ?>"><?= e(payment_status_label((string) ($order['payment_status'] ?? 'pending'))) ?></span>
                        <?php endif; ?>
                        <?php if ($closed): ?>
                            <span class="badge badge-success" title="Delivered and fully paid - no more items can be added">Closed</span>
                        <?php endif; ?>
                    </div>
                    <div class="tile-cust">
                        <?= e((string) ($order['contact_name'] ?? '')) ?>
                        <?php if (!empty($order['contact_phone'])): ?>
                            <span class="subtle"> &middot; <?= e((string) $order['contact_phone']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($note !== ''): ?>
                        <div class="tile-note"><?= e($note) ?></div>
                    <?php endif; ?>
                </div>
                <div class="tile-side">
                    <?php if ($oldDues !== null && (float) $oldDues > 0): ?>
                        <span class="tile-old-dues"><?= e(money((float) $oldDues)) ?> old</span>
                    <?php endif; ?>
                    <strong class="tile-money<?= $due > 0 ? ' is-due' : '' ?>"><?= e(money($due)) ?></strong>
                    <span class="tile-money-label"><?= $due > 0 ? 'due' : ($paidUp ? 'paid' : 'balance') ?></span>
                    <span class="tile-caret" aria-hidden="true"></span>
                </div>
            </summary>
            <div class="tile-body">
                <dl class="kv kv-compact">
                    <?php foreach ($details as $label => $value): ?>
                        <dt><?= e((string) $label) ?></dt>
                        <dd><?= e((string) $value) ?></dd>
                    <?php endforeach; ?>
                </dl>
                <?php if (!empty($options['actions'])): ?>
                    <div class="tile-actions"><?= $options['actions'] ?></div>
                <?php endif; ?>
            </div>
        </details>
    </article>
    <?php
}

/**
 * Very short "already done" row used for the history panels at the bottom of staff pages.
 */
function order_history_row(array $order, string $right = '', string $href = ''): void
{
    ?>
    <div class="mini-row">
        <div class="mini-row-main">
            <strong><?= e((string) ($order['order_number'] ?? '')) ?></strong>
            <span class="subtle"><?= e((string) ($order['contact_name'] ?? '')) ?></span>
        </div>
        <div class="mini-row-side">
            <?php if ($right !== ''): ?>
                <span class="subtle"><?= e($right) ?></span>
            <?php endif; ?>
            <?php if ($href !== ''): ?>
                <a class="btn btn-sm btn-ghost" href="<?= e($href) ?>">Open</a>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
