<?php
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Terms of Service — ' . setting('site_name', SITE_NAME);
$pageDescription = 'Please read our terms of service carefully before using our website or placing an order.';
require_once __DIR__ . '/includes/header.php';
$brandName = setting('site_name', SITE_NAME);
$contactEmail = setting('contact_email', 'hello@lumeegy.com');
?>
<?php
/* Section-built content.
   If sections exist for this page in Admin -> Builder, they render here and
   replace the static layout below. Until then the original page is shown
   unchanged, so adding the builder cannot break a live page. */
$sections = get_homepage_sections(true, 'terms');
if ($sections):
    require __DIR__ . '/includes/render-sections.php';
else:
?>


<section class="lume-section container" style="padding-top:60px;padding-bottom:80px">
    <div class="lume-legal">
        <p class="lume-section__eyebrow lume-reveal">Legal</p>
        <h1 class="lume-section__title lume-reveal" style="font-size:clamp(1.8rem,4vw,2.8rem)">Terms of Service</h1>
        <div class="lume-divider lume-reveal" style="margin-bottom:12px"></div>
        <p class="lume-legal__updated lume-reveal">Last updated: <?= date('F j, Y') ?></p>

        <div class="lume-legal__body lume-reveal">
            <?php $content = setting('page_terms_content'); if ($content): echo $content; else: ?>

            <h2>1. Acceptance of Terms</h2>
            <p>By accessing or using the <?= h($brandName) ?> website, you agree to be bound by these Terms of Service and all applicable laws and regulations. If you do not agree with any part of these terms, you may not use our website.</p>

            <h2>2. Use of the Website</h2>
            <p>You agree to use our website only for lawful purposes and in accordance with these terms. You agree not to:</p>
            <ul>
                <li>Use the site in any way that violates applicable laws or regulations</li>
                <li>Attempt to interfere with or disrupt the website's functionality</li>
                <li>Use automated tools to scrape, crawl, or extract data without permission</li>
                <li>Impersonate any person or entity</li>
                <li>Transmit malicious code or engage in any activity that harms our systems</li>
            </ul>

            <h2>3. Accounts</h2>
            <p>When you create an account, you are responsible for maintaining the confidentiality of your login credentials. You agree to provide accurate information and to notify us immediately of any unauthorized use of your account. We reserve the right to suspend or terminate accounts that violate these terms.</p>

            <h2>4. Products & Pricing</h2>
            <p>We make every effort to display accurate product information and pricing. However, we reserve the right to:</p>
            <ul>
                <li>Correct pricing errors at any time</li>
                <li>Modify or discontinue products without prior notice</li>
                <li>Limit order quantities</li>
                <li>Refuse or cancel orders that contain pricing errors</li>
            </ul>
            <p>All prices are displayed in the currency indicated on the website and are subject to applicable taxes and shipping fees.</p>

            <h2>5. Orders & Payment</h2>
            <p>Placing an order constitutes an offer to purchase. We reserve the right to accept or decline any order. Payment must be made at the time of purchase through our accepted payment methods. Orders are subject to product availability and payment verification.</p>

            <h2>6. Shipping & Delivery</h2>
            <p>Shipping times and costs vary depending on your location and the shipping method selected. Estimated delivery times are approximate and not guaranteed. We are not responsible for delays caused by carriers, customs, or events beyond our control. Risk of loss passes to you upon delivery to the carrier.</p>

            <h2>7. Returns & Exchanges</h2>
            <p>We accept returns within 14 days of delivery for unused items in their original packaging with tags attached. To initiate a return, please contact us. The following items are non-returnable:</p>
            <ul>
                <li>Items marked as final sale</li>
                <li>Items that have been worn, washed, or altered</li>
                <li>Intimate apparel and accessories for hygiene reasons</li>
            </ul>
            <p>Refunds will be processed to the original payment method within 7–14 business days of receiving the returned item. Shipping costs are non-refundable.</p>

            <h2>8. Intellectual Property</h2>
            <p>All content on this website — including text, graphics, logos, images, product designs, and software — is the property of <?= h($brandName) ?> or its licensors and is protected by copyright, trademark, and other intellectual property laws. You may not reproduce, distribute, modify, or create derivative works from our content without written permission.</p>

            <h2>9. User Content</h2>
            <p>If you submit reviews, comments, or other content, you grant us a non-exclusive, royalty-free license to use, display, and distribute that content. You are responsible for ensuring your content does not violate any third-party rights or applicable laws.</p>

            <h2>10. Limitation of Liability</h2>
            <p><?= h($brandName) ?> shall not be liable for any indirect, incidental, special, consequential, or punitive damages arising from your use of our website or products. Our total liability for any claim related to our products or services shall not exceed the amount you paid for the specific product or service giving rise to the claim.</p>

            <h2>11. Indemnification</h2>
            <p>You agree to indemnify and hold <?= h($brandName) ?>, its officers, employees, and affiliates harmless from any claims, losses, or damages (including legal fees) arising from your use of the website or violation of these terms.</p>

            <h2>12. Governing Law</h2>
            <p>These terms shall be governed by and construed in accordance with the laws of the Arab Republic of Egypt, without regard to conflict of law provisions. Any disputes shall be resolved in the competent courts of Egypt.</p>

            <h2>13. Changes to These Terms</h2>
            <p>We reserve the right to update these Terms of Service at any time. Changes take effect immediately upon posting. Your continued use of the website constitutes acceptance of the revised terms.</p>

            <h2>14. Contact</h2>
            <p>If you have questions about these terms, please contact us:</p>
            <ul>
                <li>Email: <a href="mailto:<?= h($contactEmail) ?>" style="color:var(--gold)"><?= h($contactEmail) ?></a></li>
                <li>Contact page: <a href="<?= SITE_URL ?>/contact.php" style="color:var(--gold)">Contact Us</a></li>
            </ul>

            <?php endif; ?>
        </div>
    </div>
</section>

<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
