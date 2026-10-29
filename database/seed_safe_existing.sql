-- ============================================================
-- Laundry Friends - Dummy Test Data (SAFE for existing data)
-- Import AFTER schema.sql, into the SAME database.
-- Re-running this script is safe: it never overwrites existing rows.
-- ============================================================
-- Demo logins:
--   Owner          owner@laundryfriends.test / owner@123  (mobile 9000000002)
--   Super admin    superadmin@laundryfriends.test / superadmin@123
--   Pickup staff   ramesh@laundryfriends.test / pickup@123  (mobile 9000000003)
--   Pickup staff   vikas@laundryfriends.test / pickup@123   (mobile 9000000005)
--   Delivery staff suresh@laundryfriends.test / delivery@123 (mobile 9000000004)
--   Delivery staff anita@laundryfriends.test / delivery@123  (mobile 9000000006)
--   Customer       anita.sharma@example.com / customer123    (mobile 9812345670)
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1) Users (skipped automatically if the email or mobile exists)
-- ------------------------------------------------------------
INSERT IGNORE INTO `users` (`role`, `name`, `email`, `phone`, `phone_key`, `password_hash`, `is_active`, `must_change_password`) VALUES
('super_admin', 'System Super Admin', 'superadmin@laundryfriends.test', '9000000001', '9000000001', '$2y$10$ymZ8fjpET7m35X5WucfMtuZDFz1/edGkHrWePsXbV1YA3hgKTk0Uu', 1, 0),
('admin',       'Store Owner',        'owner@laundryfriends.test',      '9000000002', '9000000002', '$2y$10$itOvgf7uaQiJ4hAohBe4veGUXP.m2129FRu0FSFz6rmT0cK8aBCri', 1, 0),
('staff',       'Ramesh Kumar',       'ramesh@laundryfriends.test',     '9000000003', '9000000003', '$2y$10$spUROQGt6rNP7b7H6gpRb.qcnWWUm.F9fplMmHrwguhXFA9tmsF.2', 1, 0),
('staff',       'Suresh Patil',       'suresh@laundryfriends.test',     '9000000004', '9000000004', '$2y$10$buzYeD4lQcDt0Jah9DuhI.dXjjb2EotlwX0snTD4dfRe9vtI.a6qm', 1, 0),
('staff',       'Vikas Sharma',       'vikas@laundryfriends.test',      '9000000005', '9000000005', '$2y$10$spUROQGt6rNP7b7H6gpRb.qcnWWUm.F9fplMmHrwguhXFA9tmsF.2', 1, 0),
('staff',       'Anita Desai',        'anita@laundryfriends.test',      '9000000006', '9000000006', '$2y$10$buzYeD4lQcDt0Jah9DuhI.dXjjb2EotlwX0snTD4dfRe9vtI.a6qm', 1, 0);

-- Demo customer login only when that mobile is not already used by a real customer
INSERT IGNORE INTO `users` (`role`, `name`, `email`, `phone`, `phone_key`, `password_hash`, `is_active`, `must_change_password`)
SELECT 'customer', 'Anita Sharma', 'anita.sharma@example.com', '9812345670', '9812345670', '$2y$10$1mSW5VkAhXvFD8MLosTptuOdBzD6YWRLT.uLN3NYkA4BVCih8vzEC', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `customers` WHERE phone_key = '9812345670');

-- ------------------------------------------------------------
-- 2) Laundry catalog (skipped if the service/item already exists)
-- ------------------------------------------------------------
INSERT IGNORE INTO `services` (`service_name`, `price_mode`, `category`, `rate`, `is_active`) VALUES
('Wash & Iron', 'per_piece', 'piece', 15.00, 1),
('Wash & Fold', 'per_piece', 'piece', 10.00, 1),
('Wash & Fold (kg)', 'per_kg', 'kg', 60.00, 1),
('Dry Clean', 'per_piece', 'piece', 90.00, 1),
('Shoe Cleaning', 'per_piece', 'piece', 200.00, 1),
('Express & Add-ons', 'per_piece', 'extra', 50.00, 1);

