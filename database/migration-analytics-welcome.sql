-- ════════════════════════════════════════════════════════════════
-- Visitor tracking, product-demand history and the welcome discount
--
-- You do NOT need to run this by hand: the site creates these on the first
-- request after the upload (analytics_ensure_schema() in
-- includes/analytics.php). It is kept here as a record, and for phpMyAdmin
-- if automatic creation is ever blocked by database permissions.
-- ════════════════════════════════════════════════════════════════

-- One row per storefront page view (sent from the browser; bots and admins
-- are not recorded). visitor_id is a random id in a first-party cookie.
CREATE TABLE IF NOT EXISTS page_views (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    visitor_id CHAR(16) NOT NULL,
    session_id CHAR(16) NOT NULL,
    user_id INT UNSIGNED NULL,
    path VARCHAR(255) NOT NULL,
    page_type VARCHAR(20) NOT NULL DEFAULT 'other',
    product_id INT UNSIGNED NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'Direct',
    is_paid TINYINT(1) NOT NULL DEFAULT 0,
    referrer_host VARCHAR(120) NULL,
    utm_campaign VARCHAR(120) NULL,
    device VARCHAR(10) NOT NULL DEFAULT 'desktop',
    browser VARCHAR(20) NULL,
    is_new_visitor TINYINT(1) NOT NULL DEFAULT 0,
    duration_s SMALLINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_created (created_at),
    KEY idx_visitor (visitor_id, created_at),
    KEY idx_session (session_id),
    KEY idx_product (product_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every add to bag, removal and purchase, kept after checkout (cart_sessions
-- rows are deleted when an order is placed, so they can't show demand).
CREATE TABLE IF NOT EXISTS cart_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event VARCHAR(10) NOT NULL,              -- add | remove | purchase
    product_id INT UNSIGNED NOT NULL,
    variant_id INT UNSIGNED NULL,
    quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    visitor_id CHAR(16) NULL,
    session_key VARCHAR(64) NULL,
    user_id INT UNSIGNED NULL,
    order_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_product (product_id, event, created_at),
    KEY idx_created (event, created_at),
    KEY idx_visitor (visitor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time welcome discount per account.
--   welcome_discount_pct       set when the account is created (NULL = no offer)
--   welcome_discount_used_at   set when an order uses it
--   welcome_discount_order_id  that order
ALTER TABLE users
    ADD COLUMN welcome_discount_pct DECIMAL(5,2) NULL DEFAULT NULL,
    ADD COLUMN welcome_discount_order_id INT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN welcome_discount_used_at DATETIME NULL DEFAULT NULL;

INSERT INTO settings (key_name, value) VALUES ('schema_analytics_v1', '1')
    ON DUPLICATE KEY UPDATE value = VALUES(value);
INSERT IGNORE INTO settings (key_name, value) VALUES ('analytics_started_at', NOW());
