<?php
/**
 * LUMEEGY — Order Confirmation Email Template
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
<title>Order Confirmed — <?= htmlspecialchars($siteName) ?></title>
</head>
<body style="margin:0;padding:0;background:#0a0a0a;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0a0a0a;">
  <tr>
    <td align="center" style="padding:40px 16px;">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:<?= $surface ?>;border-radius:8px;overflow:hidden;border:1px solid <?= $border ?>;">

        <!-- Header -->
        <tr>
          <td style="background:<?= $bg ?>;padding:32px 40px;text-align:center;border-bottom:1px solid <?= $border ?>;">
            <p style="margin:0;font-size:11px;letter-spacing:.2em;text-transform:uppercase;color:<?= $gold ?>;margin-bottom:8px;">Order Confirmed</p>
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
            <p style="margin:0;color:<?= $muted ?>;font-size:14px;line-height:1.6;">Your order has been received and is being processed.<br>We'll notify you when it ships.</p>
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
                  <p style="margin:0;font-size:14px;color:<?= $text ?>"><?= $order['payment_method'] === 'online' ? 'Online Card' : ($order['payment_method'] === 'instapay' ? 'InstaPay' : 'Cash on Delivery') ?></p>
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
              <?php foreach ($order['items'] as $item): 
                $itemImg = !empty($item['variant_image']) ? $item['variant_image'] : (!empty($item['product_image']) ? $item['product_image'] : '');
              ?>
              <tr>
                <td style="padding:12px 0;border-bottom:1px solid <?= $border ?>;">
                  <table cellpadding="0" cellspacing="0" border="0">
                    <tr>
                      <?php if ($itemImg): ?>
                      <td style="padding-right:12px;vertical-align:top;">
                        <img src="<?= rtrim(SITE_URL, '/') . '/' . htmlspecialchars($itemImg) ?>" width="44" height="44" style="width:44px;height:44px;object-fit:cover;border-radius:4px;display:block;" alt="<?= htmlspecialchars($item['name']) ?>">
                      </td>
                      <?php endif; ?>
                      <td style="vertical-align:top;">
                        <p style="margin:0 0 2px;font-size:14px;color:<?= $text ?>;font-weight:500;"><?= htmlspecialchars($item['name']) ?></p>
                        <?php if (!empty($item['variant_size']) || !empty($item['variant_color'])): ?>
                        <p style="margin:0;font-size:12px;color:<?= $muted ?>;">
                          <?= htmlspecialchars(trim(($item['variant_size'] ?? '') . ' ' . ($item['variant_color'] ?? ''))) ?>
                        </p>
                        <?php endif; ?>
                        <p style="margin:2px 0 0;font-size:12px;color:<?= $muted ?>;">Qty: <?= (int)$item['quantity'] ?></p>
                      </td>
                    </tr>
                  </table>
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
            /* track-order.php looks an order up by order number + email, so
               the link carries both. _recipient_email is set by mailer.php and
               works for both guests and account holders. */
            $recipient = $order['_recipient_email'] ?? ($order['guest_email'] ?? '');
            $trackUrl  = $siteUrl . '/track-order.php?order_number=' . urlencode($order['order_number'])
                       . '&email=' . urlencode($recipient);

            /* Contact WhatsApp — defined out here, not inside the InstaPay
               branch, because the cancel/returns block below needs it for
               every payment method. Number comes from Settings → Payment. */
            $waLink0 = instapay_whatsapp_link('Hello, about my order #' . $order['order_number']);
            ?>
            <a href="<?= htmlspecialchars($trackUrl) ?>"
               style="display:inline-block;padding:14px 36px;background:<?= $gold ?>;color:#0a0a0a;text-decoration:none;font-size:13px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;border-radius:2px;margin:0 5px 10px;">
              View Your Order
            </a>

            <?php if ($order['payment_method'] === 'instapay'):
                /* Shared with checkout.php and instapay-upload.php via
                   instapay_details() (Settings → Payment, with the previous
                   hardcoded values as fallback). */
                $instapay = instapay_details();
                $ipa     = $instapay['ipa'];
                $payLink = $instapay['link'];
                $waLink  = instapay_whatsapp_link("Hello, here is my payment receipt for order #" . $order['order_number']);
            ?>
            <div style="background:<?= $bg ?>;border:1px solid <?= $gold ?>;border-radius:6px;padding:24px;margin-top:24px;text-align:left;">
                <p style="margin:0 0 6px;font-size:10px;text-transform:uppercase;letter-spacing:.18em;color:<?= $accent ?>;">Action needed</p>
                <h3 style="margin:0 0 10px;color:<?= $gold ?>;font-size:18px;">Your order isn&rsquo;t paid yet</h3>
                <p style="margin:0 0 20px;color:<?= $muted ?>;font-size:14px;line-height:1.6;">
                    We&rsquo;ll start preparing it as soon as we receive your InstaPay transfer of
                    <strong style="color:<?= $gold ?>"><?= money_plain((float)$order['total']) ?></strong>.
                </p>

                <!-- Primary action: go straight to InstaPay -->
                <div style="text-align:center;margin-bottom:22px;">
                  <a href="<?= htmlspecialchars($payLink) ?>"
                     style="display:inline-block;padding:16px 44px;background:<?= $gold ?>;color:#0a0a0a;text-decoration:none;font-size:14px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;border-radius:2px;">
                    Pay Now &mdash; <?= money_plain((float)$order['total']) ?>
                  </a>
                  <p style="margin:10px 0 0;font-size:11px;color:<?= $muted ?>;">Opens InstaPay. Already paid? Just send us the receipt below.</p>
                </div>

                <p style="margin:0 0 10px;font-size:11px;text-transform:uppercase;letter-spacing:.15em;color:<?= $muted ?>;">Or pay manually</p>
                <ol style="color:<?= $muted ?>;font-size:14px;line-height:1.7;margin:0 0 20px 20px;padding:0;">
                    <li style="margin-bottom:10px">
                        Open your banking or InstaPay app and send
                        <strong style="color:<?= $gold ?>"><?= money_plain((float)$order['total']) ?></strong> to:
                        <br>
                        <strong style="color:<?= $text ?>;display:inline-block;margin-top:6px;padding:6px 10px;background:#111;border:1px solid <?= $border ?>;border-radius:4px;font-size:15px;letter-spacing:.03em;"><?= htmlspecialchars($ipa) ?></strong>
                    </li>
                    <li style="margin-bottom:10px">Screenshot the confirmation screen.</li>
                    <li>Send us that screenshot on WhatsApp so we can verify it.</li>
                </ol>

                <div style="text-align:center;">
                  <a href="<?= $waLink ?>"
                     style="display:inline-block;padding:13px 32px;background:#25D366;color:#ffffff;text-decoration:none;font-size:13px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;border-radius:2px;">
                    Send Receipt via WhatsApp
                  </a>
                </div>

                <p style="margin:18px 0 0;font-size:12px;color:<?= $muted ?>;line-height:1.6;text-align:center;">
                    Your order is held as <strong style="color:<?= $text ?>">Pending Payment</strong> until the transfer arrives.
                </p>
            </div>
            <?php else: ?>
            <a href="<?= $siteUrl ?>/shop.php"
               style="display:inline-block;padding:14px 36px;background:transparent;border:1px solid <?= $gold ?>;color:<?= $gold ?>;text-decoration:none;font-size:13px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;border-radius:2px;margin:0 5px 10px;">
              Continue Shopping
            </a>
            <?php endif; ?>

            <!-- Changed your mind / something wrong -->
            <div style="margin-top:28px;padding-top:20px;border-top:1px solid <?= $border ?>;text-align:left;">
                <p style="margin:0 0 8px;font-size:11px;text-transform:uppercase;letter-spacing:.15em;color:<?= $muted ?>;">Need to change or cancel?</p>
                <p style="margin:0;font-size:13px;color:<?= $muted ?>;line-height:1.7;">
                    You can cancel free of charge while the order is still <strong style="color:<?= $text ?>">Pending</strong> or
                    <strong style="color:<?= $text ?>">Processing</strong> &mdash; message us on
                    <a href="<?= $waLink0 ?>" style="color:<?= $gold ?>;text-decoration:underline;">WhatsApp</a>
                    or reply to this email with your order number.
                    <br><br>
                    Already delivered? See our
                    <a href="<?= $siteUrl ?>/exchange-returns.php" style="color:<?= $gold ?>;text-decoration:underline;">exchange &amp; returns policy</a>
                    &mdash; returns are accepted within 7 days of delivery.
                </p>
            </div>
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
