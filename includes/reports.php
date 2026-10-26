<?php
declare(strict_types=1);

function daily_report_data(string $date): array
{
    $statusRows = db_all(
        'SELECT status, COUNT(*) AS total, COALESCE(SUM(total_amount), 0) AS value
         FROM orders WHERE order_date = ? GROUP BY status',
        [$date]
    );
    $byStatus = [];
    foreach (order_statuses() as $key => $label) {
        $byStatus[$key] = ['label' => $label, 'count' => 0, 'value' => 0.0];
    }
    $ordersCreated = 0;
    $orderValue = 0.0;
    foreach ($statusRows as $row) {
        $key = (string) $row['status'];
        $byStatus[$key]['count'] = (int) $row['total'];
        $byStatus[$key]['value'] = (float) $row['value'];
        $ordersCreated += (int) $row['total'];
        $orderValue += (float) $row['value'];
    }

    $pickupsCompleted = (int) db_value(
        'SELECT COUNT(*) FROM orders WHERE DATE(pickup_completed_at) = ?',
        [$date],
        0
    );
    $deliveriesCompleted = (int) db_value(
        'SELECT COUNT(*) FROM orders WHERE DATE(delivery_completed_at) = ?',
        [$date],
        0
    );
    $cancelled = (int) db_value(
        'SELECT COUNT(*) FROM orders WHERE order_date = ? AND status = \'cancelled\'',
        [$date],
        0
    );

    $collected = (float) db_value(
        'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE voided_at IS NULL AND DATE(collected_at) = ?',
        [$date],
        0
    );
    $collectionCount = (int) db_value(
        'SELECT COUNT(*) FROM payments WHERE voided_at IS NULL AND DATE(collected_at) = ?',
        [$date],
        0
    );
    $byMethod = db_all(
        'SELECT method, COUNT(*) AS total, COALESCE(SUM(amount), 0) AS value
         FROM payments WHERE voided_at IS NULL AND DATE(collected_at) = ? GROUP BY method',
        [$date]
    );

    $pendingDelivery = db_all(
        "SELECT o.*, c.name AS customer_name FROM orders o
         JOIN customers c ON c.id = o.customer_id
         WHERE o.status IN ('ready_for_delivery','out_for_delivery')
         ORDER BY o.scheduled_delivery_date ASC, o.id ASC"
    );

    $pendingPayment = db_all(
        "SELECT o.*, c.name AS customer_name FROM orders o
         JOIN customers c ON c.id = o.customer_id
         WHERE o.payment_status <> 'paid' AND o.status <> 'cancelled'
         ORDER BY o.order_date ASC, o.id ASC"
    );
    $outstanding = 0.0;
    foreach ($pendingPayment as $row) {
        $outstanding += (float) $row['total_amount'] - (float) $row['paid_amount'];
    }

    $newCustomers = (int) db_value(
        'SELECT COUNT(*) FROM customers WHERE DATE(created_at) = ?',
        [$date],
        0
    );

    $staffPerformance = db_all(
        "SELECT u.id, u.name, u.role,
                (SELECT COUNT(*) FROM orders o WHERE o.pickup_staff_id = u.id AND DATE(o.pickup_completed_at) = ?) AS pickups,
                (SELECT COUNT(*) FROM orders o WHERE o.delivery_staff_id = u.id AND DATE(o.delivery_completed_at) = ?) AS deliveries,
                (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.voided_at IS NULL AND p.collected_by = u.id AND DATE(p.collected_at) = ?) AS collected
         FROM users u
         WHERE u.role IN ('staff','pickup','delivery')
         ORDER BY u.name",
        [$date, $date, $date]
    );

    return [
        'date' => $date,
        'orders_created' => $ordersCreated,
        'order_value' => $orderValue,
        'by_status' => $byStatus,
        'pickups_completed' => $pickupsCompleted,
        'deliveries_completed' => $deliveriesCompleted,
        'cancelled' => $cancelled,
        'collected' => $collected,
        'collection_count' => $collectionCount,
        'by_method' => $byMethod,
        'pending_delivery' => $pendingDelivery,
        'pending_delivery_count' => count($pendingDelivery),
        'pending_payment' => $pendingPayment,
        'pending_payment_count' => count($pendingPayment),
        'outstanding' => $outstanding,
        'new_customers' => $newCustomers,
        'staff_performance' => $staffPerformance,
    ];
}

function daily_report_csv(string $date): string
{
    $data = daily_report_data($date);
    $lines = [];
    $lines[] = 'Laundry Daily Report';
    $lines[] = 'Date,' . $date;
    $lines[] = '';
    $lines[] = 'Section,Metric,Value';
    $lines[] = 'Order Activity,Orders booked,' . $data['orders_created'];
    $lines[] = 'Order Activity,Order value,' . $data['order_value'];
    $lines[] = 'Order Activity,Pickups completed,' . $data['pickups_completed'];
    $lines[] = 'Order Activity,Deliveries completed,' . $data['deliveries_completed'];
    $lines[] = 'Order Activity,Cancelled,' . $data['cancelled'];
    $lines[] = 'Order Activity,New customers,' . $data['new_customers'];
    $lines[] = '';
    $lines[] = 'Status Breakdown,Status,Orders,Value';
    foreach ($data['by_status'] as $key => $row) {
        $lines[] = 'Status Breakdown,' . $row['label'] . ',' . $row['count'] . ',' . $row['value'];
    }
    $lines[] = '';
    $lines[] = 'Collections,Method,Transactions,Amount';
    foreach ($data['by_method'] as $row) {
        $lines[] = 'Collections,' . (payment_methods()[(string) $row['method']] ?? $row['method']) . ',' . $row['total'] . ',' . $row['value'];
    }
    $lines[] = 'Collections,Total,' . $data['collection_count'] . ',' . $data['collected'];
    $lines[] = '';
    $lines[] = 'Pending Deliveries,Order No,Customer,Phone,Address,Status,Amount';
    foreach ($data['pending_delivery'] as $row) {
        $lines[] = 'Pending Deliveries,' . $row['order_number'] . ',' . $row['contact_name'] . ',' . $row['contact_phone']
            . ',' . str_replace(["\r", "\n", ','], ' ', (string) $row['delivery_address'])
            . ',' . order_status_label((string) $row['status']) . ',' . $row['total_amount'];
    }
    $lines[] = 'Pending Deliveries,Total Count,' . $data['pending_delivery_count'] . ',';
    $lines[] = '';
    $lines[] = 'Pending Payments,Order No,Customer,Phone,Total,Paid,Due,Status';
    foreach ($data['pending_payment'] as $row) {
        $due = (float) $row['total_amount'] - (float) $row['paid_amount'];
        $lines[] = 'Pending Payments,' . $row['order_number'] . ',' . $row['contact_name'] . ',' . $row['contact_phone']
            . ',' . $row['total_amount'] . ',' . $row['paid_amount'] . ',' . $due . ',' . payment_status_label((string) $row['payment_status']);
    }
    $lines[] = 'Pending Payments,Total Count,' . $data['pending_payment_count'] . ',' . $data['outstanding'];
    $lines[] = '';
    $lines[] = 'Staff Performance,Name,Role,Pickups,Deliveries,Collected';
    foreach ($data['staff_performance'] as $row) {
        $lines[] = 'Staff Performance,' . $row['name'] . ',' . role_label((string) $row['role'])
            . ',' . $row['pickups'] . ',' . $row['deliveries'] . ',' . $row['collected'];
    }

    return implode("\r\n", $lines) . "\r\n";
}
