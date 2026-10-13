<?php
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Exchange & Return Policy — ' . setting('site_name', SITE_NAME);
$pageDescription = 'Find information about our exchange and return policies.';
require_once __DIR__ . '/includes/header.php';
$brandName = setting('site_name', SITE_NAME);
$contactEmail = setting('contact_email', 'hello@lumeegy.com');
?>
<?php
/* Section-built content.
   If sections exist for this page in Admin -> Builder, they render here and
   replace the static layout below. Until then the original page is shown
   unchanged, so adding the builder cannot break a live page. */
$sections = get_homepage_sections(true, 'returns');
if ($sections):
    require __DIR__ . '/includes/render-sections.php';
else:
?>

<section class="lume-section container" style="padding-top:60px;padding-bottom:80px">
    <div class="lume-legal">
        <p class="lume-section__eyebrow lume-reveal">Support</p>
        <h1 class="lume-section__title lume-reveal" style="font-size:clamp(1.8rem,4vw,2.8rem)">Exchange & Return Policy</h1>
        <div class="lume-divider lume-reveal" style="margin-bottom:12px"></div>
        <div class="lume-legal__body lume-reveal">
            <?php $content = setting('page_returns_content'); if ($content): echo $content; else: ?>
            <h2>Exchange Policy</h2>
            <p>We want you to love your new accessories! If you need to make a swap, we are happy to help.</p>
            <ul>
                <li><strong>Exchange Window:</strong> You can request an exchange within 7 days of receiving your product.</li>
                <li><strong>How to Request:</strong> Simply send us a message on our Instagram page to initiate the process.</li>
                <li><strong>The Process:</strong> Once your request is confirmed, the product will be shipped back to us. We will review the item to ensure it is in its original, pre-received condition. Upon approval, your new item will be shipped to you.</li>
                <li><strong>Timeline:</strong> The entire exchange process takes between 4 to 10 business days.</li>
                <li><strong>Costs & Limitations:</strong> Customers are responsible for the return shipping costs as well as any price difference between the original and exchanged items. Please note that there is a maximum limit of 2 exchanges per original purchase.</li>
            </ul>
            <hr style="border:none;border-top:1px solid var(--border);margin:40px 0">
            <h2>Return Policy</h2>
            <p>If your purchase isn't quite right, we offer a straightforward return process.</p>
            <h3>Standard Returns</h3>
            <ul>
                <li><strong>Cash Refunds (Days 1–7):</strong> Return requests made via our Instagram page within the first 7 days of receival are eligible for a monetary refund (issued via Instapay).</li>
                <li><strong>Store Credit (Days 8–14):</strong> If you miss the first week, you have an additional 7 days to request a return. Returns requested during this window will be refunded in the form of store credit.</li>
                <li><strong>The Process:</strong> The item must be shipped back to us for review. Once we confirm it is in its original condition, the refund will be issued.</li>
                <li><strong>Timeline & Costs:</strong> This process takes 3 to 7 business days. Customers are responsible for covering the return shipping costs.</li>
            </ul>
            <h3>Defective or Damaged Products</h3>
            <ul>
                <li>If you experience any issues or receive a defective product upon delivery, please reach out via our Instagram page within 7 days.</li>
                <li>Once we confirm the product issue, we will issue a full refund via online transfer (Instapay, Telda, Vodafone Cash, etc.).</li>
                <li>The return shipping for defective items is completely free of charge. This process takes 3 to 7 business days.</li>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
