<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script can only be run from the command line.');
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/orders.php';
require_once dirname(__DIR__) . '/includes/order-items.php';

function seed_user(string $role, string $name, string $phone, string $email, string $password): int
{
    $existing = db_row('SELECT id FROM users WHERE role = ? AND phone_key = ?', [$role, phone_key($phone)]);
    if ($existing !== null) {
        return (int) $existing['id'];
    }
    return db_insert(
        'INSERT INTO users (role, name, email, phone, phone_key, password_hash, must_change_password) VALUES (?, ?, ?, ?, ?, ?, 0)',
        [$role, $name, $email, $phone, phone_key($phone), password_hash($password, PASSWORD_DEFAULT)]
    );
}

function seed_customer(string $name, string $phone, string $email, string $address): int
{
    $existing = db_row('SELECT id FROM customers WHERE phone_key = ?', [phone_key($phone)]);
    if ($existing !== null) {
        return (int) $existing['id'];
    }
    $userId = db_insert(
        'INSERT INTO users (role, name, email, phone, phone_key, password_hash, must_change_password) VALUES (?, ?, ?, ?, ?, ?, 0)',
        ['customer', $name, $email, $phone, phone_key($phone), password_hash('customer123', PASSWORD_DEFAULT)]
    );
    return db_insert(
        'INSERT INTO customers (user_id, name, phone, phone_key, email, address, city, pincode, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$userId, $name, $phone, phone_key($phone), $email, $address, 'Pune', '411001', $userId]
    );
}

$superAdminId = seed_user('super_admin', 'System Super Admin', '9000000001', 'superadmin@laundryfriends.test', 'superadmin@123');
$adminId = seed_user('admin', 'Store Owner', '9000000002', 'owner@laundryfriends.test', 'owner@123');
$pickupId = seed_user('staff', 'Ramesh Kumar', '9000000003', 'ramesh@laundryfriends.test', 'pickup@123');
$deliveryId = seed_user('staff', 'Suresh Patil', '9000000004', 'suresh@laundryfriends.test', 'delivery@123');
$pickup2Id = seed_user('staff', 'Vikas Sharma', '9000000005', 'vikas@laundryfriends.test', 'pickup@123');
$delivery2Id = seed_user('staff', 'Anita Desai', '9000000006', 'anita@laundryfriends.test', 'delivery@123');

echo "Users ready.\n";

$services = [
    ['Wash & Iron', 'per_piece', 15],
    ['Wash & Fold', 'per_piece', 10],
    ['Wash & Fold (kg)', 'per_kg', 60],
    ['Dry Clean', 'per_piece', 90],
    ['Shoe Cleaning', 'per_piece', 200],
];

$serviceIds = [];
foreach ($services as [$serviceName, $mode, $rate]) {
    $existing = db_row('SELECT id FROM services WHERE service_name = ?', [$serviceName]);
    $serviceIds[$serviceName] = $existing !== null
        ? (int) $existing['id']
        : db_insert(
            'INSERT INTO services (service_name, price_mode, rate, is_active) VALUES (?, ?, ?, 1)',
            [$serviceName, $mode, $rate]
        );
}

$catalog = [
    ['Shirt', 'Wash & Iron', 'wash_iron', 'per_piece', 15, 0],
    ['Trouser', 'Wash & Iron', 'wash_iron', 'per_piece', 20, 0],
    ['Jeans', 'Wash & Iron', 'wash_iron', 'per_piece', 25, 0],
    ['T-Shirt', 'Wash & Fold', 'wash_fold', 'per_piece', 10, 0],
    ['Towel Set', 'Wash & Fold', 'wash_fold', 'per_piece', 12, 0],
    ['Curtain', 'Wash & Fold (kg)', 'wash_fold', 'per_kg', 0, 90],
    ['Bed Cover', 'Wash & Fold (kg)', 'wash_fold', 'per_kg', 0, 110],
    ['Mixed Laundry (kg)', 'Wash & Fold (kg)', 'wash_fold', 'per_kg', 0, 60],
    ['Blanket', 'Wash & Fold (kg)', 'wash_fold', 'per_kg', 0, 80],
    ['Dress / Saree', 'Dry Clean', 'dry_clean', 'per_piece', 90, 0],
    ['Suit / Blazer', 'Dry Clean', 'dry_clean', 'per_piece', 120, 0],
    ['Sneakers', 'Shoe Cleaning', 'shoe_clean', 'per_piece', 200, 0],
];

