<?php
/**
 * LUMEEGY — Core Functions
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/meta-capi.php';
require_once __DIR__ . '/analytics.php';
require_once __DIR__ . '/media-video.php';

// ════════════════════════════════════════════════════════
// SESSION
// ════════════════════════════════════════════════════════
function lume_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            // Follow the actual connection: a secure-only cookie on plain HTTP
            // is never sent back, so logins and carts silently reset.
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
                        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        // Signed out because the session expired (closing the browser, or the
        // server clearing idle sessions)? Sign back in from the remember cookie.
        if (empty($_SESSION['user_id']) && !empty($_COOKIE[REMEMBER_COOKIE])) {
            remember_resume();
        }
    }
}

// ════════════════════════════════════════════════════════
// STAY SIGNED IN ("remember me")
// ════════════════════════════════════════════════════════
// A long-lived cookie holds "selector:validator". Only a SHA-256 of the
// validator is stored, so a leaked database can't be used to sign in, and
// the cookie is httponly so page scripts can't read it.
const REMEMBER_COOKIE = 'adn_remember';
const REMEMBER_DAYS   = 60;

function remember_ensure_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS remember_tokens (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        selector CHAR(24) NOT NULL,
        validator_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        user_agent VARCHAR(200) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY selector (selector),
        KEY user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function remember_set_cookie(string $value, int $expires): void
{
    if (headers_sent()) return;
    setcookie(REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                      || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
                      || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Give this browser a remember-me token for the user. */
function remember_issue(int $userId): void
{
    try {
        remember_ensure_table();
        $selector  = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        db()->prepare('INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at, user_agent)
                       VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), ?)')
            ->execute([$userId, $selector, hash('sha256', $validator), REMEMBER_DAYS,
                       mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
        remember_set_cookie($selector . ':' . $validator, time() + REMEMBER_DAYS * 86400);
        // Housekeeping now and then.
        if (random_int(1, 50) === 1) db()->exec('DELETE FROM remember_tokens WHERE expires_at < NOW()');
    } catch (Exception $e) {
        error_log('remember_issue: ' . $e->getMessage());
    }
}

/** Sign in from the remember cookie, if it is valid. */
function remember_resume(): void
{
    $raw = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m)) { remember_set_cookie('', time() - 3600); return; }
    try {
        remember_ensure_table();
        $st = db()->prepare('SELECT t.id, t.user_id, t.validator_hash, t.expires_at < DATE_ADD(NOW(), INTERVAL 30 DAY) AS renew
                             FROM remember_tokens t JOIN users u ON u.id = t.user_id
                             WHERE t.selector = ? AND t.expires_at > NOW()');
        $st->execute([$m[1]]);
        $row = $st->fetch();
        if (!$row || !hash_equals($row['validator_hash'], hash('sha256', $m[2]))) {
            remember_set_cookie('', time() - 3600);
            return;
        }
        // The validator stays the same (several requests can arrive at once,
        // e.g. the page and its cart/analytics calls); the expiry slides
        // forward so regular visitors never get signed out.
        if ($row['renew']) {
            db()->prepare('UPDATE remember_tokens SET expires_at = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?')
                ->execute([REMEMBER_DAYS, $row['id']]);
            remember_set_cookie($raw, time() + REMEMBER_DAYS * 86400);
        }
        db()->prepare('UPDATE remember_tokens SET last_used_at = NOW() WHERE id = ?')->execute([$row['id']]);
        if (!headers_sent()) session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$row['user_id'];
        cart_merge_guest((int)$row['user_id']);
    } catch (Exception $e) {
        error_log('remember_resume: ' . $e->getMessage());
    }
}

/** Forget this browser's token (logout). */
function remember_forget(): void
{
    $raw = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if (preg_match('/^([a-f0-9]{24}):/', $raw, $m)) {
        try { remember_ensure_table(); db()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$m[1]]); }
        catch (Exception $e) {}
    }
    remember_set_cookie('', time() - 3600);
    unset($_COOKIE[REMEMBER_COOKIE]);
}

/** Sign a user out everywhere (e.g. after a password change). */
function remember_forget_user(int $userId): void
{
    try { remember_ensure_table(); db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$userId]); }
    catch (Exception $e) {}
}

function csrf_token(): string
{
    lume_session_start();
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function csrf_verify(string $token): bool
{
    $expected = $_SESSION[CSRF_TOKEN_NAME] ?? '';
    return $expected !== '' && hash_equals($expected, $token);
}

/**
 * The CSRF token sent with the current request, from wherever the caller put
 * it: a form field, the X-CSRF-Token header (fetch/XHR), or the query string
 * (destructive GET links in the admin).
 */
function csrf_request_token(): string
{
    $t = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_GET['csrf'] ?? '';
    return is_string($t) ? $t : '';
}

/**
 * Reject state-changing admin requests that do not carry a valid token:
 * every POST, plus GET links that delete things (?action=delete).
 * SameSite=Lax cookies stop cross-site POSTs but NOT a top-level GET, so a
 * link on another site could otherwise delete orders or users.
 */
function admin_enforce_csrf(): void
{
    lume_session_start();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $isDestructiveGet = $method === 'GET' && in_array($_GET['action'] ?? '', ['delete'], true);
    if ($method !== 'POST' && !$isDestructiveGet) return;
    if (csrf_verify(csrf_request_token())) return;

    http_response_code(403);
    $wantsJson = is_ajax() || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Your session expired. Please reload the page and try again.']);
    } else {
        echo 'Your session expired or the request was not valid. Please go back, reload the page and try again.';
    }
    exit;
}

/**
 * Only allow redirects to paths on this site (used for ?redirect= after login).
 */
function safe_redirect_path(?string $path, string $fallback = '/account.php'): string
{
    $path = (string)$path;
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\')
        || preg_match('/[\r\n]/', $path)) {
        return $fallback;
    }
    // Strip the site's own sub-folder prefix so redirect() does not double it.
    $basePath = rtrim((string)parse_url(SITE_URL, PHP_URL_PATH), '/');
    if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
        $path = substr($path, strlen($basePath));
    }
    return $path;
}

// ════════════════════════════════════════════════════════
// SECURITY — Rate Limiting & SHA-256
// ════════════════════════════════════════════════════════
/**
 * Rate-limit login attempts. Returns true if the request should be blocked.
 */
