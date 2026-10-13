<?php
/**
 * LUMEEGY — Checkout Page
 * 
 * All redirect logic runs BEFORE header.php to avoid "headers already sent" errors.
 */
ob_start(); // Buffer all output so headers can always be sent safely
require_once __DIR__ . '/includes/functions.php';
// Self-load the CAPI helper rather than relying on functions.php having been
// updated to require it — is_readable() first, so a missing file is a no-op
// instead of a fatal.
if (!function_exists('meta_capi_send') && is_readable(__DIR__ . '/includes/meta-capi.php')) {
    require_once __DIR__ . '/includes/meta-capi.php';
}
if (is_readable(__DIR__ . '/includes/checkout-leads.php')) {
    require_once __DIR__ . '/includes/checkout-leads.php';
}
lume_session_start();

// ══════════════════════════════════════════════
// PHASE 1: Handle all redirects BEFORE any HTML
// ══════════════════════════════════════════════

// Check for order success first (cart will be empty after order)
$showSuccess = false;
$successId   = null;
if (!empty($_GET['success']) && !empty($_SESSION['order_success'])) {
    $successId   = $_SESSION['order_success'];
    $showSuccess = true;
    unset($_SESSION['order_success']);
}

// If NOT showing success, check cart
if (!$showSuccess) {
    $items = cart_items();
    if (empty($items)) {
        redirect('/cart.php');
    }
    $subtotal = cart_total();
    // One-time welcome discount (logged-in accounts that haven't used it yet).
    $cartDiscount = cart_discount($subtotal);
}

$user  = current_user();
$error = '';

// Settings
$freeShippingOver = (float)setting('free_shipping_over', '2000');
$codEnabled       = setting('cod_enabled', '1') === '1';
$codLabel         = setting('cod_label', 'Cash on Delivery');
$codFee           = (float)setting('cod_extra_fee', '0');

// Cash on delivery is withheld when the cart contains a pre-order: those ship
// weeks later, so COD would mean shipping unpaid goods against an order the
// customer can simply refuse on arrival. $codAvailable — not $codEnabled — is
// what gates the radio button and the server-side check below.
$hasPreorder      = cart_has_preorder();
$codAvailable     = $codEnabled && !$hasPreorder;
// The phone_display_name setting once held a bad value, which is why this was
// hardcoded. Use it again, but only when it looks like a real label.
$phoneLabel       = trim(setting('phone_display_name', ''));
if ($phoneLabel === '' || mb_strlen($phoneLabel) > 40 || preg_match('/^[\d\s+()-]+$/', $phoneLabel)) {
    $phoneLabel = 'Phone Number';
}
// Card payments are only offered when Paymob is fully configured.
$onlineAvailable  = paymob_configured();
$allowedMethods   = array_merge(['instapay'], $codAvailable ? ['cod'] : [], $onlineAvailable ? ['online'] : []);

// Fetch active shipping zones
$zones = db()->query('SELECT id, name, cost FROM shipping_zones WHERE is_active = 1 ORDER BY sort_order ASC, name ASC')->fetchAll();

