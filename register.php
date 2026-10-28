<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/accounts.php';

if (is_logged_in()) {
    redirect(home_for_role((string) current_user()['role']));
}

$errors = [];
$form = [
    'name' => '',
    'phone' => '',
    'email' => '',
    'address' => '',
    'landmark' => '',
    'city' => '',
    'pincode' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    foreach (array_keys($form) as $key) {
        $form[$key] = post($key);
    }
    $password = raw_post('password');
    $confirm = raw_post('password_confirm');

    if ($form['name'] === '') {
        $errors[] = 'Please enter your name.';
    }
    require_once __DIR__ . '/includes/phone-helper.php';
    $phoneNormalized = normalize_mobile_10($form['phone']);
    if ($phoneNormalized === false) {
        $errors[] = 'Please enter a valid 10-digit Indian mobile number starting with 6-9.';
    } else {
        $form['phone'] = $phoneNormalized;
    }
    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }
    if ($form['phone'] !== '' && db_row('SELECT id FROM users WHERE phone_key = ?', [phone_key($form['phone'])]) !== null) {
        $errors[] = 'An account already exists with this mobile number. Please sign in.';
    }
    if ($form['email'] !== '' && db_row('SELECT id FROM users WHERE email = ?', [$form['email']]) !== null) {
        $errors[] = 'An account already exists with this email address. Please sign in.';
    }

    if ($errors === []) {
        try {
            $account = db_transaction(static function () use ($form, $password): array {
                return register_customer_account([
                    'name' => $form['name'],
                    'phone' => $form['phone'],
                    'email' => $form['email'],
                    'password' => $password,
                    'address' => $form['address'],
                    'landmark' => $form['landmark'],
                    'city' => $form['city'],
                    'pincode' => $form['pincode'],
                ]);
            });
        } catch (Throwable $e) {
            $errors[] = app_form_message($e);
        }
    }

    if ($errors === []) {
        $userId = $account['user_id'];
        audit('customer.register', 'user', (string) $userId, $form['name']);
        start_session();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        flash('success', 'Welcome to ' . APP_NAME . ', ' . $form['name'] . '!');
        redirect('customer/schedule-pickup.php');
    }
}

layout_start('Customer Registration', 'public');
?>
<div class="auth-wrap">
    <div class="auth-card wide">
        <a class="brand" href="<?= e(url('index.php')) ?>">
            <span class="brand-mark">LF</span>
            <span><?= e(APP_NAME) ?></span>
        </a>
        <h1>Create a customer account</h1>
        <p class="sub">Register once, then schedule as many pickups as you need and track every order.</p>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="post" action="<?= e(url('register.php')) ?>">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="form-group">
                    <label for="name" class="required">Full name</label>
                    <input type="text" id="name" name="name" value="<?= e($form['name']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="phone" class="required">Mobile number</label>
                    <input type="tel" inputmode="numeric" maxlength="10" pattern="[6-9][0-9]{9}" id="phone" name="phone" value="<?= e($form['phone']) ?>" required placeholder="Enter 10 digit mobile number">
                    <small class="subtle">Only 10 digits allowed. Must start with 6, 7, 8 or 9</small>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= e($form['email']) ?>">
                </div>
                <div class="form-group">
                    <label for="pincode">PIN code</label>
                    <input type="text" id="pincode" name="pincode" value="<?= e($form['pincode']) ?>">
                </div>
                <div class="form-group">
                    <label for="password" class="required">Password</label>
                    <input type="password" id="password" name="password" required>
                    <div class="help">Minimum 6 characters.</div>
                </div>
                <div class="form-group">
                    <label for="password_confirm" class="required">Confirm password</label>
                    <input type="password" id="password_confirm" name="password_confirm" required>
                </div>
            </div>
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
            </div>
            <div class="form-actions">
                <button class="btn btn-lg" type="submit">Create Account</button>
                <a class="btn btn-lg btn-ghost" href="<?= e(url('login.php')) ?>">I already have an account</a>
            </div>
        </form>
    </div>
</div>
<?php layout_end(); ?>
