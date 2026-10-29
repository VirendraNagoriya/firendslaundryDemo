<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';

$user = require_admin();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action');

    if ($action === 'save_coupon') {
        $id = (int) post('id');
        $code = strtoupper(trim(post('code')));
        $type = post('discount_type') === 'fixed' ? 'fixed' : 'percent';
        $value = (float) post('discount_value');
        $minOrder = (float) post('min_order');
        $maxUses = post('max_uses') !== '' ? (int) post('max_uses') : null;
        $active = post('is_active') === '1' ? 1 : 0;
        $expires = post('expires_at') !== '' ? post('expires_at') : null;

        if ($code === '') { $errors[] = 'Coupon code is required.'; }
        if ($value <= 0) { $errors[] = 'Discount value must be greater than 0.'; }

        if ($errors === []) {
            if ($id > 0) {
                db_exec(
                    'UPDATE coupons SET code=?, discount_type=?, discount_value=?, min_order=?, max_uses=?, is_active=?, expires_at=? WHERE id=?',
                    [$code, $type, $value, $minOrder, $maxUses, $active, $expires, $id]
                );
                flash('success', 'Coupon updated.');
            } else {
                db_insert(
                    'INSERT INTO coupons (code, discount_type, discount_value, min_order, max_uses, is_active, expires_at) VALUES (?,?,?,?,?,?,?)',
                    [$code, $type, $value, $minOrder, $maxUses, $active, $expires]
                );
                flash('success', 'Coupon created.');
            }
            redirect('admin/offers.php');
        }
    }

    if ($action === 'save_offer') {
        $id = (int) post('id');
        $title = post('title');
        $desc = post('description');
        $active = post('is_active') === '1' ? 1 : 0;
        if ($title === '') { $errors[] = 'Offer title is required.'; }
        if ($errors === []) {
            if ($id > 0) {
                db_exec('UPDATE offers SET title=?, description=?, is_active=? WHERE id=?', [$title, $desc, $active, $id]);
                flash('success', 'Offer updated.');
            } else {
                db_insert('INSERT INTO offers (title, description, is_active) VALUES (?,?,?)', [$title, $desc, $active]);
                flash('success', 'Offer created.');
            }
            redirect('admin/offers.php');
        }
    }

    if ($action === 'toggle_coupon') {
        db_exec('UPDATE coupons SET is_active = 1 - is_active WHERE id = ?', [(int) post('id')]);
        flash('success', 'Coupon status updated.');
        redirect('admin/offers.php');
    }

    if ($action === 'toggle_offer') {
        db_exec('UPDATE offers SET is_active = 1 - is_active WHERE id = ?', [(int) post('id')]);
        flash('success', 'Offer status updated.');
        redirect('admin/offers.php');
    }
}

$coupons = db_all('SELECT * FROM coupons ORDER BY created_at DESC');
$offers = db_all('SELECT * FROM offers ORDER BY created_at DESC');

layout_start('Offers & Coupons', 'app');
?>
<div class="app-content">
    <div class="panel">
        <div class="panel-head"><h2>Offers (banners)</h2></div>
        <div class="panel-body">
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_offer">
                <input type="hidden" name="id" value="0">
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" name="title" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="description">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="is_active">
                        <option value="1" selected>Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
                <div class="form-group" style="align-self:end;">
                    <button class="btn" type="submit">Add Offer</button>
                </div>
            </form>
            <div class="table-wrap mt-1">
                <table class="data">
                    <thead><tr><th>Title</th><th>Description</th><th>Status</th><th class="actions">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($offers as $o): ?>
                        <tr>
                            <td><?= e((string) $o['title']) ?></td>
                            <td><?= e((string) ($o['description'] ?? '')) ?></td>
                            <td><span class="badge <?= (int)$o['is_active']===1?'badge-success':'badge-muted' ?>"><?= (int)$o['is_active']===1?'Active':'Inactive' ?></span></td>
                            <td class="actions">
                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_offer">
                                    <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                    <button class="btn btn-sm btn-ghost" type="submit"><?= (int)$o['is_active']===1?'Disable':'Enable' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><h2>Coupons</h2></div>
        <div class="panel-body">
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_coupon">
                <input type="hidden" name="id" value="0">
                <div class="form-group">
                    <label>Code</label>
                    <input type="text" name="code" required>
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="discount_type">
                        <option value="percent">Percent %</option>
                        <option value="fixed">Fixed amount</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Value</label>
                    <input type="number" name="discount_value" min="0.01" step="0.01" required>
                </div>
                <div class="form-group">
                    <label>Min order</label>
                    <input type="number" name="min_order" min="0" step="0.01" value="0">
                </div>
                <div class="form-group">
                    <label>Max uses (blank = unlimited)</label>
                    <input type="number" name="max_uses" min="1" step="1">
                </div>
                <div class="form-group">
                    <label>Expires at</label>
                    <input type="datetime-local" name="expires_at">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="is_active">
                        <option value="1" selected>Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
                <div class="form-group" style="align-self:end;">
                    <button class="btn" type="submit">Add Coupon</button>
                </div>
            </form>
            <div class="table-wrap mt-1">
                <table class="data">
                    <thead><tr><th>Code</th><th>Type</th><th>Value</th><th>Min order</th><th>Uses</th><th>Status</th><th class="actions">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($coupons as $c): ?>
                        <tr>
                            <td><strong><?= e((string) $c['code']) ?></strong></td>
                            <td><?= e((string) $c['discount_type']) ?></td>
                            <td><?= e((string) $c['discount_value']) ?><?= $c['discount_type']==='percent'?'%':'' ?></td>
                            <td><?= e(money((float) $c['min_order'])) ?></td>
                            <td><?= (int)$c['used_count'] ?><?= $c['max_uses']!==null?' / '.(int)$c['max_uses']:'' ?></td>
                            <td><span class="badge <?= (int)$c['is_active']===1?'badge-success':'badge-muted' ?>"><?= (int)$c['is_active']===1?'Active':'Inactive' ?></span></td>
                            <td class="actions">
                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_coupon">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button class="btn btn-sm btn-ghost" type="submit"><?= (int)$c['is_active']===1?'Disable':'Enable' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php
layout_end();