function is_rate_limited(string $identifier, int $maxAttempts = 5, int $windowSeconds = 900): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)'
    );
    $stmt->execute([$identifier, $windowSeconds]);
    return (int) $stmt->fetchColumn() >= $maxAttempts;
}

function record_login_attempt(string $identifier, bool $success = false): void
{
    db()->prepare(
        'INSERT INTO login_attempts (identifier, ip_address, success, attempted_at) VALUES (?, ?, ?, NOW())'
    )->execute([$identifier, get_client_ip(), $success ? 1 : 0]);
}

function clear_login_attempts(string $identifier): void
{
    db()->prepare('DELETE FROM login_attempts WHERE identifier = ?')->execute([$identifier]);
}

/**
 * Generate a SHA-256 HMAC signature for data integrity.
 */
function hmac_sign(string $data): string
{
    $key = defined('GATEWAY_HMAC_SECRET') && GATEWAY_HMAC_SECRET
        ? GATEWAY_HMAC_SECRET
        : hash('sha256', DB_PASS . SESSION_NAME);
    return hash_hmac('sha256', $data, $key);
}

function hmac_verify(string $data, string $signature): bool
{
    return hash_equals(hmac_sign($data), $signature);
}

/**
 * Generate a secure, time-limited nonce (SHA-256 based).
 */
function generate_nonce(string $action, int $ttlHours = 24): string
{
    $tick = ceil(time() / (3600 * $ttlHours));
    return hash_hmac('sha256', $action . '|' . $tick, csrf_token());
}

function verify_nonce(string $nonce, string $action, int $ttlHours = 24): bool
{
    $tick = ceil(time() / (3600 * $ttlHours));
    $expected = hash_hmac('sha256', $action . '|' . $tick, csrf_token());
    if (hash_equals($expected, $nonce))
        return true;
    // Check previous window
    $prev = hash_hmac('sha256', $action . '|' . ($tick - 1), csrf_token());
    return hash_equals($prev, $nonce);
}

// ════════════════════════════════════════════════════════
// AUTH
// ════════════════════════════════════════════════════════
function current_user(): ?array
{
    static $user = null;
    if ($user !== null) return $user;

    lume_session_start();
    if (empty($_SESSION['user_id']))
        return null;
    
    $stmt = db()->prepare('SELECT id, first_name, last_name, email, role FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    return $user;
}

function is_logged_in(): bool
{
    lume_session_start();
    return !empty($_SESSION['user_id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('/account.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    }
}

/**
 * Sign a user in. $remember keeps them signed in on this browser for
 * REMEMBER_DAYS (the session cookie alone ended when the browser closed or the
 * server cleared idle sessions, so people were signed out constantly).
 */
function login_user(int $userId, bool $remember = true): void
{
    lume_session_start();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    cart_merge_guest($userId);
    if ($remember) remember_issue($userId);
    $u = db()->prepare('SELECT role FROM users WHERE id = ?');
    $u->execute([$userId]);
    if ($u->fetchColumn() === 'admin') analytics_mark_staff();
}

/** Move the guest bag onto the account that just signed in. */
function cart_merge_guest(int $userId): void
{
    if (!empty($_SESSION['cart_session_key'])) {
        db()->prepare(
            'UPDATE cart_sessions SET user_id = ? WHERE session_key = ? AND user_id IS NULL'
        )->execute([$userId, $_SESSION['cart_session_key']]);
    }
}

function logout_user(): void
{
    lume_session_start();
    remember_forget();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function log_activity(string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void
{
    $user = current_user();
    $userId = $user ? (int)$user['id'] : null;
    $ip = get_client_ip();

    db()->prepare(
        'INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())'
    )->execute([$userId, $action, $entityType, $entityId, $details, $ip]);
}

// ════════════════════════════════════════════════════════
// VARIANTS
// ════════════════════════════════════════════════════════
function get_product_variants(int $productId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM product_variants WHERE product_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$productId]);
    return $stmt->fetchAll();
}

function get_variant(int $variantId): ?array
{
    $stmt = db()->prepare('SELECT * FROM product_variants WHERE id = ?');
    $stmt->execute([$variantId]);
    return $stmt->fetch() ?: null;
}

/**
 * Batch-fetch unique color swatches for multiple products.
 */
function get_product_color_swatches(array $productIds): array
{
    if (empty($productIds)) return [];
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = db()->prepare(
        "SELECT product_id, color_name, color_hex, image 
         FROM product_variants 
         WHERE product_id IN ($placeholders) AND is_active = 1 AND color_name IS NOT NULL AND color_name != ''
         ORDER BY sort_order ASC"
    );
    $stmt->execute($productIds);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!isset($result[(int)$row['product_id']][$row['color_name']])) {
            $result[(int)$row['product_id']][$row['color_name']] = [
                'hex' => $row['color_hex'],
                'image' => $row['image'] ?? null,
            ];
        }
    }
    return $result;
}

/**
 * Compute the effective price for a cart/order item that may have a variant.
 */
function item_effective_price(array $item): float
{
    if (isset($item['variant_price']) && $item['variant_price'] !== null && (float)$item['variant_price'] > 0)
        return (float) $item['variant_price'];
    return product_effective_price($item);
}

/**
 * The product's real sale price, or null when it is not on sale.
 *
 * MySQL returns DECIMAL columns as strings, and "0.00" is NOT empty() in PHP,
 * so the old `!empty($p['sale_price'])` checks treated a stored 0.00 as a sale
 * — showing a "Sale" badge and pricing the item at 0 in the cart/checkout.
 * A sale only counts when it is above zero and below the regular price.
 */
function product_sale_price(array $p): ?float
{
    $sale  = (float)($p['sale_price'] ?? 0);
    $price = (float)($p['price'] ?? 0);
    return ($sale > 0 && ($price <= 0 || $sale < $price)) ? $sale : null;
}

/** What the customer actually pays for a (non-variant) product. */
function product_effective_price(array $p): float
{
    return product_sale_price($p) ?? (float)($p['price'] ?? 0);
}

