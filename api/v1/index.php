<?php
/**
 * API Front Controller (v1)
 *
 * This file acts as the single entry point for all /api/v1/* requests.
 * It strictly enforces JSON-only output and acts as a firewall against
 * HTML stack traces leaking.
 */

// 1. Force strict JSON response headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// 2. Disable default HTML error output
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// 3. Register strict JSON error handlers
function api_error_handler($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}

function api_exception_handler($e) {
    http_response_code(500);
    $error = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    
    // Log the actual error internally
    error_log("[API ERROR] " . $error);

    echo json_encode([
        'status' => 500,
        'error'  => $error
    ]);
    exit;
}

function api_shutdown_handler() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        http_response_code(500);
        echo json_encode([
            'status' => 500,
            'error'  => 'Fatal Internal Error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']
        ]);
        exit;
    }
}

set_error_handler('api_error_handler');
set_exception_handler('api_exception_handler');
register_shutdown_function('api_shutdown_handler');

// 4. Load database and environment
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// 5. Basic Router
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = str_replace('/api/v1/', '', $requestUri);

$parts = explode('/', trim($path, '/'));
$resource = $parts[0] ?? '';
$id = $parts[1] ?? null;

// Route Map
$routes = [
    'products'   => 'routes/products.php',
    'cart'       => 'routes/cart.php',
    'theme'      => 'routes/theme.php',
    'categories' => 'routes/categories.php',
    'auth'       => 'routes/auth.php',
    'orders'     => 'routes/orders.php'
];

if (array_key_exists($resource, $routes)) {
    require_once __DIR__ . '/' . $routes[$resource];
} else {
    http_response_code(404);
    echo json_encode(['status' => 404, 'error' => 'API endpoint not found']);
}
