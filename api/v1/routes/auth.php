<?php
require_once __DIR__ . '/../../includes/functions.php';

lume_session_start();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $user = current_user();
    if ($user) {
        echo json_encode([
            'status' => 200, 
            'data' => [
                'logged_in' => true,
                'role' => $user['role'] ?? 'customer',
                'first_name' => $user['first_name'] ?? ''
            ]
        ]);
    } else {
        echo json_encode([
            'status' => 200, 
            'data' => [
                'logged_in' => false,
                'role' => 'guest'
            ]
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 405, 'error' => 'Method not allowed']);
}
