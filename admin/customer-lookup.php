<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/orders.php';

$user = require_admin();

$term = query('q');
$results = [];

if ($term !== '') {
    $like = '%' . $term . '%';
    $key = phone_key($term);
    $where = 'name LIKE ? OR phone LIKE ? OR email LIKE ?';
    $params = [$like, $like, $like];
    if ($key !== '') {
        $where .= ' OR phone_key = ?';
        $params[] = $key;
    }
    $rows = db_all("SELECT * FROM customers WHERE {$where} ORDER BY name LIMIT 8", $params);

    foreach ($rows as $row) {
        $dues = customer_dues((int) $row['id']);
        $total = 0.0;
        $lastDue = null;
        foreach ($dues as $order) {
            $total += max(0, (float) $order['total_amount'] - (float) $order['paid_amount']);
            $lastDue = $order;
        }
        $matchedBy = '';
        if ($key !== '' && (string) $row['phone_key'] === $key) {
            $matchedBy = 'mobile';
        } elseif (stripos((string) $row['name'], $term) !== false) {
            $matchedBy = 'name';
        } elseif ($row['email'] !== null && stripos((string) $row['email'], $term) !== false) {
            $matchedBy = 'email';
        }
        $results[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'phone' => (string) $row['phone'],
            'email' => (string) ($row['email'] ?? ''),
            'address' => trim(implode(', ', array_filter([
                (string) ($row['address'] ?? ''),
                (string) ($row['landmark'] ?? ''),
                (string) ($row['city'] ?? ''),
                (string) ($row['pincode'] ?? ''),
            ]))),
            'dues' => round($total, 2),
            'due_orders' => count($dues),
            'matched_by' => $matchedBy,
            'last_due' => $lastDue === null ? null : [
                'order_number' => (string) $lastDue['order_number'],
                'order_date' => (string) $lastDue['order_date'],
                'amount' => round(max(0, (float) $lastDue['total_amount'] - (float) $lastDue['paid_amount']), 2),
                'status' => (string) $lastDue['payment_status'],
            ],
        ];
    }
}

json_out(['results' => $results]);