-- Every item must belong to a service. Without service_id the item picker and
-- catalog page cannot load the item, so resolve the service by name here.
INSERT IGNORE INTO `laundry_items` (`service_id`, `item_name`, `service_type`, `price_mode`, `rate_per_piece`, `rate_per_kg`, `is_active`)
SELECT s.id, c.item_name, c.service_type, c.price_mode, c.rate_per_piece, c.rate_per_kg, 1
FROM (
  SELECT 'Shirt' AS item_name, 'Wash & Iron' AS service_name, 'wash_iron' AS service_type, 'per_piece' AS price_mode, 15.00 AS rate_per_piece, 0 AS rate_per_kg
  UNION ALL SELECT 'Trouser', 'Wash & Iron', 'wash_iron', 'per_piece', 20.00, 0
  UNION ALL SELECT 'Jeans', 'Wash & Iron', 'wash_iron', 'per_piece', 25.00, 0
  UNION ALL SELECT 'T-Shirt', 'Wash & Fold', 'wash_fold', 'per_piece', 10.00, 0
  UNION ALL SELECT 'Towel Set', 'Wash & Fold', 'wash_fold', 'per_piece', 12.00, 0
  UNION ALL SELECT 'Curtain', 'Wash & Fold (kg)', 'wash_fold', 'per_kg', 0, 90.00
  UNION ALL SELECT 'Bed Cover', 'Wash & Fold (kg)', 'wash_fold', 'per_kg', 0, 110.00
  UNION ALL SELECT 'Mixed Laundry (kg)', 'Wash & Fold (kg)', 'wash_fold', 'per_kg', 0, 60.00
  UNION ALL SELECT 'Blanket', 'Wash & Fold (kg)', 'wash_fold', 'per_kg', 0, 80.00
  UNION ALL SELECT 'Dress / Saree', 'Dry Clean', 'dry_clean', 'per_piece', 90.00, 0
  UNION ALL SELECT 'Suit / Blazer', 'Dry Clean', 'dry_clean', 'per_piece', 120.00, 0
  UNION ALL SELECT 'Sneakers', 'Shoe Cleaning', 'shoe_clean', 'per_piece', 200.00, 0
  UNION ALL SELECT 'Express Delivery', 'Express & Add-ons', 'other', 'per_piece', 50.00, 0
  UNION ALL SELECT 'Stain Removal', 'Express & Add-ons', 'other', 'per_piece', 30.00, 0
  UNION ALL SELECT 'Extra Detergent', 'Express & Add-ons', 'other', 'per_piece', 20.00, 0
) AS c
JOIN `services` s ON s.service_name = c.service_name;

-- ------------------------------------------------------------
-- 3) Settings (skipped if the setting already exists)
-- ------------------------------------------------------------
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('default_delivery_charge', '40'),
('tax_percent', '0'),
('backup_retention_days', '7'),
('business_name', 'Laundry Friends'),
('business_tagline', 'Wash. Dry. Fold. Delivered.');

-- ------------------------------------------------------------
-- 4) Customers (skipped if the mobile number already exists)
-- ------------------------------------------------------------
INSERT IGNORE INTO `customers` (`user_id`, `name`, `phone`, `phone_key`, `email`, `address`, `city`, `pincode`, `created_by`)
SELECT u.id, 'Anita Sharma', '9812345670', '9812345670', 'anita.sharma@example.com', 'Flat 402, Sunrise Apartments, FC Road', 'Pune', '411001', NULL
FROM `users` u
WHERE u.email = 'anita.sharma@example.com'
LIMIT 1;

INSERT IGNORE INTO `customers` (`user_id`, `name`, `phone`, `phone_key`, `email`, `address`, `city`, `pincode`, `created_by`) VALUES
(NULL, 'Vikram Nair', '9812345671', '9812345671', 'vikram.nair@example.com', '18 Shivaji Nagar, Near Post Office', 'Pune', '411005', NULL),
(NULL, 'Meera Joshi', '9812345672', '9812345672', 'meera.joshi@example.com', 'Bungalow 7, Green Park, Baner Road', 'Pune', '411045', NULL);

-- ------------------------------------------------------------
-- 5) Demo orders (skipped if order number DEMO-xxxx already exists)
-- ------------------------------------------------------------
-- DEMO-1001: new order waiting for pickup (Anita / Ramesh) - unpaid, 4x Shirt + 2x Trouser
INSERT IGNORE INTO `orders`
(`order_number`, `customer_id`, `source`, `pricing_mode`, `status`, `payment_status`, `order_date`,
 `scheduled_pickup_date`, `scheduled_delivery_date`, `contact_name`, `contact_phone`, `delivery_address`,
 `pickup_staff_id`, `delivery_staff_id`, `pickup_assigned_at`, `pickup_completed_at`, `delivery_completed_at`,
 `subtotal`, `discount_amount`, `delivery_charge`, `tax_amount`, `total_amount`, `paid_amount`, `notes`)
