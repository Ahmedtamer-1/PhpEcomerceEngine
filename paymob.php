<?php
/**
 * LUMEEGY — Paymob Integration Functions
 */

/**
 * Perform a cURL request to Paymob API.
 */
function paymob_request(string $endpoint, array $data, string $method = 'POST') {
    $url = 'https://accept.paymob.com/api/' . ltrim($endpoint, '/');
    $ch = curl_init($url);
    $payload = json_encode($data);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        throw new Exception("Paymob cURL Error: $error");
    }

    $result = json_decode($response, true);
    if ($httpCode >= 400) {
        $msg = $result['message'] ?? $result['detail'] ?? 'Paymob API Error';
        throw new Exception($msg . " (HTTP $httpCode)");
    }

    return $result;
}

/**
 * Generate Paymob payment iframe URL for an order.
 */
function paymob_generate_iframe_url(int $orderId, float $amountCents, array $billingData) {
    $apiKey = paymob_setting('paymob_api_key');
    $integrationId = paymob_setting('paymob_integration_id');
    $iframeId = paymob_setting('paymob_iframe_id');

    if (!$apiKey || !$integrationId || !$iframeId) {
        throw new Exception("Paymob is not configured properly in settings.");
    }

    // 1. Authentication
    $authRes = paymob_request('auth/tokens', ['api_key' => $apiKey]);
    $token = $authRes['token'] ?? null;
    if (!$token) {
        throw new Exception("Failed to obtain Paymob auth token.");
    }

    // Fetch the order number from our DB to use as merchant_order_id
    $stmt = db()->prepare('SELECT order_number FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $orderRow = $stmt->fetch();
    if (!$orderRow) {
        throw new Exception("Local order not found.");
    }
    $merchantOrderId = $orderRow['order_number'] . '_' . time(); // Append time to avoid duplicate merchant_order_id if user retries

    // 2. Order Registration
    $orderRes = paymob_request('ecommerce/orders', [
        'auth_token' => $token,
        'delivery_needed' => 'false',
        'amount_cents' => (int) $amountCents,
        'currency' => setting('currency', 'EGP'),
        'merchant_order_id' => $merchantOrderId,
        'items' => [] // We can leave items empty or map them. Empty is usually fine for Paymob.
    ]);
    
    $paymobOrderId = $orderRes['id'] ?? null;
    if (!$paymobOrderId) {
        throw new Exception("Failed to register Paymob order.");
    }

    // Save Paymob Order ID to our local order's payment_ref
    db()->prepare('UPDATE orders SET payment_ref = ? WHERE id = ?')->execute([$paymobOrderId, $orderId]);

    // 3. Payment Key Request
    // Ensure all required billing data fields exist, default to 'NA' if empty
    $billing = [
        'first_name' => $billingData['first_name'] ?: 'NA',
        'last_name' => $billingData['last_name'] ?: 'NA',
        'email' => $billingData['email'] ?: 'NA',
        'phone_number' => $billingData['phone_number'] ?: 'NA',
        'apartment' => 'NA',
        'floor' => 'NA',
        'building' => 'NA',
        'street' => $billingData['street'] ?: 'NA',
        'city' => $billingData['city'] ?: 'NA',
        'country' => 'EG',
        'state' => 'NA'
    ];

    $keyRes = paymob_request('acceptance/payment_keys', [
        'auth_token' => $token,
        'amount_cents' => (int) $amountCents,
        'expiration' => 3600,
        'order_id' => $paymobOrderId,
        'billing_data' => $billing,
        'currency' => setting('currency', 'EGP'),
        'integration_id' => $integrationId
    ]);

    $paymentToken = $keyRes['token'] ?? null;
    if (!$paymentToken) {
        throw new Exception("Failed to obtain Paymob payment key.");
    }

    return "https://accept.paymob.com/api/acceptance/iframes/{$iframeId}?payment_token={$paymentToken}";
}