// Handle POST submission (before any HTML output so redirect works)
if (!$showSuccess && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) { $error = 'Invalid request.'; }
    else {
        $name    = trim($_POST['shipping_name'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $addr    = trim($_POST['address'] ?? '');
        $zoneId  = (int)($_POST['shipping_zone'] ?? 0);
        $country = trim($_POST['country'] ?? 'Egypt');
        $notes   = trim($_POST['notes'] ?? '');
        $paymentMethod = (string)($_POST['payment_method'] ?? '');
        $phoneDigits   = preg_replace('/\D/', '', $phone);

        // Validate
        if (!$name || !$email || !$phone || !$addr || !$zoneId) {
            $error = 'Please fill in all required fields, including your phone number and city.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email.';
        } elseif (strlen($phoneDigits) < 8 || strlen($phoneDigits) > 15) {
            $error = 'Please enter a valid phone number.';
        } elseif (mb_strlen($name) > 150 || mb_strlen($addr) > 500 || mb_strlen($notes) > 1000) {
            $error = 'Some of the details you entered are too long.';
        } elseif ($paymentMethod === 'cod' && !$codAvailable) {
            // Re-checked server-side: hiding the radio is not a control, the
            // POST can be forged.
            $error = $hasPreorder
                ? 'Cash on delivery is not available for pre-orders. Please choose another payment method.'
                : 'Cash on delivery is not available.';
        } elseif (!in_array($paymentMethod, $allowedMethods, true)) {
            // Anything else used to be stored as-is (and a missing value fell
            // through to the Paymob branch even when it was not configured).
            $error = 'Please choose a payment method.';
        } else {
            // Find zone
            $selectedZone = null;
            foreach ($zones as $z) {
                if ($z['id'] == $zoneId) { $selectedZone = $z; break; }
            }
            if (!$selectedZone) {
                $error = 'Invalid shipping zone selected.';
            } else {
                // Check inventory. Pre-order products are exempt: the cart lets
                // them go past stock on purpose, so blocking here made every
                // genuine pre-order impossible to check out.
                $stockError = null;
                $preorderIds = preorder_product_ids(array_column($items, 'product_id'));
                foreach ($items as $i) {
                    if (in_array((int)$i['product_id'], $preorderIds, true)) continue;
                    $reqQty = (int)$i['quantity'];
                    if (!empty($i['has_variants'])) {
                        if ((int)$i['variant_stock'] < $reqQty) {
                            $stockError = "Sorry, we don't have enough stock for " . $i['name'] . " (" . trim($i['variant_size'] . ' ' . $i['variant_color']) . ").";
                            break;
                        }
                    } else {
                        if ((int)$i['stock'] < $reqQty) {
                            $stockError = "Sorry, we don't have enough stock for " . $i['name'] . ".";
                            break;
                        }
                    }
                }

                if ($stockError) {
                    $error = $stockError;
                } else {
                    // Calculate final shipping
                    $shipping = ($freeShippingOver > 0 && $subtotal >= $freeShippingOver) ? 0 : (float)$selectedZone['cost'];

                    // Claim the welcome discount atomically before the order
                    // is written, so a double-submitted form can't use it twice.
                    $discountPct = $user ? welcome_discount_claim((int)$user['id']) : 0;
                    $discountAmt = $discountPct > 0 ? round($subtotal * $discountPct / 100, 2) : 0;
                    $total = $subtotal - $discountAmt + $shipping;
                    
                    if ($paymentMethod === 'cod') {
                        $total += $codFee;
                    }

                    $orderItems = [];
                    foreach ($items as $i) {
                        $orderItems[] = [
                            'product_id'    => $i['product_id'],
                            'variant_id'    => $i['pv_id'] ?? null,
                            'variant_size'  => $i['variant_size'] ?? null,
                            'variant_color' => $i['variant_color'] ?? null,
                            'name'          => $i['name'],
                            'sku'           => $i['variant_sku'] ?? $i['sku'] ?? null,
                            'price'         => item_effective_price($i),
                            'cost_price'    => $i['variant_cost_price'] ?? $i['cost_price'] ?? null,
                            'quantity'      => (int)$i['quantity'],
                        ];
                    }

                    // Insert the order
                    try {
                    $orderId = create_order([
                        'user_id'        => $user['id'] ?? null,
                        'guest_email'    => $user ? null : $email,
                        'subtotal'       => $subtotal,
                        'shipping_cost'  => $shipping,
                        'discount'       => $discountAmt,
                        'total'          => $total,
                        'payment_method' => $paymentMethod,
                        'shipping_name'  => $name,
                        'shipping_addr'  => $addr,
                        'shipping_city'  => $selectedZone['name'],
                        'shipping_country' => $country,
                        'notes'          => $notes,
                        'phone'          => $phone,
                        'shipping_zone'  => $selectedZone['name'],
                        'items'          => $orderItems,
                    ]);
                    } catch (Exception $e) {
                        if ($discountPct > 0) welcome_discount_unclaim((int)$user['id']);
                        throw $e;
                    }
                    if ($discountPct > 0) welcome_discount_attach_order((int)$user['id'], $orderId);
                    // Checkout lead: this one turned into an order.
                    if (function_exists('checkout_lead_mark_ordered')) checkout_lead_mark_ordered((int)$orderId);
                    // Demand history: what was actually bought, per product.
                    foreach ($orderItems as $oi) {
                        cart_event_log('purchase', (int)$oi['product_id'], $oi['variant_id'] ? (int)$oi['variant_id'] : null,
                            (int)$oi['quantity'], (float)$oi['price'], $orderId);
                    }
                    // Lets this browser open its own order on pages such as
                    // instapay-upload.php without logging in.
                    remember_viewable_order($orderId);

                    // Handle Payment Method
                    if ($paymentMethod === 'online') {
                        // ── Cancel any stale pending online order from a previous attempt ──
                        $stalePendingId = $_SESSION['paymob_pending_order'] ?? null;
                        if ($stalePendingId) {
                            cancel_order((int)$stalePendingId);
                            unset($_SESSION['paymob_pending_order']);
                        }

                        try {
                            require_once __DIR__ . '/includes/paymob.php';
                            
                            $names = explode(' ', $name, 2);
                            $billingData = [
                                'first_name' => $names[0] ?? 'NA',
                                'last_name' => $names[1] ?? 'NA',
                                'email' => $email,
                                'phone_number' => $phone,
                                'street' => $addr,
                                'city' => $selectedZone['name'],
                            ];
                            
                            // round(): e.g. 199.99 * 100 is 19998.999… and the
                            // (int) cast inside would charge one piaster short.
                            $iframeUrl = paymob_generate_iframe_url($orderId, round($total * 100), $billingData);
                            
                            // Clear cart since order is generated and user is leaving to pay
                            cart_clear();
                            $_SESSION['paymob_pending_order'] = $orderId;
                            
                            redirect($iframeUrl);
                        } catch (Exception $e) {
                            error_log('Paymob Error: ' . $e->getMessage());
                            cancel_order($orderId);
                            // Gateway error details go to the log, not the customer.
                            $error = 'We could not start the card payment. Please try again or choose another payment method.';
                        }

                    } elseif ($paymentMethod === 'instapay') {
                        db()->prepare("UPDATE orders SET status = 'pending_payment' WHERE id = ?")->execute([$orderId]);
                        cart_clear();
                        // InstaPay orders were the ONLY branch that never sent a
                        // confirmation — and they are the ones that most need it,
                        // because the email carries the payment instructions and
                        // the pay link. Without it the customer left the success
                        // page with no record of how to pay.
                        require_once __DIR__ . '/includes/mailer.php';
                        send_order_email('confirmation', $orderId);
                        $_SESSION['order_success'] = $orderId;
                        redirect('/checkout.php?success=1');
                    } else {
                        cart_clear();
                        // COD order confirmed — send confirmation email
                        require_once __DIR__ . '/includes/mailer.php';
                        send_order_email('confirmation', $orderId);
                        // Set session and redirect
                        $_SESSION['order_success'] = $orderId;
                        redirect('/checkout.php?success=1');
                    }
                }
            }
        }
    }
}

// ══════════════════════════════════════════════
// PHASE 2: Render HTML (no more redirects below)
// ══════════════════════════════════════════════

$pageTitle = $showSuccess ? 'Order Confirmed — ' . setting('site_name', SITE_NAME) : 'Checkout — ' . setting('site_name', SITE_NAME);
require_once __DIR__ . '/includes/header.php';

// ── Success Page ──
if ($showSuccess):
    // Fetch order details for Pixel Purchase event
    $stmt = db()->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$successId]);
    $order = $stmt->fetch();
    
    $orderTotal = 0;
    $numItems = 0;
    $productIds = [];
    
    if ($order) {
        $orderTotal = (float)$order['total'];
        $itemStmt = db()->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ?');
        $itemStmt->execute([$successId]);
        $orderItems = $itemStmt->fetchAll();
        $numItems = array_sum(array_column($orderItems, 'quantity'));
        $productIds = array_values(array_unique(array_column($orderItems, 'product_id')));

        // ── Meta Conversions API: Purchase ──
        // Guarded by meta_pixel_event_sent so refreshing this page can never
        // send (or count) the same sale twice. event_id is derived from the
        // order itself — not random — so it also matches the browser
        // fbq('track','Purchase', ..., {eventID}) call below across reloads,
        // letting Meta's own dedup collapse repeats into one event.
        if (empty($order['meta_pixel_event_sent']) && function_exists('meta_capi_send')) {
            $buyerEmail = $order['guest_email'] ?: null;
            if (!$buyerEmail && $order['user_id']) {
                $buyerEmail = db()->prepare('SELECT email FROM users WHERE id = ?');
                $buyerEmail->execute([$order['user_id']]);
                $buyerEmail = $buyerEmail->fetchColumn() ?: null;
            }
            $nameParts = explode(' ', trim((string)$order['shipping_name']), 2);

            meta_capi_send('Purchase', 'purchase_' . $successId, [
                'currency'     => CURRENCY_CODE,
                'value'        => $orderTotal,
                'num_items'    => $numItems,
                'content_ids'  => array_map('strval', $productIds),
                'content_type' => 'product',
                'order_id'     => $order['order_number'] ?? (string)$successId,
            ], [
                'email'      => $buyerEmail,
                'phone'      => $order['phone'] ?? null,
                'first_name' => $nameParts[0] ?? null,
                'last_name'  => $nameParts[1] ?? null,
                'city'       => $order['shipping_city'] ?? null,
                'country'    => $order['shipping_country'] ?? null,
            ]);

            db()->prepare('UPDATE orders SET meta_pixel_event_sent = 1 WHERE id = ?')->execute([$successId]);
        }
    }
