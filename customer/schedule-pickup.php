<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/orders.php';
require_once dirname(__DIR__) . '/includes/order-items.php';
require_once dirname(__DIR__) . '/includes/accounts.php';

$loggedIn = current_user();
$isGuest = true;
$customer = null;

if ($loggedIn !== null) {
    if ($loggedIn['role'] !== 'customer') {
        flash('info', 'You are signed in as ' . role_label((string) $loggedIn['role']) . '.');
        redirect(home_for_role((string) $loggedIn['role']));
    }
    $customer = customer_for_user((int) $loggedIn['id']);
    if ($customer === null) {
        $customerId = find_or_create_customer([
            'user_id' => (int) $loggedIn['id'],
            'name' => (string) $loggedIn['name'],
            'phone' => (string) ($loggedIn['phone'] ?? ''),
            'email' => $loggedIn['email'] ?? null,
        ], (int) $loggedIn['id']);
        $customer = db_row('SELECT * FROM customers WHERE id = ?', [$customerId]);
    }
    $isGuest = false;
}

$catalog = active_catalog();
$deliveryCharge = (float) setting('default_delivery_charge', '40');
$taxPercent = (float) setting('tax_percent', '0');

$activeOffers = db_all('SELECT * FROM offers WHERE is_active = 1 AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW()) ORDER BY created_at DESC');

$openDues = 0.0;
$lastPending = null;
if ($customer !== null) {
    foreach (customer_dues((int) $customer['id']) as $row) {
        $amount = max(0.0, (float) $row['total_amount'] - (float) $row['paid_amount']);
        if ($amount > 0) {
            $openDues += $amount;
            $lastPending = $row;
        }
    }
}

