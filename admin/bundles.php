<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';

$user = require_admin();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action');
    if ($action === 'save_bundle') {
        $name = post('name');
        $desc = post('description');
        $price = (float) post('price');
        $itemsText = post('items');
        $active = post('is_active') === '1' ? 1 : 0;
        if ($name === '') { $errors[] = 'Bundle name is required.'; }
        if ($price <= 0) { $errors[] = 'Price must be greater than 0.'; }
        if ($errors === []) {
            $bid = db_insert('INSERT INTO service_bundles (name, description, price, is_active) VALUES (?,?,?,?)', [$name, $desc, $price, $active]);
            foreach (preg_split('/\r?\n/', $itemsText) as $line) {
                $line = trim($line);
                if ($line === '') { continue; }
                $parts = array_map('trim', explode(',', $line));
                if (count($parts) < 2) { continue; }
                $sid = (int) $parts[0];
                $itemName = $parts[1];
                $qty = isset($parts[2]) ? max(1, (int) $parts[2]) : 1;
                db_insert('INSERT INTO service_bundle_items (bundle_id, service_id, item_name, qty) VALUES (?,?,?,?)', [$bid, $sid > 0 ? $sid : null, $itemName, $qty]);
            }
            flash('success', 'Bundle created.');
            redirect('admin/bundles.php');
        }
    }
    if ($action === 'toggle_bundle') {
        db_exec('UPDATE service_bundles SET is_active = 1 - is_active WHERE id = ?', [(int) post('id')]);
        flash('success', 'Bundle status updated.');
        redirect('admin/bundles.php');
    }
}

$bundles = db_all('SELECT * FROM service_bundles ORDER BY created_at DESC');
$services = db_all('SELECT id, service_name FROM services ORDER BY service_name');

layout_start('Service Bundles', 'app');
?>
<div class="app-content">
    <div class="panel">
        <div class="panel-head"><h2>Create Bundle</h2></div>
        <div class="panel-body">
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_bundle">
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" name="name" required>
                </div>
                <div class="form-group">
                    <label>Price</label>
                    <input type="number" name="price" min="0.01" step="0.01" required>
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
                <div class="form-group" style="grid-column:1/-1;">
                    <label>Items (one per line: service_id, item_name, qty)</label>
                    <textarea name="items" rows="5" placeholder="1, Shirt, 3&#10;4, Dress / Saree, 1" style="width:100%;"></textarea>
                    <p class="subtle">Service IDs: <?php foreach ($services as $s): ?><?= (int)$s['id'] ?>=<?= e((string)$s['service_name']) ?>  <?php endforeach; ?></p>
                </div>
                <div class="form-group" style="align-self:end;">
                    <button class="btn" type="submit">Create Bundle</button>
                </div>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head"><h2>Bundles</h2></div>
        <div class="panel-body tight">
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Name</th><th>Price</th><th>Status</th><th class="actions">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($bundles as $b): ?>
                        <tr>
                            <td><strong><?= e((string) $b['name']) ?></strong><br><small><?= e((string) ($b['description'] ?? '')) ?></small></td>
                            <td class="num"><?= e(money((float) $b['price'])) ?></td>
                            <td><span class="badge <?= (int)$b['is_active']===1?'badge-success':'badge-muted' ?>"><?= (int)$b['is_active']===1?'Active':'Inactive' ?></span></td>
                            <td class="actions">
                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_bundle">
                                    <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                    <button class="btn btn-sm btn-ghost" type="submit"><?= (int)$b['is_active']===1?'Disable':'Enable' ?></button>
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
