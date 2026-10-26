<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/orders.php';
require_once dirname(__DIR__) . '/includes/location-picker.php';

$user = require_login();
$errors = [];
$isStaff = in_array($user['role'], staff_roles(), true);

$customer = null;
if ($user['role'] === 'customer') {
    $customer = customer_for_user((int) $user['id']);
    if ($customer === null) {
        $customerId = find_or_create_customer([
            'user_id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'phone' => (string) ($user['phone'] ?? ''),
            'email' => $user['email'] ?? null,
        ], (int) $user['id']);
        $customer = db_row('SELECT * FROM customers WHERE id = ?', [$customerId]);
    }
}

$form = [
    'name' => (string) $user['name'],
    'email' => (string) ($user['email'] ?? ''),
    'phone' => (string) ($user['phone'] ?? ($customer['phone'] ?? '')),
    'address' => (string) ($customer['address'] ?? ''),
    'landmark' => (string) ($customer['landmark'] ?? ''),
    'city' => (string) ($customer['city'] ?? ''),
    'pincode' => (string) ($customer['pincode'] ?? ''),
];
$location = [
    'latitude' => isset($customer['latitude']) && $customer['latitude'] !== null ? (float) $customer['latitude'] : null,
    'longitude' => isset($customer['longitude']) && $customer['longitude'] !== null ? (float) $customer['longitude'] : null,
    'place_id' => (string) ($customer['place_id'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if ($isStaff) {
        flash('error', 'Staff details are managed by the owner. Please ask the owner to update them.');
        redirect('account/profile.php');
    }
    foreach (array_keys($form) as $key) {
        $form[$key] = post($key);
    }
    $location = location_from_post();

    if ($form['name'] === '') {
        $errors[] = 'Please enter your name.';
    }
    if (!valid_phone($form['phone'])) {
        $errors[] = 'Please enter a valid 10-digit mobile number.';
    }
    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($form['email'] !== '') {
        $exists = db_row('SELECT id FROM users WHERE email = ? AND id <> ?', [$form['email'], (int) $user['id']]);
        if ($exists !== null) {
            $errors[] = 'That email address is already used by another account.';
        }
    }
    $newPhoneKey = phone_key($form['phone']);
    if (db_row('SELECT id FROM users WHERE phone_key = ? AND id <> ?', [$newPhoneKey, (int) $user['id']]) !== null) {
        $errors[] = 'An account already exists with this mobile number.';
    }
    if ($customer !== null && db_row('SELECT id FROM customers WHERE phone_key = ? AND id <> ?', [$newPhoneKey, (int) $customer['id']]) !== null) {
        $errors[] = 'Another customer already uses this mobile number.';
    }

    if ($errors === []) {
        try {
            db_transaction(static function () use ($form, $location, $newPhoneKey, $user, $customer): void {
                db_exec(
                    'UPDATE users SET name = ?, email = ?, phone = ?, phone_key = ? WHERE id = ?',
                    [
                        $form['name'],
                        $form['email'] !== '' ? $form['email'] : null,
                        normalise_phone($form['phone']),
                        $newPhoneKey,
                        (int) $user['id'],
                    ]
                );
                if ($customer !== null) {
                    db_exec(
                        'UPDATE customers
                         SET name = ?, email = ?, phone = ?, phone_key = ?, address = ?, landmark = ?, city = ?, pincode = ?,
                             latitude = COALESCE(?, latitude),
                             longitude = COALESCE(?, longitude),
                             place_id = COALESCE(NULLIF(?, \'\'), place_id)
                         WHERE id = ?',
                        [
                            $form['name'],
                            $form['email'] !== '' ? $form['email'] : null,
                            normalise_phone($form['phone']),
                            $newPhoneKey,
                            $form['address'],
                            $form['landmark'],
                            $form['city'],
                            $form['pincode'],
                            $location['latitude'],
                            $location['longitude'],
                            $location['place_id'],
                            (int) $customer['id'],
                        ]
                    );
                }
            });
        } catch (Throwable $e) {
            $errors[] = app_form_message($e);
        }
    }

    if ($errors === []) {
        audit('profile.update', 'user', (string) $user['id'], 'Profile updated');
        flash('success', 'Profile updated.');
        redirect('account/profile.php');
    }
}

layout_start('My Profile', $user['role'] === 'customer' ? 'customer' : 'app');
?>
<div class="panel" style="max-width: 720px;">
    <div class="panel-head">
        <h2>My Profile</h2>
        <span class="subtle"><?= e(role_label((string) $user['role'])) ?> &middot; last login <?= e(format_datetime($user['last_login_at'])) ?></span>
    </div>
    <div class="panel-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <?php if ($isStaff): ?>
            <div class="alert alert-info">
                These details are read-only for staff accounts. The owner controls the staff name, mobile number, email and password.
            </div>
            <dl class="kv">
                <dt>Name</dt><dd><?= e($form['name']) ?></dd>
                <dt>Mobile number</dt><dd><?= e($form['phone'] !== '' ? $form['phone'] : '—') ?></dd>
                <dt>Email</dt><dd><?= e($form['email'] !== '' ? $form['email'] : '—') ?></dd>
                <dt>Staff ID</dt><dd>LF-<?= e(str_pad((string) $user['id'], 4, '0', STR_PAD_LEFT)) ?></dd>
                <dt>Role</dt><dd><?= e(role_label((string) $user['role'])) ?></dd>
                <dt>Last login</dt><dd><?= e(format_datetime($user['last_login_at'])) ?></dd>
            </dl>
            <div class="form-actions">
                <a class="btn btn-ghost" href="<?= e(url(home_for_role((string) $user['role']))) ?>">Back to dashboard</a>
            </div>
        <?php else: ?>
        <form method="post" action="<?= e(url('account/profile.php')) ?>">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="form-group">
                    <label for="name" class="required">Name</label>
                    <input type="text" id="name" name="name" value="<?= e($form['name']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="phone">Mobile number</label>
                    <input type="tel" inputmode="tel" maxlength="16" id="phone" name="phone" value="<?= e($form['phone']) ?>" required title="10-digit mobile number">
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= e($form['email']) ?>">
                </div>
            </div>

            <?php if ($user['role'] === 'customer'): ?>
                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea id="address" name="address"><?= e($form['address']) ?></textarea>
                </div>
                <div class="form-grid">
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
                <?php render_location_picker([
                    'id' => 'profile-location',
                    'address_id' => 'address',
                    'city_id' => 'city',
                    'pincode_id' => 'pincode',
                    'landmark_id' => 'landmark',
                    'lat' => $location['latitude'],
                    'lng' => $location['longitude'],
                    'place' => (string) ($location['place_id'] ?? ''),
                    'label' => 'My saved location',
                    'height' => 240,
                ]); ?>
            <?php endif; ?>

            <div class="form-actions">
                <button class="btn" type="submit">Save Changes</button>
                <a class="btn btn-ghost" href="<?= e(url('account/change-password.php')) ?>">Change Password</a>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php layout_end(); ?>
