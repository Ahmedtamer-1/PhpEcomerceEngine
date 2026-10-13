<?php
/**
 * ADORNA — Saves checkout details as the shopper types (see includes/checkout-leads.php).
 * Called by assets/js/checkout.js with fetch() and, when the page closes, sendBeacon().
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/checkout-leads.php';
lume_session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false], 405);
}
if (!csrf_verify((string)($_POST['csrf'] ?? ''))) {
    json_response(['ok' => false], 403);
}

try {
    $saved = checkout_lead_save([
        'name'  => $_POST['name']  ?? '',
        'email' => $_POST['email'] ?? '',
        'phone' => $_POST['phone'] ?? '',
        'city'  => $_POST['city']  ?? '',
    ]);
    json_response(['ok' => $saved]);
} catch (Throwable $e) {
    error_log('checkout-lead: ' . $e->getMessage());
    json_response(['ok' => false], 500);
}
