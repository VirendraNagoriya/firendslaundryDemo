<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';

$user = require_admin();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action');

    if ($action === 'save_service') {
        $serviceId = (int) post('service_id');
        $name = post('service_name');
        $mode = post('price_mode');
        $rate = (float) post('rate');
        $minWeight = (float) post('min_weight_kg');
        $active = post('is_active') === '1' ? 1 : 0;

        if ($name === '') {
            $errors[] = 'Please enter the service name.';
        }
        if (!isset(service_modes()[$mode])) {
            $errors[] = 'Please select a valid billing mode.';
        }
        if ($rate <= 0) {
            $errors[] = 'Enter a rate greater than zero.';
        }
        if ($minWeight < 0) {
            $errors[] = 'Minimum weight cannot be negative.';
        }
        $duplicate = db_row('SELECT id FROM services WHERE service_name = ? AND id <> ?', [$name, $serviceId]);
        if ($duplicate !== null) {
            $errors[] = 'A service with this name already exists.';
        }

        if ($errors === []) {
            if ($serviceId > 0) {
                db_exec(
                    'UPDATE services SET service_name = ?, price_mode = ?, rate = ?, min_weight_kg = ?, is_active = ? WHERE id = ?',
                    [$name, $mode, $rate, $minWeight, $active, $serviceId]
                );
                audit('service.update', 'service', (string) $serviceId, $name);
                flash('success', 'Service updated.');
            } else {
                $newId = db_insert(
                    'INSERT INTO services (service_name, price_mode, rate, min_weight_kg, is_active) VALUES (?, ?, ?, ?, ?)',
                    [$name, $mode, $rate, $minWeight, $active]
                );
                audit('service.create', 'service', (string) $newId, $name);
                flash('success', 'Service created.');
            }
            redirect('admin/catalog.php');
        }
    }

    if ($action === 'toggle_service') {
        $serviceId = (int) post('service_id');
        db_exec('UPDATE services SET is_active = 1 - is_active WHERE id = ?', [$serviceId]);
        audit('service.toggle', 'service', (string) $serviceId);
        flash('success', 'Service status updated.');
        redirect('admin/catalog.php');
    }

    if ($action === 'save_item') {
        $itemId = (int) post('item_id');
        $name = post('item_name');
        $serviceId = (int) post('service_id');
        $override = (float) post('rate_override');
        $active = post('is_active') === '1' ? 1 : 0;
        $service = $serviceId > 0 ? db_row('SELECT * FROM services WHERE id = ?', [$serviceId]) : null;

        if ($name === '') {
            $errors[] = 'Please enter the item name.';
        }
        if ($service === null) {
            $errors[] = 'Please select a valid service.';
        }
        if ($override < 0) {
            $errors[] = 'The rate override cannot be negative.';
        }
        if ($service !== null) {
            $duplicate = db_row(
                'SELECT id FROM laundry_items WHERE service_id = ? AND item_name = ? AND id <> ?',
                [$serviceId, $name, $itemId]
            );
            if ($duplicate !== null) {
                $errors[] = 'This service already has an item with that name.';
            }
        }

        if ($errors === []) {
            $mode = (string) $service['price_mode'] === 'per_kg' ? 'per_kg' : 'per_piece';
            $perPiece = $mode === 'per_piece' ? $override : 0;
            $perKg = $mode === 'per_kg' ? $override : 0;
            $serviceType = service_type_for_name((string) $service['service_name']);

            if ($itemId > 0) {
                db_exec(
                    'UPDATE laundry_items SET service_id = ?, item_name = ?, service_type = ?, price_mode = ?,
                     rate_per_piece = ?, rate_per_kg = ?, is_active = ? WHERE id = ?',
                    [$serviceId, $name, $serviceType, $mode, $perPiece, $perKg, $active, $itemId]
                );
                audit('catalog.update', 'laundry_item', (string) $itemId, $name);
                flash('success', 'Item updated.');
            } else {
                $newId = db_insert(
                    'INSERT INTO laundry_items (service_id, item_name, service_type, price_mode, rate_per_piece, rate_per_kg, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$serviceId, $name, $serviceType, $mode, $perPiece, $perKg, $active]
                );
                audit('catalog.create', 'laundry_item', (string) $newId, $name);
                flash('success', 'Item added to the service.');
            }
            redirect('admin/catalog.php');
        }
    }

    if ($action === 'toggle_item') {
        $itemId = (int) post('item_id');
        db_exec('UPDATE laundry_items SET is_active = 1 - is_active WHERE id = ?', [$itemId]);
        audit('catalog.toggle', 'laundry_item', (string) $itemId);
        flash('success', 'Item status updated.');
        redirect('admin/catalog.php');
    }
}