// ════════════════════════════════════════════════════════
// CART
// ════════════════════════════════════════════════════════
function cart_session_key(): string
{
    lume_session_start();
    if (empty($_SESSION['cart_session_key'])) {
        $_SESSION['cart_session_key'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['cart_session_key'];
}

function cart_items(): array
{
    $key = cart_session_key();
    $userId = is_logged_in() ? $_SESSION['user_id'] : null;

    $cols = 'cs.*, p.name, p.price, p.sale_price, p.cost_price, p.image, p.slug, p.stock, p.sku, p.has_variants,
             pv.id AS pv_id, pv.size AS variant_size, pv.color_name AS variant_color,
             pv.color_hex AS variant_color_hex, pv.sku AS variant_sku,
             pv.price_override AS variant_price, pv.cost_price AS variant_cost_price, pv.stock AS variant_stock,
             pv.image AS variant_image';

    if ($userId) {
        $stmt = db()->prepare(
            "SELECT $cols
             FROM cart_sessions cs
             JOIN products p ON p.id = cs.product_id
             LEFT JOIN product_variants pv ON pv.id = cs.variant_id
             WHERE cs.user_id = ? AND p.is_active = 1"
        );
        $stmt->execute([$userId]);
    } else {
        $stmt = db()->prepare(
            "SELECT $cols
             FROM cart_sessions cs
             JOIN products p ON p.id = cs.product_id
             LEFT JOIN product_variants pv ON pv.id = cs.variant_id
             WHERE cs.session_key = ? AND cs.user_id IS NULL AND p.is_active = 1"
        );
        $stmt->execute([$key]);
    }
    return $stmt->fetchAll();
}

function cart_count(): int
{
    return (int) array_sum(array_column(cart_items(), 'quantity'));
}

function cart_total(): float
{
    $total = 0.0;
    foreach (cart_items() as $item) {
        $total += item_effective_price($item) * (int) $item['quantity'];
    }
    return $total;
}

function cart_add(int $productId, int $qty = 1, ?int $variantId = null): bool
{
    $key = cart_session_key();
    $userId = is_logged_in() ? (int) $_SESSION['user_id'] : null;

    // Check existing (same product + same variant)
    if ($userId) {
        if ($variantId) {
            $stmt = db()->prepare('SELECT id, quantity FROM cart_sessions WHERE user_id = ? AND product_id = ? AND variant_id = ?');
            $stmt->execute([$userId, $productId, $variantId]);
        } else {
            $stmt = db()->prepare('SELECT id, quantity FROM cart_sessions WHERE user_id = ? AND product_id = ? AND variant_id IS NULL');
            $stmt->execute([$userId, $productId]);
        }
    } else {
        if ($variantId) {
            $stmt = db()->prepare('SELECT id, quantity FROM cart_sessions WHERE session_key = ? AND user_id IS NULL AND product_id = ? AND variant_id = ?');
            $stmt->execute([$key, $productId, $variantId]);
        } else {
            $stmt = db()->prepare('SELECT id, quantity FROM cart_sessions WHERE session_key = ? AND user_id IS NULL AND product_id = ? AND variant_id IS NULL');
            $stmt->execute([$key, $productId]);
        }
    }
    $existing = $stmt->fetch();

    if ($existing) {
        db()->prepare('UPDATE cart_sessions SET quantity = quantity + ? WHERE id = ?')
            ->execute([$qty, $existing['id']]);
    } else {
        db()->prepare('INSERT INTO cart_sessions (session_key, user_id, product_id, variant_id, quantity) VALUES (?,?,?,?,?)')
            ->execute([$key, $userId, $productId, $variantId, $qty]);
    }
    return true;
}

/**
 * Update or remove a cart line — but only one that belongs to the current
 * visitor. The id comes straight from the request, so without the owner
 * condition anyone could change or empty other shoppers' carts by guessing ids.
 */
function cart_update(int $cartId, int $qty): void
{
    if (is_logged_in()) {
        $owner = 'user_id = ?';
        $ownerVal = (int)$_SESSION['user_id'];
    } else {
        $owner = 'session_key = ? AND user_id IS NULL';
        $ownerVal = cart_session_key();
    }
    if ($qty <= 0) {
        db()->prepare("DELETE FROM cart_sessions WHERE id = ? AND $owner")->execute([$cartId, $ownerVal]);
    } else {
        db()->prepare("UPDATE cart_sessions SET quantity = ? WHERE id = ? AND $owner")->execute([$qty, $cartId, $ownerVal]);
    }
}

/**
 * Product ids (from the given list) that are flagged for pre-order.
 * Returns [] when the preorder_enabled column does not exist yet.
 */
function preorder_product_ids(array $productIds): array
{
    $productIds = array_values(array_unique(array_map('intval', $productIds)));
    if (!$productIds) return [];
    try {
        $ph   = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = db()->prepare("SELECT id FROM products WHERE id IN ($ph) AND preorder_enabled = 1");
        $stmt->execute($productIds);
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    } catch (Exception $e) {
        return [];
    }
}

function cart_clear(): void
{
    $key = cart_session_key();
    $userId = is_logged_in() ? (int) $_SESSION['user_id'] : null;
    if ($userId) {
        db()->prepare('DELETE FROM cart_sessions WHERE user_id = ?')->execute([$userId]);
    } else {
        db()->prepare('DELETE FROM cart_sessions WHERE session_key = ? AND user_id IS NULL')->execute([$key]);
    }
}

// ════════════════════════════════════════════════════════
// PRODUCTS
// ════════════════════════════════════════════════════════
function get_featured_products(int $limit = 4): array
{
    $stmt = db()->prepare(
        // Joined so featured cards can show their category (it was always blank).
        'SELECT p.*, c.name AS category_name, c.slug AS category_slug
         FROM products p LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.is_featured = 1 AND p.is_active = 1 ORDER BY p.created_at DESC LIMIT ?'
    );
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function get_products(array $opts = []): array
{
    $where = ['p.is_active = 1'];
    $params = [];
    $limit = (int) ($opts['limit'] ?? 12);
    $offset = (int) ($opts['offset'] ?? 0);

    if (!empty($opts['category_slug'])) {
        $where[] = 'c.slug = ?';
        $params[] = $opts['category_slug'];
    }
    if (!empty($opts['category_id'])) {
        $where[] = 'c.id = ?';
        $params[] = (int)$opts['category_id'];
    }
    if (!empty($opts['exclude_id'])) {
        $where[] = 'p.id != ?';
        $params[] = (int)$opts['exclude_id'];
    }
    if (!empty($opts['search'])) {
        $where[] = '(p.name LIKE ? OR p.description LIKE ?)';
        $term = '%' . $opts['search'] . '%';
        $params[] = $term;
        $params[] = $term;
    }

    $sql = 'SELECT p.*, c.name AS category_name, c.slug AS category_slug
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?';
    $params[] = $limit;
    $params[] = $offset;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_product_by_slug(string $slug): ?array
{
    $stmt = db()->prepare(
        'SELECT p.*, c.name AS category_name, c.slug AS category_slug
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.slug = ? AND p.is_active = 1'
    );
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

function get_categories(): array
{
    $stmt = db()->query('SELECT * FROM categories ORDER BY sort_order ASC, name ASC');
    return $stmt->fetchAll();
}

// ════════════════════════════════════════════════════════
// FORMATTING
// ════════════════════════════════════════════════════════
function currency_symbol(): string
{
    $symbol = setting('currency_symbol', '');
    if (!$symbol || !is_string($symbol) || strlen($symbol) > 10 || preg_match('/^\d+$/', trim($symbol))) {
        $symbol = defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : 'EGP ';
    }
    if (!is_string($symbol) || strlen($symbol) > 10 || preg_match('/^\d+$/', trim($symbol))) {
        $symbol = 'EGP ';
    }
    return $symbol;
}

function money(float $amount): string
{
    return '<span class="lume-price-val">' . number_format($amount, 2) . '</span><span class="lume-price-cur">' . currency_symbol() . '</span>';
}

/**
 * Money as plain text: "1,234.56 EGP".
 *
 * money() returns HTML (<span class="lume-price-val">…</span>) for the
 * storefront. Email templates were calling htmlspecialchars(money(...)),
 * which escaped that markup so recipients saw the literal tags in their
 * inbox. Emails must use this instead — it needs no escaping, and the CSS
 * classes money() relies on do not exist in an email client anyway.
 */
function money_plain(float $amount): string
{
    return number_format($amount, 2) . ' ' . currency_symbol();
}

function product_price(array $product): string
{
    $sale = product_sale_price($product);
    if ($sale !== null) {
        return '<div class="price-original">' . money((float) $product['price']) . '</div>'
             . '<div class="price-sale">' . money($sale) . '</div>';
    }
    return '<div class="price">' . money((float) $product['price']) . '</div>';
}

function thumb_url(?string $path, int $width = 400): string
{
    if (empty($path)) return SITE_URL . '/assets/images/placeholder.jpg';
    if (strpos($path, 'http') === 0) return $path;
    // thumb.php can only resize raster images; an SVG sent through it 415s.
    if (preg_match('/\.svg$/i', strtok($path, '?'))) return asset_url($path);
    return SITE_URL . '/thumb.php?src=' . urlencode(ltrim($path, '/')) . '&w=' . $width;
}

/**
 * URL for a link typed into the page builder (button URLs etc.).
 * Full URLs (https://instagram.com/…, wa.me links), mailto:, tel: and #anchors
 * are used as-is; anything else is treated as a path on this site. Sections
 * used to always prefix SITE_URL, turning "https://wa.me/…" into
 * "https://adornaeg.com/https://wa.me/…". javascript: and other schemes are
 * refused.
 */
function link_url(?string $url, string $fallback = ''): string
{
    $url = trim((string)$url);
    if ($url === '') $url = $fallback;
    if ($url === '') return SITE_URL . '/';
    if (preg_match('~^(https?://|mailto:|tel:|#)~i', $url)) return $url;
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) || str_starts_with($url, '//')) return SITE_URL . '/';
    return SITE_URL . '/' . ltrim($url, '/');
}

function asset_url(?string $path): string
{
    if (empty($path)) return SITE_URL . '/assets/images/placeholder.jpg';
    if (strpos($path, 'http') === 0) return $path;
    return SITE_URL . '/' . ltrim($path, '/');
}

/**
 * Normalizes a raw video value (YouTube/Vimeo URL, or an uploaded file path)
 * into a renderable source. Self-hosted files are preferred where the caller
 * offers a choice: no third-party cookies, and they can autoplay muted
 * inline as a background. Shared by every video-based homepage section so
 * the same link formats work everywhere.
 */
function resolve_video_source(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') return ['embed' => '', 'file' => '', 'provider' => '', 'id' => ''];

    if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/)|youtu\.be/)([A-Za-z0-9_-]{6,})~', $raw, $m)) {
        return ['embed' => 'https://www.youtube-nocookie.com/embed/' . $m[1], 'file' => '', 'provider' => 'youtube', 'id' => $m[1]];
    }
    if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $raw, $m)) {
        return ['embed' => 'https://player.vimeo.com/video/' . $m[1], 'file' => '', 'provider' => 'vimeo', 'id' => $m[1]];
    }

    $file = $raw;
    if (!preg_match('~^https?://~i', $file)) {
        $file = SITE_URL . '/' . ltrim($file, '/');
    }
    return ['embed' => '', 'file' => $file, 'provider' => 'file', 'id' => ''];
}

