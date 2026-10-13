<?php
/**
 * Admin global search (⌘K / Ctrl+K).
 * Returns products, orders and customers matching ?q=, each with a link.
 */
require_once dirname(__DIR__) . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}
$like = '%' . addcslashes($q, '%_\\') . '%';
$base = SITE_URL . '/admin';
$results = [];

try {
    $st = db()->prepare(
        'SELECT id, name, sku, price, sale_price, stock, is_active, image
         FROM products WHERE name LIKE ? OR sku LIKE ? OR slug LIKE ?
         ORDER BY is_active DESC, name ASC LIMIT 6'
    );
    $st->execute([$like, $like, $like]);
    foreach ($st->fetchAll() as $p) {
        $price = product_effective_price($p);
        $results[] = [
            'type'  => 'Product',
            'title' => $p['name'],
            'sub'   => trim(($p['sku'] ? $p['sku'] . ' · ' : '') . money_plain($price) . ' · ' . (int)$p['stock'] . ' in stock' . ($p['is_active'] ? '' : ' · hidden')),
            'image' => !empty($p['image']) ? thumb_url($p['image'], 80) : null,
            'url'   => $base . '/products.php?edit=' . (int)$p['id'],
        ];
    }
} catch (Exception $e) { error_log('admin search products: ' . $e->getMessage()); }

try {
    $st = db()->prepare(
        'SELECT o.id, o.order_number, o.status, o.total, o.shipping_name, o.created_at
         FROM orders o LEFT JOIN users u ON u.id = o.user_id
         WHERE o.order_number LIKE ? OR o.shipping_name LIKE ? OR o.phone LIKE ?
            OR o.guest_email LIKE ? OR u.email LIKE ?
         ORDER BY o.created_at DESC LIMIT 6'
    );
    $st->execute([$like, $like, $like, $like, $like]);
    foreach ($st->fetchAll() as $o) {
        $results[] = [
            'type'  => 'Order',
            'title' => '#' . $o['order_number'] . ' — ' . $o['shipping_name'],
            'sub'   => ucwords(str_replace('_', ' ', $o['status'])) . ' · ' . money_plain((float)$o['total']) . ' · ' . date('d M Y', strtotime($o['created_at'])),
            'image' => null,
            'url'   => $base . '/orders.php?id=' . (int)$o['id'],
        ];
    }
} catch (Exception $e) { error_log('admin search orders: ' . $e->getMessage()); }

try {
    $st = db()->prepare(
        'SELECT id, first_name, last_name, email, role FROM users
         WHERE email LIKE ? OR CONCAT(first_name, " ", last_name) LIKE ?
         ORDER BY created_at DESC LIMIT 4'
    );
    $st->execute([$like, $like]);
    foreach ($st->fetchAll() as $u) {
        $results[] = [
            'type'  => $u['role'] === 'admin' ? 'Admin' : 'Customer',
            'title' => trim($u['first_name'] . ' ' . $u['last_name']) ?: $u['email'],
            'sub'   => $u['email'],
            'image' => null,
            'url'   => $base . '/users.php?id=' . (int)$u['id'],
        ];
    }
} catch (Exception $e) { error_log('admin search users: ' . $e->getMessage()); }

echo json_encode(['success' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