$services = all_services();
$items = db_all(
    'SELECT i.*, s.service_name, s.price_mode AS service_price_mode, s.rate AS service_rate
     FROM laundry_items i
     LEFT JOIN services s ON s.id = i.service_id
     ORDER BY s.service_name, i.is_active DESC, i.item_name'
);

$editService = null;
if (query_int('edit_service') > 0) {
    $editService = db_row('SELECT * FROM services WHERE id = ?', [query_int('edit_service')]);
}
$editItem = null;
if (query_int('edit') > 0) {
    $editItem = db_row('SELECT * FROM laundry_items WHERE id = ?', [query_int('edit')]);
}

layout_start('Services & Items', 'app');
?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="panel">
    <div class="panel-head">
        <h2><?= $editService !== null ? 'Edit Service' : 'Add Service' ?></h2>
        <?php if ($editService !== null): ?>
            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/catalog.php')) ?>">Cancel edit</a>
        <?php endif; ?>
    </div>
    <div class="panel-body">
        <form method="post" action="<?= e(url('admin/catalog.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_service">
            <input type="hidden" name="service_id" value="<?= e((string) ($editService['id'] ?? 0)) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label for="service_name" class="required">Service name</label>
                    <input type="text" id="service_name" name="service_name" value="<?= e((string) ($editService['service_name'] ?? '')) ?>" placeholder="e.g. Wash & Fold, Premium Dry Clean" required>
                    <p class="subtle">A descriptive name for your service (e.g., "Standard Wash & Fold", "Premium Dry Clean")</p>
                </div>
                <div class="form-group">
                    <label for="price_mode" class="required">Billed by</label>
                    <select name="price_mode" id="price_mode">
                        <?php foreach (service_modes() as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= ($editService['price_mode'] ?? 'per_piece') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="subtle">Choose how this service will be billed - per piece or per kilogram</p>
                </div>
                <div class="form-group">
                    <label for="rate" class="required">Rate (<?= e(APP_CURRENCY) ?>)</label>
                    <input type="number" id="rate" name="rate" min="0.01" step="0.01" value="<?= e((string) ($editService['rate'] ?? '0')) ?>" required>
                    <p class="subtle">Base rate for this service. For per-kg services, this is the rate per kilogram.</p>
                </div>
                <?php if (($editService['price_mode'] ?? 'per_piece') === 'per_kg'): ?>
                <div class="form-group">
                    <label for="min_weight_kg">Minimum weight (kg)</label>
                    <input type="number" id="min_weight_kg" name="min_weight_kg" min="0" step="0.5" value="<?= e((string) ($editService['min_weight_kg'] ?? '0')) ?>">
                    <p class="subtle">Minimum weight required for this service. Orders below this weight will be charged for the minimum.</p>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label for="service_active">Status</label>
                    <select name="is_active" id="service_active">
                        <option value="1"<?= (int) ($editService['is_active'] ?? 1) === 1 ? ' selected' : '' ?>>Active</option>
                        <option value="0"<?= $editService !== null && (int) $editService['is_active'] === 0 ? ' selected' : '' ?>>Inactive</option>
                    </select>
                    <p class="subtle">Inactive services will not appear in customer or staff interfaces</p>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn" type="submit"><?= $editService !== null ? 'Update Service' : 'Add Service' ?></button>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h2>Services</h2><span class="subtle"><?= e((string) count($services)) ?> services</span></div>
    <div class="panel-body tight">
        <?php if ($services === []): ?>
            <div class="empty-state"><strong>No services yet</strong>Create a service above, then map items to it.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Service</th>
                            <th>Billed by</th>
                            <th class="num">Rate</th>
                            <th>Status</th>
                            <th class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($services as $service): ?>
                        <tr>
                            <td><strong><?= e((string) $service['service_name']) ?></strong></td>
                            <td><?= e(service_mode_label((string) $service['price_mode'])) ?></td>
                            <td class="num"><?= e(money((float) $service['rate'])) ?><?= $service['price_mode'] === 'per_kg' ? ' / kg' : ' / pc' ?></td>
                            <td><span class="badge <?= (int) $service['is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>"><?= (int) $service['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                            <td class="actions">
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/catalog.php?edit_service=' . (int) $service['id'])) ?>">Edit</a>
                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_service">
                                    <input type="hidden" name="service_id" value="<?= e((string) $service['id']) ?>">
                                    <button class="btn btn-sm btn-ghost" type="submit"><?= (int) $service['is_active'] === 1 ? 'Disable' : 'Enable' ?></button>
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

<div class="panel">
    <div class="panel-head">
        <h2><?= $editItem !== null ? 'Edit Item' : 'Add Item' ?></h2>
        <?php if ($editItem !== null): ?>
            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/catalog.php')) ?>">Cancel edit</a>
        <?php endif; ?>
    </div>
    <div class="panel-body">
        <?php if ($services === []): ?>
            <div class="empty-state"><strong>Add a service first</strong>Every item belongs to a service.</div>
        <?php else: ?>
            <form method="post" action="<?= e(url('admin/catalog.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_item">
                <input type="hidden" name="item_id" value="<?= e((string) ($editItem['id'] ?? 0)) ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="item_name" class="required">Item name</label>
                        <input type="text" id="item_name" name="item_name" value="<?= e((string) ($editItem['item_name'] ?? '')) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="service_id" class="required">Service</label>
                        <select name="service_id" id="service_id">
                            <?php foreach ($services as $service): ?>
                                <option value="<?= (int) $service['id'] ?>"<?= (int) ($editItem['service_id'] ?? 0) === (int) $service['id'] ? ' selected' : '' ?>>
                                    <?= e((string) $service['service_name']) ?> (<?= e(service_mode_label((string) $service['price_mode'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="rate_override">Rate override (<?= e(APP_CURRENCY) ?>)</label>
                        <input type="number" id="rate_override" name="rate_override" min="0" step="0.01" value="<?= e((string) ($editItem !== null ? service_effective_rate(['price_mode' => $editItem['price_mode'], 'rate' => 0], $editItem) : '0')) ?>">
                        <span class="subtle">Leave 0 to use the service rate.</span>
                    </div>
                    <div class="form-group">
                        <label for="item_active">Status</label>
                        <select name="is_active" id="item_active">
                            <option value="1"<?= (int) ($editItem['is_active'] ?? 1) === 1 ? ' selected' : '' ?>>Active</option>
                            <option value="0"<?= $editItem !== null && (int) $editItem['is_active'] === 0 ? ' selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn" type="submit"><?= $editItem !== null ? 'Update Item' : 'Add Item' ?></button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h2>Items</h2><span class="subtle"><?= e((string) count($items)) ?> items</span></div>
    <div class="panel-body tight">
        <?php if ($items === []): ?>
            <div class="empty-state"><strong>No items yet</strong>Add items and map them to a service.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Service</th>
                            <th>Billing</th>
                            <th class="num">Rate</th>
                            <th>Status</th>
                            <th class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $serviceRow = [
                            'price_mode' => (string) ($item['service_price_mode'] ?? $item['price_mode']),
                            'rate' => (float) ($item['service_rate'] ?? 0),
                        ];
                        $rate = service_effective_rate($serviceRow, $item);
                        ?>
                        <tr>
                            <td><strong><?= e((string) $item['item_name']) ?></strong></td>
                            <td><?= e((string) ($item['service_name'] ?? service_type_label((string) $item['service_type']))) ?></td>
                            <td><?= e(service_mode_label((string) ($item['service_price_mode'] ?? $item['price_mode']))) ?></td>
                            <td class="num"><?= e(money($rate)) ?><?= ($item['service_price_mode'] ?? $item['price_mode']) === 'per_kg' ? ' / kg' : ' / pc' ?></td>
                            <td><span class="badge <?= (int) $item['is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>"><?= (int) $item['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                            <td class="actions">
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/catalog.php?edit=' . (int) $item['id'])) ?>">Edit</a>
                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_item">
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
