SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role ENUM('super_admin','admin','staff','customer') NOT NULL DEFAULT 'customer',
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NULL,
  phone VARCHAR(20) NULL,
  phone_key VARCHAR(20) NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_phone_key (phone_key),
  KEY idx_users_role (role),
  KEY idx_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  name VARCHAR(100) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  phone_key VARCHAR(20) NULL,
  email VARCHAR(150) NULL,
  address TEXT NULL,
  landmark VARCHAR(150) NULL,
  city VARCHAR(80) NULL,
  pincode VARCHAR(10) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  place_id VARCHAR(255) NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_customers_user (user_id),
  UNIQUE KEY uq_customers_phone_key (phone_key),
  KEY idx_customers_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_name VARCHAR(120) NOT NULL,
  price_mode ENUM('per_piece','per_kg') NOT NULL DEFAULT 'per_piece',
  category ENUM('piece','kg','extra') NOT NULL DEFAULT 'piece',
  rate DECIMAL(12,2) NOT NULL DEFAULT 0,
  min_weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_services_name (service_name),
  KEY idx_services_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS laundry_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_id INT UNSIGNED NULL,
  item_name VARCHAR(120) NOT NULL,
  service_type VARCHAR(40) NOT NULL DEFAULT 'wash_fold',
  price_mode ENUM('per_piece','per_kg') NOT NULL DEFAULT 'per_piece',
  rate_per_piece DECIMAL(12,2) NOT NULL DEFAULT 0,
  rate_per_kg DECIMAL(12,2) NOT NULL DEFAULT 0,
  min_weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_laundry_item_service (service_id, item_name),
  KEY idx_laundry_items_service (service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(30) NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  source ENUM('customer','admin') NOT NULL DEFAULT 'admin',
  pricing_mode ENUM('per_item','per_kg') NOT NULL DEFAULT 'per_item',
  status ENUM('new','pickup_assigned','picked_up','processing','ready_for_delivery','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'new',
  payment_status ENUM('pending','partial','paid') NOT NULL DEFAULT 'pending',
  order_date DATE NOT NULL,
  scheduled_pickup_date DATE NULL,
  scheduled_delivery_date DATE NULL,
  contact_name VARCHAR(100) NOT NULL,
  contact_phone VARCHAR(20) NOT NULL,
  delivery_address TEXT NOT NULL,
  pickup_latitude DECIMAL(10,7) NULL,
  pickup_longitude DECIMAL(10,7) NULL,
  pickup_place_id VARCHAR(255) NULL,
  pickup_staff_id INT UNSIGNED NULL,
  delivery_staff_id INT UNSIGNED NULL,
  pickup_assigned_at DATETIME NULL,
  delivery_assigned_at DATETIME NULL,
  pickup_completed_at DATETIME NULL,
  delivery_completed_at DATETIME NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  delivery_charge DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  staff_notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_orders_number (order_number),
  KEY idx_orders_customer (customer_id),
  KEY idx_orders_status (status),
  KEY idx_orders_pickup_staff (pickup_staff_id),
  KEY idx_orders_delivery_staff (delivery_staff_id),
  KEY idx_orders_payment (payment_status),
  KEY idx_orders_date (order_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  service_id INT UNSIGNED NULL,
  item_name VARCHAR(120) NOT NULL,
  service_name VARCHAR(120) NULL,
  service_type VARCHAR(40) NOT NULL DEFAULT 'wash_fold',
  price_mode ENUM('per_piece','per_kg') NOT NULL DEFAULT 'per_piece',
  qty INT UNSIGNED NOT NULL DEFAULT 1,
  weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0,
  weight_verified TINYINT(1) NOT NULL DEFAULT 0,
  weight_verified_by INT UNSIGNED NULL,
  weight_verified_at DATETIME NULL,
  rate DECIMAL(12,2) NOT NULL DEFAULT 0,
  catalog_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
  rate_changed_by INT UNSIGNED NULL,
  rate_changed_by_name VARCHAR(100) NULL,
  rate_change_reason VARCHAR(255) NULL,
  rate_changed_at DATETIME NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  KEY idx_order_items_order (order_id),
  KEY idx_order_items_service (service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_status_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  from_status VARCHAR(30) NULL,
  to_status VARCHAR(30) NOT NULL,
  action VARCHAR(50) NOT NULL,
  notes VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  user_name VARCHAR(100) NULL,
  user_role VARCHAR(30) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_history_order (order_id),
  KEY idx_history_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  method ENUM('cash','upi','card') NOT NULL DEFAULT 'cash',
  reference_no VARCHAR(100) NULL,
  notes VARCHAR(255) NULL,
  collected_by INT UNSIGNED NULL,
  collected_by_name VARCHAR(100) NULL,
  collected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  voided_at DATETIME NULL,
  KEY idx_payments_order (order_id),
  KEY idx_payments_date (collected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
  setting_value VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_key VARCHAR(40) NOT NULL,
  body TEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_whatsapp_event (event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  user_name VARCHAR(100) NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(60) NULL,
  entity_id VARCHAR(40) NULL,
  details VARCHAR(255) NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_user (user_id),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
