-- Migration: add structured checkout/payment fields on orders
-- Safe for repeated execution (checks information_schema before ALTER).
-- Run:
--   mysql -u root -p locally < database/migrations/20260505_orders_checkout_payment.sql

SET @db := DATABASE();

-- phone_number
SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'phone_number'
);
SET @sql := IF(
  @has_col = 0,
  'ALTER TABLE orders ADD COLUMN phone_number VARCHAR(32) NULL AFTER shipping_address',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- recovery_number
SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'recovery_number'
);
SET @sql := IF(
  @has_col = 0,
  'ALTER TABLE orders ADD COLUMN recovery_number VARCHAR(32) NULL AFTER phone_number',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- address_line_1
SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'address_line_1'
);
SET @sql := IF(
  @has_col = 0,
  'ALTER TABLE orders ADD COLUMN address_line_1 VARCHAR(180) NULL AFTER recovery_number',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- address_line_2
SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'address_line_2'
);
SET @sql := IF(
  @has_col = 0,
  'ALTER TABLE orders ADD COLUMN address_line_2 VARCHAR(180) NULL AFTER address_line_1',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- city
SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'city'
);
SET @sql := IF(
  @has_col = 0,
  'ALTER TABLE orders ADD COLUMN city VARCHAR(100) NULL AFTER address_line_2',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- state_region
SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'state_region'
);
SET @sql := IF(
  @has_col = 0,
  'ALTER TABLE orders ADD COLUMN state_region VARCHAR(100) NULL AFTER city',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- postal_code
SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'postal_code'
);
SET @sql := IF(
  @has_col = 0,
  'ALTER TABLE orders ADD COLUMN postal_code VARCHAR(24) NULL AFTER state_region',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- country
SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'country'
);
SET @sql := IF(
  @has_col = 0,
  'ALTER TABLE orders ADD COLUMN country VARCHAR(100) NULL AFTER postal_code',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- payment_type
SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'payment_type'
);
SET @sql := IF(
  @has_col = 0,
  'ALTER TABLE orders ADD COLUMN payment_type ENUM(''cash'',''visa'') NOT NULL DEFAULT ''cash'' AFTER country',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- card_last4
SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'card_last4'
);
SET @sql := IF(
  @has_col = 0,
  'ALTER TABLE orders ADD COLUMN card_last4 CHAR(4) NULL AFTER payment_type',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill payment_type from stored JSON where possible (legacy rows keep cash default).
UPDATE orders
SET payment_type = CASE
  WHEN JSON_VALID(shipping_address) AND JSON_UNQUOTE(JSON_EXTRACT(shipping_address, '$.payment_type')) = 'visa' THEN 'visa'
  ELSE 'cash'
END
WHERE payment_type IS NULL OR payment_type = '';

-- Backfill card_last4 from shipping JSON payment snapshot when present.
UPDATE orders
SET card_last4 = JSON_UNQUOTE(JSON_EXTRACT(shipping_address, '$.payment.card_last4'))
WHERE card_last4 IS NULL
  AND JSON_VALID(shipping_address)
  AND JSON_EXTRACT(shipping_address, '$.payment.card_last4') IS NOT NULL;