function product_image(array $product, int $width = 600): string
{
    // No h() here: the result is a URL (callers escape on output), and
    // escaping first turned "&" in a filename into "&amp;" inside the path.
    return thumb_url($product['image'] ?? '', $width);
}

// ════════════════════════════════════════════════════════
// ORDERS
// ════════════════════════════════════════════════════════
function generate_order_number(): string
{
    // Random rather than uniqid()-based: order numbers are used (with the
    // email) to look orders up on track-order.php, so they must not be
    // guessable from the time an order was placed.
    return 'LME-' . strtoupper(bin2hex(random_bytes(3))) . '-' . date('ymd');
}

/** Thrown by create_order() when an item sold out between the cart check and the order. */
class OutOfStockException extends RuntimeException
{
    public function __construct(public readonly string $itemName)
    {
        parent::__construct('Out of stock: ' . $itemName);
    }
}

/**
 * Write an order and take its items off the shelf, all in one transaction.
 *
 * Items flagged 'preorder' => true, or every item when $data['allow_oversell']
 * is set, may go past the available stock; anything else that is no longer in
 * stock throws OutOfStockException and nothing is written.
 */
function create_order(array $data): int
{
    $pdo = db();
    $number = generate_order_number();

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO orders
             (order_number, user_id, guest_email, subtotal, shipping_cost, discount, tax, total,
              currency, payment_method, shipping_name, shipping_addr, shipping_city, shipping_country, notes, phone, shipping_zone)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
                    $number,
                    $data['user_id'] ?? null,
                    $data['guest_email'] ?? null,
                    $data['subtotal'],
                    $data['shipping_cost'],
                    $data['discount'] ?? 0,
                    $data['tax'] ?? 0,
                    $data['total'],
                    CURRENCY_CODE,
                    $data['payment_method'],
                    $data['shipping_name'],
                    $data['shipping_addr'],
                    $data['shipping_city'],
                    $data['shipping_country'],
                    $data['notes'] ?? null,
                    $data['phone'] ?? null,
                    $data['shipping_zone'] ?? null,
                ]);

        $orderId = (int) $pdo->lastInsertId();
        $touchedProducts = [];

        foreach ($data['items'] as $item) {
            $pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, variant_id, variant_size, variant_color, name, sku, price, cost_price, quantity, subtotal)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                        $orderId,
                        $item['product_id'] ?? null,
                        $item['variant_id'] ?? null,
                        $item['variant_size'] ?? null,
                        $item['variant_color'] ?? null,
                        $item['name'],
                        $item['sku'] ?? null,
                        $item['price'],
                        $item['cost_price'] ?? null,
                        $item['quantity'],
                        $item['price'] * $item['quantity'],
                    ]);

            // Deduct stock. "stock >= ?" makes the check and the deduction one
            // atomic step: when two shoppers race for the last item, the second
            // UPDATE matches no row and the whole order rolls back. Pre-order
            // lines (and admin manual orders with allow_oversell) may go past
            // stock on purpose, so they keep clamping at 0 instead.
            $qty    = (int)$item['quantity'];
            $strict = empty($item['preorder']) && empty($data['allow_oversell']);
            if (!empty($item['variant_id'])) {
                $st = $pdo->prepare($strict
                    ? 'UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?'
                    : 'UPDATE product_variants SET stock = GREATEST(0, stock - ?) WHERE id = ?');
                $st->execute($strict ? [$qty, $item['variant_id'], $qty] : [$qty, $item['variant_id']]);
                if ($strict && $st->rowCount() === 0) throw new OutOfStockException($item['name']);
            }
            if (!empty($item['product_id'])) {
                if ($strict && empty($item['variant_id'])) {
                    $st = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
                    $st->execute([$qty, $item['product_id'], $qty]);
                    if ($st->rowCount() === 0) throw new OutOfStockException($item['name']);
                } else {
                    $pdo->prepare('UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?')
                        ->execute([$qty, $item['product_id']]);
                }
                $touchedProducts[(int)$item['product_id']] = true;
            }
        }

        // Variant products keep products.stock as the sum of their variants
        // (same roll-up as _order_stock_delta()), so it can't drift.
        foreach (array_keys($touchedProducts) as $pid) {
            $pdo->prepare(
                'UPDATE products SET stock = (SELECT COALESCE(SUM(stock),0) FROM product_variants WHERE product_id = ?)
                 WHERE id = ? AND has_variants = 1'
            )->execute([$pid, $pid]);
        }

        $pdo->commit();
        return $orderId;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Cancel an order and restore stock for all its items.
 *
 * Safe to call multiple times — skips orders already cancelled/refunded.
 * Returns true if the order was cancelled, false if it was already in a
 * terminal state or not found.
 */
