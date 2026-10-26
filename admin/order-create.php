<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/orders.php';
require_once dirname(__DIR__) . '/includes/order-items.php';
require_once dirname(__DIR__) . '/includes/location-picker.php';

$user = require_admin();

$catalog = active_catalog();
$pickupStaff = staff_list('pickup');
$deliveryStaff = staff_list('delivery');

$errors = [];
$order = [
    'contact_name' => '',
    'contact_phone' => '',
    'delivery_address' => '',
    'order_date' => today(),
    'scheduled_pickup_date' => today(),
    'scheduled_delivery_date' => '',
    'pickup_staff_id' => '',
    'delivery_staff_id' => '',
    'discount_amount' => 0,
    'delivery_charge' => setting('default_delivery_charge', '40'),
    'tax_amount' => 0,
    'notes' => '',
    'staff_notes' => '',
    'pickup_latitude' => '',
    'pickup_longitude' => '',
    'pickup_place_id' => '',
];
$items = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    foreach (array_keys($order) as $key) {
        $order[$key] = post($key);
    }

    if ($order['contact_name'] === '') {
        $errors[] = 'Please enter the customer name.';
    }
    if (!valid_phone($order['contact_phone'])) {
        $errors[] = 'Please enter a valid 10-digit mobile number.';
    }
    if (trim($order['delivery_address']) === '') {
        $errors[] = 'Please enter the delivery address.';
    }

    $items = collect_items_from_post();
    $location = location_from_post('pickup_');

    if ($errors === []) {
        try {
            $orderId = db_transaction(static function () use ($order, $items, $location, $user): int {
                $customerId = find_or_create_customer([
                    'name' => $order['contact_name'],
                    'phone' => $order['contact_phone'],
                    'email' => null,
                    'address' => $order['delivery_address'],
                    'latitude' => $location['latitude'],
                    'longitude' => $location['longitude'],
                    'place_id' => $location['place_id'],
                ], (int) $user['id']);

                $tax = (float) $order['tax_amount'];
                if ($tax === 0.0) {
                    $subtotal = 0.0;
                    foreach ($items as $item) {
                        $subtotal += order_item_amount($item);
                    }
                    $tax = round($subtotal * ((float) setting('tax_percent', '0') / 100), 2);
                }

                return create_order([
                    'customer_id' => $customerId,
                    'source' => 'admin',
                    'pricing_mode' => 'per_item',
                    'status' => !empty($order['pickup_staff_id']) ? 'pickup_assigned' : 'new',
                    'order_date' => $order['order_date'] !== '' ? $order['order_date'] : today(),
                    'scheduled_pickup_date' => $order['scheduled_pickup_date'] !== '' ? $order['scheduled_pickup_date'] : null,
                    'scheduled_delivery_date' => $order['scheduled_delivery_date'] !== '' ? $order['scheduled_delivery_date'] : null,
                    'contact_name' => $order['contact_name'],
                    'contact_phone' => $order['contact_phone'],
                    'delivery_address' => $order['delivery_address'],
                    'pickup_latitude' => $location['latitude'],
                    'pickup_longitude' => $location['longitude'],
                    'pickup_place_id' => $location['place_id'],
                    'pickup_staff_id' => $order['pickup_staff_id'] !== '' ? (int) $order['pickup_staff_id'] : null,
                    'delivery_staff_id' => $order['delivery_staff_id'] !== '' ? (int) $order['delivery_staff_id'] : null,
                    'discount_amount' => (float) $order['discount_amount'],
                    'delivery_charge' => (float) $order['delivery_charge'],
                    'tax_amount' => $tax,
                    'notes' => $order['notes'],
                    'staff_notes' => $order['staff_notes'],
                ], $items, (int) $user['id']);
            });
        } catch (Throwable $e) {
            $errors[] = app_form_message($e);
        }
    }

    if ($errors === []) {
        flash('success', 'Order ' . order_number_for($orderId) . ' created. The staff can add the items when they reach the customer.');
        redirect('admin/order-view.php?id=' . $orderId);
    }
}

