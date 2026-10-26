-- ============================================================
-- Laundry Friends - production schema upgrade (phpMyAdmin safe)
--
-- Use this when the live site shows a generic save error after the
-- services/order upgrade. It adds the columns used by order creation
-- and maps the existing catalog and order lines to services.
--
-- BEFORE RUNNING:
--   1. Back up the production database.
--   2. Select the production database in phpMyAdmin (the script uses
--      DATABASE(), so it must not be empty).
--   3. Paste this whole file into the SQL tab and click Go.
--
-- The script is idempotent: it is safe to run more than once.
-- It does NOT merge customers and does NOT add demo data.
-- ============================================================

SET NAMES utf8mb4;

-- 1) Services table
CREATE TABLE IF NOT EXISTS `services` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_name` VARCHAR(120) NOT NULL,
  `price_mode` ENUM('per_piece','per_kg') NOT NULL DEFAULT 'per_piece',
  `rate` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `min_weight_kg` DECIMAL(10,3) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_services_name` (`service_name`),
  KEY `idx_services_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @db := DATABASE();

-- 2) laundry_items.service_id
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='laundry_items' AND COLUMN_NAME='service_id')=0,
  'ALTER TABLE `laundry_items` ADD COLUMN `service_id` INT UNSIGNED NULL AFTER `id`',
  'SET @noop = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) orders.delivery_assigned_at (the column that broke order creation)
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='orders' AND COLUMN_NAME='delivery_assigned_at')=0,
  'ALTER TABLE `orders` ADD COLUMN `delivery_assigned_at` DATETIME NULL AFTER `pickup_assigned_at`',
  'SET @noop = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) order_items new columns
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='order_items' AND COLUMN_NAME='service_id')=0,
  'ALTER TABLE `order_items` ADD COLUMN `service_id` INT UNSIGNED NULL AFTER `order_id`',
  'SET @noop = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='order_items' AND COLUMN_NAME='service_name')=0,
  'ALTER TABLE `order_items` ADD COLUMN `service_name` VARCHAR(120) NULL AFTER `item_name`',
  'SET @noop = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='order_items' AND COLUMN_NAME='weight_verified')=0,
  'ALTER TABLE `order_items` ADD COLUMN `weight_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `weight_kg`',
  'SET @noop = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='order_items' AND COLUMN_NAME='weight_verified_by')=0,
  'ALTER TABLE `order_items` ADD COLUMN `weight_verified_by` INT UNSIGNED NULL AFTER `weight_verified`',
  'SET @noop = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='order_items' AND COLUMN_NAME='weight_verified_at')=0,
  'ALTER TABLE `order_items` ADD COLUMN `weight_verified_at` DATETIME NULL AFTER `weight_verified_by`',
  'SET @noop = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5) Create services from the existing catalog
INSERT INTO `services` (`service_name`, `price_mode`, `rate`, `is_active`)
SELECT
  CASE
    WHEN g.`price_mode` = 'per_kg' AND g.`mode_count` > 1 THEN CONCAT(g.`label`, ' (kg)')
    ELSE g.`label`
  END,
  g.`price_mode`,
  COALESCE(g.`sample_rate`, 0),
  1
FROM (
  SELECT
    l.`service_type`,
    l.`price_mode`,
    CASE l.`service_type`
      WHEN 'wash_fold' THEN 'Wash & Fold'
      WHEN 'wash_iron' THEN 'Wash & Iron'
      WHEN 'dry_clean' THEN 'Dry Clean'
      WHEN 'iron_only' THEN 'Ironing Only'
      WHEN 'shoe_clean' THEN 'Shoe Cleaning'
      WHEN 'bed_cover' THEN 'Bed Cover / Curtain'
      ELSE 'Other'
    END AS `label`,
    (SELECT COUNT(DISTINCT l2.`price_mode`) FROM `laundry_items` l2 WHERE l2.`service_type` = l.`service_type`) AS `mode_count`,
    MIN(NULLIF(CASE WHEN l.`price_mode` = 'per_kg' THEN l.`rate_per_kg` ELSE l.`rate_per_piece` END, 0)) AS `sample_rate`
  FROM `laundry_items` l
  WHERE l.`service_id` IS NULL
  GROUP BY l.`service_type`, l.`price_mode`
) g
WHERE NOT EXISTS (
  SELECT 1 FROM `services` s WHERE s.`service_name` = CASE
    WHEN g.`price_mode` = 'per_kg' AND g.`mode_count` > 1 THEN CONCAT(g.`label`, ' (kg)')
    ELSE g.`label`
  END
);

