<?php
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Shipping Policy — ' . setting('site_name', SITE_NAME);
$pageDescription = 'Find information about our shipping methods and delivery times.';
require_once __DIR__ . '/includes/header.php';
$brandName = setting('site_name', SITE_NAME);
$contactEmail = setting('contact_email', 'hello@lumeegy.com');
?>
<?php
/* Section-built content.
   If sections exist for this page in Admin -> Builder, they render here and
   replace the static layout below. Until then the original page is shown
   unchanged, so adding the builder cannot break a live page. */
$sections = get_homepage_sections(true, 'shipping');
if ($sections):
    require __DIR__ . '/includes/render-sections.php';
else:
?>

<section class="lume-section container" style="padding-top:60px;padding-bottom:80px">
    <div class="lume-legal">
        <p class="lume-section__eyebrow lume-reveal">Support</p>
        <h1 class="lume-section__title lume-reveal" style="font-size:clamp(1.8rem,4vw,2.8rem)">Shipping Policy</h1>
        <div class="lume-divider lume-reveal" style="margin-bottom:12px"></div>
        <div class="lume-legal__body lume-reveal">
            <?php $content = setting('page_shipping_content'); if ($content): echo $content; else: ?>
            <h2>Shipping Policy</h2>
            <p>Our pieces are thoughtfully sourced—featuring custom-made copper accessories from local Egyptian workshops, alongside stainless steel, silver, and gold pieces carefully selected from trusted workshops and wholesalers. Every item is packed with care in our signature packaging before making its way to you via our shipping partner, Ship Blu.</p>
            <ul>
                <li><strong>Standard Delivery:</strong> Orders typically arrive within 3 to 7 business days.</li>
                <li><strong>Next-Day Delivery:</strong> We offer expedited next-day delivery exclusively for the Sheikh Zayed and 6th of October regions.</li>
                <li><strong>Receiving Your Order:</strong> We want you to feel completely confident in your purchase! For your peace of mind, please feel free to open and inspect your new accessories to your heart's content before paying the courier.</li>
            </ul>
            <hr style="border:none;border-top:1px solid var(--border);margin:40px 0">
            <h2>Special Instructions for Instapay Payments</h2>
            <p>If you choose to pay using Instapay at checkout, please follow these quick steps to complete your order:</p>
            <ol>
                <li>Click the provided Instapay link (or scan the QR code if you are browsing on a desktop) to be directed to our Instapay account.</li>
                <li>Manually enter the exact final price shown on your checkout page.</li>
                <li>Once the transfer is complete, head back to our website checkout page.</li>
                <li>Click the WhatsApp link provided on the page.</li>
                <li>Send us a screenshot of your payment proof along with your Order Code so we can confirm your purchase and begin preparing your items!</li>
            </ol>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
