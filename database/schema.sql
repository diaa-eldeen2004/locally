-- Locally — single database schema (MySQL 8.0+ / MariaDB 10.5+)
-- Import once per environment: mysql -u user -p locally < database/schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS analytics_events;
DROP TABLE IF EXISTS order_status_history;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS cart_items;
DROP TABLE IF EXISTS carts;
DROP TABLE IF EXISTS homepage_sections;
DROP TABLE IF EXISTS favorites;
DROP TABLE IF EXISTS reviews;
DROP TABLE IF EXISTS product_images;
DROP TABLE IF EXISTS product_variants;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS permissions;
DROP TABLE IF EXISTS roles;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- Roles & permissions (RBAC foundation)
-- ---------------------------------------------------------------------------

CREATE TABLE roles (
  id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(50) NOT NULL,
  slug VARCHAR(32) NOT NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(64) NOT NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id TINYINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_id TINYINT UNSIGNED NOT NULL,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(100) NOT NULL DEFAULT '',
  last_name VARCHAR(100) NOT NULL DEFAULT '',
  phone VARCHAR(32) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  theme_preference ENUM('system','light','dark') NOT NULL DEFAULT 'system',
  last_login_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role_id),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sessions (
  id VARCHAR(128) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(512) NULL,
  payload MEDIUMTEXT NOT NULL,
  last_activity INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_user (user_id),
  KEY idx_sessions_last_activity (last_activity),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Catalog
-- ---------------------------------------------------------------------------

CREATE TABLE categories (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id SMALLINT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  description TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug),
  KEY idx_categories_parent (parent_id),
  CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id SMALLINT UNSIGNED NOT NULL,
  name VARCHAR(200) NOT NULL,
  slug VARCHAR(200) NOT NULL,
  description MEDIUMTEXT NULL,
  price DECIMAL(10,2) NOT NULL,
  discount_price DECIMAL(10,2) NULL,
  availability_status ENUM('in_stock','out_of_stock','preorder') NOT NULL DEFAULT 'in_stock',
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  is_trending TINYINT(1) NOT NULL DEFAULT 0,
  average_rating DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  review_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_slug (slug),
  KEY idx_products_category (category_id),
  KEY idx_products_featured (is_featured),
  KEY idx_products_trending (is_trending),
  KEY idx_products_price (price),
  FULLTEXT KEY ft_products_search (name, description),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_variants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  sku VARCHAR(64) NULL,
  size VARCHAR(32) NOT NULL DEFAULT '',
  color VARCHAR(64) NOT NULL DEFAULT '',
  stock_quantity INT NOT NULL DEFAULT 0,
  price_adjustment DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_variants_sku (sku),
  KEY idx_variants_product (product_id),
  KEY idx_variants_stock (stock_quantity),
  CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_images (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  path VARCHAR(512) NOT NULL,
  alt_text VARCHAR(255) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_images_product_sort (product_id, sort_order),
  CONSTRAINT fk_images_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  title VARCHAR(200) NULL,
  body TEXT NULL,
  is_approved TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reviews_user_product (user_id, product_id),
  KEY idx_reviews_product (product_id),
  CONSTRAINT fk_reviews_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE favorites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_favorites_user_product (user_id, product_id),
  KEY idx_favorites_user (user_id),
  CONSTRAINT fk_favorites_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_favorites_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE homepage_sections (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(120) NOT NULL,
  category_id SMALLINT UNSIGNED NULL,
  display_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_homepage_order (display_order),
  KEY idx_homepage_category (category_id),
  CONSTRAINT fk_homepage_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Cart (persisted server-side; guest carts can use session id in carts.key)
-- ---------------------------------------------------------------------------

CREATE TABLE carts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  guest_key CHAR(64) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_carts_user (user_id),
  UNIQUE KEY uq_carts_guest (guest_key),
  CONSTRAINT fk_carts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cart_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cart_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cart_variant (cart_id, variant_id),
  KEY idx_cart_items_cart (cart_id),
  CONSTRAINT fk_cart_items_cart FOREIGN KEY (cart_id) REFERENCES carts (id) ON DELETE CASCADE,
  CONSTRAINT fk_cart_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE CASCADE,
  CONSTRAINT chk_cart_items_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Orders
-- ---------------------------------------------------------------------------

CREATE TABLE orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  order_number VARCHAR(32) NOT NULL,
  status ENUM(
    'pending_approval',
    'approved',
    'rejected',
    'cancelled',
    'processing',
    'shipped',
    'delivered'
  ) NOT NULL DEFAULT 'pending_approval',
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  shipping_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  grand_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  shipping_address JSON NULL,
  confirmer_id BIGINT UNSIGNED NULL,
  customer_note VARCHAR(500) NULL,
  internal_note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_number (order_number),
  KEY idx_orders_user (user_id),
  KEY idx_orders_status_created (status, created_at),
  KEY idx_orders_confirmer (confirmer_id),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_orders_confirmer FOREIGN KEY (confirmer_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NULL,
  product_name VARCHAR(200) NOT NULL,
  variant_label VARCHAR(120) NOT NULL DEFAULT '',
  unit_price DECIMAL(10,2) NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  line_total DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_order_items_order (order_id),
  KEY idx_order_items_product (product_id),
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products (id),
  CONSTRAINT fk_order_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE SET NULL,
  CONSTRAINT chk_order_items_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_status_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  to_status VARCHAR(32) NOT NULL,
  note VARCHAR(500) NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_osh_order (order_id),
  KEY idx_osh_created (created_at),
  CONSTRAINT fk_osh_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_osh_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Analytics (append-only events; aggregate in SQL or cache for dashboards)
-- ---------------------------------------------------------------------------

CREATE TABLE analytics_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  session_id VARCHAR(64) NULL,
  event_name VARCHAR(64) NOT NULL,
  entity_type VARCHAR(32) NULL,
  entity_id BIGINT UNSIGNED NULL,
  properties JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_analytics_event_time (event_name, created_at),
  KEY idx_analytics_user_time (user_id, created_at),
  CONSTRAINT fk_analytics_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
