<?php
/**
 * ADORNA — Checkout leads (abandoned checkouts)
 *
 * Saves the name / phone / email / city a shopper types on the checkout page,
 * plus a snapshot of their bag, so you can follow up if they never order.
 * One row per checkout attempt (keyed by the cart session). When the order is
 * placed the row is marked 'ordered' and detached from the session, so a later
 * checkout in the same browser starts a fresh row.
 */

function checkout_leads_ensure_table(): void
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS checkout_leads (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_key VARCHAR(100) NOT NULL,
        user_id INT UNSIGNED NULL,
        name VARCHAR(150) NULL,
        email VARCHAR(180) NULL,
        phone VARCHAR(30) NULL,
        city VARCHAR(100) NULL,
        cart_json TEXT NULL,
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
        status ENUM('open','contacted','ordered','dismissed') NOT NULL DEFAULT 'open',
        order_id INT UNSIGNED NULL,
        contacted_at TIMESTAMP NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_session (session_key),
        KEY idx_status (status, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

/** Save/update the lead for the current checkout. Returns true if a row was written. */
function checkout_lead_save(array $in): bool
{
    $name  = mb_substr(trim((string)($in['name'] ?? '')), 0, 150);
    $email = mb_substr(trim((string)($in['email'] ?? '')), 0, 180);
    $phone = mb_substr(trim((string)($in['phone'] ?? '')), 0, 30);
    $city  = mb_substr(trim((string)($in['city'] ?? '')), 0, 100);

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
    if (strlen(preg_replace('/\D/', '', $phone)) < 8) $phone = '';
    // Nothing we could contact them on yet: don't store anything.
    if ($phone === '' && $email === '') return false;

    $items = cart_items();
    if (!$items) return false;

    $cart = [];
    foreach ($items as $i) {
        $variant = trim(($i['variant_size'] ?? '') . ' ' . ($i['variant_color'] ?? ''));
        $cart[] = [
            'product_id' => (int)$i['product_id'],
            'name'       => $i['name'],
            'variant'    => $variant,
            'qty'        => (int)$i['quantity'],
            'price'      => (float)item_effective_price($i),
        ];
    }

    checkout_leads_ensure_table();
    $user = current_user();

    // NULLIF/COALESCE: a later save with an empty field never wipes a value
    // the shopper already typed. A row someone already handled stays handled.
    $sql = "INSERT INTO checkout_leads (session_key, user_id, name, email, phone, city, cart_json, subtotal)
            VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, ?)
            ON DUPLICATE KEY UPDATE
                user_id   = COALESCE(VALUES(user_id), user_id),
                name      = COALESCE(VALUES(name), name),
                email     = COALESCE(VALUES(email), email),
                phone     = COALESCE(VALUES(phone), phone),
                city      = COALESCE(VALUES(city), city),
                cart_json = VALUES(cart_json),
                subtotal  = VALUES(subtotal)";
    db()->prepare($sql)->execute([
        cart_session_key(),
        $user['id'] ?? null,
        $name, $email, $phone, $city,
        json_encode($cart, JSON_UNESCAPED_UNICODE),
        cart_total(),
    ]);
    return true;
}

/** Call right after an order is created. */
function checkout_lead_mark_ordered(int $orderId): void
{
    try {
        checkout_leads_ensure_table();
        $key = cart_session_key();
        // Renaming the key frees the session for any future checkout.
        db()->prepare("UPDATE checkout_leads
                       SET status = 'ordered', order_id = ?, session_key = CONCAT(session_key, '#', ?)
                       WHERE session_key = ?")
            ->execute([$orderId, $orderId, $key]);
    } catch (Throwable $e) {
        error_log('checkout_lead_mark_ordered: ' . $e->getMessage());
    }
}

/** Egyptian mobile → international digits for wa.me (01xxxxxxxxx → 201xxxxxxxxx). */
function checkout_lead_wa_number(string $phone): string
{
    $d = preg_replace('/\D/', '', $phone);
    if (str_starts_with($d, '0020')) $d = substr($d, 2);
    if (str_starts_with($d, '01') && strlen($d) === 11) $d = '2' . $d;
    return $d;
}
