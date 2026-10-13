<?php
/**
 * LUMEEGY — Paymob Webhook Callback
 *
 * Handles server-to-server transaction status updates from Paymob.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

// 1. Ensure method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// 2. Read JSON payload
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    exit('Bad Request');
}

// Paymob sometimes sends different events, we only care about Transaction Processed
if (($data['type'] ?? '') !== 'TRANSACTION') {
    http_response_code(200); // Acknowledge other events
    exit;
}

$obj = $data['obj'] ?? [];
if (!$obj) {
    http_response_code(400);
    exit('Missing object data');
}

// 3. Verify HMAC for security
if (paymob_setting('paymob_hmac') === '') {
    error_log('Paymob Webhook Error: HMAC Secret is not configured in settings or .env.');
    http_response_code(500);
    exit('Server Configuration Error');
}

$receivedHmac = $_GET['hmac'] ?? '';
if (!$receivedHmac) {
    error_log('Paymob Webhook Error: Missing HMAC parameter.');
    http_response_code(401);
    exit('Unauthorized');
}

// Flatten the nested fields into the names Paymob's HMAC spec uses.
$hmacFields = $obj;
$hmacFields['order']                = $obj['order']['id'] ?? '';
$hmacFields['source_data_pan']      = $obj['source_data']['pan'] ?? '';
$hmacFields['source_data_sub_type'] = $obj['source_data']['sub_type'] ?? '';
$hmacFields['source_data_type']     = $obj['source_data']['type'] ?? '';

if (!paymob_hmac_valid($hmacFields, (string)$receivedHmac)) {
    error_log('Paymob Webhook Error: HMAC signature mismatch.');
    http_response_code(401);
    exit('Unauthorized: Invalid HMAC');
}

// 4. Update Order Status
$merchantOrderIdRaw = $obj['order']['merchant_order_id'] ?? '';
// Note: We appended '_' . time() to avoid duplicate errors in Paymob. We must extract the actual order number.
$parts = explode('_', $merchantOrderIdRaw);
$orderNumber = $parts[0];
$success     = ($obj['success'] ?? false) === true || ($obj['success'] ?? '') === 'true';
$pending     = ($obj['pending'] ?? false) === true || ($obj['pending'] ?? '') === 'true';
$transactionId = $obj['id'] ?? null;

// Find the order
$stmt = db()->prepare('SELECT id, status, total, payment_ref FROM orders WHERE order_number = ?');
$stmt->execute([$orderNumber]);
$order = $stmt->fetch();

if (!$order) {
    error_log('Paymob Webhook Error: Order ' . $orderNumber . ' not found in DB.');
    http_response_code(200); // Acknowledge to stop retries
    exit('Order not found');
}

// Still processing on Paymob's side — nothing to decide yet.
if ($pending && !$success) {
    http_response_code(200);
    exit('OK');
}

// Only orders still waiting for payment may change here. Paymob retries and
// re-sends webhooks, so without this a late duplicate could drag an order the
// admin already moved to Shipped/Delivered back to "paid".
$awaitingPayment = in_array($order['status'], ['pending', 'pending_payment', 'cancelled'], true);

if ($success) {
    if (!$awaitingPayment) {
        http_response_code(200);
        exit('OK');
    }

    // The paid amount must match what we asked for; otherwise flag it for a
    // human instead of silently marking a short payment as paid.
    $expectedCents = (int)round((float)$order['total'] * 100);
    $paidCents     = (int)($obj['amount_cents'] ?? 0);
    if ($paidCents !== $expectedCents) {
        error_log("Paymob Webhook: amount mismatch for order {$orderNumber}: paid {$paidCents}, expected {$expectedCents}.");
        db()->prepare(
            'UPDATE orders SET notes = CONCAT(IFNULL(notes, ""), "\n[System] Paymob payment amount mismatch — paid ", ?, " cents, expected ", ?, ". Transaction ID: ", ?) WHERE id = ?'
        )->execute([$paidCents, $expectedCents, (string)$transactionId, $order['id']]);
        http_response_code(200);
        exit('OK');
    }

    // set_order_status() also takes the stock again if the order had been
    // cancelled in the meantime (e.g. the customer retried and then paid the
    // first attempt after all).
    set_order_status((int)$order['id'], 'paid');
    db()->prepare('UPDATE orders SET payment_ref = ? WHERE id = ?')->execute([$transactionId, $order['id']]);
    send_order_email('confirmation', (int)$order['id']);
} else {
    if ($order['status'] === 'cancelled' || !$awaitingPayment) {
        // Already resolved — acknowledge silently
        http_response_code(200);
        exit('OK');
    }

    // Payment failed or was declined — auto-cancel and restore stock, and
    // only email the customer if this call actually cancelled it.
    if (cancel_order((int)$order['id'])) {
        send_order_email('cancelled', (int)$order['id']);
    }

    // Append a system note with the failed transaction ID for admin visibility
    if ($transactionId) {
        db()->prepare(
            'UPDATE orders SET notes = CONCAT(IFNULL(notes, ""), "\n[System] Declined Paymob transaction ID: ", ?) WHERE id = ?'
        )->execute([$transactionId, $order['id']]);
    }
}

http_response_code(200);
exit('OK');
