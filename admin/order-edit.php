<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/orders.php';
require_once dirname(__DIR__) . '/includes/order-items.php';
require_once dirname(__DIR__) . '/includes/order-form-fields.php';

$user = require_admin();
$orderId = query_int('id');
$order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);

if ($order === null) {
    flash('error', 'Order not found.');
    redirect('admin/orders.php');
}

$catalog = active_catalog();
$pickupStaff = staff_list('pickup');
$deliveryStaff = staff_list('delivery');
$items = db_all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
$closed = order_items_locked($order);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $form = [
        'contact_name' => post('contact_name'),
        'contact_phone' => post('contact_phone'),
        'delivery_address' => post('delivery_address'),
        'order_date' => post('order_date'),
        'scheduled_pickup_date' => post('scheduled_pickup_date'),
        'scheduled_delivery_date' => post('scheduled_delivery_date'),
        'pickup_staff_id' => post('pickup_staff_id'),
        'delivery_staff_id' => post('delivery_staff_id'),
        'discount_amount' => post('discount_amount'),
        'delivery_charge' => post('delivery_charge'),
        'tax_amount' => post('tax_amount'),
        'notes' => post('notes'),
        'staff_notes' => post('staff_notes'),
    ];

    if ($form['contact_name'] === '') {
        $errors[] = 'Please enter the customer name.';
    }
    if (!valid_phone($form['contact_phone'])) {
        $errors[] = 'Please enter a valid 10-digit mobile number.';
    }
    if (trim($form['delivery_address']) === '') {
        $errors[] = 'Please enter the delivery address.';
    }

    $newItems = [];
    if (!$closed) {
        $newItems = collect_items_from_post(true);
        if ($newItems === []) {
            $errors[] = 'Please add at least one item to the order.';
        }
    }

    if ($errors === []) {
        try {
            db_transaction(static function () use ($orderId, $form, $closed, $newItems, $order): void {
                update_order($orderId, $form);
                if (!$closed) {
                    save_order_items($orderId, $newItems);
                    recalc_order($orderId);
                }

                $pickupChanged = (int) $form['pickup_staff_id'] !== (int) $order['pickup_staff_id'];
                if ($pickupChanged) {
                    assign_staff(
                        $orderId,
                        $form['pickup_staff_id'] !== '' ? (int) $form['pickup_staff_id'] : null,
                        $form['delivery_staff_id'] !== '' ? (int) $form['delivery_staff_id'] : null
                    );
                } elseif ((int) $form['delivery_staff_id'] !== (int) $order['delivery_staff_id']) {
                    assign_staff(
                        $orderId,
                        $form['pickup_staff_id'] !== '' ? (int) $form['pickup_staff_id'] : null,
                        $form['delivery_staff_id'] !== '' ? (int) $form['delivery_staff_id'] : null
                    );
                    log_status($orderId, (string) $order['status'], (string) $order['status'], 'assignment_updated', 'Delivery staff changed');
                }
            });
        } catch (Throwable $e) {
            $errors[] = app_form_message($e);
        }
    }

    if ($errors === []) {
        audit('order.update', 'order', (string) $orderId, 'Order updated');
        flash('success', 'Order updated.');
        redirect('admin/order-view.php?id=' . $orderId);
    }

    $order = array_merge($order, $form);
    if (!$closed) {
        $items = $newItems;
    }
}

layout_start('Edit Order ' . $order['order_number'], 'app');
?>
<div class="panel">
    <div class="panel-head">
        <h2>Edit Order <?= e($order['order_number']) ?></h2>
        <div class="row-between">
            <span class="badge <?= e(status_badge((string) $order['status'])) ?>"><?= e(order_status_label((string) $order['status'])) ?></span>
            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/order-view.php?id=' . (int) $orderId)) ?>">Back to order</a>
        </div>
    </div>
    <div class="panel-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="post" action="<?= e(url('admin/order-edit.php?id=' . (int) $orderId)) ?>"<?= $closed ? '' : ' data-calc-items' ?>>
            <?= csrf_field() ?>
            <?php render_order_fields($order, $items, $catalog, $pickupStaff, $deliveryStaff, true, $closed); ?>
            <div class="form-actions">
                <button class="btn btn-lg" type="submit">Save Changes</button>
                <a class="btn btn-lg btn-ghost" href="<?= e(url('admin/order-view.php?id=' . (int) $orderId)) ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php layout_end(); ?>
