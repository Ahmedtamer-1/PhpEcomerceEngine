<?php
/**
 * LUMEEGY — Payment Received Email Template
 *
 * Variables available: $order (array with 'items' key)
 * Rendered by mailer.php via include — uses ob_start() capture.
 */
$siteName  = setting('site_name', SITE_NAME);
$siteUrl   = SITE_URL;
$logoUrl   = $order['_logo'] ?? '';
$gold      = '#C8B89A';
$bg        = '#0f0f0f';
$surface   = '#1a1a1a';
$border    = '#2a2a2a';
$text      = '#F5F5F0';
$muted     = '#888880';
$accent    = '#C4714A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payment Received — <?= htmlspecialchars($siteName) ?></title>
</head>
<body style="margin:0;padding:0;background:#0a0a0a;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0a0a0a;">
  <tr>
    <td align="center" style="padding:40px 16px;">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:<?= $surface ?>;border-radius:8px;overflow:hidden;border:1px solid <?= $border ?>;">
        <!-- Header -->
        <tr>
          <td style="background:<?= $bg ?>;padding:32px 40px;text-align:center;border-bottom:1px solid <?= $border ?>;">
            <p style="margin:0;font-size:11px;letter-spacing:.2em;text-transform:uppercase;color:<?= $gold ?>;margin-bottom:8px;">Payment Received</p>
            <h1 style="margin:0;font-size:26px;font-weight:400;color:<?= $text ?>;letter-spacing:.05em;"><?= htmlspecialchars($siteName) ?></h1>
          </td>
        </tr>
        <!-- Hero message -->
        <tr>
          <td style="padding:40px 40px 24px;text-align:center;">
            <table width="56" height="56" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 20px;border-radius:50%;border:2px solid <?= $gold ?>;display:inline-table;"><tr><td align="center" valign="middle" style="width:56px;height:56px;text-align:center;vertical-align:middle;">
              <span style="font-size:24px;color:<?= $gold ?>;line-height:1;display:block;">&#10003;</span>
            </td></tr></table>
            <h2 style="margin:0 0 8px;font-size:20px;font-weight:400;color:<?= $text ?>;">Thank you, <?= htmlspecialchars(explode(' ', $order['shipping_name'] ?? 'there')[0]) ?>!</h2>
            <p style="margin:0;color:<?= $muted ?>;font-size:14px;line-height:1.6;">Your payment for order #<?= htmlspecialchars($order['order_number']) ?> has been successfully received and verified.<br>We are now preparing your items for shipment.</p>
          </td>
        </tr>
        <!-- Order Meta -->
        <tr>
          <td style="padding:0 40px 24px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:<?= $bg ?>;border-radius:6px;border:1px solid <?= $border ?>;">
              <tr>
                <td style="padding:16px 20px;border-right:1px solid <?= $border ?>;width:50%;">
                  <p style="margin:0 0 4px;font-size:10px;text-transform:uppercase;letter-spacing:.15em;color:<?= $muted ?>;">Order Number</p>
                  <p style="margin:0;font-size:14px;font-weight:600;color:<?= $gold ?>"><?= htmlspecialchars($order['order_number']) ?></p>
                </td>
                <td style="padding:16px 20px;width:50%;">
                  <p style="margin:0 0 4px;font-size:10px;text-transform:uppercase;letter-spacing:.15em;color:<?= $muted ?>;">Date</p>
                  <p style="margin:0;font-size:14px;color:<?= $text ?>"><?= date('F j, Y', strtotime($order['created_at'])) ?></p>
                </td>
              </tr>
              <tr>
                <td style="padding:16px 20px;border-top:1px solid <?= $border ?>;border-right:1px solid <?= $border ?>;">
                  <p style="margin:0 0 4px;font-size:10px;text-transform:uppercase;letter-spacing:.15em;color:<?= $muted ?>;">Payment</p>
                  <p style="margin:0;font-size:14px;color:<?= $text ?>"><?= $order['payment_method'] === 'instapay' ? 'InstaPay Transfer' : 'Online Card' ?></p>
                </td>
                <td style="padding:16px 20px;border-top:1px solid <?= $border ?>;">
                  <p style="margin:0 0 4px;font-size:10px;text-transform:uppercase;letter-spacing:.15em;color:<?= $muted ?>;">Ship To</p>
                  <p style="margin:0;font-size:14px;color:<?= $text ?>"><?= htmlspecialchars($order['shipping_city'] ?? '') ?></p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <!-- Order Items -->
        <tr>
          <td style="padding:0 40px 8px;">
            <p style="margin:0 0 12px;font-size:11px;text-transform:uppercase;letter-spacing:.15em;color:<?= $muted ?>;">Items Ordered</p>
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <?php foreach ($order['items'] as $item): ?>
              <tr>
                <td style="padding:12px 0;border-bottom:1px solid <?= $border ?>;">
                  <p style="margin:0 0 2px;font-size:14px;color:<?= $text ?>;font-weight:500;"><?= htmlspecialchars($item['name']) ?></p>
                  <?php if (!empty($item['variant_size']) || !empty($item['variant_color'])): ?>
                  <p style="margin:0;font-size:12px;color:<?= $muted ?>;">
                    <?= htmlspecialchars(trim(($item['variant_size'] ?? '') . ' ' . ($item['variant_color'] ?? ''))) ?>
                  </p>
                  <?php endif; ?>
                  <p style="margin:2px 0 0;font-size:12px;color:<?= $muted ?>;">Qty: <?= (int)$item['quantity'] ?></p>
                </td>
                <td style="padding:12px 0;border-bottom:1px solid <?= $border ?>;text-align:right;vertical-align:top;">
                  <p style="margin:0;font-size:14px;color:<?= $text ?>;"><?= money_plain((float)$item['subtotal']) ?></p>
                </td>
              </tr>
              <?php endforeach; ?>
            </table>
          </td>
        </tr>
        <!-- Totals -->
        <tr>
          <td style="padding:16px 40px 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="padding:4px 0;font-size:13px;color:<?= $muted ?>;">Subtotal</td>
                <td style="padding:4px 0;font-size:13px;color:<?= $text ?>;text-align:right;"><?= money_plain((float)$order['subtotal']) ?></td>
              </tr>
              <tr>
                <td style="padding:4px 0;font-size:13px;color:<?= $muted ?>;">Shipping</td>
                <td style="padding:4px 0;font-size:13px;color:<?= $text ?>;text-align:right;"><?= money_plain((float)$order['shipping_cost']) ?></td>
              </tr>
              <?php if ((float)$order['discount'] > 0): ?>
              <tr>
                <td style="padding:4px 0;font-size:13px;color:<?= $muted ?>;">Discount</td>
                <td style="padding:4px 0;font-size:13px;color:#6dbf7e;text-align:right;">-<?= money_plain((float)$order['discount']) ?></td>
              </tr>
              <?php endif; ?>
              <tr>
                <td style="padding:12px 0 0;font-size:15px;font-weight:600;color:<?= $text ?>;border-top:1px solid <?= $border ?>;">Total</td>
                <td style="padding:12px 0 0;font-size:15px;font-weight:600;color:<?= $gold ?>;text-align:right;border-top:1px solid <?= $border ?>;"><?= money_plain((float)$order['total']) ?></td>
              </tr>
            </table>
          </td>
        </tr>
        <!-- Shipping Address -->
        <tr>
          <td style="padding:0 40px 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:<?= $bg ?>;border-radius:6px;border:1px solid <?= $border ?>;padding:20px;">
              <tr>
                <td style="padding:20px;">
                  <p style="margin:0 0 8px;font-size:10px;text-transform:uppercase;letter-spacing:.15em;color:<?= $muted ?>;">Shipping Address</p>
                  <p style="margin:0;font-size:14px;color:<?= $text ?>;font-weight:500;"><?= htmlspecialchars($order['shipping_name'] ?? '') ?></p>
                  <p style="margin:4px 0 0;font-size:13px;color:<?= $muted ?>;line-height:1.6;">
                    <?= htmlspecialchars($order['shipping_addr'] ?? '') ?><br>
                    <?= htmlspecialchars($order['shipping_city'] ?? '') ?><?= !empty($order['shipping_country']) ? ', ' . htmlspecialchars($order['shipping_country']) : '' ?><br>
                    <?php if (!empty($order['phone'])): ?>
                    📞 <?= htmlspecialchars($order['phone']) ?>
                    <?php endif; ?>
                  </p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <!-- CTA -->
        <tr>
          <td style="padding:0 40px 40px;text-align:center;">
            <?php
            $trackUrl = $siteUrl . '/track-order.php?order_number=' . urlencode($order['order_number']) . '&email=' . urlencode($order['guest_email'] ?: ($order['user_email'] ?? ''));
            ?>
            <a href="<?= $trackUrl ?>"
               style="display:inline-block;padding:14px 36px;background:<?= $gold ?>;color:#0a0a0a;text-decoration:none;font-size:13px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;border-radius:2px;margin:0 5px 10px;">
              Track Order
            </a>
            <a href="<?= $siteUrl ?>/shop.php"
               style="display:inline-block;padding:14px 36px;background:transparent;border:1px solid <?= $gold ?>;color:<?= $gold ?>;text-decoration:none;font-size:13px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;border-radius:2px;margin:0 5px 10px;">
              Continue Shopping
            </a>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="padding:24px 40px;border-top:1px solid <?= $border ?>;text-align:center;">
            <p style="margin:0;font-size:11px;color:<?= $muted ?>;line-height:1.7;">
              Questions? Reply to this email or contact us at
              <a href="mailto:<?= htmlspecialchars(setting('contact_email', '')) ?>" style="color:<?= $gold ?>;text-decoration:none;"><?= htmlspecialchars(setting('contact_email', '')) ?></a>
            </p>
            <p style="margin:12px 0 0;font-size:10px;color:#444;letter-spacing:.1em;text-transform:uppercase;"><?= htmlspecialchars($siteName) ?> — <?= htmlspecialchars(setting('site_tagline', '')) ?></p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