layout_start('New Order', 'app');
?>
<div class="panel">
    <div class="panel-head">
        <h2>Create Order</h2>
        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/orders.php')) ?>">Back to orders</a>
    </div>
    <div class="panel-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="post" action="<?= e(url('admin/order-create.php')) ?>" data-calc-items data-customer-lookup data-lookup-url="<?= e(url('admin/customer-lookup.php')) ?>">

            <?= csrf_field() ?>

            <div class="form-grid">
                <div class="form-group">
                    <label for="contact_name" class="required">Customer name</label>
                    <input type="text" id="contact_name" name="contact_name" value="<?= e((string) $order['contact_name']) ?>" data-lookup-field="name" required>
                </div>
                <div class="form-group">
                    <label for="contact_phone" class="required">Mobile number</label>
                    <input type="tel" inputmode="tel" maxlength="16" id="contact_phone" name="contact_phone" value="<?= e((string) $order['contact_phone']) ?>" data-lookup-field="phone" required title="10-digit mobile number">
                </div>
            </div>

            <div class="form-group">
                <label for="delivery_address" class="required">Delivery address</label>
                <textarea id="delivery_address" name="delivery_address" data-lookup-field="address" required><?= e((string) $order['delivery_address']) ?></textarea>
            </div>
            <?php render_location_picker([
                'id' => 'order-create-location',
                'address_id' => 'delivery_address',
                'lat_name' => 'pickup_latitude',
                'lng_name' => 'pickup_longitude',
                'place_name' => 'pickup_place_id',
                'lat' => (string) $order['pickup_latitude'],
                'lng' => (string) $order['pickup_longitude'],
                'place' => (string) $order['pickup_place_id'],
                'label' => 'Customer location',
                'height' => 240,
            ]); ?>

            <div class="lookup-box" data-lookup-results hidden></div>
            <div id="customer-dues-panel" class="alert alert-warning" data-customer-dues hidden style="margin-top:10px;">
                <strong>Pending dues from old invoices:</strong> <span data-customer-dues-total>0.00</span> across <span data-customer-dues-count>0</span> invoice(s).
                <a class="btn btn-sm btn-ghost" href="#" target="_blank" data-customer-dues-link>View old invoices</a>
                <div class="subtle">Details auto-filled from existing customer. You can still edit name/phone/address before creating this order.</div>
            </div>

            <p class="subtle">Search by name or mobile to auto-fill existing customer details (editable). Items can be added now or by staff at pickup.</p>

            <details class="fold">
                <summary>Add items now (optional)</summary>
                <div class="fold-body">
                    <?php render_item_picker($items, $catalog, ['intro' => 'Tap a service to add it, then set the quantity or weight.']); ?>
                </div>
            </details>

            <details class="fold">
                <summary>More options (dates, staff, pricing)</summary>
                <div class="fold-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="order_date">Order date</label>
                            <input type="date" id="order_date" name="order_date" value="<?= e((string) $order['order_date']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="scheduled_pickup_date">Scheduled pickup date</label>
                            <input type="date" id="scheduled_pickup_date" name="scheduled_pickup_date" value="<?= e((string) $order['scheduled_pickup_date']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="scheduled_delivery_date">Scheduled delivery date</label>
                            <input type="date" id="scheduled_delivery_date" name="scheduled_delivery_date" value="<?= e((string) $order['scheduled_delivery_date']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="pickup_staff_id">Pickup staff</label>
                            <select name="pickup_staff_id" id="pickup_staff_id">
                                <option value="">Assign later</option>
                                <?php foreach ($pickupStaff as $staff): ?>
                                    <option value="<?= e((string) $staff['id']) ?>"<?= (int) $order['pickup_staff_id'] === (int) $staff['id'] ? ' selected' : '' ?>>
                                        <?= e($staff['name']) ?><?= $staff['is_active'] ? '' : ' (disabled)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="delivery_staff_id">Delivery staff</label>
                            <select name="delivery_staff_id" id="delivery_staff_id">
                                <option value="">Assign later</option>
                                <?php foreach ($deliveryStaff as $staff): ?>
                                    <option value="<?= e((string) $staff['id']) ?>"<?= (int) $order['delivery_staff_id'] === (int) $staff['id'] ? ' selected' : '' ?>>
                                        <?= e($staff['name']) ?><?= $staff['is_active'] ? '' : ' (disabled)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="discount_amount">Discount (<?= e(APP_CURRENCY) ?>)</label>
                            <input type="number" id="discount_amount" name="discount_amount" min="0" step="0.01" value="<?= e((string) $order['discount_amount']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="delivery_charge">Delivery charge (<?= e(APP_CURRENCY) ?>)</label>
                            <input type="number" id="delivery_charge" name="delivery_charge" min="0" step="0.01" value="<?= e((string) $order['delivery_charge']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="tax_amount">Tax (<?= e(APP_CURRENCY) ?>)</label>
                            <input type="number" id="tax_amount" name="tax_amount" min="0" step="0.01" value="<?= e((string) $order['tax_amount']) ?>">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="notes">Order notes</label>
                            <input type="text" id="notes" name="notes" value="<?= e((string) $order['notes']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="staff_notes">Internal notes</label>
                            <input type="text" id="staff_notes" name="staff_notes" value="<?= e((string) $order['staff_notes']) ?>">
                        </div>
                    </div>
                </div>
            </details>

            <div class="form-actions">
                <button class="btn btn-lg" type="submit">Create Order</button>
                <a class="btn btn-lg btn-ghost" href="<?= e(url('admin/orders.php')) ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php layout_end(); ?>
