<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/orders.php';

$user = require_admin();
$errors = [];
$search = query('q');
$showCreate = query('new') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (post('action') === 'create_customer') {
        $name = post('name');
        $phone = post('phone');
        $email = post('email');
        $address = post('address');
        $password = raw_post('password');

        if ($name === '') {
            $errors[] = 'Please enter the customer name.';
        }
        if (!valid_phone($phone)) {
            $errors[] = 'Please enter a valid 10-digit mobile number.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if ($password !== '' && strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters long.';
        }
        if ($phone !== '' && db_row('SELECT id FROM users WHERE phone_key = ?', [phone_key($phone)]) !== null) {
            $errors[] = 'An account already exists with this mobile number.';
        }
        if (phone_key($phone) !== '' && db_row('SELECT id FROM customers WHERE phone_key = ?', [phone_key($phone)]) !== null) {
            $errors[] = 'A customer with this mobile number already exists. Search for the mobile number instead of adding the same person twice.';
        }

        if ($errors === []) {
            try {
                $customerId = db_transaction(static function () use ($name, $phone, $email, $address, $password, $user): int {
                    $userId = null;
                    if ($password !== '') {
                        $userId = db_insert(
                            'INSERT INTO users (role, name, email, phone, phone_key, password_hash) VALUES (?, ?, ?, ?, ?, ?)',
                            ['customer', $name, $email !== '' ? $email : null, normalise_phone($phone), phone_key($phone), password_hash($password, PASSWORD_DEFAULT)]
                        );
                    }
                    return db_insert(
                        'INSERT INTO customers (user_id, name, phone, phone_key, email, address, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$userId, $name, normalise_phone($phone), phone_key($phone), $email !== '' ? $email : null, $address, (int) $user['id']]
                    );
                });
            } catch (Throwable $e) {
                $errors[] = app_form_message($e);
            }
        }

        if ($errors === []) {
            audit('customer.create', 'customer', (string) $customerId, $name);
            flash('success', 'Customer added.');
            redirect('admin/customers.php?q=' . urlencode(phone_key($phone) !== '' ? phone_key($phone) : $phone));
        }
        $showCreate = true;
    }
}

$where = ['1=1'];
$params = [];
if ($search !== '') {
    $searchKey = phone_key($search);
    $where[] = $searchKey !== ''
        ? '(c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.phone_key = ?)'
        : '(c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    if ($searchKey !== '') {
        $params[] = $searchKey;
    }
}

$customers = db_all(
    'SELECT c.*,
            (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) AS order_count,
            (SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o WHERE o.customer_id = c.id AND o.status <> \'cancelled\') AS billed,
            (SELECT COALESCE(SUM(o.total_amount - o.paid_amount), 0) FROM orders o WHERE o.customer_id = c.id AND o.payment_status <> \'paid\' AND o.status <> \'cancelled\') AS due,
            (SELECT MAX(o.order_date) FROM orders o WHERE o.customer_id = c.id) AS last_order
     FROM customers c
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY c.id DESC LIMIT 200',
    $params
);

layout_start('Customers', 'app');
?>
<div class="toolbar">
    <form method="get" class="filter-form">
        <div class="form-group">
            <label for="q">Mobile / name / email</label>
            <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Search by mobile number, name or email">
        </div>
        <div class="form-group">
            <button class="btn" type="submit">Search</button>
        </div>
        <div class="form-group">
            <a class="btn btn-ghost" href="<?= e(url('admin/customers.php')) ?>">Reset</a>
        </div>
    </form>
    <a class="btn" href="<?= e(url('admin/customers.php?new=1')) ?>">+ Add Customer</a>
</div>

<?php if ($showCreate): ?>
    <div class="panel">
        <div class="panel-head"><h2>Add Customer</h2></div>
        <div class="panel-body">
            <?php foreach ($errors as $error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endforeach; ?>
            <form method="post" action="<?= e(url('admin/customers.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_customer">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="name" class="required">Name</label>
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
                        <label for="password">Account password</label>
                        <input type="password" id="password" name="password">
                        <div class="help">Leave blank to keep it as a phone-only customer record.</div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea id="address" name="address"></textarea>
                </div>
                <div class="form-actions">
                    <button class="btn" type="submit">Add Customer</button>
                    <a class="btn btn-ghost" href="<?= e(url('admin/customers.php')) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head">
        <h2>Customers</h2>
        <span class="subtle"><?= e((string) count($customers)) ?> shown</span>
    </div>
    <div class="panel-body tight">
        <?php if ($customers === []): ?>
            <div class="empty-state"><strong>No customers found</strong>Try a different search.</div>
        <?php else: ?>
            <?php foreach ($customers as $customer): ?>
                <?php
                $phone = (string) $customer['phone'];
                $invoiceCount = (int) $customer['order_count'];
                $customerDue = (float) $customer['due'];
                $actions = '<a class="btn btn-sm btn-ghost" href="' . e(url('admin/orders.php?q=' . urlencode($phone))) . '">View orders</a>';
                if ($customerDue > 0) {
                    $actions .= '<a class="btn btn-sm" href="' . e(url('admin/dues.php?q=' . urlencode((string) $customer['name']))) . '">Collect dues</a>';
                }
                ?>
                <details class="tile tile-fold">
                    <summary class="tile-head">
                        <div class="tile-main">
                            <div class="tile-line">
                                <strong class="tile-id"><?= e((string) $customer['name']) ?></strong>
                            </div>
                            <div class="tile-cust">
                                <a class="subtle" href="tel:<?= e($phone) ?>"><?= e($phone) ?></a>
                                <span class="subtle"> · <?= e((string) $invoiceCount) ?> invoice<?= $invoiceCount === 1 ? '' : 's' ?></span>
                            </div>
                        </div>
                        <div class="tile-side">
                            <span class="tile-caret" aria-hidden="true"></span>
                        </div>
                    </summary>
                    <div class="tile-body">
                        <dl class="kv kv-compact">
                            <dt>Email</dt><dd><?= e((string) ($customer['email'] ?? '—')) ?></dd>
                            <dt>Address</dt><dd><?= e((string) ($customer['address'] ?? '—')) ?></dd>
                            <dt>Invoices</dt><dd><?= e((string) $invoiceCount) ?></dd>
                            <dt>Billed</dt><dd><?= e(money((float) $customer['billed'])) ?></dd>
                            <dt>Due</dt><dd><?= $customerDue > 0 ? e(money($customerDue)) : '—' ?></dd>
                            <dt>Last order</dt><dd><?= e(format_date($customer['last_order'])) ?></dd>
                        </dl>
                        <div class="tile-actions"><?= $actions ?></div>
                    </div>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php layout_end(); ?>
