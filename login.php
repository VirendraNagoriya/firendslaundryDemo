<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

if (is_logged_in()) {
    redirect(home_for_role((string) current_user()['role']));
}

$errors = [];
$identity = '';
$roleHint = 'any';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $identity = post('identity');
    $password = raw_post('password');
    $roleHint = post('role_hint', 'any');

    if ($identity === '' || $password === '') {
        $errors[] = 'Please enter your login id and password.';
    } else {
        $result = attempt_login($identity, $password);
        if ($result['ok']) {
            $user = $result['user'];
            $roleMatch = $user['role'] === $roleHint
                || ($roleHint === 'admin' && $user['role'] === 'super_admin')
                || ($roleHint === 'staff' && in_array($user['role'], staff_roles(), true));
            if ($roleHint !== 'any' && !$roleMatch) {
                logout_user();
                $errors[] = 'This account is not registered as a ' . role_label($roleHint) . ' account.';
            } else {
                flash('success', 'Welcome back, ' . $user['name'] . '.');
                redirect(home_for_role((string) $user['role']));
            }
        } else {
            $errors[] = $result['message'];
        }
    }
}

$tabs = [
    'any' => 'Any account',
    'customer' => 'Customer',
    'staff' => 'Staff',
    'admin' => 'Admin / Owner',
];

layout_start('Login', 'public');
?>
<div class="auth-wrap">
    <div class="auth-card">
        <a class="brand" href="<?= e(url('index.php')) ?>">
            <span class="brand-mark">LF</span>
            <span><?= e(APP_NAME) ?></span>
        </a>
        <h1>Sign in</h1>
        <p class="sub">One login for customers, staff and the owner. Staff sign in with a single ID and switch between pickup, delivery and payments.</p>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <div class="tabs" id="loginTabs">
            <?php foreach ($tabs as $key => $label): ?>
                <a href="#" class="<?= $roleHint === $key ? 'active' : '' ?>" data-role="<?= e($key) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>

        <form method="post" action="<?= e(url('login.php')) ?>" autocomplete="on">
            <?= csrf_field() ?>
            <input type="hidden" name="role_hint" id="roleHint" value="<?= e($roleHint) ?>">
            <div class="form-group">
                <label for="identity">Email or Mobile number</label>
                <input type="text" id="identity" name="identity" value="<?= e($identity) ?>" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button class="btn btn-block btn-lg" type="submit">Sign in</button>
        </form>

        <div class="auth-foot">
            New customer? <a href="<?= e(url('register.php')) ?>">Create an account</a> or
            <a href="<?= e(url('customer/schedule-pickup.php')) ?>">book a pickup</a>.<br>
            Staff account? Ask the owner to create it from Staff Accounts.
        </div>
    </div>
</div>
<script>
(function () {
    var tabs = document.getElementById('loginTabs');
    var hint = document.getElementById('roleHint');
    if (!tabs || !hint) { return; }
    tabs.addEventListener('click', function (event) {
        var link = event.target.closest('a[data-role]');
        if (!link) { return; }
        event.preventDefault();
        tabs.querySelectorAll('a').forEach(function (a) { a.classList.remove('active'); });
        link.classList.add('active');
        hint.value = link.getAttribute('data-role');
    });
})();
</script>
<?php layout_end(); ?>