?>
<section class="lume-section container" style="text-align:center;padding:200px 0">
    <div style="max-width:520px;margin:0 auto">
        <div style="width:72px;height:72px;border-radius:50%;border:2px solid var(--gold);display:flex;align-items:center;justify-content:center;margin:0 auto 24px;animation:heroFadeUp .8s .2s cubic-bezier(.16,1,.3,1) forwards;opacity:0">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <p class="lume-section__eyebrow">Thank You</p>
        <h1 class="lume-section__title">Order Confirmed</h1>
        <p style="color:var(--muted);margin:16px auto;max-width:400px;line-height:1.7">Your order <strong style="color:var(--gold)">#<?= h($order['order_number'] ?? $successId) ?></strong> has been received. We'll send you a confirmation email shortly.</p>
        
        <?php if ($order && $order['payment_method'] === 'instapay'):
            /* InstaPay details come from Settings → Payment via
               instapay_details(), shared with instapay-upload.php and the
               confirmation email so all three always agree. */
            $instapay = instapay_details();
            $ipa     = $instapay['ipa'];
            $payLink = $instapay['link'];
            $waLink  = instapay_whatsapp_link("Hello, here is my payment receipt for order #" . $order['order_number']);
        ?>
            <div style="background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:24px;margin-top:32px;text-align:left;">
                <h3 style="margin-bottom:16px;color:var(--gold)">Complete Your Payment</h3>
                <p style="margin-bottom:12px;color:var(--muted)">Your order has been placed, but we need you to complete the payment via InstaPay to process it.</p>
                <ol style="color:var(--muted);margin-left:20px;line-height:1.6;margin-bottom:24px;">
                    <li style="margin-bottom:8px">Send exactly <strong style="color:var(--gold)"><?= money((float)$order['total']) ?></strong> to the following InstaPay Address:<br><strong style="color:var(--text);display:inline-block;margin-top:4px;padding:4px 8px;background:var(--bg-body);border:1px solid var(--border);border-radius:4px;"><?= h($ipa) ?></strong><br><a href="<?= h($payLink) ?>" target="_blank" rel="noopener" style="color:var(--gold);font-size:.9rem;text-decoration:underline;display:inline-block;margin-top:8px">Or tap here to pay via InstaPay</a></li>
                    <li style="margin-bottom:8px">Take a screenshot of the confirmation screen.</li>
                    <li>Click the button below to open WhatsApp and send us the screenshot.</li>
                </ol>
                <a href="<?= h($waLink) ?>" target="_blank" rel="noopener" class="lume-btn lume-btn--full lume-btn--solid" style="background:#25D366;color:#fff;border-color:#25D366;text-align:center;">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" style="vertical-align:middle;margin-right:8px;margin-top:-2px"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                    Send Receipt via WhatsApp
                </a>
            </div>
        <?php else: ?>
            <a href="<?= SITE_URL ?>/shop.php" class="lume-btn lume-btn--solid" style="margin-top:32px">Continue Shopping</a>
        <?php endif; ?>
    </div>
