<?php
declare(strict_types=1);

/**
 * Editable WhatsApp status messages.
 *
 * The owner/admin edits the wording in Admin -> WhatsApp Messages; every send
 * button builds a wa.me link on the device, so nothing is sent automatically
 * and there is no API key or monthly fee. A template only stores the message
 * body; the event list lives in code so new statuses can be added safely.
 */

require_once __DIR__ . '/phone-helper.php';

/** Events that have a customer-facing WhatsApp message. */
function whatsapp_events(): array
{
    return [
        'new_order' => 'Order received',
        'accepted' => 'Order accepted',
        'picked_up' => 'Picked up / processing',
        'out_for_delivery' => 'Out for delivery',
        'delivered' => 'Delivered',
        'payment_due' => 'Payment reminder',
    ];
}

/** The order status that usually triggers each event, for smart sorting. */
function whatsapp_event_status(string $event): string
{
    $map = [
        'new_order' => 'new',
        'accepted' => 'pickup_assigned',
        'picked_up' => 'picked_up',
        'out_for_delivery' => 'out_for_delivery',
        'delivered' => 'delivered',
        'payment_due' => 'delivered',
    ];
    return $map[$event] ?? '';
}

/** Default wording, used on a fresh install and by "Reset to default". */
function whatsapp_default_templates(): array
{
    return [
        'new_order' => "Hello {customer_name}, thank you for choosing {business_name}!\n\nOrder: {order_number}\nDate: {order_date}\nItems: {items}\nTotal: {total}\nAdvance paid: {paid}\nBalance: {balance}\n\nWe will update you when the order is picked up.\n{contact_phone}",
        'accepted' => "Hello {customer_name}, your order {order_number} dated {order_date} has been accepted.\n\nItems: {items}\nEstimated total: {total}\nPending balance: {balance}\nPickup: {pickup_date}\n\n{payment_note}{business_name} · {contact_phone}",
        'picked_up' => "Hello {customer_name}, we have picked up order {order_number} dated {order_date} and it is now being processed.\n\nItems: {items}\nPending invoice: {balance}\n{dues_note}\nWe will message you when it is out for delivery.\n{business_name} · {contact_phone}",
        'out_for_delivery' => "Hello {customer_name}, order {order_number} dated {order_date} is out for delivery today.\n\nItems: {items}\nTotal: {total}\nPending balance: {balance}\n{dues_note}\nPlease keep the balance ready. Thank you!\n{business_name} · {contact_phone}",
        'delivered' => "Hello {customer_name}, order {order_number} dated {order_date} has been delivered.\n\nItems: {items}\nTotal: {total}\nPaid: {paid}\nBalance: {balance}\n{dues_note}\nThank you for choosing {business_name}!\n{contact_phone}",
        'payment_due' => "Hello {customer_name}, a gentle reminder: {dues_total} is pending against your invoice(s).\n\nPending invoices:\n{dues}\n\nYou can pay on delivery or online. Thank you!\n{business_name} · {contact_phone}",
    ];
}

/** Placeholders the owner can use in any template. */
function whatsapp_placeholders(): array
{
    return [
        '{customer_name}' => 'Customer name',
        '{order_number}' => 'Order number, e.g. LF261029-00012',
        '{order_date}' => 'Order date',
        '{status}' => 'Current order status',
        '{items}' => 'Items summary, e.g. 4 pcs / 2.50 kg',
        '{total}' => 'Order total',
        '{paid}' => 'Amount already paid',
        '{balance}' => 'Balance on this order',
        '{pickup_date}' => 'Scheduled pickup date',
        '{delivery_date}' => 'Scheduled delivery date',
        '{dues_total}' => 'Total of all pending invoices',
        '{dues}' => 'List of pending invoices (number, date, due)',
        '{payment_note}' => 'Payment reminder line when a balance exists',
        '{dues_note}' => 'Old-invoice reminder line when dues exist',
        '{business_name}' => 'Business name from Settings',
        '{contact_phone}' => 'Business phone from Settings',
    ];
}

/**
 * Create the table and seed missing events. Safe to call on every request:
 * the work stops after the first successful check in a request, and only runs
 * an INSERT for events that do not exist yet.
 */