-- 6) Map catalog items to their service
UPDATE `laundry_items` i
JOIN (
  SELECT
    l.`service_type`,
    l.`price_mode`,
    CASE
      WHEN l.`price_mode` = 'per_kg' AND (SELECT COUNT(DISTINCT l2.`price_mode`) FROM `laundry_items` l2 WHERE l2.`service_type` = l.`service_type`) > 1
        THEN CONCAT(CASE l.`service_type`
          WHEN 'wash_fold' THEN 'Wash & Fold'
          WHEN 'wash_iron' THEN 'Wash & Iron'
          WHEN 'dry_clean' THEN 'Dry Clean'
          WHEN 'iron_only' THEN 'Ironing Only'
          WHEN 'shoe_clean' THEN 'Shoe Cleaning'
          WHEN 'bed_cover' THEN 'Bed Cover / Curtain'
          ELSE 'Other'
        END, ' (kg)')
      ELSE CASE l.`service_type`
        WHEN 'wash_fold' THEN 'Wash & Fold'
        WHEN 'wash_iron' THEN 'Wash & Iron'
        WHEN 'dry_clean' THEN 'Dry Clean'
        WHEN 'iron_only' THEN 'Ironing Only'
        WHEN 'shoe_clean' THEN 'Shoe Cleaning'
        WHEN 'bed_cover' THEN 'Bed Cover / Curtain'
        ELSE 'Other'
      END
    END AS `service_name`
  FROM `laundry_items` l
  WHERE l.`service_id` IS NULL
  GROUP BY l.`service_type`, l.`price_mode`
) m ON m.`service_type` = i.`service_type` AND m.`price_mode` = i.`price_mode`
JOIN `services` s ON s.`service_name` = m.`service_name`
SET i.`service_id` = s.`id`
WHERE i.`service_id` IS NULL;

-- 7) Map existing order lines to their service
UPDATE `order_items` oi
JOIN `services` s
  ON s.`price_mode` = oi.`price_mode`
 AND s.`service_name` = CASE
      WHEN oi.`price_mode` = 'per_kg' AND (SELECT COUNT(DISTINCT l2.`price_mode`) FROM `laundry_items` l2 WHERE l2.`service_type` = oi.`service_type`) > 1
        THEN CONCAT(CASE oi.`service_type`
          WHEN 'wash_fold' THEN 'Wash & Fold'
          WHEN 'wash_iron' THEN 'Wash & Iron'
          WHEN 'dry_clean' THEN 'Dry Clean'
          WHEN 'iron_only' THEN 'Ironing Only'
          WHEN 'shoe_clean' THEN 'Shoe Cleaning'
          WHEN 'bed_cover' THEN 'Bed Cover / Curtain'
          ELSE 'Other'
        END, ' (kg)')
      ELSE CASE oi.`service_type`
        WHEN 'wash_fold' THEN 'Wash & Fold'
        WHEN 'wash_iron' THEN 'Wash & Iron'
        WHEN 'dry_clean' THEN 'Dry Clean'
        WHEN 'iron_only' THEN 'Ironing Only'
        WHEN 'shoe_clean' THEN 'Shoe Cleaning'
        WHEN 'bed_cover' THEN 'Bed Cover / Curtain'
        ELSE 'Other'
      END
    END
SET oi.`service_id` = s.`id`,
    oi.`service_name` = s.`service_name`
WHERE oi.`service_id` IS NULL;

-- 8) Legacy kg lines with a recorded weight were already weighed
UPDATE `order_items`
SET `weight_verified` = 1,
    `weight_verified_at` = COALESCE(`weight_verified_at`, NOW())
WHERE `price_mode` = 'per_kg'
  AND `weight_kg` > 0
  AND `weight_verified` = 0;

-- 9) Indexes: item names are unique per service now
SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='laundry_items' AND INDEX_NAME='uq_laundry_item')>0,
  'ALTER TABLE `laundry_items` DROP INDEX `uq_laundry_item`',
  'SET @noop = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Only add the per-service unique key when the catalog has no duplicates,
-- so the upgrade never stops on a pre-existing duplicate item name.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='laundry_items' AND INDEX_NAME='uq_laundry_item_service')=0
  AND (SELECT COUNT(*) FROM (
        SELECT `service_id`, `item_name`
        FROM `laundry_items`
        WHERE `service_id` IS NOT NULL
        GROUP BY `service_id`, `item_name`
        HAVING COUNT(*) > 1
      ) dup)=0,
  'ALTER TABLE `laundry_items` ADD UNIQUE KEY `uq_laundry_item_service` (`service_id`, `item_name`)',
  'SET @noop = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='laundry_items' AND INDEX_NAME='idx_laundry_items_service')=0,
  'ALTER TABLE `laundry_items` ADD KEY `idx_laundry_items_service` (`service_id`)',
  'SET @noop = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='order_items' AND INDEX_NAME='idx_order_items_service')=0,
  'ALTER TABLE `order_items` ADD KEY `idx_order_items_service` (`service_id`)',
  'SET @noop = 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 10) Verification
SELECT 'services' AS check_name, COUNT(*) AS total FROM `services`
UNION ALL SELECT 'catalog_items', COUNT(*) FROM `laundry_items`
UNION ALL SELECT 'unmapped_catalog_items', COUNT(*) FROM `laundry_items` WHERE `service_id` IS NULL
UNION ALL SELECT 'unmapped_order_items', COUNT(*) FROM `order_items` WHERE `service_id` IS NULL;