</section>

<?php if ($orderTotal > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof fbq === 'function') {
        // eventID matches the server-side Conversions API call this page just
        // made (see meta_capi_send('Purchase', 'purchase_<id>', ...) above),
        // so Meta dedupes the two into one event instead of double-counting.
        fbq('track', 'Purchase', {
            value: <?= json_encode($orderTotal) ?>,
            currency: <?= json_encode(CURRENCY_CODE) ?>, // ISO code — Meta rejects a symbol like "EGP "
            num_items: <?= json_encode($numItems) ?>,
            content_ids: <?= json_encode(array_map('strval', $productIds)) ?>,
            content_type: 'product'
        }, {eventID: <?= json_encode('purchase_' . $successId) ?>});
    }
});
</script>
<?php endif; ?>

<?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
endif;
?>

<!-- ── Checkout Form ── -->
<section class="lume-page-header">
    <div class="container">
        <h1 class="lume-page-header__title">Checkout</h1>
        <p class="lume-page-header__breadcrumb"><a href="<?= SITE_URL ?>/">Home</a> / <a href="<?= SITE_URL ?>/cart.php">Cart</a> / Checkout</p>
    </div>
</section>

<section class="container">
    <div class="lume-checkout">
        <div>
            <?php if ($error): ?>
            <div class="lume-alert lume-alert--error"><?= h($error) ?></div>
            <?php endif; ?>
            <form method="post" class="lume-form" id="checkout-form">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                
                <h2 style="font-family:var(--font-serif);font-size:1.4rem;text-transform:uppercase;margin-bottom:4px">Contact & Shipping</h2>
                <div class="lume-form__group">
                    <label class="lume-form__label" for="shipping_name">Full Name *</label>
                    <input class="lume-form__input" type="text" id="shipping_name" name="shipping_name" value="<?= h($_POST['shipping_name'] ?? ($user ? $user['first_name'].' '.$user['last_name'] : '')) ?>" required>
                </div>
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <div class="lume-form__group">
                        <label class="lume-form__label" for="email">Email *</label>
                        <input class="lume-form__input" type="email" id="email" name="email" value="<?= h($_POST['email'] ?? ($user['email'] ?? '')) ?>" required>
                    </div>
                    <div class="lume-form__group">
                        <label class="lume-form__label" for="phone"><?= h($phoneLabel) ?> *</label>
                        <input class="lume-form__input" type="tel" id="phone" name="phone" value="<?= h($_POST['phone'] ?? '') ?>" required>
                    </div>
                </div>

                <div class="lume-form__group">
                    <label class="lume-form__label" for="address">Address *</label>
                    <input class="lume-form__input" type="text" id="address" name="address" value="<?= h($_POST['address'] ?? '') ?>" required>
                </div>
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <div class="lume-form__group">
                        <label class="lume-form__label" for="shipping_zone">City / Governorate *</label>
                        <select class="lume-form__input" id="shipping_zone" name="shipping_zone" required>
                            <option value="">Select your city...</option>
                            <?php foreach ($zones as $z): ?>
                                <option value="<?= $z['id'] ?>" data-cost="<?= $z['cost'] ?>" <?= (($_POST['shipping_zone'] ?? '') == $z['id']) ? 'selected' : '' ?>>
                                    <?= h($z['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lume-form__group">
                        <label class="lume-form__label" for="country">Country</label>
                        <input class="lume-form__input" type="text" id="country" name="country" value="Egypt" readonly>
                    </div>
                </div>
                <div class="lume-form__group">
                    <label class="lume-form__label" for="notes">Notes (optional)</label>
                    <textarea class="lume-form__textarea" id="notes" name="notes" rows="3"><?= h($_POST['notes'] ?? '') ?></textarea>
                </div>

                <p style="font-size:.75rem;color:var(--muted);margin:-4px 0 0;line-height:1.6">We save these details as you type, so we can reach you if anything goes wrong with your order.</p>

                <!-- Payment Method -->
                <h2 style="font-family:var(--font-serif);font-size:1.4rem;text-transform:uppercase;margin:32px 0 16px">Payment Method</h2>
                <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:24px">
                    <?php if ($codAvailable): ?>
                    <label style="display:flex;align-items:center;gap:10px;padding:16px;border:1px solid var(--border);border-radius:4px;cursor:pointer" id="label-cod">
                        <input type="radio" name="payment_method" value="cod" checked style="accent-color:var(--terracotta);width:18px;height:18px">
                        <span><?= h($codLabel) ?> <?= $codFee > 0 ? '(+ '.money($codFee).')' : '' ?></span>
                    </label>
                    <?php elseif ($hasPreorder && $codEnabled): ?>
                    <p style="padding:12px 16px;border:1px dashed var(--border);border-radius:4px;color:var(--muted);font-size:.85rem;margin:0">
                        <?= h($codLabel) ?> isn&rsquo;t available for pre-orders &mdash; your bag contains an item that ships once it&rsquo;s back in stock.
                    </p>
                    <?php endif; ?>
                    <?php if ($onlineAvailable): ?>
                    <label style="display:flex;align-items:center;gap:10px;padding:16px;border:1px solid var(--border);border-radius:4px;cursor:pointer" id="label-online">
                        <input type="radio" name="payment_method" value="online" <?= ($_POST['payment_method'] ?? '') === 'online' ? 'checked' : '' ?> style="accent-color:var(--terracotta);width:18px;height:18px">
                        <span>Credit / Debit Card</span>
                    </label>
                    <?php endif; ?>
                    <label style="display:flex;align-items:center;gap:10px;padding:16px;border:1px solid var(--border);border-radius:4px;cursor:pointer" id="label-instapay">
                        <input type="radio" name="payment_method" value="instapay" <?= !$codAvailable ? 'checked' : '' ?> style="accent-color:var(--terracotta);width:18px;height:18px">
                        <span>InstaPay (Manual Transfer)</span>
                    </label>
                </div>

                <button type="submit" class="lume-btn lume-btn--full lume-btn--solid" id="checkout-btn">Place Order — Calculating...</button>
            </form>
        </div>
        <div class="lume-checkout__summary">
            <h3 style="font-family:var(--font-serif);font-size:1.1rem;text-transform:uppercase;margin-bottom:20px">Order Summary</h3>
            <?php foreach ($items as $i):
                $p = item_effective_price($i);
                $vLabel = '';
                if (!empty($i['variant_size'])) $vLabel .= $i['variant_size'];
                if (!empty($i['variant_color'])) $vLabel .= ($vLabel ? ' / ' : '') . $i['variant_color'];
            ?>
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:.85rem">
                <span>
                    <?= h($i['name']) ?> × <?= (int)$i['quantity'] ?>
                    <?php if ($vLabel): ?><br><span style="font-size:.72rem;color:var(--muted)"><?= h($vLabel) ?></span><?php endif; ?>
                </span>
                <span><?= money($p * (int)$i['quantity']) ?></span>
            </div>
            <?php endforeach; ?>
            <div class="lume-cart-summary__row" style="margin-top:16px"><span>Subtotal</span><span><?= money($subtotal) ?></span></div>
            <?php if ($cartDiscount): ?>
            <div class="lume-cart-summary__row lume-cart-discount"><span><?= h($cartDiscount['label']) ?></span><span>-<?= money($cartDiscount['amount']) ?></span></div>
            <?php elseif (!$user && welcome_offer_enabled()): ?>
            <p class="lume-welcome-nudge"><a href="<?= SITE_URL ?>/account.php?action=register&amp;redirect=<?= urlencode('/checkout.php') ?>">Create an account</a> and get <?= h(rtrim(rtrim(number_format(welcome_offer_pct(), 2), '0'), '.')) ?>% off this order. Your bag is kept.</p>
            <?php endif; ?>
            <div class="lume-cart-summary__row" id="row-shipping"><span>Shipping</span><span id="txt-shipping">—</span></div>
            <?php if ($codAvailable): ?>
            <div class="lume-cart-summary__row" id="row-cod" style="display:none;color:var(--muted)"><span>COD Fee</span><span><?= money($codFee) ?></span></div>
            <?php endif; ?>
            <div class="lume-cart-summary__row total"><span>Total</span><span id="txt-total">—</span></div>
        </div>
    </div>
</section>

<?php
// ── Meta Conversions API: InitiateCheckout ──
// Deliberately OUTSIDE the <script type="application/json"> block below:
// anything this emits (a warning, a notice) would land inside that tag and
// corrupt the JSON, which stops checkout.js from ever computing shipping and
// leaves the button stuck on "Calculating...". Keep it out here.
$capiEventId = '';
if (function_exists('meta_capi_send') && function_exists('meta_capi_event_id')) {
    $capiEventId = meta_capi_event_id('ic');
    meta_capi_send('InitiateCheckout', $capiEventId, [
        'currency'     => defined('CURRENCY_CODE') ? CURRENCY_CODE : 'EGP',
        'value'        => $subtotal,
        'num_items'    => (int)array_sum(array_column($items, 'quantity')),
        'content_ids'  => array_values(array_unique(array_map('strval', array_column($items, 'product_id')))),
        'content_type' => 'product',
    ], $user ? ['email' => $user['email'], 'first_name' => $user['first_name'], 'last_name' => $user['last_name']] : []);
}
?>
<script id="checkout-config" type="application/json">
<?php
$configData = [
    'subtotal'        => $subtotal,
    'discount'        => $cartDiscount['amount'] ?? 0,
    'freeShippingOver'=> $freeShippingOver,
    'codFee'          => $codFee,
    'currency'        => currency_symbol(),
    'num_items'       => (int)array_sum(array_column($items, 'quantity')),
    'product_ids'     => array_values(array_unique(array_column($items, 'product_id'))),
    'event_id'        => $capiEventId,
];
$jsonStr = json_encode($configData, JSON_INVALID_UTF8_SUBSTITUTE);
if ($jsonStr === false) {
    $jsonStr = '{"error": "'.json_last_error_msg().'"}';
}
echo $jsonStr;
?>
</script>
<script src="<?= SITE_URL ?>/assets/js/checkout.js?v=<?= time() ?>"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