/**
 * Does the current cart contain at least one genuine pre-order line?
 *
 * "Genuine" means the product is flagged preorder_enabled AND the quantity
 * in the cart exceeds what is actually in stock — an in-stock item from a
 * pre-order-enabled product is a normal sale and must not restrict payment.
 *
 * Deliberately a separate query rather than a column on cart_items(): that
 * SELECT names its columns explicitly, so adding preorder_enabled there
 * would throw and break the whole cart on any install where the column has
 * not been created yet. Here a missing column just returns false.
 */
function cart_has_preorder(): bool
{
    $items = cart_items();
    if (!$items) return false;

    // Column not present yet → [] → behave exactly as before.
    $preorderIds = preorder_product_ids(array_column($items, 'product_id'));
    if (!$preorderIds) return false;

    foreach ($items as $i) {
        if (!in_array((int)$i['product_id'], $preorderIds, true)) continue;
        $available = !empty($i['has_variants'])
            ? (int)($i['variant_stock'] ?? 0)
            : (int)($i['stock'] ?? 0);
        if ($available < (int)$i['quantity']) return true;
    }

    return false;
}

/**
 * Move an order's items on or off the shelf.
 *
 * $sign = +1 puts them back (cancel/refund/delete), -1 takes them again
 * (un-cancelling an order back into an active state).
 *
 * Mirrors create_order()'s deduction exactly — it decrements BOTH
 * product_variants.stock and products.stock, so this adjusts both. For
 * variant products products.stock is then recalculated from the sum of its
 * variants, which is what the admin product API treats as authoritative.
 */
function _order_stock_delta(int $orderId, int $sign): void
{
    $pdo = db();
    $items = $pdo->prepare('SELECT product_id, variant_id, quantity FROM order_items WHERE order_id = ?');
    $items->execute([$orderId]);
    $rows = $items->fetchAll();

    $touchedProducts = [];
    foreach ($rows as $item) {
        $qty = (int)$item['quantity'] * $sign;
        if (!empty($item['variant_id'])) {
            $pdo->prepare('UPDATE product_variants SET stock = GREATEST(0, stock + ?) WHERE id = ?')
                ->execute([$qty, $item['variant_id']]);
        }
        if (!empty($item['product_id'])) {
            $pdo->prepare('UPDATE products SET stock = GREATEST(0, stock + ?) WHERE id = ?')
                ->execute([$qty, $item['product_id']]);
            $touchedProducts[(int)$item['product_id']] = true;
        }
    }

    // Variant products keep products.stock as a roll-up; recompute so it
    // cannot drift from the per-variant numbers.
    foreach (array_keys($touchedProducts) as $pid) {
        $pdo->prepare(
            'UPDATE products SET stock = (SELECT COALESCE(SUM(stock),0) FROM product_variants WHERE product_id = ?)
             WHERE id = ? AND has_variants = 1'
        )->execute([$pid, $pid]);
    }
}

/** Statuses in which an order's stock is considered already returned. */
function order_status_releases_stock(string $status): bool
{
    return in_array($status, ['cancelled', 'refunded'], true);
}

/**
 * Change an order's status AND keep stock correct.
 *
 * The admin previously ran a bare `UPDATE orders SET status = ?` from both
 * the row dropdown and the bulk action, so marking an order Cancelled never
 * put its items back — cancel_order(), which does handle stock, was only
 * ever called by the Paymob callback.
 *
 * Transitions handled:
 *   active   -> cancelled/refunded : items go back on the shelf
 *   cancelled/refunded -> active   : items are taken again (un-cancelling)
 *   anything else                  : status only
 */
