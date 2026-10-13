<?php
/**
 * Page-view beacon (sent by analytics_beacon_script() on storefront pages).
 *
 *   {sid, t, p, path, r, us, um, uc, ck}  → records a page view, returns {id}
 *   {id, dur}                              → time spent on that page view
 *
 * Anonymous and first-party: a random visitor id in a cookie, no IP stored.
 */
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$out = function (array $a = []) { echo json_encode($a ?: ['ok' => true]); exit; };

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(['ok' => false]);
$raw = file_get_contents('php://input', false, null, 0, 4096);
$in  = json_decode((string)$raw, true);
if (!is_array($in)) $out(['ok' => false]);

$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
if (analytics_is_bot($ua)) $out();

lume_session_start();
$user = current_user();
// The shop's own browsers (anyone who has signed in as admin) aren't counted.
if (analytics_is_staff()) $out();

try {
    analytics_ensure_schema();
} catch (Exception $e) { $out(); }

$str = function ($v, int $max): string {
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
};

// ── Time on page ──
if (isset($in['id'], $in['dur'])) {
    $vid = analytics_visitor_id();
    if ($vid !== '') {
        try {
            db()->prepare('UPDATE page_views SET duration_s = ? WHERE id = ? AND visitor_id = ? AND duration_s IS NULL')
                ->execute([max(0, min(3600, (int)$in['dur'])), (int)$in['id'], $vid]);
        } catch (Exception $e) {}
    }
    $out();
}

// ── New page view ──
$sid = $str($in['sid'] ?? '', 16);
if (!preg_match('/^[a-f0-9]{16}$/', $sid)) $out(['ok' => false]);

$vid = analytics_visitor_id();
$isNew = $vid === '' ? 1 : 0;
if ($isNew) {
    $vid = bin2hex(random_bytes(8));
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    setcookie(ANALYTICS_COOKIE, $vid, [
        'expires' => time() + 400 * 86400, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    $_COOKIE[ANALYTICS_COOKIE] = $vid;
}

$types = ['home', 'shop', 'product', 'cart', 'checkout', 'order', 'account', 'track', 'page'];
$type  = in_array($in['t'] ?? '', $types, true) ? $in['t'] : 'page';
$path  = $str($in['path'] ?? '/', 255);
if ($path === '' || $path[0] !== '/') $path = '/';
// Strip ad click ids and tracking tags so the same page groups together.
$qPos = strpos($path, '?');
if ($qPos !== false) {
    parse_str(substr($path, $qPos + 1), $q);
    $q = array_filter($q, fn($k) => !preg_match('/^(fbclid|gclid|ttclid|utm_\w+|_ga|mc_\w+)$/i', (string)$k), ARRAY_FILTER_USE_KEY);
    $path = substr($path, 0, $qPos) . ($q ? '?' . http_build_query($q) : '');
}

$refHost = strtolower($str($in['r'] ?? '', 120));
if ($refHost !== '' && !preg_match('/^[a-z0-9.-]+$/', $refHost)) $refHost = '';
$clickId = in_array($in['ck'] ?? '', ['fbclid', 'gclid', 'ttclid'], true) ? $in['ck'] : '';
[$source, $paid] = analytics_classify_source($refHost, $str($in['us'] ?? '', 80), $str($in['um'] ?? '', 80), $clickId, $ua);

$productId = (isset($in['p']) && is_numeric($in['p']) && (int)$in['p'] > 0) ? (int)$in['p'] : null;

try {
    db()->prepare(
        'INSERT INTO page_views (visitor_id, session_id, user_id, path, page_type, product_id, source, is_paid,
                                 referrer_host, utm_campaign, device, browser, is_new_visitor, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
    )->execute([
        $vid, $sid, $user['id'] ?? null, $path, $type, $productId, $source, $paid ? 1 : 0,
        $refHost ?: null, $str($in['uc'] ?? '', 120) ?: null,
        analytics_device($ua), analytics_browser($ua), $isNew,
    ]);
    $id = (int) db()->lastInsertId();

    // Keep the table from growing forever: occasionally drop rows over ~13 months old.
    if (random_int(1, 500) === 1) {
        db()->exec('DELETE FROM page_views WHERE created_at < DATE_SUB(NOW(), INTERVAL 400 DAY) LIMIT 5000');
    }
    $out(['ok' => true, 'id' => $id]);
} catch (Exception $e) {
    error_log('track: ' . $e->getMessage());
    $out(['ok' => false]);
}
