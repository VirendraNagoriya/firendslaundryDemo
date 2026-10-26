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
    ];
}

function service_mode_label(string $mode): string
{
    $all = service_modes();
    return $all[$mode] ?? ucfirst(str_replace('_', ' ', $mode));
}

/**
 * Map an owner-created service name back to the legacy service_type key used by
 * older orders and reports. Unknown/custom services fall back to "other".
 */
function service_type_for_name(string $name): string
{
    $normalised = mb_strtolower(trim($name));
    foreach (service_types() as $key => $label) {
        if (mb_strtolower($label) === $normalised) {
            return $key;
        }
    }
    foreach (service_types() as $key => $label) {
        if (mb_strpos($normalised, mb_strtolower($label)) !== false) {
            return $key;
        }
    }
    return 'other';
}

function active_services(): array
{
    return db_all('SELECT * FROM services WHERE is_active = 1 ORDER BY service_name');
}

function all_services(): array
{
    return db_all('SELECT * FROM services ORDER BY is_active DESC, service_name');
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
 */
function phone_key(string $phone): string
{
    $digits = phone_digits($phone);
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) > 10 && strpos($digits, '91') === 0) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }
    return $digits;
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
    return preg_match('/^\d{10}$/', phone_key($phone)) === 1;
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