function set_order_status(int $orderId, string $newStatus): bool
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, status FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) return false;

    $old = (string)$order['status'];
    if ($old === $newStatus) return true;

    $wasReleased = order_status_releases_stock($old);
    $nowReleased = order_status_releases_stock($newStatus);

    $pdo->beginTransaction();
    try {
        if (!$wasReleased && $nowReleased)      _order_stock_delta($orderId, +1);
        elseif ($wasReleased && !$nowReleased)  _order_stock_delta($orderId, -1);

        $pdo->prepare('UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$newStatus, $orderId]);
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('set_order_status() failed for order #' . $orderId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Return an order's items to stock, for use when an order is DELETED.
 *
 * Deliberately refuses orders that are already 'cancelled' or 'refunded':
 * cancel_order() restored their stock at cancellation time, so restoring
 * again here would inflate inventory by the order quantity.
 *
 * @return bool true if stock was restored, false if skipped or on error
 */
function restore_order_stock(int $orderId): bool
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT id, status FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        return false;
    }

    // Stock for these was already returned when they were cancelled/refunded.
    if (order_status_releases_stock((string)$order['status'])) {
        return false;
    }

    // Shared with set_order_status() so both paths adjust stock identically
    // and both keep the variant roll-up consistent.
    _order_stock_delta($orderId, +1);
    return true;
}

function cancel_order(int $orderId): bool
{
    $pdo = db();

    // Fetch current order state
    $stmt = $pdo->prepare('SELECT id, status FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        return false;
    }

    // Only cancel orders that are in a cancellable state
    $terminal = ['cancelled', 'refunded', 'delivered'];
    if (in_array($order['status'], $terminal)) {
        return false;
    }

    $pdo->beginTransaction();
    try {
        // Same stock path as set_order_status(), so the variant roll-up on
        // products.stock is recomputed here too instead of drifting.
        _order_stock_delta($orderId, +1);

        // Mark as cancelled
        $pdo->prepare(
            'UPDATE orders SET status = "cancelled", updated_at = NOW() WHERE id = ?'
        )->execute([$orderId]);

        $pdo->commit();
        // Only failed/abandoned card payments come through here, so a welcome
        // discount spent on this order goes back to the customer.
        welcome_discount_release_for_order($orderId);
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('cancel_order() failed for order #' . $orderId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Remember that this browser has proven it may see an order (it just placed
 * it, or looked it up with order number + email on track-order.php).
 */
function remember_viewable_order(int $orderId): void
{
    lume_session_start();
    $ids = $_SESSION['viewable_orders'] ?? [];
    $ids[] = $orderId;
    $_SESSION['viewable_orders'] = array_slice(array_values(array_unique(array_map('intval', $ids))), -50);
}

/** May the current visitor see this order's details? */
function can_view_order(array $order): bool
{
    lume_session_start();
    if (!empty($order['user_id']) && is_logged_in() && (int)$order['user_id'] === (int)$_SESSION['user_id']) {
        return true;
    }
    $u = current_user();
    if ($u && ($u['role'] ?? '') === 'admin') return true;
    return in_array((int)$order['id'], $_SESSION['viewable_orders'] ?? [], true);
}

/**
 * Restore cart items from an order so the user can retry payment.
 *
 * Reads the order_items and re-adds them to the current cart session.
 * Skips items whose products are no longer active.
 */
function cart_restore_from_order(int $orderId): void
{
    $items = db()->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $items->execute([$orderId]);
    $orderItems = $items->fetchAll();

    foreach ($orderItems as $item) {
        if (empty($item['product_id'])) {
            continue;
        }
        // Check the product is still active before re-adding
        $p = db()->prepare('SELECT id, is_active FROM products WHERE id = ? AND is_active = 1');
        $p->execute([$item['product_id']]);
        if (!$p->fetch()) {
            continue;
        }
        cart_add(
            (int)$item['product_id'],
            (int)$item['quantity'],
            !empty($item['variant_id']) ? (int)$item['variant_id'] : null
        );
    }
}

// ════════════════════════════════════════════════════════
// MEDIA LIBRARY
// ════════════════════════════════════════════════════════

/**
 * Resize and compress an image file in-place using GD library.
 * Automatically called during uploads to save disk space and improve load times.
 */
function optimize_image_in_place(string $filepath, int $maxWidth = 1600, int $quality = 85): bool
{
    if (!file_exists($filepath) || !function_exists('getimagesize')) {
        return false;
    }

    $info = @getimagesize($filepath);
    if (!$info) return false;

    list($width, $height, $type) = $info;
    
    // Only optimize JPEGs, PNGs, and WEBPs. Skip SVG and GIF.
    if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP])) {
        return false;
    }

    $newWidth = $width;
    $newHeight = $height;

    if ($width > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = (int)($height * ($maxWidth / $width));
    }

    $srcImg = null;
    switch ($type) {
        case IMAGETYPE_JPEG: $srcImg = @imagecreatefromjpeg($filepath); break;
        case IMAGETYPE_PNG:  $srcImg = @imagecreatefrompng($filepath); break;
        case IMAGETYPE_WEBP: $srcImg = @imagecreatefromwebp($filepath); break;
    }

    if (!$srcImg) return false;

    $dstImg = imagecreatetruecolor($newWidth, $newHeight);

    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
        imagealphablending($dstImg, false);
        imagesavealpha($dstImg, true);
        $transparent = imagecolorallocatealpha($dstImg, 255, 255, 255, 127);
        imagefilledrectangle($dstImg, 0, 0, $newWidth, $newHeight, $transparent);
    } else {
        $white = imagecolorallocate($dstImg, 255, 255, 255);
        imagefill($dstImg, 0, 0, $white);
    }

    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    $success = false;
    switch ($type) {
        case IMAGETYPE_JPEG:
            $success = imagejpeg($dstImg, $filepath, $quality);
            break;
        case IMAGETYPE_PNG:
            $pngQuality = round((100 - $quality) / 10);
            $pngQuality = max(0, min(9, $pngQuality));
            $success = imagepng($dstImg, $filepath, $pngQuality);
            break;
        case IMAGETYPE_WEBP:
            $success = imagewebp($dstImg, $filepath, $quality);
            break;
    }

    imagedestroy($srcImg);
    imagedestroy($dstImg);

    return $success;
}