SELECT 'DEMO-1001', c.id, 'admin', 'per_item', 'new', 'pending', CURDATE(),
       CURDATE(), NULL, 'Anita Sharma', '9812345670', 'Flat 402, Sunrise Apartments, FC Road, Pune - 411001',
       (SELECT id FROM `users` WHERE email = 'ramesh@laundryfriends.test' LIMIT 1), NULL, NULL, NULL, NULL,
       100.00, 0.00, 40.00, 0.00, 140.00, 0.00, 'Handle with care.'
FROM `customers` c WHERE c.phone_key = '9812345670' AND c.email = 'anita.sharma@example.com';

-- DEMO-1002: picked up, at facility (Vikram / Vikas) - unpaid, 6.5 kg mixed laundry
INSERT IGNORE INTO `orders`
(`order_number`, `customer_id`, `source`, `pricing_mode`, `status`, `payment_status`, `order_date`,
 `scheduled_pickup_date`, `scheduled_delivery_date`, `contact_name`, `contact_phone`, `delivery_address`,
 `pickup_staff_id`, `delivery_staff_id`, `pickup_assigned_at`, `pickup_completed_at`, `delivery_completed_at`,
 `subtotal`, `discount_amount`, `delivery_charge`, `tax_amount`, `total_amount`, `paid_amount`, `notes`)
SELECT 'DEMO-1002', c.id, 'admin', 'per_item', 'picked_up', 'pending', CURDATE(),
       CURDATE(), NULL, 'Vikram Nair', '9812345671', '18 Shivaji Nagar, Near Post Office, Pune - 411005',
       (SELECT id FROM `users` WHERE email = 'vikas@laundryfriends.test' LIMIT 1), NULL, NOW(), NOW(), NULL,
       390.00, 0.00, 40.00, 0.00, 430.00, 0.00, 'Handle with care.'
FROM `customers` c WHERE c.phone_key = '9812345671' AND c.email = 'vikram.nair@example.com';

-- DEMO-1003: ready for delivery (Meera / Suresh) - unpaid, Suit + 3x Shirt
INSERT IGNORE INTO `orders`
(`order_number`, `customer_id`, `source`, `pricing_mode`, `status`, `payment_status`, `order_date`,
 `scheduled_pickup_date`, `scheduled_delivery_date`, `contact_name`, `contact_phone`, `delivery_address`,
 `pickup_staff_id`, `delivery_staff_id`, `pickup_assigned_at`, `pickup_completed_at`, `delivery_completed_at`,
 `subtotal`, `discount_amount`, `delivery_charge`, `tax_amount`, `total_amount`, `paid_amount`, `notes`)
SELECT 'DEMO-1003', c.id, 'admin', 'per_item', 'ready_for_delivery', 'pending', CURDATE(),
       CURDATE(), CURDATE(), 'Meera Joshi', '9812345672', 'Bungalow 7, Green Park, Baner Road, Pune - 411045',
       (SELECT id FROM `users` WHERE email = 'ramesh@laundryfriends.test' LIMIT 1),
       (SELECT id FROM `users` WHERE email = 'suresh@laundryfriends.test' LIMIT 1),
       NOW(), NOW(), NULL,
       165.00, 0.00, 40.00, 0.00, 205.00, 0.00, 'Handle with care.'
FROM `customers` c WHERE c.phone_key = '9812345672' AND c.email = 'meera.joshi@example.com';

-- DEMO-1004: delivered and fully paid (Anita / Anita Desai) - 4 kg curtain, paid by UPI
INSERT IGNORE INTO `orders`
(`order_number`, `customer_id`, `source`, `pricing_mode`, `status`, `payment_status`, `order_date`,
 `scheduled_pickup_date`, `scheduled_delivery_date`, `contact_name`, `contact_phone`, `delivery_address`,
 `pickup_staff_id`, `delivery_staff_id`, `pickup_assigned_at`, `pickup_completed_at`, `delivery_completed_at`,
 `subtotal`, `discount_amount`, `delivery_charge`, `tax_amount`, `total_amount`, `paid_amount`, `notes`)
SELECT 'DEMO-1004', c.id, 'admin', 'per_item', 'delivered', 'paid', CURDATE(),
       CURDATE(), CURDATE(), 'Anita Sharma', '9812345670', 'Flat 402, Sunrise Apartments, FC Road, Pune - 411001',
       (SELECT id FROM `users` WHERE email = 'ramesh@laundryfriends.test' LIMIT 1),
       (SELECT id FROM `users` WHERE email = 'anita@laundryfriends.test' LIMIT 1),
       NOW(), NOW(), NOW(),
       360.00, 0.00, 40.00, 0.00, 400.00, 400.00, 'Handle with care.'
