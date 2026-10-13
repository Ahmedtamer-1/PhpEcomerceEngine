<?php
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Privacy Policy — ' . setting('site_name', SITE_NAME);
$pageDescription = 'Read our privacy policy to understand how we collect, use, and protect your personal information.';
require_once __DIR__ . '/includes/header.php';
$brandName = setting('site_name', SITE_NAME);
$contactEmail = setting('contact_email', 'hello@lumeegy.com');
?>
<?php
/* Section-built content.
   If sections exist for this page in Admin -> Builder, they render here and
   replace the static layout below. Until then the original page is shown
   unchanged, so adding the builder cannot break a live page. */
$sections = get_homepage_sections(true, 'privacy');
if ($sections):
    require __DIR__ . '/includes/render-sections.php';
else:
?>


<section class="lume-section container" style="padding-top:60px;padding-bottom:80px">
    <div class="lume-legal">
        <p class="lume-section__eyebrow lume-reveal">Legal</p>
        <h1 class="lume-section__title lume-reveal" style="font-size:clamp(1.8rem,4vw,2.8rem)">Privacy Policy</h1>
        <div class="lume-divider lume-reveal" style="margin-bottom:12px"></div>
        <p class="lume-legal__updated lume-reveal">Last updated: <?= date('F j, Y') ?></p>

        <div class="lume-legal__body lume-reveal">
            <?php $content = setting('page_privacy_content'); if ($content): echo $content; else: ?>

            <h2>1. Introduction</h2>
            <p><?= h($brandName) ?> ("we", "us", or "our") operates the <?= h($brandName) ?> website. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you visit our website or make a purchase.</p>

            <h2>2. Information We Collect</h2>
            <h3>Personal Information</h3>
            <p>When you create an account, place an order, or contact us, we may collect:</p>
            <ul>
                <li>Name (first and last)</li>
                <li>Email address</li>
                <li>Phone number</li>
                <li>Shipping and billing address</li>
                <li>Payment information (processed securely through third-party providers)</li>
            </ul>

            <h3>Automatically Collected Information</h3>
            <p>When you browse our website, we may automatically collect:</p>
            <ul>
                <li>IP address and browser type</li>
                <li>Pages visited and time spent on our site</li>
                <li>Referring website information</li>
                <li>Device information (type, operating system)</li>
                <li>Cookies and similar tracking technologies</li>
            </ul>

            <h2>3. How We Use Your Information</h2>
            <p>We use the information we collect to:</p>
            <ul>
                <li>Process and fulfill your orders</li>
                <li>Create and manage your account</li>
                <li>Send order confirmations and shipping updates</li>
                <li>Respond to your inquiries and provide customer support</li>
                <li>Send promotional emails and newsletters (with your consent)</li>
                <li>Improve our website, products, and services</li>
                <li>Detect and prevent fraud</li>
                <li>Comply with legal obligations</li>
            </ul>

            <h2>4. Cookies</h2>
            <p>We use cookies and similar technologies to enhance your browsing experience, remember your preferences, and analyze site traffic. You can control cookie settings through your browser preferences. Disabling cookies may affect some website functionality.</p>

            <h2>5. Sharing Your Information</h2>
            <p>We do not sell your personal information. We may share your data with:</p>
            <ul>
                <li><strong>Service providers:</strong> Shipping carriers, payment processors, and analytics tools that assist us in operating our business</li>
                <li><strong>Legal requirements:</strong> When required by law, regulation, or legal process</li>
                <li><strong>Business transfers:</strong> In connection with a merger, acquisition, or sale of assets</li>
            </ul>

            <h2>6. Data Security</h2>
            <p>We implement appropriate technical and organizational measures to protect your personal information, including encrypted data transmission (SSL/TLS), secure password hashing, and access controls. However, no method of electronic transmission or storage is 100% secure.</p>

            <h2>7. Your Rights</h2>
            <p>Depending on your jurisdiction, you may have the right to:</p>
            <ul>
                <li>Access the personal data we hold about you</li>
                <li>Request correction of inaccurate data</li>
                <li>Request deletion of your personal data</li>
                <li>Withdraw consent for marketing communications</li>
                <li>Object to data processing</li>
            </ul>
            <p>To exercise any of these rights, please contact us at <a href="mailto:<?= h($contactEmail) ?>" style="color:var(--gold)"><?= h($contactEmail) ?></a>.</p>

            <h2>8. Data Retention</h2>
            <p>We retain your personal information for as long as necessary to fulfill the purposes outlined in this policy, unless a longer retention period is required by law. Order records are retained for accounting and legal compliance purposes.</p>

            <h2>9. Third-Party Links</h2>
            <p>Our website may contain links to third-party websites. We are not responsible for the privacy practices of these external sites. We encourage you to review their privacy policies before providing any personal information.</p>

            <h2>10. Children's Privacy</h2>
            <p>Our services are not directed to individuals under the age of 16. We do not knowingly collect personal information from children. If we discover that we have collected information from a child, we will take steps to delete it promptly.</p>

            <h2>11. Changes to This Policy</h2>
            <p>We may update this Privacy Policy from time to time. Changes will be posted on this page with an updated revision date. Continued use of our website after changes constitutes acceptance of the revised policy.</p>

            <h2>12. Contact Us</h2>
            <p>If you have questions or concerns about this Privacy Policy, please contact us:</p>
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