function media_upload(array $file, ?int $userId = null): ?array
{
    if ($file['error'] !== UPLOAD_ERR_OK)
        return null;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/svg+xml' => 'svg'
    ];

    if (!array_key_exists($mime, $allowedMimes))
        return null;

    $ext = $allowedMimes[$mime];
    $newName = 'media_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = UPLOADS_PATH . '/' . $newName;
    $filepath = 'assets/images/products/' . $newName; // Keep relative for DB storage

    // Ensure uploads directory exists
    if (!is_dir(UPLOADS_PATH))
        mkdir(UPLOADS_PATH, 0755, true);

    if (!move_uploaded_file($file['tmp_name'], $dest))
        return null;
        
    optimize_image_in_place($dest);
    $size = filesize($dest);

    // Get dimensions for images
    $width = null;
    $height = null;
    $imgInfo = @getimagesize($dest);
    if ($imgInfo) {
        $width = $imgInfo[0];
        $height = $imgInfo[1];
    }

    db()->prepare(
        'INSERT INTO media_library (filename, filepath, filetype, filesize, width, height, uploaded_by)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([$file['name'], $filepath, 'image', $size, $width, $height, $userId]);

    $id = (int) db()->lastInsertId();
    return [
        'id' => $id,
        'filename' => $file['name'],
        'filepath' => $filepath,
        'url' => SITE_URL . '/' . $filepath,
        'width' => $width,
        'height' => $height,
    ];
}

function media_list(int $limit = 50, int $offset = 0): array
{
    $stmt = db()->prepare(
        'SELECT * FROM media_library ORDER BY created_at DESC LIMIT ? OFFSET ?'
    );
    $stmt->execute([$limit, $offset]);
    return $stmt->fetchAll();
}

function media_delete(int $id): bool
{
    media_ensure_schema();
    $stmt = db()->prepare('SELECT filepath, poster FROM media_library WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row)
        return false;

    // A video's poster is its own file; remove it too.
    foreach ([$row['filepath'], $row['poster'] ?? null] as $rel) {
        if (!$rel) continue;
        $path = ROOT_PATH . '/' . $rel;
        if (file_exists($path))
            @unlink($path);
    }

    db()->prepare('DELETE FROM media_library WHERE id = ?')->execute([$id]);
    return true;
}

function media_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM media_library')->fetchColumn();
}

// ════════════════════════════════════════════════════════
// HOMEPAGE SECTIONS
// ════════════════════════════════════════════════════════
/**
 * Make sure homepage_sections can be scoped to a page.
 *
 * The section system was homepage-only; adding this column lets the exact
 * same section types, renderer and admin UI build About/Contact/etc. too.
 * Auto-created (same pattern as ensure_material_gem_columns) so no manual
 * migration is required; database/migration-page-sections.sql does the same
 * thing by hand if the DB user lacks ALTER.
 */
function ensure_page_sections_column(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $cols = array_column(db()->query('SHOW COLUMNS FROM homepage_sections')->fetchAll(), 'Field');
        if (!in_array('page', $cols, true)) {
            db()->exec("ALTER TABLE homepage_sections ADD COLUMN page VARCHAR(50) NOT NULL DEFAULT 'home'");
            db()->exec("ALTER TABLE homepage_sections ADD KEY page (page)");
        }
    } catch (Exception $e) { /* silently skip — callers degrade to 'home' */ }
}

/**
 * Sections for a page. Defaults to 'home' so every existing call site keeps
 * working unchanged, and existing rows (which default to 'home') stay on the
 * homepage exactly as before.
 */