FROM `customers` c WHERE c.phone_key = '9812345670' AND c.email = 'anita.sharma@example.com';

-- ------------------------------------------------------------
-- 6) Order items (added only if the order has no items yet)
-- ------------------------------------------------------------
INSERT INTO `order_items` (`order_id`, `item_name`, `service_type`, `price_mode`, `qty`, `weight_kg`, `rate`, `amount`, `notes`)
SELECT o.id, 'Shirt', 'wash_iron', 'per_piece', 4, 0, 15.00, 60.00, NULL FROM `orders` o
WHERE o.order_number = 'DEMO-1001' AND NOT EXISTS (SELECT 1 FROM `order_items` oi WHERE oi.order_id = o.id);

INSERT INTO `order_items` (`order_id`, `item_name`, `service_type`, `price_mode`, `qty`, `weight_kg`, `rate`, `amount`, `notes`)
SELECT o.id, 'Trouser', 'wash_iron', 'per_piece', 2, 0, 20.00, 40.00, NULL FROM `orders` o
WHERE o.order_number = 'DEMO-1001' AND NOT EXISTS (SELECT 1 FROM `order_items` oi WHERE oi.order_id = o.id AND oi.item_name = 'Trouser');

INSERT INTO `order_items` (`order_id`, `item_name`, `service_type`, `price_mode`, `qty`, `weight_kg`, `rate`, `amount`, `notes`)
SELECT o.id, 'Mixed Laundry (kg)', 'wash_fold', 'per_kg', 1, 6.5, 60.00, 390.00, NULL FROM `orders` o
WHERE o.order_number = 'DEMO-1002' AND NOT EXISTS (SELECT 1 FROM `order_items` oi WHERE oi.order_id = o.id);

INSERT INTO `order_items` (`order_id`, `item_name`, `service_type`, `price_mode`, `qty`, `weight_kg`, `rate`, `amount`, `notes`)
SELECT o.id, 'Suit / Blazer', 'dry_clean', 'per_piece', 1, 0, 120.00, 120.00, NULL FROM `orders` o
WHERE o.order_number = 'DEMO-1003' AND NOT EXISTS (SELECT 1 FROM `order_items` oi WHERE oi.order_id = o.id);

INSERT INTO `order_items` (`order_id`, `item_name`, `service_type`, `price_mode`, `qty`, `weight_kg`, `rate`, `amount`, `notes`)
SELECT o.id, 'Shirt', 'wash_iron', 'per_piece', 3, 0, 15.00, 45.00, NULL FROM `orders` o
WHERE o.order_number = 'DEMO-1003' AND NOT EXISTS (SELECT 1 FROM `order_items` oi WHERE oi.order_id = o.id AND oi.item_name = 'Shirt');

INSERT INTO `order_items` (`order_id`, `item_name`, `service_type`, `price_mode`, `qty`, `weight_kg`, `rate`, `amount`, `notes`)
SELECT o.id, 'Curtain', 'wash_fold', 'per_kg', 1, 4, 90.00, 360.00, NULL FROM `orders` o
WHERE o.order_number = 'DEMO-1004' AND NOT EXISTS (SELECT 1 FROM `order_items` oi WHERE oi.order_id = o.id);

-- ------------------------------------------------------------
-- 7) Payment for the delivered demo order (only if none recorded)
-- ------------------------------------------------------------
INSERT INTO `payments` (`order_id`, `amount`, `method`, `reference_no`, `notes`, `collected_by`, `collected_by_name`)
SELECT o.id, 400.00, 'upi', 'UPI837492', NULL,
       (SELECT id FROM `users` WHERE email = 'anita@laundryfriends.test' LIMIT 1),
       'Anita Desai'
FROM `orders` o
WHERE o.order_number = 'DEMO-1004' AND NOT EXISTS (SELECT 1 FROM `payments` p WHERE p.order_id = o.id);

-- ------------------------------------------------------------
-- 8) Initial status history (only if none exists for the order)
-- ------------------------------------------------------------
INSERT INTO `order_status_history` (`order_id`, `from_status`, `to_status`, `action`, `notes`, `user_id`, `user_name`, `user_role`)
SELECT o.id, NULL, o.status, 'seeded', 'Demo data', NULL, 'Seed Script', 'admin'
FROM `orders` o
WHERE o.order_number IN ('DEMO-1001', 'DEMO-1002', 'DEMO-1003', 'DEMO-1004')
  AND NOT EXISTS (SELECT 1 FROM `order_status_history` h WHERE h.order_id = o.id);

SET FOREIGN_KEY_CHECKS = 1;
