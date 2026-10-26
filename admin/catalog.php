<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';

$user = require_admin();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action');

    if ($action === 'save') {
        $itemId = (int) post('item_id');
        $name = post('item_name');
        $service = post('service_type');
        $mode = post('price_mode');
        $perPiece = (float) post('rate_per_piece');
        $perKg = (float) post('rate_per_kg');
        $active = post('is_active') === '1' ? 1 : 0;

        if ($name === '') {
            $errors[] = 'Please enter the item name.';
        }
        if (!isset(service_types()[$service])) {
            $errors[] = 'Please select a valid service type.';
        }
        if (!in_array($mode, ['per_piece', 'per_kg'], true)) {
            $errors[] = 'Please select a valid billing mode.';
        }
        if ($mode === 'per_piece' && $perPiece <= 0) {
            $errors[] = 'Enter a per-piece rate greater than zero.';
        }
        if ($mode === 'per_kg' && $perKg <= 0) {
            $errors[] = 'Enter a per-kg rate greater than zero.';
        }
        $duplicate = db_row('SELECT id FROM laundry_items WHERE item_name = ? AND id <> ?', [$name, $itemId]);
        if ($duplicate !== null) {
            $errors[] = 'An item with this name already exists.';
        }

        if ($errors === []) {
            if ($itemId > 0) {
                db_exec(
                    'UPDATE laundry_items SET item_name = ?, service_type = ?, price_mode = ?, rate_per_piece = ?, rate_per_kg = ?, is_active = ? WHERE id = ?',
                    [$name, $service, $mode, $perPiece, $perKg, $active, $itemId]
                );
                audit('catalog.update', 'laundry_item', (string) $itemId, $name);
                flash('success', 'Item updated.');
            } else {
                $newId = db_insert(
                    'INSERT INTO laundry_items (item_name, service_type, price_mode, rate_per_piece, rate_per_kg, is_active) VALUES (?, ?, ?, ?, ?, ?)',
                    [$name, $service, $mode, $perPiece, $perKg, $active]
                );
                audit('catalog.create', 'laundry_item', (string) $newId, $name);
                flash('success', 'Item added to the catalog.');
            }
            redirect('admin/catalog.php');
        }
    }

    if ($action === 'toggle') {
        $itemId = (int) post('item_id');
        db_exec('UPDATE laundry_items SET is_active = 1 - is_active WHERE id = ?', [$itemId]);
        redirect('admin/catalog.php');
    }
}

$items = db_all('SELECT * FROM laundry_items ORDER BY is_active DESC, item_name');
$editItem = null;
if (query_int('edit') > 0) {
    $editItem = db_row('SELECT * FROM laundry_items WHERE id = ?', [query_int('edit')]);
}

layout_start('Service Catalog', 'app');
?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="panel">
    <div class="panel-head">
        <h2><?= $editItem !== null ? 'Edit Item' : 'Add Catalog Item' ?></h2>
        <?php if ($editItem !== null): ?>
            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/catalog.php')) ?>">Cancel edit</a>
        <?php endif; ?>
    </div>
    <div class="panel-body">
        <form method="post" action="<?= e(url('admin/catalog.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="item_id" value="<?= e((string) ($editItem['id'] ?? 0)) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label for="item_name" class="required">Item name</label>
                    <input type="text" id="item_name" name="item_name" value="<?= e((string) ($editItem['item_name'] ?? '')) ?>" required>
                </div>
                <div class="form-group">
                    <label for="service_type" class="required">Service</label>
                    <select name="service_type" id="service_type">
                        <?php foreach (service_types() as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= ($editItem['service_type'] ?? 'wash_fold') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="price_mode" class="required">Billing mode</label>
                    <select name="price_mode" id="price_mode">
                        <option value="per_piece"<?= ($editItem['price_mode'] ?? 'per_piece') === 'per_piece' ? ' selected' : '' ?>>Per piece</option>
                        <option value="per_kg"<?= ($editItem['price_mode'] ?? '') === 'per_kg' ? ' selected' : '' ?>>Per kg</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="rate_per_piece">Rate per piece (<?= e(APP_CURRENCY) ?>)</label>
                    <input type="number" id="rate_per_piece" name="rate_per_piece" min="0" step="0.01" value="<?= e((string) ($editItem['rate_per_piece'] ?? '0')) ?>">
                </div>
                <div class="form-group">
                    <label for="rate_per_kg">Rate per kg (<?= e(APP_CURRENCY) ?>)</label>
                    <input type="number" id="rate_per_kg" name="rate_per_kg" min="0" step="0.01" value="<?= e((string) ($editItem['rate_per_kg'] ?? '0')) ?>">
                </div>
                <div class="form-group">
                    <label for="is_active">Status</label>
                    <select name="is_active" id="is_active">
                        <option value="1"<?= (int) ($editItem['is_active'] ?? 1) === 1 ? ' selected' : '' ?>>Active</option>
                        <option value="0"<?= isset($editItem) && (int) $editItem['is_active'] === 0 ? ' selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn" type="submit"><?= $editItem !== null ? 'Update Item' : 'Add Item' ?></button>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h2>Catalog</h2><span class="subtle"><?= e((string) count($items)) ?> items</span></div>
    <div class="panel-body tight">
        <?php if ($items === []): ?>
            <div class="empty-state"><strong>No catalog items</strong>Add your laundry items and rates above.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Service</th>
                            <th>Billing</th>
                            <th class="num">Rate per piece</th>
                            <th class="num">Rate per kg</th>
                            <th>Status</th>
                            <th class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><strong><?= e($item['item_name']) ?></strong></td>
                            <td><?= e(service_type_label((string) $item['service_type'])) ?></td>
                            <td><?= $item['price_mode'] === 'per_kg' ? 'Per kg' : 'Per piece' ?></td>
                            <td class="num"><?= e(money((float) $item['rate_per_piece'])) ?></td>
                            <td class="num"><?= e(money((float) $item['rate_per_kg'])) ?></td>
                            <td><span class="badge <?= (int) $item['is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>"><?= (int) $item['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                            <td class="actions">
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/catalog.php?edit=' . (int) $item['id'])) ?>">Edit</a>
                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="item_id" value="<?= e((string) $item['id']) ?>">
                                    <button class="btn btn-sm btn-ghost" type="submit"><?= (int) $item['is_active'] === 1 ? 'Disable' : 'Enable' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php layout_end(); ?>