$errors = [];
$items = [];
$form = [
    'name' => (string) ($customer['name'] ?? ($loggedIn['name'] ?? '')),
    'phone' => (string) ($customer['phone'] ?? ($loggedIn['phone'] ?? '')),
    'email' => (string) ($customer['email'] ?? ($loggedIn['email'] ?? '')),
    'address' => (string) ($customer['address'] ?? ''),
    'landmark' => (string) ($customer['landmark'] ?? ''),
    'city' => (string) ($customer['city'] ?? ''),
    'pincode' => (string) ($customer['pincode'] ?? ''),
    'scheduled_pickup_date' => today(),
    'notes' => '',
    'coupon_code' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    foreach (array_keys($form) as $key) {
        $form[$key] = post($key);
    }

    if (!$isGuest) {
        // The signed-in booking form only collects pickup details, so identity
        // always comes from the account (and is never wiped by a missing field).
        $form['name'] = (string) ($customer['name'] ?? $loggedIn['name'] ?? '');
        $form['phone'] = (string) ($customer['phone'] ?? $loggedIn['phone'] ?? '');
        $form['email'] = (string) ($customer['email'] ?? $loggedIn['email'] ?? '');
    }

    if ($form['name'] === '') {
        $errors[] = 'Please enter your name.';
    }
    if (!valid_phone($form['phone'])) {
        $errors[] = 'Please enter a valid 10-digit mobile number.';
    }
    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (trim($form['address']) === '') {
        $errors[] = 'Please enter the pickup address.';
    }

    if ($isGuest) {
        $password = raw_post('password');
        $confirm = raw_post('password_confirm');
        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters long.';
        } elseif ($password !== $confirm) {
            $errors[] = 'The two passwords do not match.';
        }
        if ($form['phone'] !== '' && db_row('SELECT id FROM users WHERE phone_key = ?', [phone_key($form['phone'])]) !== null) {
            $errors[] = 'An account already exists with this mobile number. Please sign in instead.';
        }
        if ($form['email'] !== '' && db_row('SELECT id FROM users WHERE email = ?', [$form['email']]) !== null) {
            $errors[] = 'An account already exists with this email address. Please sign in instead.';
        }
    }

    $items = collect_items_from_post(false, $errors);
    if ($items === []) {
        $errors[] = 'Please add at least one item to the order.';
    }

    if ($errors === []) {
        try {
            $result = db_transaction(static function () use ($isGuest, $form, $customer, $loggedIn, $items, $taxPercent, $deliveryCharge): array {
                if ($isGuest) {
                    $account = register_customer_account([
                        'name' => $form['name'],
                        'phone' => $form['phone'],
                        'email' => $form['email'],
                        'password' => raw_post('password'),
                        'address' => $form['address'],
                        'landmark' => $form['landmark'],
                        'city' => $form['city'],
                        'pincode' => $form['pincode'],
                    ]);
                    $userId = $account['user_id'];
                    $customerId = $account['customer_id'];
                } else {
                    $customerId = (int) $customer['id'];
                    $userId = (int) $loggedIn['id'];
                    db_exec(
                        'UPDATE customers
                         SET name = ?, phone = ?, email = ?, address = ?, landmark = ?, city = ?, pincode = ?
                         WHERE id = ?',
                        [
                            $form['name'],
                            normalise_phone($form['phone']),
                            $form['email'] !== '' ? $form['email'] : null,
                            $form['address'],
                            $form['landmark'],
                            $form['city'],
                            $form['pincode'],
                            $customerId,
                        ]
                    );
                }

                $subtotal = 0.0;
                foreach ($items as $item) {
                    $subtotal += order_item_amount($item);
                }
                $tax = round($subtotal * ($taxPercent / 100), 2);

                // Apply coupon if provided.
                $discount = 0.0;
                $couponCode = strtoupper(trim((string) ($form['coupon_code'] ?? '')));
                if ($couponCode !== '') {
                    $coupon = db_row('SELECT * FROM coupons WHERE code = ? AND is_active = 1', [$couponCode]);
                    if ($coupon !== null) {
                        $now = date('Y-m-d H:i:s');
                        $expired = $coupon['expires_at'] !== null && $coupon['expires_at'] < $now;
                        $notStarted = false; // coupons have no starts_at
                        $maxed = $coupon['max_uses'] !== null && (int) $coupon['used_count'] >= (int) $coupon['max_uses'];
                        $minOk = $subtotal >= (float) $coupon['min_order'];
                        if (!$expired && !$maxed && $minOk) {
                            if ($coupon['discount_type'] === 'percent') {
                                $discount = round($subtotal * ((float) $coupon['discount_value'] / 100), 2);
                            } else {
                                $discount = min($subtotal, (float) $coupon['discount_value']);
                            }
                            db_exec('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?', [(int) $coupon['id']]);
                        }
                    }
                }

                $orderId = create_order([
                    'customer_id' => $customerId,
                    'source' => 'customer',
                    'pricing_mode' => 'per_item',
                    'order_date' => today(),
                    'scheduled_pickup_date' => $form['scheduled_pickup_date'] !== '' ? $form['scheduled_pickup_date'] : null,
                    'contact_name' => $form['name'],
                    'contact_phone' => $form['phone'],
                    'delivery_address' => trim($form['address'] . ', ' . $form['landmark'] . ', ' . $form['city'] . ' - ' . $form['pincode'], ', '),
                    'discount_amount' => $discount,
                    'delivery_charge' => $deliveryCharge,
                    'tax_amount' => $tax,
                    'notes' => $form['notes'],
                ], $items, $isGuest ? null : $userId);

                return ['user_id' => $userId, 'order_id' => $orderId];
            });
        } catch (Throwable $e) {
            $errors[] = app_form_message($e);
        }
    }

    if ($errors === []) {
        if ($isGuest) {
            start_session();
            session_regenerate_id(true);
            $_SESSION['user_id'] = $result['user_id'];
        }
        flash('success', 'Pickup scheduled. Our team will call you to confirm.');
        redirect('customer/order-view.php?id=' . $result['order_id'] . '&new=1');
    }
}