foreach ($catalog as [$name, $serviceName, $serviceType, $mode, $perPiece, $perKg]) {
    $serviceId = $serviceIds[$serviceName];
    db_exec(
        'INSERT INTO laundry_items (service_id, item_name, service_type, price_mode, rate_per_piece, rate_per_kg, is_active)
         VALUES (?, ?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE service_type = VALUES(service_type), price_mode = VALUES(price_mode),
         rate_per_piece = VALUES(rate_per_piece), rate_per_kg = VALUES(rate_per_kg), is_active = 1',
        [$serviceId, $name, $serviceType, $mode, $perPiece, $perKg]
    );
}
echo "Catalog ready.\n";

set_setting('default_delivery_charge', '40');
set_setting('tax_percent', '0');
set_setting('backup_retention_days', '7');
set_setting('business_name', 'Laundry Friends');
set_setting('business_tagline', 'Wash. Dry. Fold. Delivered.');

$customerA = seed_customer('Anita Sharma', '9812345670', 'anita.sharma@example.com', 'Flat 402, Sunrise Apartments, FC Road');
$customerB = seed_customer('Vikram Nair', '9812345671', 'vikram.nair@example.com', '18 Shivaji Nagar, Near Post Office');
$customerC = seed_customer('Meera Joshi', '9812345672', 'meera.joshi@example.com', 'Bungalow 7, Green Park, Baner Road');

echo "Customers ready.\n";

if ((int) db_value('SELECT COUNT(*) FROM orders', [], 0) > 0) {
    echo "Orders already exist, skipping demo order creation.\n";
    echo "Done.\n";
    exit(0);
}

$demoOrders = [
    [
        'customer' => $customerA,
        'status' => 'new',
        'pickup' => $pickupId,
        'delivery' => null,
        'items' => [
            ['item_name' => 'Shirt', 'service_type' => 'wash_iron', 'price_mode' => 'per_piece', 'qty' => 4, 'weight_kg' => 0, 'rate' => 15],
            ['item_name' => 'Trouser', 'service_type' => 'wash_iron', 'price_mode' => 'per_piece', 'qty' => 2, 'weight_kg' => 0, 'rate' => 20],
        ],
        'address' => 'Flat 402, Sunrise Apartments, FC Road, Pune - 411001',
    ],
    [
        'customer' => $customerB,
        'status' => 'picked_up',
        'pickup' => $pickup2Id,
        'delivery' => null,
        'items' => [
            ['item_name' => 'Mixed Laundry (kg)', 'service_type' => 'wash_fold', 'price_mode' => 'per_kg', 'qty' => 1, 'weight_kg' => 6.5, 'weight_verified' => 1, 'rate' => 60],
        ],
        'address' => '18 Shivaji Nagar, Near Post Office, Pune - 411005',
    ],
    [
        'customer' => $customerC,
        'status' => 'ready_for_delivery',
        'pickup' => $pickupId,
        'delivery' => $deliveryId,
        'items' => [
            ['item_name' => 'Suit / Blazer', 'service_type' => 'dry_clean', 'price_mode' => 'per_piece', 'qty' => 1, 'weight_kg' => 0, 'rate' => 120],
            ['item_name' => 'Shirt', 'service_type' => 'wash_iron', 'price_mode' => 'per_piece', 'qty' => 3, 'weight_kg' => 0, 'rate' => 15],
        ],
        'address' => 'Bungalow 7, Green Park, Baner Road, Pune - 411045',
    ],
    [
        'customer' => $customerA,
        'status' => 'delivered',
        'pickup' => $pickupId,
        'delivery' => $delivery2Id,
        'items' => [
            ['item_name' => 'Curtain', 'service_type' => 'wash_fold', 'price_mode' => 'per_kg', 'qty' => 1, 'weight_kg' => 4, 'weight_verified' => 1, 'rate' => 90],
        ],
        'address' => 'Flat 402, Sunrise Apartments, FC Road, Pune - 411001',
    ],
];

foreach ($demoOrders as $demo) {
    $customer = db_row('SELECT * FROM customers WHERE id = ?', [$demo['customer']]);
    $orderId = create_order([
        'customer_id' => (int) $demo['customer'],
        'source' => 'customer',
        'pricing_mode' => 'per_item',
        'status' => $demo['status'],
        'order_date' => today(),
        'scheduled_pickup_date' => today(),
        'scheduled_delivery_date' => today(),
        'contact_name' => (string) $customer['name'],
        'contact_phone' => (string) $customer['phone'],
        'delivery_address' => (string) $demo['address'],
        'pickup_staff_id' => $demo['pickup'],
        'delivery_staff_id' => $demo['delivery'],
        'discount_amount' => 0,
        'delivery_charge' => 40,
        'tax_amount' => 0,
        'notes' => 'Handle with care.',
    ], $demo['items'], $adminId);

    if (in_array($demo['status'], ['picked_up', 'ready_for_delivery', 'delivered'], true)) {
        db_exec('UPDATE orders SET pickup_completed_at = ?, pickup_assigned_at = ? WHERE id = ?', [now(), now(), $orderId]);
    }
    if ($demo['status'] === 'delivered') {
        db_exec(
            'UPDATE orders SET delivery_completed_at = ?, delivery_assigned_at = ? WHERE id = ?',
            [now(), now(), $orderId]
        );
        $total = (float) db_value('SELECT total_amount FROM orders WHERE id = ?', [$orderId], 0);
        record_payment($orderId, $total, 'upi', 'UPI' . random_int(100000, 999999), null, $delivery2Id);
    }
}

echo "Demo orders created.\n";
echo "Done.\n";
