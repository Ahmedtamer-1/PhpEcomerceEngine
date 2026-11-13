-- ════════════════════════════════════════════════════════════════
-- LUMEEGY security-test seed: FAKE DATA ONLY, for a local test database.
-- Never run this on production.
--
-- Load after schema.sql:
--   mysql -u root -p lumeegy_test < database/seed-security-test.sql
-- Reset to this state before each test run or the demo by re-running
-- schema.sql on an empty database and then this file (see README in docs/security).
--
-- Test accounts (local test values only, not used anywhere else):
--   admin       admin@lumeegy.test      Lume-Admin-Test-2026
--   customer A  customer.a@lumeegy.test Lume-CustA-Test-2026
--   customer B  customer.b@lumeegy.test Lume-CustB-Test-2026
--
-- Payment and email keys are deliberately empty, so the local copy cannot
-- charge cards or send real email. Use "Cash on delivery" when testing checkout.
-- ════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE order_items;
TRUNCATE TABLE orders;
TRUNCATE TABLE cart_sessions;
TRUNCATE TABLE product_variants;
TRUNCATE TABLE products;
TRUNCATE TABLE categories;
TRUNCATE TABLE login_attempts;
TRUNCATE TABLE activity_log;
TRUNCATE TABLE contact_messages;
TRUNCATE TABLE newsletter_subscribers;
TRUNCATE TABLE shipping_zones;
TRUNCATE TABLE users;
SET FOREIGN_KEY_CHECKS = 1;

-- ── Users ────────────────────────────────────────────────────────
INSERT INTO users (id, first_name, last_name, email, phone, password_hash, role) VALUES
(1, 'Test', 'Admin',     'admin@lumeegy.test',      '01000000001', '$2y$10$N6yO/9R1VRuxb6zk2egNYuBdyBYFVSArS517LAU2kHh9jCLagHZ/G', 'admin'),
(2, 'Alice', 'Customer', 'customer.a@lumeegy.test', '01000000002', '$2y$10$ShEGJ65okM.pRbL2DWOcYuQ2c3v6l5oFaVnDhLCTGDbvTO/WElbx.', 'customer'),
(3, 'Bob',   'Customer', 'customer.b@lumeegy.test', '01000000003', '$2y$10$JlKE/FFa3vObggAGhAfja.co36RcCcEhqiYcE4a0Ys7bJLyPSbRWa', 'customer');

-- ── Settings (no secrets) ────────────────────────────────────────
INSERT INTO settings (key_name, value) VALUES
('site_name', 'LUMEEGY (local test)'),
('site_tagline', 'Illuminate Your Ritual'),
('contact_email', 'shop@lumeegy.test'),
('currency', 'EGP'),
('free_shipping_over', '2000'),
('show_stock_indicator', '1'),
('maintenance_mode', '0'),
('email_enabled', '0'),
('welcome_offer_enabled', '0'),
('paymob_api_key', ''),
('paymob_hmac', ''),
('paymob_iframe_id', ''),
('paymob_integration_id', ''),
('email_resend_api_key', ''),
('smtp_password', ''),
('meta_pixel_id', '')
ON DUPLICATE KEY UPDATE value = VALUES(value);

-- ── Shipping ─────────────────────────────────────────────────────
INSERT INTO shipping_zones (id, name, cost, is_active, sort_order) VALUES
(1, 'Cairo', 95.00, 1, 1),
(2, 'Giza', 95.00, 1, 2),
(3, 'Alexandria', 110.00, 1, 3);

-- ── Catalogue ────────────────────────────────────────────────────
INSERT INTO categories (id, name, slug, description, sort_order) VALUES
(1, 'Rings',     'rings',     'Test category for rings.', 1),
(2, 'Necklaces', 'necklaces', 'Test category for necklaces.', 2);

INSERT INTO products (id, category_id, name, slug, description, price, sale_price, cost_price, sku, stock, is_featured, is_active, has_variants) VALUES
(1, 1, 'Test Ring Classic',   'test-ring-classic',   '<p>Plain test ring.</p>',            450.00, NULL,   200.00, 'TR-001', 10, 1, 1, 0),
(2, 1, 'Test Ring Sized',     'test-ring-sized',     '<p>Test ring with size variants.</p>', 520.00, 480.00, 230.00, 'TR-002', 12, 1, 1, 1),
(3, 2, 'Test Necklace Moon',  'test-necklace-moon',  '<p>Test necklace.</p>',              890.00, NULL,   400.00, 'TN-001',  5, 0, 1, 0),
(4, 2, 'Test Necklace Hidden','test-necklace-hidden','<p>Inactive product, must not show.</p>', 300.00, NULL, 100.00, 'TN-002', 3, 0, 0, 0);

INSERT INTO product_variants (id, product_id, size, color_name, color_hex, sku, stock, sort_order, is_active) VALUES
(1, 2, '6', 'Gold',   '#D4AF37', 'TR-002-6G', 4, 1, 1),
(2, 2, '7', 'Gold',   '#D4AF37', 'TR-002-7G', 5, 2, 1),
(3, 2, '8', 'Silver', '#C0C0C0', 'TR-002-8S', 3, 3, 1);

-- ── Orders: one per customer, for IDOR / ownership tests ─────────
INSERT INTO orders (id, order_number, user_id, guest_email, subtotal, shipping_cost, discount, tax, total, currency,
                    payment_method, status, shipping_name, shipping_addr, shipping_city, shipping_country, shipping_zone, phone, created_at) VALUES
(1, 'LME-AAA111-261101', 2, NULL, 450.00, 95.00, 0, 0, 545.00, 'EGP', 'cod', 'processing',
    'Alice Customer', '1 Test Street', 'Cairo', 'Egypt', 'Cairo', '01000000002', '2026-11-01 10:00:00'),
(2, 'LME-BBB222-261102', 3, NULL, 890.00, 110.00, 0, 0, 1000.00, 'EGP', 'cod', 'pending',
    'Bob Customer', '2 Test Street', 'Alexandria', 'Egypt', 'Alexandria', '01000000003', '2026-11-02 11:00:00');

INSERT INTO order_items (order_id, product_id, variant_id, variant_size, variant_color, name, sku, price, cost_price, quantity, subtotal) VALUES
(1, 1, NULL, NULL, NULL, 'Test Ring Classic',  'TR-001', 450.00, 200.00, 1, 450.00),
(2, 3, NULL, NULL, NULL, 'Test Necklace Moon', 'TN-001', 890.00, 400.00, 1, 890.00);
