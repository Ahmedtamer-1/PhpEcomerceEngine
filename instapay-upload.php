<?php
require_once __DIR__ . '/includes/functions.php';
lume_session_start();
$orderId = (int)($_GET['order_id'] ?? 0);
if (!$orderId) {
    redirect('/');
}
$stmt = db()->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch();
// Order ids are sequential, so without the ownership check anyone could walk
// through them and read other customers' order numbers and totals.
if (!$order || $order['status'] !== 'pending_payment' || !can_view_order($order)) {
    redirect('/track-order.php');
}
$instapay = instapay_details();
$waLink   = instapay_whatsapp_link('Hello, I\'d like to submit the payment receipt for my order #' . $order['order_number']);
$pageTitle = 'Upload InstaPay Receipt — ' . setting('site_name', SITE_NAME);
require_once __DIR__ . '/includes/header.php';
?>
<section class="lume-page-header">
    <div class="container">
        <h1 class="lume-page-header__title">Complete Your Payment</h1>
    </div>
</section>
<section class="container" style="max-width: 600px; padding: 40px 0; min-height: 50vh;">

        <div style="background:var(--bg-card); padding:32px; border-radius:8px; border:1px solid var(--border);">
            <p style="font-size:1.1rem; margin-bottom: 24px;">Your order <strong style="color:var(--gold);">#<?= h($order['order_number']) ?></strong> has been created.</p>
            
            <div style="background:rgba(200,184,154,0.1); padding:20px; border-radius:4px; margin-bottom:24px;">
                <h3 style="font-size:1rem; margin-bottom:12px; color:var(--gold);">Instructions:</h3>
                <p style="margin-bottom:8px;">1. Open the InstaPay app or click the link below.</p>
                <p style="margin-bottom:8px;">2. Send exactly <strong><?= money((float)$order['total']) ?></strong> to the following InstaPay address:</p>
                <div style="font-size:1.2rem; font-weight:bold; letter-spacing:1px; background:var(--bg); padding:16px; text-align:center; border:1px dashed var(--border); margin: 12px 0;">
                    <?php /* From Settings → Payment (instapay_details()), shared
                             with checkout.php and the confirmation email. */ ?>
                    <?= h($instapay['ipa']) ?>
                    <?php if ($instapay['link']): ?><br>
                    <a href="<?= h($instapay['link']) ?>" target="_blank" rel="noopener" style="color:var(--gold); font-size:1rem; text-decoration:underline; margin-top:8px; display:inline-block;">Click here to pay via InstaPay</a>
                    <?php endif; ?>
                </div>
                <p style="margin-bottom:0;">3. Take a screenshot of the successful transfer and <?= $waLink ? 'send it to us on WhatsApp.' : 'reply to your confirmation email with it.' ?></p>
            </div>

            <?php if ($waLink): ?>
            <a href="<?= h($waLink) ?>" target="_blank" rel="noopener" class="lume-btn lume-btn--full lume-btn--solid" style="margin-top:16px; display:inline-block; text-align:center; box-sizing:border-box;">
                Send Payment Proof on WhatsApp
            </a>
            <?php endif; ?>
        </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