layout_start('Schedule a Pickup', 'customer');
?>
<div class="panel">
    <div class="panel-head">
        <h2>Schedule a Pickup</h2>
        <span class="subtle">Add your garments, tell us where to pick up and choose a date.</span>
    </div>
    <div class="panel-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <?php if ($lastPending !== null): ?>
            <div class="dues-strip">
                <span>
                    Welcome back — you have <strong><?= e(money($openDues)) ?></strong> pending on
                    <?= e($lastPending['order_number']) ?> (<?= e(format_date((string) $lastPending['order_date'])) ?>).
                </span>
                <a class="btn btn-sm btn-ghost" href="<?= e(url('customer/orders.php')) ?>">View pending invoice</a>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('customer/schedule-pickup.php')) ?>" data-calc-items data-tax-percent="<?= e((string) $taxPercent) ?>">
            <?= csrf_field() ?>
            <?php if ($activeOffers !== []): ?>
                <div class="panel" style="background:linear-gradient(135deg,#2563eb,#7c3aed);color:#fff;margin-bottom:1rem;">
                    <div class="panel-body">
                        <?php foreach ($activeOffers as $o): ?>
                            <div style="margin-bottom:.5rem;">
                                <strong><?= e((string) $o['title']) ?></strong>
                                <?php if (!empty($o['description'])): ?>
                                    <span style="opacity:.9;"> — <?= e((string) $o['description']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <input type="hidden" name="delivery_charge" value="<?= e((string) $deliveryCharge) ?>">

            <?php if ($isGuest): ?>
                <h3>1. Your details</h3>
                <p class="subtle">We create your customer account with this booking so you can track the order.</p>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="name" class="required">Full name</label>
                        <input type="text" id="name" name="name" value="<?= e($form['name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="phone" class="required">Mobile number</label>
                        <input type="tel" inputmode="tel" maxlength="16" id="phone" name="phone" value="<?= e($form['phone']) ?>" required title="10-digit mobile number">
                    </div>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?= e($form['email']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="password" class="required">Password</label>
                        <input type="password" id="password" name="password" required>
                        <div class="help">Minimum 6 characters. Use this to sign in later.</div>
                    </div>
                    <div class="form-group">
                        <label for="password_confirm" class="required">Confirm password</label>
                        <input type="password" id="password_confirm" name="password_confirm" required>
                    </div>
                </div>
                <hr>
                <h3>2. Pickup details</h3>
            <?php else: ?>
                <h3>Pickup details</h3>
            <?php endif; ?>

            <div class="form-grid">
                <div class="form-group">
                    <label for="scheduled_pickup_date">Preferred pickup date</label>
                    <input type="date" id="scheduled_pickup_date" name="scheduled_pickup_date" min="<?= e(today()) ?>" value="<?= e($form['scheduled_pickup_date']) ?>">
                </div>
                <div class="form-group">
                    <label for="landmark">Landmark</label>
                    <input type="text" id="landmark" name="landmark" value="<?= e($form['landmark']) ?>">
                </div>
                <div class="form-group">
                    <label for="city">City</label>
                    <input type="text" id="city" name="city" value="<?= e($form['city']) ?>">
                </div>
                <div class="form-group">
                    <label for="pincode">PIN code</label>
                    <input type="text" id="pincode" name="pincode" value="<?= e($form['pincode']) ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="address" class="required">Pickup address</label>
                <textarea id="address" name="address" required><?= e($form['address']) ?></textarea>
            </div>

            <h3 class="mt-1">3. Items to be washed</h3>
            <p class="subtle">Tap a service to add it, then set how many pieces or how many kilos. Our team confirms the final weight at pickup.</p>
            <?php render_item_picker($items, $catalog, ['draft_key' => 'customer-booking', 'intro' => 'Tap + Add item and choose a service. Weight is an estimate - the pickup boy measures and verifies the actual kg.', 'add_item_url' => 'add-item.php']); ?>

            <div class="form-grid mt-1">
                <div class="form-group">
                    <label for="notes">Special instructions</label>
                    <input type="text" id="notes" name="notes" value="<?= e($form['notes']) ?>" placeholder="e.g. starch for shirts, no bleach">
                </div>
            </div>

            <div class="total-box">
                <div class="total-row">
                    <span class="subtle">Items total</span>
                    <strong><?= e(APP_CURRENCY) ?><span data-subtotal>0.00</span></strong>
                </div>
                <div class="total-row">
                    <span class="subtle">Delivery charge</span>
                    <strong><?= e(money($deliveryCharge)) ?></strong>
                </div>
                <?php if ($taxPercent > 0): ?>
                    <div class="total-row">
                        <span class="subtle">Tax (<?= e(number_format($taxPercent, 2)) ?>%)</span>
                        <strong>included</strong>
                    </div>
                <?php endif; ?>
                <div class="form-group">
                    <label for="coupon_code">Coupon code (optional)</label>
                    <input type="text" id="coupon_code" name="coupon_code" value="<?= e($form['coupon_code']) ?>" placeholder="Enter code">
                </div>
                <div class="total-row grand">
                    <span>Estimated total</span>
                    <strong><?= e(APP_CURRENCY) ?><span data-total>0.00</span></strong>
                </div>
            </div>

            <div class="row-between">
                <div>
                    <span class="subtle">Delivery charge</span> <strong><?= e(money($deliveryCharge)) ?></strong>
                    <?php if ($taxPercent > 0): ?>
                        &nbsp;<span class="subtle">| Tax <?= e(number_format($taxPercent, 2)) ?>%</span>
                    <?php endif; ?>
                </div>
                <button class="btn btn-lg" type="submit"><?= $isGuest ? 'Create Account & Schedule Pickup' : 'Schedule Pickup' ?></button>
            </div>
        </form>
    </div>
</div>
<?php layout_end(); ?>