function whatsapp_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        db_exec(
            'CREATE TABLE IF NOT EXISTS whatsapp_templates (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_key VARCHAR(40) NOT NULL,
                body TEXT NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_whatsapp_event (event_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $existing = db_all('SELECT event_key FROM whatsapp_templates');
        $have = [];
        foreach ($existing as $row) {
            $have[(string) $row['event_key']] = true;
        }
        foreach (whatsapp_default_templates() as $key => $body) {
            if (!isset($have[$key])) {
                db_exec(
                    'INSERT INTO whatsapp_templates (event_key, body) VALUES (?, ?)',
                    [$key, $body]
                );
            }
        }
    } catch (Throwable $e) {
        app_log_throwable($e);
    }
}

/** All templates keyed by event, seeded defaults included. */
function whatsapp_templates(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) {
        return $cache;
    }
    whatsapp_ensure_schema();

    $defaults = whatsapp_default_templates();
    $cache = [];
    foreach ($defaults as $key => $body) {
        $cache[$key] = [
            'event_key' => $key,
            'body' => $body,
            'is_active' => 1,
            'updated_by' => null,
            'updated_at' => null,
        ];
    }
    foreach (db_all('SELECT * FROM whatsapp_templates') as $row) {
        $key = (string) $row['event_key'];
        if (isset($cache[$key])) {
            $cache[$key] = $row;
        }
    }
    return $cache;
}

function whatsapp_template_body(string $event): ?string
{
    $templates = whatsapp_templates();
    if (!isset($templates[$event])) {
        return null;
    }
    $row = $templates[$event];
    if ((int) ($row['is_active'] ?? 1) !== 1) {
        return null;
    }
    $body = trim((string) ($row['body'] ?? ''));
    return $body === '' ? (whatsapp_default_templates()[$event] ?? null) : $body;
}

function can_edit_whatsapp_templates(?array $user = null): bool
{
    $user = $user ?? current_user();
    return $user !== null && in_array((string) ($user['role'] ?? ''), ['super_admin', 'admin'], true);
}

/** Pending invoices for the same customer, excluding the order being messaged. */
function whatsapp_customer_dues(array $order): array
{
    $customerId = (int) ($order['customer_id'] ?? 0);
    $orderId = (int) ($order['id'] ?? 0);
    if ($customerId <= 0) {
        return ['rows' => [], 'total' => 0.0];
    }

    $rows = db_all(
        "SELECT id, order_number, order_date, total_amount, paid_amount
         FROM orders
         WHERE customer_id = ? AND id <> ? AND status <> 'cancelled'
           AND total_amount > paid_amount
         ORDER BY order_date, id",
        [$customerId, $orderId]
    );

    $total = 0.0;
    foreach ($rows as $index => $row) {
        $due = max(0.0, (float) $row['total_amount'] - (float) $row['paid_amount']);
        $rows[$index]['due'] = $due;
        $total += $due;
    }
    return ['rows' => $rows, 'total' => round($total, 2)];
}

/**
 * Fill a template for an order. Reads the customer dues so messages can show
 * pending invoice details, which is what the owner asked for.
 */
