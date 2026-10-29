<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';

$user = require_admin();

if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="catalog_export.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['service_name', 'price_mode', 'category', 'service_rate', 'item_name', 'subcategory', 'item_rate']);
    $rows = db_all(
        'SELECT s.service_name, s.price_mode, s.category, s.rate AS service_rate,
                i.item_name, sc.name AS subcategory, COALESCE(NULLIF(i.rate_per_piece,0), i.rate_per_kg) AS item_rate
         FROM laundry_items i
         JOIN services s ON s.id = i.service_id
         LEFT JOIN subcategories sc ON sc.id = i.subcategory_id
         ORDER BY s.service_name, i.item_name'
    );
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['service_name'], $r['price_mode'], $r['category'], $r['service_rate'],
            $r['item_name'], $r['subcategory'], $r['item_rate']
        ]);
    }
    fclose($out);
    exit;
}

$errors = [];
$imported = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (isset($_FILES['csv']) && $_FILES['csv']['error'] === UPLOAD_ERR_OK) {
        $handle = fopen((string) $_FILES['csv']['tmp_name'], 'r');
        if ($handle) {
            $header = fgetcsv($handle);
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 7) { continue; }
                [$serviceName, $priceMode, $category, $serviceRate, $itemName, $subcategory, $itemRate] = array_map('trim', $row);
                if ($serviceName === '' || $itemName === '') { continue; }
                if (!in_array($priceMode, ['per_piece', 'per_kg', 'fixed'], true)) { $priceMode = 'per_piece'; }
                if (!in_array($category, ['piece', 'kg', 'extra'], true)) { $category = 'piece'; }

                $service = db_row('SELECT id FROM services WHERE service_name = ?', [$serviceName]);
                if ($service === null) {
                    $sid = db_insert(
                        'INSERT INTO services (service_name, price_mode, category, rate, is_active) VALUES (?,?,?,?,1)',
                        [$serviceName, $priceMode, $category, (float) $serviceRate]
                    );
                } else {
                    $sid = (int) $service['id'];
                }

                $sub = $subcategory !== '' ? db_row('SELECT id FROM subcategories WHERE name = ?', [$subcategory]) : null;
                $subId = $sub !== null ? (int) $sub['id'] : null;

                $mode = $priceMode;
                $perPiece = $mode === 'per_kg' ? 0 : (float) $itemRate;
                $perKg = $mode === 'per_kg' ? (float) $itemRate : 0;

                db_exec(
                    'INSERT INTO laundry_items (service_id, subcategory_id, item_name, service_type, price_mode, rate_per_piece, rate_per_kg, is_active)
                     VALUES (?,?,?,?,?,?,?,1)
                     ON DUPLICATE KEY UPDATE subcategory_id=VALUES(subcategory_id), price_mode=VALUES(price_mode),
                     rate_per_piece=VALUES(rate_per_piece), rate_per_kg=VALUES(rate_per_kg), is_active=1',
                    [$sid, $subId, $itemName, 'other', $mode, $perPiece, $perKg]
                );
                $imported++;
            }
            fclose($handle);
            flash('success', $imported . ' rows imported.');
            redirect('admin/bulk-catalog.php');
        } else {
            $errors[] = 'Could not read CSV file.';
        }
    } else {
        $errors[] = 'Please choose a CSV file.';
    }
}

layout_start('Bulk Catalog Import/Export', 'app');
?>
<div class="app-content">
    <?php foreach ($errors as $e): ?>
        <div class="alert alert-error"><?= e($e) ?></div>
    <?php endforeach; ?>
    <div class="panel">
        <div class="panel-head"><h2>Export</h2></div>
        <div class="panel-body">
            <p class="subtle">Download the current catalogue as CSV.</p>
            <a class="btn" href="<?= e(url('admin/bulk-catalog.php?export=1')) ?>">Download CSV</a>
        </div>
    </div>
    <div class="panel">
        <div class="panel-head"><h2>Import</h2></div>
        <div class="panel-body">
            <p class="subtle">CSV columns: service_name, price_mode, category, service_rate, item_name, subcategory, item_rate.</p>
            <form method="post" enctype="multipart/form-data" class="form-grid">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label>CSV file</label>
                    <input type="file" name="csv" accept=".csv" required>
                </div>
                <div class="form-group" style="align-self:end;">
                    <button class="btn" type="submit">Import</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
layout_end();
