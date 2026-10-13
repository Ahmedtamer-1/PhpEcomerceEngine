<?php
require_once __DIR__ . '/../../includes/functions.php';

lume_session_start();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    if ($action === 'add') {
        $pid = (int)($input['product_id'] ?? 0);
        $qty = max(1, (int)($input['quantity'] ?? 1));
        
        if ($pid > 0) {
            if (!isset($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }
            if (isset($_SESSION['cart'][$pid])) {
                $_SESSION['cart'][$pid]['quantity'] += $qty;
            } else {
                $_SESSION['cart'][$pid] = ['id' => $pid, 'quantity' => $qty];
            }
            echo json_encode(['status' => 200, 'cart_count' => cart_count()]);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 400, 'error' => 'Invalid product ID']);
        }
    } elseif ($action === 'remove') {
        $pid = (int)($input['product_id'] ?? 0);
        if (isset($_SESSION['cart'][$pid])) {
            unset($_SESSION['cart'][$pid]);
        }
        echo json_encode(['status' => 200, 'cart_count' => cart_count()]);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 400, 'error' => 'Invalid action']);
    }
} elseif ($method === 'GET') {
    echo json_encode(['status' => 200, 'cart_count' => cart_count()]);
} else {
    http_response_code(405);
    echo json_encode(['status' => 405, 'error' => 'Method not allowed']);
}