function get_homepage_sections(bool $activeOnly = true, string $page = 'home'): array
{
    ensure_page_sections_column();

    $sql = 'SELECT * FROM homepage_sections WHERE 1=1';
    $params = [];
    try {
        $cols = array_column(db()->query('SHOW COLUMNS FROM homepage_sections')->fetchAll(), 'Field');
        if (in_array('page', $cols, true)) {
            $sql .= ' AND page = ?';
            $params[] = $page;
        } elseif ($page !== 'home') {
            return []; // column missing and a sub-page was asked for
        }
    } catch (Exception $e) { /* fall through unscoped */ }

    if ($activeOnly) $sql .= ' AND is_active = 1';
    $sql .= ' ORDER BY sort_order ASC, id ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Pages that can be built from sections. Key = `page` column value.
 */
function page_section_targets(): array
{
    return [
        'home'    => 'Homepage',
        'about'   => 'About',
        'contact' => 'Contact',
        'shipping'=> 'Shipping & Returns',
        'returns' => 'Exchange & Returns',
        'privacy' => 'Privacy Policy',
        'terms'   => 'Terms of Service',
    ];
}

function get_section(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM homepage_sections WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// ════════════════════════════════════════════════════════
// UTILITIES
// ════════════════════════════════════════════════════════
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    // If it's already an absolute URL (e.g. Paymob iframe redirect), use it directly
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        header('Location: ' . $path);
        exit;
    }
    $base = rtrim(SITE_URL, '/');
    $path = '/' . ltrim($path, '/');
    header('Location: ' . $base . $path);
    exit;
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function is_ajax(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function get_client_ip(): string
{
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        return filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
    }
    return 'unknown';
}

function setting(string $key, string $default = ''): string
{
    // Load the whole (small) settings table once per request. It used to run
    // one query per key — dozens on every page view.
    static $all = null;
    if ($all === null) {
        try {
            $all = db()->query('SELECT key_name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Exception $e) {
            $all = [];
        }
    }
    return array_key_exists($key, $all) && $all[$key] !== null ? (string) $all[$key] : $default;
}

/**
 * Paymob credential: the admin Settings value, falling back to the matching
 * .env constant (GATEWAY_API_KEY / GATEWAY_IFRAME_ID / GATEWAY_HMAC_SECRET),
 * which were loaded from .env but never actually read anywhere.
 */
function paymob_setting(string $key): string
{
    $val = trim(setting($key));
    if ($val !== '') return $val;
    $envMap = [
        'paymob_api_key'   => 'GATEWAY_API_KEY',
        'paymob_iframe_id' => 'GATEWAY_IFRAME_ID',
        'paymob_hmac'      => 'GATEWAY_HMAC_SECRET',
    ];
    $const = $envMap[$key] ?? null;
    return ($const && defined($const)) ? trim((string)constant($const)) : '';
}

/** True when every Paymob credential needed to start a card payment exists. */
function paymob_configured(): bool
{
    return paymob_setting('paymob_api_key') !== ''
        && paymob_setting('paymob_integration_id') !== ''
        && paymob_setting('paymob_iframe_id') !== '';
}

/**
 * InstaPay payment details, used by checkout, instapay-upload.php and the
 * confirmation email so the three can never disagree.
 *
 * Read from Settings → Payment only. There is deliberately no built-in
 * fallback account: an unset field is ''. The old form placeholders (which
 * were once saved into the DB by mistake) also count as unset.
 */
function instapay_details(): array
{
    $ipa  = trim(setting('instapay_username'));
    $wa   = preg_replace('/\D/', '', setting('instapay_whatsapp'));
    $link = trim(setting('instapay_link'));

    if ($ipa === 'your_instapay_username@instapay') $ipa = '';
    if ($wa === '201000000000')                     $wa  = '';
    if (!preg_match('~^https://~i', $link))         $link = '';

    return ['ipa' => $ipa, 'whatsapp' => $wa, 'link' => $link];
}

/** InstaPay is only offered at checkout once an InstaPay address is set. */
function instapay_configured(): bool
{
    return instapay_details()['ipa'] !== '';
}

/** wa.me link to the store's InstaPay WhatsApp with a prefilled message, or '' when no number is set. */
function instapay_whatsapp_link(string $message): string
{
    $wa = instapay_details()['whatsapp'];
    return $wa === '' ? '' : 'https://wa.me/' . $wa . '?text=' . urlencode($message);
}

/**
 * Verify a Paymob HMAC (SHA-512 over the documented field list, in order).
 * $fields maps each field name to its value as Paymob sent it.
 */
function paymob_hmac_valid(array $fields, string $received): bool
{
    $secret = paymob_setting('paymob_hmac');
    if ($secret === '' || $received === '') return false;
    $order = ['amount_cents','created_at','currency','error_occured','has_parent_transaction','id',
              'integration_id','is_3d_secure','is_auth','is_capture','is_refunded','is_standalone_payment',
              'is_voided','order','owner','pending','source_data_pan','source_data_sub_type',
              'source_data_type','success'];
    $str = '';
    foreach ($order as $k) {
        $v = $fields[$k] ?? '';
        if (is_bool($v)) $v = $v ? 'true' : 'false';
        $str .= (string)$v;
    }
    return hash_equals(strtolower(hash_hmac('sha512', $str, $secret)), strtolower($received));
}

/**
 * Google Fonts css2 URL for the theme's heading + body fonts.
 *
 * The css2 API wants weight tuples separated by ";" and sorted
 * (e.g. ital,wght@0,400;0,700;1,400). The header used to turn the ";" into
 * "," — Google answered 400 Bad Request, so the brand fonts never loaded on
 * the live site and every visitor saw fallback fonts.
 */
function google_fonts_url(string $head, string $headWeights, string $body, string $bodyWeights): string
{
    $clean = static function (string $w, string $fallback): array {
        $list = array_values(array_unique(array_filter(array_map('intval', preg_split('/[;,\s]+/', $w)),
            static fn($n) => $n >= 100 && $n <= 900)));
        sort($list);
        return $list ?: array_map('intval', explode(';', $fallback));
    };
    $families = [];
    $hw = $clean($headWeights, '400;700');
    // Upright weights, plus italic 400 for emphasis (tuples must be sorted).
    $families[] = 'family=' . str_replace('%20', '+', rawurlencode(trim($head))) . ':ital,wght@'
        . implode(';', array_map(static fn($n) => '0,' . $n, $hw)) . ';1,400';
    if (strcasecmp(trim($body), trim($head)) !== 0) {
        $families[] = 'family=' . str_replace('%20', '+', rawurlencode(trim($body))) . ':wght@'
            . implode(';', $clean($bodyWeights, '400;600'));
    }
    return 'https://fonts.googleapis.com/css2?' . implode('&', $families) . '&display=swap';
}

/**
 * Convert hex color (#RRGGBB) to "r, g, b" string for use in rgba().
 */
function hexToRgb(string $hex): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6)
        return '10, 10, 10';
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return "$r, $g, $b";
}

function track_visitor(): void
{
    $date = date('Y-m-d');
    lume_session_start();
    $newVisitor = empty($_SESSION['has_visited_today_' . $date]);
    $sql = $newVisitor
        ? "INSERT INTO daily_visitors (visit_date, visitors, views) VALUES (?, 1, 1) ON DUPLICATE KEY UPDATE visitors = visitors + 1, views = views + 1"
        : "INSERT INTO daily_visitors (visit_date, visitors, views) VALUES (?, 1, 1) ON DUPLICATE KEY UPDATE views = views + 1";

    // Try the insert first; only create the table if it is missing, instead
    // of running CREATE TABLE on every single page view.
    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            db()->prepare($sql)->execute([$date]);
            if ($newVisitor) $_SESSION['has_visited_today_' . $date] = true;
            return;
        } catch (Exception $e) {
            if ($attempt > 0) return;
            try {
                db()->exec("CREATE TABLE IF NOT EXISTS daily_visitors (
                    visit_date DATE PRIMARY KEY,
                    visitors INT DEFAULT 1,
                    views INT DEFAULT 1
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            } catch (Exception $e2) {
                return;
            }
        }
    }
}

// Note: currency_symbol() is defined above (line ~393).

/*
 * In-place editing markers for the builder canvas.
 *
 * render-sections.php tags text and pictures with which setting they come
 * from, so the builder can let you double-click text to type over it and
 * click "Change image" on a picture. Only output while api/section-preview.php
 * renders for the builder ($GLOBALS['BLD_EDIT']); the live site gets nothing.
 *
 *   kind  text  one-line text        multi  text with line breaks
 *         para  one paragraph of a blank-line-separated body (n = index)
 *         image | video | bg (background picture)
 *   List items (slides, reviews…) also carry the row and column.
 */
function bld(string $key, string $kind = 'text', ?int $row = null, ?int $col = null): string
{
    if (empty($GLOBALS['BLD_EDIT'])) return '';
    $a = ' data-bld-f="' . htmlspecialchars($key, ENT_QUOTES) . '" data-bld-k="' . $kind . '"';
    if ($row !== null) $a .= ' data-bld-row="' . $row . '"';
    if ($col !== null) $a .= ' data-bld-col="' . $col . '"';
    return $a;
}
