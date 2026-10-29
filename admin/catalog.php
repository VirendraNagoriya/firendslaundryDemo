<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';

$user = require_admin();
ensure_service_category_schema();
ensure_subcategory_schema();
$errors = [];
$categories = service_categories();
$tab = query('tab', 'piece');
if (!isset($categories[$tab])) {
    $tab = 'piece';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post('action');
    $postedTab = post('tab', 'piece');
    if (isset($categories[$postedTab])) {
        $tab = $postedTab;
    }

    if ($action === 'save_service') {
        $serviceId = (int) post('service_id');
        $name = post('service_name');
        $category = post('category', $tab);
        if (!isset($categories[$category])) {
            $category = $tab;
        }
        $mode = service_mode_for_category($category, post('price_metric'));
        $rate = (float) post('rate');
        $minWeight = $category === 'kg' ? (float) post('min_weight_kg') : 0.0;
        $active = post('is_active') === '1' ? 1 : 0;

        if ($name === '') {
            $errors[] = 'Please enter the service name.';
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
                    'UPDATE services SET service_name = ?, price_mode = ?, category = ?, rate = ?, min_weight_kg = ?, is_active = ? WHERE id = ?',
                    [$name, $mode, $category, $rate, $minWeight, $active, $serviceId]
                );
                audit('service.update', 'service', (string) $serviceId, $name);
                flash('success', 'Service updated.');
            } else {
                $newId = db_insert(
                    'INSERT INTO services (service_name, price_mode, category, rate, min_weight_kg, is_active) VALUES (?, ?, ?, ?, ?, ?)',
                    [$name, $mode, $category, $rate, $minWeight, $active]
                );
                audit('service.create', 'service', (string) $newId, $name);
                flash('success', 'Service created.');
            }
            redirect('admin/catalog.php?tab=' . urlencode($category));
        }
    }

    if ($action === 'toggle_service') {
        $serviceId = (int) post('service_id');
        $serviceRow = db_row('SELECT * FROM services WHERE id = ?', [$serviceId]);
        db_exec('UPDATE services SET is_active = 1 - is_active WHERE id = ?', [$serviceId]);
        audit('service.toggle', 'service', (string) $serviceId);
        flash('success', 'Service status updated.');
        redirect('admin/catalog.php?tab=' . urlencode(service_category_for($serviceRow ?? [])));
    }

    if ($action === 'save_subcategory') {
        $subcategoryId = (int) post('subcategory_id');
        $name = post('subcategory_name');
        $sort = (int) post('sort_order');
        $active = post('is_active') === '1' ? 1 : 0;

        if ($name === '') {
            $errors[] = 'Please enter the subcategory name.';
        }
        $slug = subcategory_slug($name);
        $duplicate = db_row(
            'SELECT id FROM subcategories WHERE slug = ? AND id <> ?',
            [$slug, $subcategoryId]
        );
        if ($duplicate !== null) {
            $errors[] = 'A subcategory with this name already exists.';
        }

        if ($errors === []) {
            if ($subcategoryId > 0) {
                db_exec(
                    'UPDATE subcategories SET name = ?, slug = ?, sort_order = ?, is_active = ? WHERE id = ?',
                    [$name, $slug, $sort, $active, $subcategoryId]
                );
                audit('subcategory.update', 'subcategory', (string) $subcategoryId, $name);
                flash('success', 'Subcategory updated.');
            } else {
                $newId = db_insert(
                    'INSERT INTO subcategories (name, slug, sort_order, is_active) VALUES (?, ?, ?, ?)',
                    [$name, $slug, $sort, $active]
                );
                audit('subcategory.create', 'subcategory', (string) $newId, $name);
                flash('success', 'Subcategory created.');
            }
            redirect('admin/catalog.php?tab=' . urlencode($tab));
        }
    }

    if ($action === 'toggle_subcategory') {
        $subcategoryId = (int) post('subcategory_id');
        db_exec('UPDATE subcategories SET is_active = 1 - is_active WHERE id = ?', [$subcategoryId]);
        audit('subcategory.toggle', 'subcategory', (string) $subcategoryId);
        flash('success', 'Subcategory status updated.');
        redirect('admin/catalog.php?tab=' . urlencode($tab));
    }

    if ($action === 'save_item') {
        $itemId = (int) post('item_id');
        $name = post('item_name');
        $serviceId = (int) post('service_id');
        $subcategoryId = (int) post('subcategory_id');
        $override = (float) post('rate_override');
        $active = post('is_active') === '1' ? 1 : 0;
        $service = $serviceId > 0 ? db_row('SELECT * FROM services WHERE id = ?', [$serviceId]) : null;

        if ($service !== null && isset($categories[service_category_for($service)])) {
            $tab = service_category_for($service);
        }

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
            $mode = in_array((string) $service['price_mode'], ['per_kg', 'fixed'], true)
                ? (string) $service['price_mode']
                : 'per_piece';
            $perPiece = $mode === 'per_kg' ? 0 : $override;
            $perKg = $mode === 'per_kg' ? $override : 0;
            $serviceType = service_type_for_name((string) $service['service_name']);
            $subcategoryValue = $subcategoryId > 0 && subcategory_by_id($subcategoryId) !== null ? $subcategoryId : null;

            if ($itemId > 0) {
                db_exec(
                    'UPDATE laundry_items SET service_id = ?, subcategory_id = ?, item_name = ?, service_type = ?, price_mode = ?,
                     rate_per_piece = ?, rate_per_kg = ?, is_active = ? WHERE id = ?',
                    [$serviceId, $subcategoryValue, $name, $serviceType, $mode, $perPiece, $perKg, $active, $itemId]
                );
                audit('catalog.update', 'laundry_item', (string) $itemId, $name);
                flash('success', 'Item updated.');
            } else {
                $newId = db_insert(
                    'INSERT INTO laundry_items (service_id, subcategory_id, item_name, service_type, price_mode, rate_per_piece, rate_per_kg, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$serviceId, $subcategoryValue, $name, $serviceType, $mode, $perPiece, $perKg, $active]
                );
                audit('catalog.create', 'laundry_item', (string) $newId, $name);
                flash('success', 'Item added to the service.');
            }
            redirect('admin/catalog.php?tab=' . urlencode($tab));
        }
    }

    if ($action === 'toggle_item') {
        $itemId = (int) post('item_id');
        $itemRow = db_row(
            'SELECT i.*, s.category AS service_category FROM laundry_items i LEFT JOIN services s ON s.id = i.service_id WHERE i.id = ?',
            [$itemId]
        );
        db_exec('UPDATE laundry_items SET is_active = 1 - is_active WHERE id = ?', [$itemId]);
        audit('catalog.toggle', 'laundry_item', (string) $itemId);
        flash('success', 'Item status updated.');
        redirect('admin/catalog.php?tab=' . urlencode(service_category_for($itemRow ?? [])));
    }
}