function whatsapp_render(string $event, array $order): string
{
    $body = whatsapp_template_body($event);
    if ($body === null) {
        return '';
    }

    $total = (float) ($order['total_amount'] ?? 0);
    $paid = (float) ($order['paid_amount'] ?? 0);
    $balance = max(0.0, $total - $paid);

    $items = 'No items yet';
    try {
        $parts = db_row(
            'SELECT COUNT(*) AS line_count, COALESCE(SUM(qty), 0) AS pcs, COALESCE(SUM(weight_kg), 0) AS kg
             FROM order_items WHERE order_id = ?',
            [(int) ($order['id'] ?? 0)]
        );
        if ($parts !== null && (int) $parts['line_count'] > 0) {
            $chunks = [];
            if ((int) $parts['pcs'] > 0) {
                $chunks[] = (int) $parts['pcs'] . ' pcs';
            }
            if ((float) $parts['kg'] > 0) {
                $chunks[] = rtrim(rtrim(number_format((float) $parts['kg'], 2, '.', ''), '0'), '.') . ' kg';
            }
            $items = $chunks !== [] ? implode(' / ', $chunks) : 'Items added';
        }
    } catch (Throwable $e) {
        app_log_throwable($e);
    }

    $dues = whatsapp_customer_dues($order);
    $dueLines = [];
    foreach ($dues['rows'] as $row) {
        $dueLines[] = $row['order_number'] . ' (' . format_date((string) $row['order_date']) . ') - ' . money((float) $row['due']);
    }
    $duesText = $dueLines === [] ? 'No old dues.' : implode("\n", $dueLines);
    $duesNote = $dues['total'] > 0
        ? 'Old dues: ' . money($dues['total']) . ' across ' . count($dues['rows']) . ' invoice(s).'
        : '';
    $paymentNote = $balance > 0 ? 'Balance ' . money($balance) . ' is payable on delivery or online.' : '';

    $replacements = [
        '{customer_name}' => (string) ($order['contact_name'] ?? 'Customer'),
        '{order_number}' => (string) ($order['order_number'] ?? ''),
        '{order_date}' => format_date((string) ($order['order_date'] ?? '')),
        '{status}' => order_statuses()[(string) ($order['status'] ?? '')] ?? (string) ($order['status'] ?? ''),
        '{items}' => $items,
        '{total}' => money($total),
        '{paid}' => money($paid),
        '{balance}' => money($balance),
        '{pickup_date}' => !empty($order['scheduled_pickup_date']) ? format_date((string) $order['scheduled_pickup_date']) : 'to be confirmed',
        '{delivery_date}' => !empty($order['scheduled_delivery_date']) ? format_date((string) $order['scheduled_delivery_date']) : 'to be confirmed',
        '{dues_total}' => money($dues['total']),
        '{dues}' => $duesText,
        '{payment_note}' => $paymentNote !== '' ? $paymentNote . "\n" : '',
        '{dues_note}' => $duesNote !== '' ? $duesNote . "\n" : '',
        '{business_name}' => setting('business_name', APP_NAME),
        '{contact_phone}' => setting('contact_phone', APP_CONTACT_PHONE),
    ];

    return trim(strtr($body, $replacements));
}

/** 10-digit Indian mobile, or null. Accepts +91 / 0 prefixes and spaces. */
function whatsapp_phone(?string $phone): ?string
{
    $normalised = normalize_mobile_10((string) $phone);
    return $normalised === false ? null : $normalised;
}

/** wa.me link; null when the order has no usable mobile number. */
function whatsapp_link(array $order, string $event): ?string
{
    $phone = whatsapp_phone((string) ($order['contact_phone'] ?? ''));
    if ($phone === null) {
        return null;
    }
    if ($event === 'payment_due') {
        $balance = max(0.0, (float) ($order['total_amount'] ?? 0) - (float) ($order['paid_amount'] ?? 0));
        $dues = whatsapp_customer_dues($order);
        if ($balance <= 0 && $dues['total'] <= 0) {
            return null;
        }
    }
    $message = whatsapp_render($event, $order);
    if ($message === '') {
        return null;
    }
    return 'https://wa.me/91' . $phone . '?text=' . rawurlencode($message);
}

/** The event whose message best matches the current status. */
function whatsapp_event_for_status(string $status): string
{
    $map = [
        'new' => 'new_order',
        'pickup_assigned' => 'accepted',
        'picked_up' => 'picked_up',
        'processing' => 'picked_up',
        'ready_for_delivery' => 'picked_up',
        'out_for_delivery' => 'out_for_delivery',
        'delivered' => 'delivered',
    ];
    return $map[$status] ?? '';
}

/** Small WhatsApp send button, rendered only when a phone number is usable. */
function whatsapp_button(array $order, string $event, string $label = 'Send on WhatsApp', string $class = 'btn btn-sm btn-ghost'): string
{
    $link = whatsapp_link($order, $event);
    if ($link === null) {
        return '';
    }
    return '<a class="' . e($class) . ' whatsapp-btn" href="' . e($link) . '" target="_blank" rel="noopener">'
        . '<span aria-hidden="true">&#128241;</span> ' . e($label) . '</a>';
}
