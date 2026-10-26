<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';

$user = require_login(true);
$errors = [];
$isStaff = in_array($user['role'], staff_roles(), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if ($isStaff) {
        flash('error', 'Staff passwords are reset by the owner. Please ask the owner for a new password.');
        redirect('account/change-password.php');
    }
    $current = raw_post('current_password');
    $new = raw_post('new_password');
    $confirm = raw_post('confirm_password');

    if (!password_verify($current, (string) $user['password_hash'])) {
        $errors[] = 'Your current password is not correct.';
    }
    if (strlen($new) < 6) {
        $errors[] = 'The new password must be at least 6 characters long.';
    }
    if ($new !== $confirm) {
        $errors[] = 'The two new passwords do not match.';
    }
    if ($new === $current && $current !== '') {
        $errors[] = 'The new password must be different from the current password.';
    }

    if ($errors === []) {
        db_exec(
            'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), (int) $user['id']]
        );
        audit('password.change', 'user', (string) $user['id'], 'Password changed');
        flash('success', 'Password updated successfully.');
        redirect(home_for_role((string) $user['role']));
    }
}

$forced = (int) $user['must_change_password'] === 1;
layout_start('Change Password', $user['role'] === 'customer' ? 'customer' : 'app');
?>
<?php if ($isStaff): ?>
    <div class="alert alert-info">
        Staff accounts cannot change their own login details. Ask the owner to reset your password or update your name, number or email.
    </div>
    <div class="panel" style="max-width: 520px;">
        <div class="panel-head"><h2>Your Login</h2></div>
        <div class="panel-body">
            <dl class="kv">
                <dt>Name</dt><dd><?= e((string) $user['name']) ?></dd>
                <dt>Mobile number</dt><dd><?= e((string) ($user['phone'] ?? '—')) ?></dd>
                <dt>Email</dt><dd><?= e((string) ($user['email'] ?? '—')) ?></dd>
                <dt>Staff ID</dt><dd>LF-<?= e(str_pad((string) $user['id'], 4, '0', STR_PAD_LEFT)) ?></dd>
            </dl>
            <div class="form-actions">
                <a class="btn btn-ghost" href="<?= e(url(home_for_role((string) $user['role']))) ?>">Back to dashboard</a>
            </div>
        </div>
    </div>
<?php else: ?>
<?php if ($forced): ?>
    <div class="alert alert-warn">Your password was reset by the owner. Please set a new password to continue.</div>
<?php endif; ?>

<div class="panel" style="max-width: 520px;">
    <div class="panel-head"><h2>Change Password</h2></div>
    <div class="panel-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="post" action="<?= e(url('account/change-password.php')) ?>">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="current_password" class="required">Current password</label>
                <input type="password" id="current_password" name="current_password" required>
            </div>
            <div class="form-group">
                <label for="new_password" class="required">New password</label>
                <input type="password" id="new_password" name="new_password" required>
                <div class="help">At least 6 characters.</div>
            </div>
            <div class="form-group">
                <label for="confirm_password" class="required">Confirm new password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            <div class="form-actions">
                <button class="btn" type="submit">Update Password</button>
                <?php if (!$forced): ?>
                    <a class="btn btn-ghost" href="<?= e(url(home_for_role((string) $user['role']))) ?>">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php layout_end(); ?>