$services = all_services();
$subcategories = all_subcategories();
$items = db_all(
    'SELECT i.*, s.service_name, s.price_mode AS service_price_mode, s.rate AS service_rate,
            s.category AS service_category, sc.name AS subcategory_name
     FROM laundry_items i
     LEFT JOIN services s ON s.id = i.service_id
     LEFT JOIN subcategories sc ON sc.id = i.subcategory_id
     ORDER BY FIELD(s.category, \'piece\', \'kg\', \'extra\'), s.service_name, sc.sort_order, i.is_active DESC, i.item_name'
);

$servicesByCategory = [];
foreach ($services as $service) {
    $servicesByCategory[service_category_for($service)][] = $service;
}
$itemsByCategory = [];
foreach ($items as $item) {
    $itemsByCategory[service_category_for($item)][] = $item;
}

$editService = null;
if (query_int('edit_service') > 0) {
    $editService = db_row('SELECT * FROM services WHERE id = ?', [query_int('edit_service')]);
    if ($editService !== null) {
        $tab = service_category_for($editService);
    }
}
$editItem = null;
if (query_int('edit') > 0) {
    $editItem = db_row('SELECT * FROM laundry_items WHERE id = ?', [query_int('edit')]);
    if ($editItem !== null) {
        $itemService = $editItem['service_id'] !== null
            ? db_row('SELECT * FROM services WHERE id = ?', [(int) $editItem['service_id']])
            : null;
        $tab = service_category_for($itemService ?? $editItem);
    }
}
$editSubcategory = null;
if (query_int('edit_subcategory') > 0) {
    $editSubcategory = db_row('SELECT * FROM subcategories WHERE id = ?', [query_int('edit_subcategory')]);
}

$tabLabel = service_category_label($tab);
$tabIntro = [
    'piece' => 'Items billed per piece, such as shirts, trousers and sarees.',
    'kg' => 'Items billed by weight. The pickup boy verifies the actual kg.',
    'extra' => 'Add-ons billed per unit, such as express delivery, stain removal or extra detergent.',
][$tab];
$tabServices = $servicesByCategory[$tab] ?? [];
$tabItems = $itemsByCategory[$tab] ?? [];

layout_start('Services & Items', 'app');
?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endforeach; ?>

<nav class="tabs" aria-label="Catalogue type">
    <?php foreach ($categories as $key => $label): ?>
        <a class="<?= $key === $tab ? 'active' : '' ?>" href="<?= e(url('admin/catalog.php?tab=' . urlencode($key))) ?>">
            <?= e($label) ?>
            <span class="badge badge-muted"><?= e((string) count($servicesByCategory[$key] ?? [])) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<p class="subtle" style="margin-top:-.6rem;"><?= e($tabIntro) ?></p>

<div class="panel">
    <div class="panel-head">
        <h2><?= $editSubcategory !== null ? 'Edit Subcategory' : 'Add Subcategory' ?></h2>
        <?php if ($editSubcategory !== null): ?>
            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/catalog.php?tab=' . urlencode($tab))) ?>">Cancel edit</a>
        <?php endif; ?>
    </div>
    <div class="panel-body">
        <form method="post" action="<?= e(url('admin/catalog.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_subcategory">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <input type="hidden" name="subcategory_id" value="<?= e((string) ($editSubcategory['id'] ?? 0)) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label for="subcategory_name" class="required">Subcategory</label>
                    <input type="text" id="subcategory_name" name="subcategory_name" value="<?= e((string) ($editSubcategory['name'] ?? '')) ?>" placeholder="e.g. Men, Women, Kids, Household, Accessories" required>
                    <p class="subtle">Groups articles on the order screen. Keep it broad, e.g. Men or Household.</p>
                </div>
                <div class="form-group">
                    <label for="sort_order">Display order</label>
                    <input type="number" id="sort_order" name="sort_order" step="1" value="<?= e((string) ($editSubcategory['sort_order'] ?? 0)) ?>">
                    <p class="subtle">Lower numbers appear first.</p>
                </div>
                <div class="form-group">
                    <label for="subcategory_active">Status</label>
                    <select name="is_active" id="subcategory_active">
                        <option value="1"<?= (int) ($editSubcategory['is_active'] ?? 1) === 1 ? ' selected' : '' ?>>Active</option>
                        <option value="0"<?= $editSubcategory !== null && (int) $editSubcategory['is_active'] === 0 ? ' selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn" type="submit"><?= $editSubcategory !== null ? 'Update Subcategory' : 'Add Subcategory' ?></button>
            </div>
        </form>
    </div>
    <div class="panel-body tight">
        <?php if ($subcategories === []): ?>
            <div class="empty-state"><strong>No subcategories yet</strong>Add Men, Women, Kids, Household and Accessories to start.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr><th>Subcategory</th><th>Articles</th><th>Status</th><th class="actions">Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php
                    $subcategoryCounts = [];
                    foreach ($items as $itemRow) {
                        $sid = (int) ($itemRow['subcategory_id'] ?? 0);
                        $subcategoryCounts[$sid] = ($subcategoryCounts[$sid] ?? 0) + 1;
                    }
                    ?>
                    <?php foreach ($subcategories as $subcategory): ?>
                        <tr>
                            <td><strong><?= e((string) $subcategory['name']) ?></strong></td>
                            <td><?= e((string) ($subcategoryCounts[(int) $subcategory['id']] ?? 0)) ?></td>
                            <td><span class="badge <?= (int) $subcategory['is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>"><?= (int) $subcategory['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                            <td class="actions">
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/catalog.php?tab=' . urlencode($tab) . '&edit_subcategory=' . (int) $subcategory['id'])) ?>">Edit</a>
                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_subcategory">
                                    <input type="hidden" name="tab" value="<?= e($tab) ?>">
                                    <input type="hidden" name="subcategory_id" value="<?= e((string) $subcategory['id']) ?>">
                                    <button class="btn btn-sm btn-ghost" type="submit"><?= (int) $subcategory['is_active'] === 1 ? 'Disable' : 'Enable' ?></button>
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
        <h2><?= $editService !== null ? 'Edit ' . e($tabLabel) . ' Service' : 'Add ' . e($tabLabel) . ' Service' ?></h2>
        <?php if ($editService !== null): ?>
            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/catalog.php?tab=' . urlencode($tab))) ?>">Cancel edit</a>
        <?php endif; ?>
    </div>
    <div class="panel-body">
        <form method="post" action="<?= e(url('admin/catalog.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_service">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <input type="hidden" name="category" value="<?= e($tab) ?>">
            <input type="hidden" name="service_id" value="<?= e((string) ($editService['id'] ?? 0)) ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label for="service_name" class="required">Service name</label>
                    <input type="text" id="service_name" name="service_name" value="<?= e((string) ($editService['service_name'] ?? '')) ?>"
                           placeholder="<?= e($tab === 'kg' ? 'e.g. Wash & Fold (kg)' : ($tab === 'extra' ? 'e.g. Express & Add-ons' : 'e.g. Wash & Iron')) ?>" required>
                    <p class="subtle">Shown to customers and staff when they add an item.</p>
                </div>
                <div class="form-group">
                    <label for="price_metric" class="required">Pricing metric</label>
                    <?php if ($tab === 'kg'): ?>
                        <input type="text" value="Per kg (weight × rate)" readonly>
                    <?php else: ?>
                        <select name="price_metric" id="price_metric">
                            <option value="per_piece"<?= (string) ($editService['price_mode'] ?? 'per_piece') !== 'fixed' ? ' selected' : '' ?>>Per piece (qty × rate)</option>
                            <option value="fixed"<?= (string) ($editService['price_mode'] ?? '') === 'fixed' ? ' selected' : '' ?>>Fixed rate (qty × fixed base rate)</option>
                        </select>
                    <?php endif; ?>
                    <p class="subtle"><?= $tab === 'kg' ? 'Weight is entered at booking and verified at pickup. Pieces are counted for inventory only.' : ($tab === 'extra' ? 'Extras are charged as quantity x rate. Use fixed for flat-price add-ons.' : 'Charged as quantity x rate. Use fixed for specialty articles such as helmets.') ?></p>
                </div>
                <div class="form-group">
                    <label for="rate" class="required">Rate (<?= e(APP_CURRENCY) ?>)</label>
                    <input type="number" id="rate" name="rate" min="0.01" step="0.01" value="<?= e((string) ($editService['rate'] ?? '0')) ?>" required>
                    <p class="subtle">Base rate for this service<?= $tab === 'kg' ? ' per kilogram' : ' per unit' ?>.</p>
                </div>
                <?php if ($tab === 'kg'): ?>
                    <div class="form-group">
                        <label for="min_weight_kg">Minimum weight (kg)</label>
                        <input type="number" id="min_weight_kg" name="min_weight_kg" min="0" step="0.5" value="<?= e((string) ($editService['min_weight_kg'] ?? '0')) ?>">
                        <p class="subtle">Orders below this weight are charged for the minimum.</p>
                    </div>
                <?php endif; ?>
                <div class="form-group">
                    <label for="service_active">Status</label>
                    <select name="is_active" id="service_active">
                        <option value="1"<?= (int) ($editService['is_active'] ?? 1) === 1 ? ' selected' : '' ?>>Active</option>
                        <option value="0"<?= $editService !== null && (int) $editService['is_active'] === 0 ? ' selected' : '' ?>>Inactive</option>
                    </select>
                    <p class="subtle">Inactive services will not appear in customer or staff interfaces.</p>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn" type="submit"><?= $editService !== null ? 'Update Service' : 'Add Service' ?></button>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h2><?= e($tabLabel) ?> Services</h2><span class="subtle"><?= e((string) count($tabServices)) ?> services</span></div>
    <div class="panel-body tight">
        <?php if ($tabServices === []): ?>
            <div class="empty-state"><strong>No <?= e(strtolower($tabLabel)) ?> services yet</strong>Create one above, then map items to it.</div>
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
                    <?php foreach ($tabServices as $service): ?>
                        <tr>
                            <td><strong><?= e((string) $service['service_name']) ?></strong></td>
                            <td><?= e(service_mode_label((string) $service['price_mode'])) ?></td>
                            <td class="num"><?= e(money((float) $service['rate'])) ?><?= $service['price_mode'] === 'per_kg' ? ' / kg' : ($service['price_mode'] === 'fixed' ? ' fixed' : ' / pc') ?></td>
                            <td><span class="badge <?= (int) $service['is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>"><?= (int) $service['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                            <td class="actions">
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/catalog.php?tab=' . urlencode($tab) . '&edit_service=' . (int) $service['id'])) ?>">Edit</a>
                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_service">
                                    <input type="hidden" name="tab" value="<?= e($tab) ?>">
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
        <h2><?= $editItem !== null ? 'Edit ' . e($tabLabel) . ' Item' : 'Add ' . e($tabLabel) . ' Item' ?></h2>
        <?php if ($editItem !== null): ?>
            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/catalog.php?tab=' . urlencode($tab))) ?>">Cancel edit</a>
        <?php endif; ?>
    </div>
    <div class="panel-body">
        <?php if ($tabServices === []): ?>
            <div class="empty-state"><strong>Add a service first</strong>Every item belongs to a service in this tab.</div>
        <?php else: ?>
            <form method="post" action="<?= e(url('admin/catalog.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_item">
                <input type="hidden" name="tab" value="<?= e($tab) ?>">
                <input type="hidden" name="item_id" value="<?= e((string) ($editItem['id'] ?? 0)) ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="item_name" class="required">Item name</label>
                        <input type="text" id="item_name" name="item_name" value="<?= e((string) ($editItem['item_name'] ?? '')) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="service_id" class="required">Service</label>
                        <select name="service_id" id="service_id">
                            <?php foreach ($tabServices as $service): ?>
                                <option value="<?= (int) $service['id'] ?>"<?= (int) ($editItem['service_id'] ?? 0) === (int) $service['id'] ? ' selected' : '' ?>>
                                    <?= e((string) $service['service_name']) ?> (<?= e(service_mode_label((string) $service['price_mode'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="subcategory_id">Subcategory</label>
                        <select name="subcategory_id" id="subcategory_id">
                            <option value="0">Unassigned</option>
                            <?php foreach ($subcategories as $subcategory): ?>
                                <option value="<?= (int) $subcategory['id'] ?>"<?= (int) ($editItem['subcategory_id'] ?? 0) === (int) $subcategory['id'] ? ' selected' : '' ?>>
                                    <?= e((string) $subcategory['name']) ?><?= (int) $subcategory['is_active'] === 1 ? '' : ' (inactive)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="subtle">The article sits under this subcategory on the order screen.</span>
                    </div>
                    <div class="form-group">
                        <label for="rate_override"><?= $tab === 'extra' ? 'Price' : 'Rate override' ?> (<?= e(APP_CURRENCY) ?>)</label>
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
    <div class="panel-head"><h2><?= e($tabLabel) ?> Items</h2><span class="subtle"><?= e((string) count($tabItems)) ?> items</span></div>
    <div class="panel-body tight">
        <?php if ($tabItems === []): ?>
            <div class="empty-state"><strong>No items in this tab yet</strong>Add items and map them to a service.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Article</th>
                            <th>Subcategory</th>
                            <th>Service</th>
                            <th>Billing</th>
                            <th class="num">Rate</th>
                            <th>Status</th>
                            <th class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tabItems as $item): ?>
                        <?php
                        $serviceRow = [
                            'price_mode' => (string) ($item['service_price_mode'] ?? $item['price_mode']),
                            'rate' => (float) ($item['service_rate'] ?? 0),
                        ];
                        $rate = service_effective_rate($serviceRow, $item);
                        $itemMode = (string) ($item['service_price_mode'] ?? $item['price_mode']);
                        ?>
                        <tr>
                            <td><strong><?= e((string) $item['item_name']) ?></strong></td>
                            <td><?= e((string) ($item['subcategory_name'] ?? 'Unassigned')) ?></td>
                            <td><?= e((string) ($item['service_name'] ?? service_type_label((string) $item['service_type']))) ?></td>
                            <td><?= e(service_mode_label($itemMode)) ?></td>
                            <td class="num"><?= e(money($rate)) ?><?= $itemMode === 'per_kg' ? ' / kg' : ($itemMode === 'fixed' ? ' fixed' : ' / pc') ?></td>
                            <td><span class="badge <?= (int) $item['is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>"><?= (int) $item['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                            <td class="actions">
                                <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/catalog.php?tab=' . urlencode($tab) . '&edit=' . (int) $item['id'])) ?>">Edit</a>
                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_item">
                                    <input type="hidden" name="tab" value="<?= e($tab) ?>">
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
