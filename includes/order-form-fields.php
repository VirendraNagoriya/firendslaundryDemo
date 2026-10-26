<?php
declare(strict_types=1);

require_once __DIR__ . '/location-picker.php';

function render_order_fields(array $order, array $items, array $catalog, array $pickupStaff, array $deliveryStaff, bool $withPricing = true, bool $itemsLocked = false): void
{
    ?>
    <div class="form-grid">
        <div class="form-group">
            <label for="contact_name" class="required">Customer name</label>
            <input type="text" id="contact_name" name="contact_name" value="<?= e((string) ($order['contact_name'] ?? '')) ?>" required>
        </div>
        <div class="form-group">
            <label for="contact_phone" class="required">Mobile number</label>
            <input type="tel" inputmode="tel" maxlength="16" id="contact_phone" name="contact_phone" value="<?= e((string) ($order['contact_phone'] ?? '')) ?>" required title="10-digit mobile number">
        </div>
        <div class="form-group">
            <label for="order_date">Order date</label>
            <input type="date" id="order_date" name="order_date" value="<?= e((string) ($order['order_date'] ?? today())) ?>">
        </div>
        <div class="form-group">
            <label for="scheduled_pickup_date">Scheduled pickup date</label>
            <input type="date" id="scheduled_pickup_date" name="scheduled_pickup_date" value="<?= e((string) ($order['scheduled_pickup_date'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label for="scheduled_delivery_date">Scheduled delivery date</label>
            <input type="date" id="scheduled_delivery_date" name="scheduled_delivery_date" value="<?= e((string) ($order['scheduled_delivery_date'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label for="pickup_staff_id">Pickup staff</label>
            <select name="pickup_staff_id" id="pickup_staff_id">
                <option value="">Not assigned</option>
                <?php foreach ($pickupStaff as $staff): ?>
                    <option value="<?= e((string) $staff['id']) ?>"<?= (int) ($order['pickup_staff_id'] ?? 0) === (int) $staff['id'] ? ' selected' : '' ?>>
                        <?= e($staff['name']) ?><?= $staff['is_active'] ? '' : ' (disabled)' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="delivery_staff_id">Delivery staff</label>
            <select name="delivery_staff_id" id="delivery_staff_id">
                <option value="">Not assigned</option>
                <?php foreach ($deliveryStaff as $staff): ?>
                    <option value="<?= e((string) $staff['id']) ?>"<?= (int) ($order['delivery_staff_id'] ?? 0) === (int) $staff['id'] ? ' selected' : '' ?>>
                        <?= e($staff['name']) ?><?= $staff['is_active'] ? '' : ' (disabled)' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="form-group">
        <label for="delivery_address" class="required">Delivery address</label>
        <textarea id="delivery_address" name="delivery_address" required><?= e((string) ($order['delivery_address'] ?? '')) ?></textarea>
    </div>
    <?php render_location_picker([
        'id' => 'order-edit-location',
        'address_id' => 'delivery_address',
        'lat_name' => 'pickup_latitude',
        'lng_name' => 'pickup_longitude',
        'place_name' => 'pickup_place_id',
        'lat' => (string) ($order['pickup_latitude'] ?? ''),
        'lng' => (string) ($order['pickup_longitude'] ?? ''),
        'place' => (string) ($order['pickup_place_id'] ?? ''),
        'label' => 'Customer location',
        'height' => 240,
    ]); ?>

    <h3>Items</h3>
    <?php if ($itemsLocked): ?>
        <div class="alert alert-warning">This order is closed because it is fully paid. Items are locked; mark the order unpaid to add or change items.</div>
        <?php if ($items === []): ?>
            <div class="empty-state">No items on this order.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr><th>Item</th><th>Service</th><th>Billing</th><th class="num">Qty</th><th class="num">Weight</th><th class="num">Rate</th><th class="num">Amount</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= e((string) $item['item_name']) ?></td>
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
        <?php endif; ?>
    <?php else: ?>
        <?php render_item_picker($items, $catalog, ['intro' => 'Tap a service to add it, then set the quantity or weight.']); ?>
    <?php endif; ?>

    <?php if ($withPricing): ?>
        <div class="form-grid mt-1">
            <div class="form-group">
                <label for="discount_amount">Discount (<?= e(APP_CURRENCY) ?>)</label>
                <input type="number" id="discount_amount" name="discount_amount" min="0" step="0.01" value="<?= e((string) ($order['discount_amount'] ?? 0)) ?>">
            </div>
            <div class="form-group">
                <label for="delivery_charge">Delivery charge (<?= e(APP_CURRENCY) ?>)</label>
                <input type="number" id="delivery_charge" name="delivery_charge" min="0" step="0.01" value="<?= e((string) ($order['delivery_charge'] ?? setting('default_delivery_charge', '40'))) ?>">
            </div>
            <div class="form-group">
                <label for="tax_amount">Tax (<?= e(APP_CURRENCY) ?>)</label>
                <input type="number" id="tax_amount" name="tax_amount" min="0" step="0.01" value="<?= e((string) ($order['tax_amount'] ?? 0)) ?>">
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$itemsLocked): ?>
        <div class="total-box">
            <div class="total-row grand">
                <span>Order total</span>
                <strong><?= e(APP_CURRENCY) ?><span data-total>0.00</span></strong>
            </div>
        </div>
    <?php else: ?>
        <div class="total-box">
            <div class="total-row"><span>Items subtotal</span><span><?= e(money((float) ($order['subtotal'] ?? 0))) ?></span></div>
            <div class="total-row grand"><span>Order total</span><strong><?= e(money((float) ($order['total_amount'] ?? 0))) ?></strong></div>
        </div>
    <?php endif; ?>

    <div class="form-grid">
        <div class="form-group">
            <label for="notes">Order notes</label>
            <input type="text" id="notes" name="notes" value="<?= e((string) ($order['notes'] ?? '')) ?>">
        </div>
        <div class="form-group">
            <label for="staff_notes">Internal notes</label>
            <input type="text" id="staff_notes" name="staff_notes" value="<?= e((string) ($order['staff_notes'] ?? '')) ?>">
        </div>
    </div>
    <?php
}
