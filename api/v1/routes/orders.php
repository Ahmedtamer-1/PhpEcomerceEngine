<?php
require_once __DIR__ . '/../../includes/functions.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'track') {
        $email = trim($_GET['email'] ?? '');
        
        if (!$email) {
            echo json_encode(['status' => 400, 'error' => 'Please provide your email address.']);
            exit;
        }

        try {
            $stmt = db()->prepare('
                SELECT o.*, u.email as user_email 
                FROM orders o 
                LEFT JOIN users u ON u.id = o.user_id 
                WHERE LOWER(TRIM(u.email)) = LOWER(TRIM(?)) OR LOWER(TRIM(o.guest_email)) = LOWER(TRIM(?))
                ORDER BY o.created_at DESC
            ');
            $stmt->execute([$email, $email]);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$orders) {
                echo json_encode(['status' => 404, 'error' => 'No orders found for this email address.']);
                exit;
            }
            
            // Fetch items
            foreach ($orders as &$order) {
                $itemsStmt = db()->prepare('SELECT * FROM order_items WHERE order_id = ?');
                $itemsStmt->execute([$order['id']]);
                $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($order);
            
            echo json_encode(['status' => 200, 'data' => $orders]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['status' => 500, 'error' => 'Database error: ' . $e->getMessage()]);
        }
    } else {
        http_response_code(400);
        echo json_encode(['status' => 400, 'error' => 'Invalid action']);
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 405, 'error' => 'Method not allowed']);
}
