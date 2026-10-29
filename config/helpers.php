<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    if ($path === '') {
        return BASE_URL === '' ? '/' : BASE_URL;
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return BASE_URL . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $suffix = '';
    if (strpos($path, '?') === false && preg_match('/\.(js|css)$/', $path) === 1) {
        $suffix = '?v=' . APP_ASSET_VERSION;
    }
    return url($path) . $suffix;
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function raw_post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? $value : $default;
}

function query(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function query_int(string $key, int $default = 0): int
{
    $value = $_GET[$key] ?? null;
    return is_numeric($value) ? (int) $value : $default;
}

function csrf_token(): string
{
    if (empty($_SESSION[CSRF_SESSION_KEY])) {
        $_SESSION[CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_SESSION_KEY];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_check(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token) && $token !== '' && hash_equals(csrf_token(), $token);
}

function require_csrf(): void
{
    if (!csrf_check()) {
        flash('error', 'Your session token expired. Please try again.');
        redirect('index.php');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $flashes;
}

function money(float $amount): string
{
    return APP_CURRENCY . ' ' . number_format($amount, 2);
}

function order_statuses(): array
{
    return [
        'new' => 'New',
        'pickup_assigned' => 'Pickup Assigned',
        'picked_up' => 'Picked Up',
        'processing' => 'Processing',
        'ready_for_delivery' => 'Ready for Delivery',
        'out_for_delivery' => 'Out for Delivery',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ];
}

function order_status_label(string $status): string
{
    $all = order_statuses();
    return $all[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function payment_statuses(): array
{
    return [
        'pending' => 'Pending',
        'partial' => 'Partially Paid',
        'paid' => 'Paid',
    ];
}

function payment_status_label(string $status): string
{
    $all = payment_statuses();
    return $all[$status] ?? ucfirst($status);
}

function service_types(): array
{
    return [
        'wash_fold' => 'Wash & Fold',
        'wash_iron' => 'Wash & Iron',
        'dry_clean' => 'Dry Clean',
        'iron_only' => 'Ironing Only',
        'shoe_clean' => 'Shoe Cleaning',
        'bed_cover' => 'Bed Cover / Curtain',
        'weight_based' => 'Weight Based Laundry',
        'other' => 'Other',
    ];
}

function service_type_label(string $type): string
{
    $all = service_types();
    return $all[$type] ?? ucwords(str_replace('_', ' ', $type));
}

function service_modes(): array
{
    return [
        'per_piece' => 'Per piece',
        'per_kg' => 'Per kg',
        'fixed' => 'Fixed rate',
    ];
}

/**
 * The three pricing metrics requested for the catalogue hierarchy.
 */
function pricing_metrics(): array
{
    return [
        'per_kg' => 'Per kg',
        'per_piece' => 'Per piece',
        'fixed' => 'Fixed rate',
    ];
}

/**
 * True when the mode is billed as quantity x rate (piece and fixed extras).
 * Only per_kg is billed from the scale weight.
 */
function mode_is_piece_billed(string $mode): bool
{
    return $mode !== 'per_kg';
}

/**
 * The three catalogue tabs used by the item picker and the owner catalogue:
 * piece-wise items, kg-wise items and optional extras/add-ons.
 */
function service_categories(): array
{
    return [
        'piece' => 'Piece wise',
        'kg' => 'Kg wise',
        'extra' => 'Extra',
    ];
}

function service_category_label(string $category): string
{
    $all = service_categories();
    return $all[$category] ?? 'Piece wise';
}

/**
 * Category of a service. Older rows only have price_mode, so derive it there.
 */
function service_category_for(array $service): string
{
    $category = (string) ($service['category'] ?? $service['service_category'] ?? '');
    if (isset(service_categories()[$category])) {
        return $category;
    }
    return (string) ($service['price_mode'] ?? $service['service_price_mode'] ?? 'per_piece') === 'per_kg' ? 'kg' : 'piece';
}

/**
 * Billing mode for a category. Extras are billed like pieces (qty x rate).
 * Piece and extra tabs may additionally be marked as a fixed rate metric.
 */
function service_mode_for_category(string $category, string $metric = ''): string
{
    if ($category === 'kg') {
        return 'per_kg';
    }
    return $metric === 'fixed' ? 'fixed' : 'per_piece';
}

/**
 * Add the category column to older databases. Called lazily before a service
 * query so customer/staff pages keep working until the migration is run.
 */
function ensure_service_category_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $exists = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'category'",
            [],
            0
        );
        if ($exists > 0) {
            return;
        }
        db_exec("ALTER TABLE services ADD COLUMN category ENUM('piece','kg','extra') NOT NULL DEFAULT 'piece' AFTER price_mode");
        db_exec("UPDATE services SET category = 'kg' WHERE price_mode = 'per_kg'");
        audit('schema.service_categories', 'schema', 'services', 'Service category column added');
    } catch (Throwable $e) {
        app_log_throwable($e);
    }
}

function service_mode_label(string $mode): string
{
    $all = service_modes();
    return $all[$mode] ?? ucfirst(str_replace('_', ' ', $mode));
}

/* ------------------------------------------------------------------
 * Subcategories (Men, Women, Kids, Household, Accessories)
 *
 * Hierarchy used by the order screen:
 *   Subcategory -> Article (laundry_items) -> Service -> Pricing metric
 * ------------------------------------------------------------------ */

function default_subcategories(): array
{
    return ['Men', 'Women', 'Kids', 'Household', 'Accessories'];
}

function subcategory_slug(string $name): string
{
    $slug = mb_strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'subcategory';
}

/**
 * Create the subcategory table/columns and seed the starter list on older
 * databases. Extend price_mode enums with the fixed pricing metric at the
 * same time so fixed-rate articles work without a manual migration.
 */
function ensure_subcategory_schema(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        db_exec(
            "CREATE TABLE IF NOT EXISTS subcategories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                slug VARCHAR(80) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_subcategories_slug (slug),
                KEY idx_subcategories_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $columns = [
            ['laundry_items', 'subcategory_id', 'INT UNSIGNED NULL AFTER service_id'],
            ['order_items', 'subcategory_id', 'INT UNSIGNED NULL AFTER service_id'],
            ['order_items', 'subcategory_name', 'VARCHAR(80) NULL AFTER subcategory_id'],
        ];
        foreach ($columns as [$table, $column, $definition]) {
            $exists = (int) db_value(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $column],
                0
            );
            if ($exists === 0) {
                db_exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
            }
        }

        // Widen price_mode to include the fixed pricing metric.
        foreach (['services', 'laundry_items', 'order_items'] as $table) {
            $row = db_row(
                "SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'price_mode'",
                [$table]
            );
            $type = (string) ($row['type'] ?? '');
            if ($type !== '' && strpos($type, "'fixed'") === false) {
                db_exec(
                    "ALTER TABLE `" . $table . "` MODIFY price_mode
                     ENUM('per_piece','per_kg','fixed') NOT NULL DEFAULT 'per_piece'"
                );
            }
        }

        // Seed the starter subcategories once.
        $icons = ['Men' => '👔', 'Women' => '👗', 'Kids' => '👶', 'Household' => '🏠', 'Accessories' => '👟'];
        $sort = 0;
        foreach (default_subcategories() as $name) {
            $sort += 10;
            $slug = subcategory_slug($name);
            $icon = $icons[$name] ?? null;
            db_exec(
                'INSERT INTO subcategories (name, icon, slug, sort_order, is_active)
                 VALUES (?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), icon = COALESCE(icon, VALUES(icon))',
                [$name, $icon, $slug, $sort]
            );
        }
        audit('schema.subcategories', 'schema', 'subcategories', 'Subcategory hierarchy ready');
    } catch (Throwable $e) {
        app_log_throwable($e);
    }
}

function all_subcategories(): array
{
    ensure_subcategory_schema();
    return db_all('SELECT * FROM subcategories ORDER BY sort_order, name');
}

function active_subcategories(): array
{
    ensure_subcategory_schema();
    return db_all('SELECT * FROM subcategories WHERE is_active = 1 ORDER BY sort_order, name');
}

/**
 * Resolve a posted subcategory id to a live row, or null when it is unknown.
 */
function subcategory_by_id(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    ensure_subcategory_schema();
    return db_row('SELECT * FROM subcategories WHERE id = ?', [$id]);
}

/**
 * Map an owner-created service name back to the legacy service_type key used by
 * older orders and reports. Unknown/custom services fall back to "other".
 */
function service_type_for_name(string $name): string
{
    $normalised = mb_strtolower(trim($name));

    // Check for exact matches first
    foreach (service_types() as $key => $label) {
        if (mb_strtolower($label) === $normalised) {
            return $key;
        }
    }

    // Check for partial matches
    foreach (service_types() as $key => $label) {
        if (mb_strpos($normalised, mb_strtolower($label)) !== false) {
            return $key;
        }
    }

    // Special handling for weight-based services
    if (mb_strpos($normalised, 'weight') !== false || mb_strpos($normalised, 'kg') !== false) {
        return 'weight_based';
    }

    return 'other';
}

function active_services(): array
{
    ensure_service_category_schema();
    return db_all("SELECT * FROM services WHERE is_active = 1 ORDER BY FIELD(category, 'piece', 'kg', 'extra'), service_name");
}

function all_services(): array
{
    ensure_service_category_schema();
    return db_all("SELECT * FROM services ORDER BY FIELD(category, 'piece', 'kg', 'extra'), is_active DESC, service_name");
}

/**
 * Effective rate for an item: an item-level override wins, otherwise the
 * service rate is used. This is what makes a 70/kg service price by weight.
 */
function service_effective_rate(array $service, array $item = []): float
{
    $mode = (string) ($item['price_mode'] ?? $service['price_mode'] ?? 'per_piece');
    $override = $mode === 'per_kg'
        ? (float) ($item['rate_per_kg'] ?? 0)
        : (float) ($item['rate_per_piece'] ?? 0);
    return $override > 0 ? $override : (float) ($service['rate'] ?? 0);
}

/**
 * Service label for a line on an order. New lines carry a snapshot name;
 * legacy lines fall back to the old fixed service_type list.
 */
function order_item_service_label(array $item): string
{
    $name = trim((string) ($item['service_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    return service_type_label((string) ($item['service_type'] ?? ''));
}

function order_item_billing_label(array $item): string
{
    $mode = (string) ($item['price_mode'] ?? 'per_piece');
    return service_mode_label($mode);
}

function payment_methods(): array
{
    return [
        'cash' => 'Cash',
        'upi' => 'UPI',
        'card' => 'Card',
    ];
}

/**
 * <option> list for the payment method select, ready to drop inside a form.
 */
function payment_method_options(?string $selected = null): string
{
    $html = '';
    foreach (payment_methods() as $key => $label) {
        $html .= '<option value="' . e($key) . '"' . ($selected === $key ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html;
}

function role_label(string $role): string
{
    $map = [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin / Owner',
        'staff' => 'Staff',
        'pickup' => 'Staff',
        'delivery' => 'Staff',
        'customer' => 'Customer',
    ];
    return $map[$role] ?? ucfirst($role);
}

/**
 * Roles that log into the staff module (single staff login with pickup / deliver tabs).
 */
function staff_roles(): array
{
    return ['staff', 'pickup', 'delivery'];
}

function phone_digits(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

/**
 * Normalised mobile number used as the primary identity key.
 * "98765 43210", "+91 98765 43210" and "09876543210" all become "9876543210".
 * Only accepts 10-digit Indian mobile numbers starting with 6-9.
 */
function phone_key(string $phone): string
{
    $digits = phone_digits($phone);
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) === 11 && $digits[0] === '0') {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) === 12 && substr($digits, 0, 2) === '91') {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }
    if (strlen($digits) === 10 && preg_match('/^[6-9]\d{9}$/', $digits)) {
        return $digits;
    }
    return '';
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

function format_date(?string $date): string
{
    if ($date === null || $date === '') {
        return '—';
    }
    $ts = strtotime($date);
    return $ts === false ? '—' : date('d M Y', $ts);
}

function format_datetime(?string $date): string
{
    if ($date === null || $date === '') {
        return '—';
    }
    $ts = strtotime($date);
    return $ts === false ? '—' : date('d M Y, h:i A', $ts);
}

function format_datetime_input(?string $date): string
{
    if ($date === null || $date === '') {
        return '';
    }
    $ts = strtotime($date);
    return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
}

function json_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function old(string $key, string $default = ''): string
{
    return $_SESSION['_old'][$key] ?? $default;
}

function keep_old(array $data): void
{
    $_SESSION['_old'] = $data;
}

function clear_old(): void
{
    unset($_SESSION['_old']);
}

function status_badge(string $status): string
{
    $map = [
        'new' => 'badge-info',
        'pickup_assigned' => 'badge-info',
        'picked_up' => 'badge-purple',
        'processing' => 'badge-purple',
        'ready_for_delivery' => 'badge-warn',
        'out_for_delivery' => 'badge-warn',
        'delivered' => 'badge-success',
        'cancelled' => 'badge-muted',
        'pending' => 'badge-danger',
        'partial' => 'badge-warn',
        'paid' => 'badge-success',
    ];
    return $map[$status] ?? 'badge-muted';
}

function valid_phone(string $phone): bool
{
    $key = phone_key($phone);
    if ($key === '') {
        return false;
    }
    return preg_match('/^[6-9]\d{9}$/', $key) === 1;
}

function normalise_phone(string $phone): string
{
    return preg_replace('/\s+/', ' ', trim($phone)) ?? trim($phone);
}

/**
 * Google Maps link for an address, or for a saved pin when one is available.
 * Used by the Navigate buttons on order pages and staff queues.
 */
function map_link(?float $lat, ?float $lng, string $address = '', bool $directions = false): string
{
    $base = $directions
        ? 'https://www.google.com/maps/dir/?api=1&destination='
        : 'https://www.google.com/maps/search/?api=1&query=';
    if ($lat !== null && $lng !== null) {
        $point = number_format($lat, 7, '.', '') . ',' . number_format($lng, 7, '.', '');
        return $base . rawurlencode($point);
    }
    $address = trim($address);
    return $address === '' ? '' : $base . rawurlencode($address);
}
