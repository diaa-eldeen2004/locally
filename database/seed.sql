-- Locally — demo seed (optional). Run after schema.sql on a dev database only.
-- Default admin: admin@locally.test / password  (change immediately in production)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE analytics_events;
TRUNCATE TABLE order_status_history;
TRUNCATE TABLE order_items;
TRUNCATE TABLE orders;
TRUNCATE TABLE cart_items;
TRUNCATE TABLE carts;
TRUNCATE TABLE favorites;
TRUNCATE TABLE reviews;
TRUNCATE TABLE product_images;
TRUNCATE TABLE product_variants;
TRUNCATE TABLE products;
TRUNCATE TABLE homepage_sections;
TRUNCATE TABLE categories;
TRUNCATE TABLE sessions;
TRUNCATE TABLE role_permissions;
TRUNCATE TABLE users;
TRUNCATE TABLE permissions;
TRUNCATE TABLE roles;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO roles (id, name, slug, description) VALUES
(1, 'Administrator', 'admin', 'Full system access'),
(2, 'Confirmer', 'confirmer', 'Approves customer orders'),
(3, 'Customer', 'customer', 'Store shopper');

INSERT INTO permissions (id, slug, description) VALUES
(1, 'products.manage', 'Create, update, and delete products'),
(2, 'categories.manage', 'Manage product categories'),
(3, 'users.manage', 'Manage customer and staff accounts'),
(4, 'orders.manage', 'View and manage all orders'),
(5, 'orders.confirm', 'Approve or reject pending orders'),
(6, 'analytics.view', 'View dashboards and analytics'),
(7, 'homepage.manage', 'Reorder and configure homepage sections'),
(8, 'inventory.view', 'View stock levels and low-stock signals');

-- Admin: all permissions
INSERT INTO role_permissions (role_id, permission_id) VALUES
(1, 1), (1, 2), (1, 3), (1, 4), (1, 5), (1, 6), (1, 7), (1, 8);

-- Confirmer: confirm orders + read inventory
INSERT INTO role_permissions (role_id, permission_id) VALUES
(2, 5), (2, 8);

-- Bcrypt for literal password: "password" (Laravel default hash — dev only)
INSERT INTO users (id, role_id, email, password_hash, first_name, last_name, is_active) VALUES
(1, 1, 'admin@locally.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Local', 'Admin', 1),
(2, 2, 'confirmer@locally.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Queue', 'Confirmer', 1);

INSERT INTO categories (id, parent_id, name, slug, description, sort_order, is_active) VALUES
(1, NULL, 'Hoodies', 'hoodies', NULL, 10, 1),
(2, NULL, 'T-Shirts', 't-shirts', NULL, 20, 1),
(3, NULL, 'Oversized', 'oversized', NULL, 30, 1),
(4, NULL, 'Jackets', 'jackets', NULL, 40, 1),
(5, NULL, 'Pants', 'pants', NULL, 50, 1),
(6, NULL, 'Sneakers', 'sneakers', NULL, 60, 1),
(7, NULL, 'Accessories', 'accessories', NULL, 70, 1);

INSERT INTO homepage_sections (id, title, category_id, display_order, is_active) VALUES
(1, 'Hoodies', 1, 10, 1),
(2, 'T-Shirts', 2, 20, 1),
(3, 'Oversized', 3, 30, 1),
(4, 'Jackets', 4, 40, 1),
(5, 'Pants', 5, 50, 1),
(6, 'Sneakers', 6, 60, 1),
(7, 'Accessories', 7, 70, 1);

INSERT INTO products (
  id, category_id, name, slug, description, price, discount_price,
  availability_status, is_featured, is_trending, average_rating, review_count
) VALUES (
  1, 1, 'Locally Core Hoodie', 'locally-core-hoodie',
  'Heavyweight cotton hoodie with minimal Locally branding.',
  89.00, 74.00, 'in_stock', 1, 1, 0.00, 0
);

INSERT INTO product_variants (id, product_id, sku, size, color, stock_quantity, price_adjustment) VALUES
(1, 1, 'LOC-HOOD-M-MNT', 'M', 'Mint', 24, 0.00),
(2, 1, 'LOC-HOOD-L-BLK', 'L', 'Black', 12, 0.00);

INSERT INTO product_images (id, product_id, path, alt_text, sort_order, is_primary) VALUES
(1, 1, '/uploads/products/locally-core-hoodie-1.jpg', 'Locally Core Hoodie front', 0, 1),
(2, 1, '/uploads/products/locally-core-hoodie-1.jpg', 'Locally Core Hoodie detail', 1, 0);

INSERT INTO reviews (product_id, user_id, rating, title, body, is_approved) VALUES
(1, 1, 5, 'Feels premium', 'Fabric and fit are excellent for daily wear.', 1);

UPDATE products SET average_rating = 5.00, review_count = 1 WHERE id = 1;

ALTER TABLE roles AUTO_INCREMENT = 4;
ALTER TABLE permissions AUTO_INCREMENT = 9;
ALTER TABLE users AUTO_INCREMENT = 3;
ALTER TABLE categories AUTO_INCREMENT = 8;
ALTER TABLE homepage_sections AUTO_INCREMENT = 8;
ALTER TABLE products AUTO_INCREMENT = 2;
ALTER TABLE product_variants AUTO_INCREMENT = 3;
ALTER TABLE product_images AUTO_INCREMENT = 3;
