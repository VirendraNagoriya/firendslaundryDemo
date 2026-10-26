<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';

$user = require_admin();
$errors = [];
$generated = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action');

    if ($action === 'create') {
        $name = post('name');
        $phone = post('phone');
        $email = post('email');
        $role = post('role');
        $password = raw_post('password');

        if ($name === '') {
            $errors[] = 'Please enter the staff name.';
        }
        if (!valid_phone($phone)) {
            $errors[] = 'Please enter a valid 10-digit mobile number.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (!in_array($role, ['staff', 'admin'], true)) {
            $errors[] = 'Please select a valid role.';
        }
        if ($role === 'admin' && !is_super_admin()) {
            $errors[] = 'Only the super admin can create admin accounts.';
        }
        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters long.';
        }
        if ($phone !== '' && db_row('SELECT id FROM users WHERE phone_key = ?', [phone_key($phone)]) !== null) {
            $errors[] = 'An account already exists with this mobile number.';
        }
        if ($email !== '' && db_row('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
            $errors[] = 'An account already exists with this email address.';
        }

        if ($errors === []) {
            try {
                $staffId = db_insert(
                    'INSERT INTO users (role, name, email, phone, phone_key, password_hash, must_change_password, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $role,
                        $name,
                        $email !== '' ? $email : null,
                        normalise_phone($phone),
                        phone_key($phone),
                        password_hash($password, PASSWORD_DEFAULT),
                        $role === 'staff' ? 0 : 1,
                        (int) $user['id'],
                    ]
                );
            } catch (Throwable $e) {
                $errors[] = app_form_message($e);
            }
        }

        if ($errors === []) {
            audit('staff.create', 'user', (string) $staffId, $role . ' ' . $name);
            flash('success', 'Staff account created. Share the login details with the staff member.');
            redirect('admin/staff.php');
        }
    }

    if ($action === 'toggle') {
        $staffId = (int) post('staff_id');
        $staff = db_row('SELECT * FROM users WHERE id = ?', [$staffId]);
        if ($staff === null || !in_array($staff['role'], array_merge(staff_roles(), ['admin']), true)) {
            $errors[] = 'Account not found.';
        } elseif ((int) $staff['id'] === (int) $user['id']) {
            $errors[] = 'You cannot disable your own account.';
        } else {
            $newState = (int) $staff['is_active'] === 1 ? 0 : 1;
            db_exec('UPDATE users SET is_active = ? WHERE id = ?', [$newState, $staffId]);
            audit('staff.toggle', 'user', (string) $staffId, $newState === 1 ? 'enabled' : 'disabled');
            flash('success', 'Account ' . ($newState === 1 ? 'enabled' : 'disabled') . '.');
            redirect('admin/staff.php');
        }
    }

    if ($action === 'reset_password') {
        $staffId = (int) post('staff_id');
        $staff = db_row('SELECT * FROM users WHERE id = ?', [$staffId]);
        if ($staff === null) {
            $errors[] = 'Account not found.';
        } else {
            $temp = 'LF' . strtoupper(bin2hex(random_bytes(3)));
            db_exec(
                'UPDATE users SET password_hash = ?, must_change_password = ? WHERE id = ?',
                [password_hash($temp, PASSWORD_DEFAULT), in_array($staff['role'], staff_roles(), true) ? 0 : 1, $staffId]
            );
            audit('staff.password_reset', 'user', (string) $staffId, 'Password reset');
            $generated = ['name' => (string) $staff['name'], 'password' => $temp];
        }
    }
}

$staffRows = db_all(
    "SELECT u.*,
            (SELECT COUNT(*) FROM orders o WHERE o.pickup_staff_id = u.id AND o.status IN ('new','pickup_assigned')) AS pickup_tasks,
            (SELECT COUNT(*) FROM orders o WHERE o.delivery_staff_id = u.id AND o.status IN ('ready_for_delivery','out_for_delivery')) AS delivery_tasks
     FROM users u
     WHERE u.role IN ('super_admin','admin','staff','pickup','delivery')
     ORDER BY u.role, u.name"
);

layout_start('Staff Accounts', 'app');
?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if ($generated !== null): ?>
    <div class="alert alert-info">
        <strong>Temporary password for <?= e($generated['name']) ?>:</strong>
        <code><?= e($generated['password']) ?></code>
        <br>Share it with the staff member. They will be asked to set a new password at first login.
    </div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head"><h2>Create Staff Account</h2></div>
    <div class="panel-body">
        <form method="post" action="<?= e(url('admin/staff.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-grid">
                <div class="form-group">
                    <label for="name" class="required">Full name</label>
                    <input type="text" id="name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="phone" class="required">Mobile number</label>
                    <input type="tel" inputmode="tel" maxlength="16" id="phone" name="phone" required title="10-digit mobile number">
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email">
                </div>
                <div class="form-group">
                    <label for="role" class="required">Role</label>
                    <select name="role" id="role">
                        <option value="staff">Staff (pickup + delivery)</option>
                        <?php if (is_super_admin()): ?>
                            <option value="admin">Admin / Owner</option>
                        <?php endif; ?>
                    </select>
                    <div class="help">Staff sign in with one ID and use the Pickup, Deliver and Payments tabs.</div>
                </div>
                <div class="form-group">
                    <label for="password" class="required">Login password</label>
                    <input type="text" id="password" name="password" value="LF<?= e(strtoupper(bin2hex(random_bytes(3)))) ?>" required>
                    <div class="help">Share it with the staff member. Staff cannot change their own login details.</div>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn" type="submit">Create Account</button>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2>Accounts</h2>
        <span class="subtle"><?= e((string) count($staffRows)) ?> staff accounts</span>
    </div>
    <div class="panel-body tight">
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Open Tasks</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th class="actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($staffRows as $staff): ?>
                    <tr>
                        <td><strong><?= e($staff['name']) ?></strong></td>
                        <td><?= e(role_label((string) $staff['role'])) ?></td>
                        <td><?= e((string) ($staff['phone'] ?? '—')) ?></td>
                        <td><?= e((string) ($staff['email'] ?? '—')) ?></td>
                        <td><?= in_array($staff['role'], staff_roles(), true) ? e((string) $staff['pickup_tasks'] . ' pickup / ' . (string) $staff['delivery_tasks'] . ' delivery') : '—' ?></td>
                        <td>
                            <span class="badge <?= (int) $staff['is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>">
                                <?= (int) $staff['is_active'] === 1 ? 'Active' : 'Disabled' ?>
                            </span>
                        </td>
                        <td><?= e(format_datetime($staff['last_login_at'])) ?></td>
                        <td class="actions">
                            <form method="post" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="staff_id" value="<?= e((string) $staff['id']) ?>">
                                <button class="btn btn-sm btn-ghost" type="submit">Reset password</button>
                            </form>
                            <form method="post" style="display:inline;" data-confirm="Confirm changing the account status?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="staff_id" value="<?= e((string) $staff['id']) ?>">
                                <button class="btn btn-sm <?= (int) $staff['is_active'] === 1 ? 'btn-outline-danger' : 'btn-soft' ?>" type="submit">
                                    <?= (int) $staff['is_active'] === 1 ? 'Disable' : 'Enable' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php layout_end(); ?>
