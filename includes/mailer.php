<?php
/**
 * LUMEEGY — Transactional Email System
 *
 * Sends order emails via:
 *   1. Resend API (preferred — free 3k/month, great deliverability, just needs API key)
 *   2. SMTP fallback via PHPMailer (Zoho, Hostinger, Gmail, etc.)
 *
 * Usage:
 *   send_order_email('confirmation', $orderId);
 *   send_order_email('shipped',      $orderId);
 *   send_order_email('cancelled',    $orderId);
 */

// ── Load PHPMailer (for SMTP fallback) ─────────────────────────────
require_once __DIR__ . '/vendor/phpmailer/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// ═══════════════════════════════════════════════════════════════════
// PUBLIC API
// ═══════════════════════════════════════════════════════════════════

/**
 * Send a transactional order email.
 *
 * @param string $type    'confirmation' | 'shipped' | 'cancelled'
 * @param int    $orderId Local DB order ID
 * @return bool  true on success, false on failure (failure is logged)
 */
function send_order_email(string $type, int $orderId): bool
{
    // Check if emails are globally enabled
    if (setting('email_enabled', '0') !== '1') {
        return false;
    }

    // Check if this specific email type is enabled
    $typeEnabled = setting('email_' . $type . '_enabled', '1');
    if ($typeEnabled !== '1') {
        return false;
    }

    // ── Load order data ───────────────────────────────────────────
    $order = _mailer_load_order($orderId);
    if (!$order) {
        error_log("[Mailer] Order #$orderId not found.");
        return false;
    }

    // ── Determine recipient ───────────────────────────────────────
    $toEmail = null;
    $toName  = $order['shipping_name'] ?? '';

    if (!empty($order['user_id'])) {
        // Logged-in user — get their email from users table
        $stmt = db()->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$order['user_id']]);
        $u = $stmt->fetch();
        if ($u) $toEmail = $u['email'];
    }

    // Fallback to guest_email
    if (!$toEmail && !empty($order['guest_email'])) {
        $toEmail = $order['guest_email'];
    }

    if (!$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        error_log("[Mailer] Order #$orderId has no valid recipient email.");
        return false;
    }

    // Templates need the recipient address to build a working "track your
    // order" link (track-order.php looks orders up by email). They were
    // reading $order['user_email'], which is not a column on `orders` — so
    // for account holders the link arrived with an empty email parameter.
    $order['_recipient_email'] = $toEmail;

    // ── Build subject & body ──────────────────────────────────────
    [$subject, $htmlBody] = _mailer_render_template($type, $order);

    // ── Send ──────────────────────────────────────────────────────
    return _mailer_send($toEmail, $toName, $subject, $htmlBody);
}

// ═══════════════════════════════════════════════════════════════════
// INTERNAL HELPERS
// ═══════════════════════════════════════════════════════════════════

/**
 * Load a full order record with all its items.
 */
function _mailer_load_order(int $orderId): ?array
{
    $stmt = db()->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) return null;

    $stmt2 = db()->prepare('
        SELECT oi.*, p.image AS product_image, pv.image AS variant_image 
        FROM order_items oi 
        LEFT JOIN products p ON oi.product_id = p.id 
        LEFT JOIN product_variants pv ON oi.variant_id = pv.id 
        WHERE oi.order_id = ?
    ');
    $stmt2->execute([$orderId]);
    $order['items'] = $stmt2->fetchAll();

    return $order;
}

/**
 * Render an email template and return [subject, htmlBody].
 */
function _mailer_render_template(string $type, array $order): array
{
    $siteName = setting('site_name', SITE_NAME);
    $orderNum = $order['order_number'];

    $subjects = [
        'confirmation' => "Your order $orderNum is confirmed — $siteName",
        'paid'         => "Payment received for order $orderNum — $siteName",
        'shipped'      => "Your order $orderNum has been shipped! — $siteName",
        'cancelled'    => "Your order $orderNum has been cancelled — $siteName",
    ];

    $subject = $subjects[$type] ?? "Order Update from $siteName";

    // Render the template file
    $templateFile = __DIR__ . '/email-templates/order-' . $type . '.php';
    if (!file_exists($templateFile)) {
        error_log("[Mailer] Template not found: $templateFile");
        return [$subject, "<p>Order $orderNum update from $siteName.</p>"];
    }

    ob_start();
    include $templateFile;
    $htmlBody = ob_get_clean();

    return [$subject, $htmlBody];
}

/**
 * Actually dispatch the email — tries Resend API first, falls back to SMTP.
 */
function _mailer_send(string $toEmail, string $toName, string $subject, string $htmlBody): bool
{
    $fromEmail = setting('email_from_address', '');
    $fromName  = setting('email_from_name', setting('site_name', SITE_NAME));

    if (!$fromEmail) {
        error_log('[Mailer] No sender email configured in settings.');
        return false;
    }

    $resendKey = setting('email_resend_api_key', '');

    if ($resendKey) {
        return _mailer_send_resend($resendKey, $fromEmail, $fromName, $toEmail, $toName, $subject, $htmlBody);
    }

    return _mailer_send_smtp($fromEmail, $fromName, $toEmail, $toName, $subject, $htmlBody);
}

/**
 * Send via Resend.com API (https://resend.com — free 3k/month).
 */
function _mailer_send_resend(
    string $apiKey,
    string $fromEmail, string $fromName,
    string $toEmail,   string $toName,
    string $subject,   string $htmlBody
): bool {
    $payload = json_encode([
        'from'    => "$fromName <$fromEmail>",
        'to'      => [$toEmail],
        'subject' => $subject,
        'html'    => $htmlBody,
    ]);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log("[Mailer/Resend] cURL error: $curlErr");
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }

    error_log("[Mailer/Resend] Failed HTTP $httpCode: $response");
    return false;
}

/**
 * Send via SMTP using PHPMailer (Zoho / Hostinger / Gmail / any SMTP).
 */
function _mailer_send_smtp(
    string $fromEmail, string $fromName,
    string $toEmail,   string $toName,
    string $subject,   string $htmlBody
): bool {
    $host     = setting('smtp_host', '');
    $port     = (int)setting('smtp_port', '587');
    $username = setting('smtp_username', '');
    $password = setting('smtp_password', '');
    $secure   = setting('smtp_secure', 'tls'); // 'tls' or 'ssl'

    if (!$host || !$username || !$password) {
        error_log('[Mailer/SMTP] SMTP is not configured in settings.');
        return false;
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = $port;
        $mail->SMTPAuth   = true;
        $mail->Username   = $username;
        $mail->Password   = $password;
        $mail->SMTPSecure = $secure === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = 'UTF-8';
        // Emails are sent while the customer waits on checkout; PHPMailer's
        // default is 300s, so a slow/unreachable SMTP server hung the order.
        $mail->Timeout    = 15;

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</tr>'], "\n", $htmlBody));

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('[Mailer/SMTP] ' . $e->getMessage());
        return false;
    }
}
