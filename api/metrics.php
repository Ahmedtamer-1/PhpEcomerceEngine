<?php
require_once dirname(__DIR__) . '/includes/auth.php';

header('Content-Type: application/json');

$response = [
    'success' => true,
    'low_stock_count' => 0,
    'abandoned_carts' => 0,
    'sparkline_7d' => []
];

// Inventory Alerts
try {
    $response['low_stock_count'] = (int) db()->query('SELECT COUNT(*) FROM products WHERE stock <= 5 AND is_active = 1')->fetchColumn();
} catch (Exception $e) {}

// Abandoned Carts
try {
    $response['abandoned_carts'] = (int) db()->query('SELECT COUNT(DISTINCT session_key) FROM cart_sessions WHERE updated_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)')->fetchColumn();
} catch (Exception $e) {}

// Sparkline (Last 7 days revenue)
try {
    $sparkline = [];
    $stmt = db()->query("SELECT DATE(created_at) as d, SUM(total) as rev FROM orders WHERE status NOT IN ('cancelled', 'refunded') AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY d ORDER BY d ASC");
    $data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $sparkline[] = isset($data[$date]) ? (float)$data[$date] : 0;
    }
    $response['sparkline_7d'] = $sparkline;
} catch (Exception $e) {}

echo json_encode($response);
exit;
