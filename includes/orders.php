<?php
declare(strict_types=1);

function order_number_for(int $id): string
{
    return 'LF' . date('ymd') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}

function order_item_amount(array $item): float
{
    $qty = (float) ($item['qty'] ?? 0);
    $weight = (float) ($item['weight_kg'] ?? 0);
    $rate = (float) ($item['rate'] ?? 0);
    $mode = $item['price_mode'] ?? 'per_piece';
    if ($mode === 'per_kg') {
        return round($weight * $rate, 2);
    }
    return round($qty * $rate, 2);
}

/**
 * A fully paid order is closed: the bill is final and no more items may be added.
 * Orders that are still pending or only partially paid stay open, so staff can keep
 * adding the garments they receive at the customer's place.
 */
function order_is_closed(array $order): bool
{
    return (string) ($order['payment_status'] ?? '') === 'paid';
}

/**
 * Whether items may still be added to / edited on this order.
 */
function order_accepts_items(array $order): bool
{
    return !order_is_closed($order) && (string) ($order['status'] ?? '') !== 'cancelled';
}

function save_order_items(int $orderId, array $items): void
{
    $order = db_row('SELECT status, payment_status FROM orders WHERE id = ?', [$orderId]);
    if ($order !== null && order_is_closed($order)) {
        return;
    }

    db_exec('DELETE FROM order_items WHERE order_id = ?', [$orderId]);
    foreach ($items as $item) {
        $name = trim((string) ($item['item_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        db_exec(
            'INSERT INTO order_items (order_id, item_name, service_type, price_mode, qty, weight_kg, rate, amount, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $orderId,
                $name,
                (string) ($item['service_type'] ?? 'wash_fold'),
                ($item['price_mode'] ?? 'per_piece') === 'per_kg' ? 'per_kg' : 'per_piece',
                max(1, (int) ($item['qty'] ?? 1)),
                (float) ($item['weight_kg'] ?? 0),
                (float) ($item['rate'] ?? 0),
                order_item_amount($item),
                trim((string) ($item['notes'] ?? '')) !== '' ? trim((string) $item['notes']) : null,
            ]
        );
    }
}

function append_order_items(int $orderId, array $items): int
{
    $order = db_row('SELECT status, payment_status FROM orders WHERE id = ?', [$orderId]);
    if ($order === null || !order_accepts_items($order)) {
        return 0;
    }

    $added = 0;
    foreach ($items as $item) {
        $name = trim((string) ($item['item_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        db_exec(
            'INSERT INTO order_items (order_id, item_name, service_type, price_mode, qty, weight_kg, rate, amount, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $orderId,
                $name,
                (string) ($item['service_type'] ?? 'wash_fold'),
                ($item['price_mode'] ?? 'per_piece') === 'per_kg' ? 'per_kg' : 'per_piece',
                max(1, (int) ($item['qty'] ?? 1)),
                (float) ($item['weight_kg'] ?? 0),
                (float) ($item['rate'] ?? 0),
                order_item_amount($item),
                trim((string) ($item['notes'] ?? '')) !== '' ? trim((string) $item['notes']) : null,
            ]
        );
        $added++;
    }
    if ($added > 0) {
        recalc_order($orderId);
    }
    return $added;
}

function recalc_order(int $orderId): void
{
    $subtotal = (float) db_value('SELECT COALESCE(SUM(amount), 0) FROM order_items WHERE order_id = ?', [$orderId], 0);
    $order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if ($order === null) {
        return;
    }
    $discount = (float) $order['discount_amount'];
    $delivery = (float) $order['delivery_charge'];
    $tax = (float) $order['tax_amount'];
    $total = max(0, $subtotal - $discount + $delivery + $tax);
    $paid = (float) db_value('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ? AND voided_at IS NULL', [$orderId], 0);
    $previousPaymentStatus = (string) $order['payment_status'];

    if ($total <= 0) {
        $paymentStatus = 'pending';
    } elseif ($paid + 0.005 >= $total) {
        $paymentStatus = 'paid';
    } elseif ($paid > 0) {
        $paymentStatus = 'partial';
    } else {
        $paymentStatus = 'pending';
    }

    db_exec(
        'UPDATE orders SET subtotal = ?, total_amount = ?, paid_amount = ?, payment_status = ? WHERE id = ?',
        [$subtotal, $total, min($paid, $total), $paymentStatus, $orderId]
    );

    if ($previousPaymentStatus !== $paymentStatus) {
        if ($paymentStatus === 'paid') {
            log_status($orderId, (string) $order['status'], (string) $order['status'], 'closed', 'Order closed - payment completed');
            audit('order.closed', 'order', (string) $orderId, 'Order ' . $order['order_number'] . ' closed after full payment');
        } elseif ($previousPaymentStatus === 'paid') {
            log_status($orderId, (string) $order['status'], (string) $order['status'], 'reopened', 'Order reopened - balance outstanding');
            audit('order.reopened', 'order', (string) $orderId, 'Order ' . $order['order_number'] . ' reopened');
        }
    }
}

function log_status(int $orderId, ?string $from, string $to, string $action, ?string $notes = null): void
{
    $user = current_user();
    db_exec(
        'INSERT INTO order_status_history (order_id, from_status, to_status, action, notes, user_id, user_name, user_role)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $orderId,
            $from,
            $to,
            $action,
            $notes,
            $user['id'] ?? null,
            $user['name'] ?? 'System',
            $user['role'] ?? null,
        ]
    );
}

function set_order_status(int $orderId, string $status, string $action, ?string $notes = null): void
{
    $order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if ($order === null) {
        return;
    }
    $fields = ['status = ?'];
    $params = [$status];
    if ($status === 'pickup_assigned' && $order['pickup_assigned_at'] === null) {
        $fields[] = 'pickup_assigned_at = ?';
        $params[] = now();
    }
    if ($status === 'picked_up' && $order['pickup_completed_at'] === null) {
        $fields[] = 'pickup_completed_at = ?';
        $params[] = now();
    }
    if ($status === 'delivered' && $order['delivery_completed_at'] === null) {
        $fields[] = 'delivery_completed_at = ?';
        $params[] = now();
    }
    $params[] = $orderId;
    db_exec('UPDATE orders SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    log_status($orderId, (string) $order['status'], $status, $action, $notes);
}

/**
 * Shared staff queues: any pickup/delivery staff member may handle any open order.
 * Whoever handles it becomes the assigned staff member, so the activity log and
 * the staff counters always show the person who actually did the work.
 */
function claim_order_for_staff(int $orderId, int $staffId): void
{
    $order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if ($order === null) {
        return;
    }

    $user = current_user();
    $name = (string) ($user['name'] ?? 'Staff');
    $status = (string) $order['status'];

    if (in_array($status, ['new', 'pickup_assigned', 'picked_up', 'processing'], true)) {
        if ((int) $order['pickup_staff_id'] === $staffId) {
            return;
        }
        db_exec(
            'UPDATE orders SET pickup_staff_id = ?, pickup_assigned_at = COALESCE(pickup_assigned_at, ?) WHERE id = ?',
            [$staffId, now(), $orderId]
        );
        if ($status === 'new') {
            set_order_status($orderId, 'pickup_assigned', 'pickup_assigned', 'Assigned to ' . $name . ' from the shared pickup queue');
        } else {
            log_status($orderId, $status, $status, 'assignment_updated', 'Pickup assigned to ' . $name);
        }
        return;
    }

    if (in_array($status, ['ready_for_delivery', 'out_for_delivery'], true)) {
        if ((int) $order['delivery_staff_id'] === $staffId) {
            return;
        }
        db_exec('UPDATE orders SET delivery_staff_id = ? WHERE id = ?', [$staffId, $orderId]);
        log_status($orderId, $status, $status, 'assignment_updated', 'Delivery assigned to ' . $name);
    }
}

function create_order(array $data, array $items, ?int $createdBy = null): int
{
    $user = current_user();
    $orderDate = $data['order_date'] ?? today();
    $sql = 'INSERT INTO orders
        (order_number, customer_id, source, pricing_mode, status, payment_status, order_date,
         scheduled_pickup_date, scheduled_delivery_date, contact_name, contact_phone, delivery_address,
         pickup_latitude, pickup_longitude, pickup_place_id,
         pickup_staff_id, delivery_staff_id, subtotal, discount_amount, delivery_charge, tax_amount,
         total_amount, paid_amount, notes, staff_notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 0, 0, ?, ?, ?)';
    $params = [
        'TMP',
        (int) $data['customer_id'],
        $data['source'] ?? 'admin',
        $data['pricing_mode'] ?? 'per_item',
        $data['status'] ?? 'new',
        'pending',
        $orderDate,
        $data['scheduled_pickup_date'] ?? null,
        $data['scheduled_delivery_date'] ?? null,
        $data['contact_name'],
        $data['contact_phone'],
        $data['delivery_address'],
        $data['pickup_latitude'] ?? null,
        $data['pickup_longitude'] ?? null,
        $data['pickup_place_id'] ?? null,
        $data['pickup_staff_id'] ?? null,
        $data['delivery_staff_id'] ?? null,
        (float) ($data['discount_amount'] ?? 0),
        (float) ($data['delivery_charge'] ?? 0),
        (float) ($data['tax_amount'] ?? 0),
        $data['notes'] ?? null,
        $data['staff_notes'] ?? null,
        $createdBy ?? ($user['id'] ?? null),
    ];
    $orderId = db_insert($sql, $params);
    db_exec('UPDATE orders SET order_number = ? WHERE id = ?', [order_number_for($orderId), $orderId]);
    save_order_items($orderId, $items);
    recalc_order($orderId);
    log_status($orderId, null, (string) ($data['status'] ?? 'new'), 'created', 'Order created');
    audit('order.create', 'order', (string) $orderId, 'Order ' . order_number_for($orderId));
    return $orderId;
}

function update_order(int $orderId, array $data): void
{
    $fields = [
        'pricing_mode = ?',
        'order_date = ?',
        'scheduled_pickup_date = ?',
        'scheduled_delivery_date = ?',
        'contact_name = ?',
        'contact_phone = ?',
        'delivery_address = ?',
        'pickup_latitude = ?',
        'pickup_longitude = ?',
        'pickup_place_id = ?',
        'pickup_staff_id = ?',
        'delivery_staff_id = ?',
        'discount_amount = ?',
        'delivery_charge = ?',
        'tax_amount = ?',
        'notes = ?',
        'staff_notes = ?',
    ];
    $params = [
        $data['pricing_mode'] ?? 'per_item',
        $data['order_date'] ?? today(),
        $data['scheduled_pickup_date'] ?? null,
        $data['scheduled_delivery_date'] ?? null,
        $data['contact_name'],
        $data['contact_phone'],
        $data['delivery_address'],
        $data['pickup_latitude'] ?? null,
        $data['pickup_longitude'] ?? null,
        $data['pickup_place_id'] ?? null,
        $data['pickup_staff_id'] ?? null,
        $data['delivery_staff_id'] ?? null,
        (float) ($data['discount_amount'] ?? 0),
        (float) ($data['delivery_charge'] ?? 0),
        (float) ($data['tax_amount'] ?? 0),
        $data['notes'] ?? null,
        $data['staff_notes'] ?? null,
        $orderId,
    ];
    db_exec('UPDATE orders SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    recalc_order($orderId);
}

function record_payment(int $orderId, float $amount, string $method, ?string $reference, ?string $notes, ?int $collectedBy = null): int
{
    $user = current_user();
    db_exec(
        'INSERT INTO payments (order_id, amount, method, reference_no, notes, collected_by, collected_by_name)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            $orderId,
            $amount,
            $method,
            $reference,
            $notes,
            $collectedBy ?? ($user['id'] ?? null),
            $user['name'] ?? 'System',
        ]
    );
    recalc_order($orderId);
    $order = db_row('SELECT order_number FROM orders WHERE id = ?', [$orderId]);
    audit('payment.record', 'order', (string) $orderId, 'Collected ' . money($amount) . ' for ' . ($order['order_number'] ?? $orderId));
    return (int) db()->lastInsertId();
}

function assign_staff(int $orderId, ?int $pickupStaffId, ?int $deliveryStaffId): void
{
    $order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if ($order === null) {
        return;
    }
    db_exec(
        'UPDATE orders SET pickup_staff_id = ?, delivery_staff_id = ? WHERE id = ?',
        [$pickupStaffId, $deliveryStaffId, $orderId]
    );
    if ($pickupStaffId !== null && $order['status'] === 'new') {
        set_order_status($orderId, 'pickup_assigned', 'pickup_assigned', 'Pickup staff assigned');
    }
    log_status($orderId, (string) $order['status'], (string) $order['status'], 'assignment_updated', 'Staff assignment updated');
}

function staff_list(string $role = 'staff'): array
{
    if (in_array($role, ['pickup', 'delivery', 'staff'], true)) {
        $roles = staff_roles();
        $placeholders = implode(', ', array_fill(0, count($roles), '?'));
        return db_all(
            "SELECT id, name, phone, email, is_active FROM users WHERE role IN ({$placeholders}) ORDER BY name",
            $roles
        );
    }
    return db_all('SELECT id, name, phone, email, is_active FROM users WHERE role = ? ORDER BY name', [$role]);
}

function customer_for_user(int $userId): ?array
{
    return db_row('SELECT * FROM customers WHERE user_id = ?', [$userId]);
}

function find_or_create_customer(array $data, ?int $createdBy = null): int
{
    $phone = normalise_phone((string) $data['phone']);
    $key = phone_key($phone);
    $existing = null;
    if ($key !== '') {
        $existing = db_row('SELECT id FROM customers WHERE phone_key = ? LIMIT 1', [$key]);
    }
    if ($existing === null && $phone !== '') {
        $existing = db_row('SELECT id FROM customers WHERE phone = ? LIMIT 1', [$phone]);
    }
    if ($existing !== null) {
        $existingId = (int) $existing['id'];
        // Keep the saved pin fresh when staff pick a location for an existing customer.
        if (($data['latitude'] ?? null) !== null && ($data['longitude'] ?? null) !== null) {
            db_exec(
                'UPDATE customers
                 SET latitude = ?, longitude = ?, place_id = COALESCE(NULLIF(?, \'\'), place_id)
                 WHERE id = ?',
                [
                    $data['latitude'],
                    $data['longitude'],
                    (string) ($data['place_id'] ?? ''),
                    $existingId,
                ]
            );
        }
        return $existingId;
    }
    $user = current_user();
    try {
        return db_insert(
            'INSERT INTO customers (user_id, name, phone, phone_key, email, address, landmark, city, pincode, latitude, longitude, place_id, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['user_id'] ?? null,
                $data['name'],
                $phone,
                $key !== '' ? $key : null,
                $data['email'] ?? null,
                $data['address'] ?? null,
                $data['landmark'] ?? null,
                $data['city'] ?? null,
                $data['pincode'] ?? null,
                $data['latitude'] ?? null,
                $data['longitude'] ?? null,
                $data['place_id'] ?? null,
                $data['notes'] ?? null,
                $createdBy ?? ($user['id'] ?? null),
            ]
        );
    } catch (PDOException $e) {
        // A simultaneous request may have created the same customer between the
        // lookup and the insert. Re-use that row instead of failing.
        if (db_exception_is_duplicate($e) && $key !== '') {
            $race = db_row('SELECT id FROM customers WHERE phone_key = ? LIMIT 1', [$key]);
            if ($race !== null) {
                return (int) $race['id'];
            }
        }
        throw $e;
    }
}

/**
 * Every order of a customer that still carries an unpaid balance (old invoices included).
 */
function customer_dues(int $customerId, ?int $excludeOrderId = null): array
{
    $sql = "SELECT * FROM orders
            WHERE customer_id = ? AND payment_status <> 'paid' AND status <> 'cancelled'";
    $params = [$customerId];
    if ($excludeOrderId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeOrderId;
    }
    $sql .= ' ORDER BY order_date ASC, id ASC';
    return db_all($sql, $params);
}

function customer_due_total(int $customerId, ?int $excludeOrderId = null): float
{
    $dues = customer_dues($customerId, $excludeOrderId);
    $total = 0.0;
    foreach ($dues as $order) {
        $total += max(0, (float) $order['total_amount'] - (float) $order['paid_amount']);
    }
    return $total;
}

/**
 * Outstanding invoices of every customer that is on the given staff member's delivery list.
 * Returns one row per customer with all of their unpaid orders (old invoices included).
 */
function staff_customer_dues(int $staffId): array
{
    $customers = db_all(
        "SELECT DISTINCT c.id, c.name, c.phone
         FROM orders o JOIN customers c ON c.id = o.customer_id
         WHERE o.delivery_staff_id = ?
         ORDER BY c.name",
        [$staffId]
    );

    $result = [];
    foreach ($customers as $customer) {
        $orders = customer_dues((int) $customer['id']);
        if ($orders === []) {
            continue;
        }
        $total = 0.0;
        foreach ($orders as $order) {
            $total += max(0, (float) $order['total_amount'] - (float) $order['paid_amount']);
        }
        if ($total <= 0) {
            continue;
        }
        $result[] = [
            'customer' => $customer,
            'orders' => $orders,
            'total' => $total,
        ];
    }
    return $result;
}

/**
 * Outstanding invoices of every customer (owner view), optionally filtered by name/phone.
 * Same shape as staff_customer_dues(): one row per customer with their unpaid orders.
 */
function all_customer_dues(?string $search = null): array
{
    $sql = "SELECT DISTINCT c.id, c.name, c.phone
            FROM orders o JOIN customers c ON c.id = o.customer_id
            WHERE o.payment_status <> 'paid' AND o.status <> 'cancelled'";
    $params = [];
    $search = trim((string) $search);
    if ($search !== '') {
        $sql .= ' AND (c.name LIKE ? OR c.phone LIKE ? OR o.contact_name LIKE ? OR o.contact_phone LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY c.name ASC, c.id ASC';

    $result = [];
    foreach (db_all($sql, $params) as $customer) {
        $orders = customer_dues((int) $customer['id']);
        if ($orders === []) {
            continue;
        }
        $total = 0.0;
        foreach ($orders as $order) {
            $total += max(0, (float) $order['total_amount'] - (float) $order['paid_amount']);
        }
        if ($total <= 0) {
            continue;
        }
        $result[] = [
            'customer' => $customer,
            'orders' => $orders,
            'total' => $total,
        ];
    }
    return $result;
}

/**
 * A staff member may collect against any invoice of a customer that is on their delivery list.
 */
function staff_may_collect(int $staffId, array $order): bool
{
    if ((int) ($order['delivery_staff_id'] ?? 0) === $staffId) {
        return true;
    }
    $count = (int) db_value(
        'SELECT COUNT(*) FROM orders WHERE delivery_staff_id = ? AND customer_id = ?',
        [$staffId, (int) ($order['customer_id'] ?? 0)],
        0
    );
    return $count > 0;
}

function order_summary_counts(string $where = '1=1', array $params = []): array
{
    $rows = db_all("SELECT status, COUNT(*) AS total FROM orders WHERE {$where} GROUP BY status", $params);
    $counts = array_fill_keys(array_keys(order_statuses()), 0);
    foreach ($rows as $row) {
        $counts[$row['status']] = (int) $row['total'];
    }
    $counts['total'] = array_sum($counts);
    return $counts;
}

function outstanding_amount(string $where = '1=1', array $params = []): float
{
    return (float) db_value(
        "SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM orders WHERE {$where} AND payment_status <> 'paid' AND status <> 'cancelled'",
        $params,
        0
    );
}
