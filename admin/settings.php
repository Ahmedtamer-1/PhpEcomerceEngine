<?php
/**
 * Admin Settings — Site Configuration
 */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Settings';
$adminPage = 'settings';

$success = '';
$error   = '';

// All editable settings
$settingKeys = [
    'site_name', 'site_tagline',
    'maintenance_mode', 'maintenance_message',
    'site_logo', 'site_favicon', 'og_image',
    'default_meta_title', 'default_meta_description', 'default_meta_keywords',
    'google_analytics_id', 'meta_pixel_id',
    'contact_email', 'instagram_url', 'tiktok_url', 'facebook_url', 'whatsapp_url',
    'cod_enabled', 'cod_label', 'cod_extra_fee',
    'currency', 'currency_symbol', 'shipping_flat_rate', 'free_shipping_over',
    'phone_display_name',
    'paymob_api_key', 'paymob_integration_id', 'paymob_iframe_id', 'paymob_hmac',
    // Theme colors
    'theme_color_bg', 'theme_color_bg_card', 'theme_color_cream',
    'theme_color_gold', 'theme_color_accent', 'theme_color_muted',
    // Typography
    'theme_font_heading', 'theme_font_body',
    'theme_font_heading_weight', 'theme_font_body_weight',
    // Stock
    'show_stock_indicator', 'stock_low_threshold',
    // Footer sections
    'show_marquee', 'marquee_text',
    'show_newsletter', 'newsletter_title', 'newsletter_eyebrow', 'newsletter_subtitle',
    'footer_brand_text',
    // Email / SMTP
    'email_enabled',
    'email_from_name', 'email_from_address',
    'email_confirmation_enabled', 'email_shipped_enabled', 'email_cancelled_enabled',
    'email_resend_api_key',
    'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_secure',
    // InstaPay — the form fields below existed but these keys were missing
    // from this whitelist, so the save loop skipped them and the values
    // silently never persisted.
    'instapay_username', 'instapay_whatsapp', 'instapay_link',
    // Welcome offer (signup discount + popup)
    'welcome_offer_enabled', 'welcome_offer_pct', 'welcome_popup_delay', 'welcome_popup_text',
];

// Handle form save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt = db()->prepare('INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        foreach ($settingKeys as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                if ($key === 'welcome_offer_pct')   $val = (string) max(0, min(50, round((float)$val, 2)));
                if ($key === 'welcome_popup_delay') $val = (string) max(2, min(120, (int)$val));
                $stmt->execute([$key, $val]);
            }
        }

        // Handle file uploads (logo, favicon, og_image)
        $uploadFields = ['site_logo', 'site_favicon', 'og_image'];
        foreach ($uploadFields as $field) {
            if (!empty($_FILES[$field . '_file']['tmp_name']) && $_FILES[$field . '_file']['error'] === UPLOAD_ERR_OK) {
                $file    = $_FILES[$field . '_file'];
                // Type is detected from the file contents and the extension is
                // chosen from it. The browser-sent type and the original
                // filename are both attacker-controlled, and keeping the
                // uploaded extension allowed e.g. a ".php" saved in the web root.
                $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp',
                            'image/svg+xml' => 'svg', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico'];
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                if (!isset($allowed[$mime])) {
                    $error = 'Invalid file type for ' . $field;
                    break;
                }
                $ext     = $allowed[$mime];
                $newName = $field . '_' . time() . '.' . $ext;
                $dest    = ROOT_PATH . '/assets/images/' . $newName;
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    optimize_image_in_place($dest, 1200, 85);
                    $url = 'assets/images/' . $newName;
                    db()->prepare('INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
                         ->execute([$field, $url]);
                }
            }
        }

        if (!$error) {
            $success = 'Settings saved successfully.';
            log_activity('update_settings', 'system');
        }
    } catch (Exception $e) {
        $error = 'Error saving settings: ' . $e->getMessage();
    }
}

// Load current values
$settings = [];
$rows = db()->query('SELECT key_name, value FROM settings')->fetchAll();
foreach ($rows as $r) {
    $settings[$r['key_name']] = $r['value'];
}
$s = function(string $key, string $default = '') use ($settings) {
    return $settings[$key] ?? $default;
};

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($success): ?>
<div class="admin-alert admin-alert--success"><?= h($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="admin-alert admin-alert--error"><?= h($error) ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="admin-form" style="max-width:900px">

    <div class="admin-settings-tabs">
        <button type="button" class="admin-settings-tab active" data-tab="general">General</button>
        <button type="button" class="admin-settings-tab" data-tab="colors">Colors</button>
        <button type="button" class="admin-settings-tab" data-tab="typography">Typography</button>
        <button type="button" class="admin-settings-tab" data-tab="product">Product</button>
        <button type="button" class="admin-settings-tab" data-tab="footer">Footer</button>
        <button type="button" class="admin-settings-tab" data-tab="welcome">Welcome offer</button>
        <button type="button" class="admin-settings-tab" data-tab="maintenance">Maintenance</button>
        <button type="button" class="admin-settings-tab" data-tab="branding">Branding</button>
        <button type="button" class="admin-settings-tab" data-tab="seo">SEO</button>
        <button type="button" class="admin-settings-tab" data-tab="social">Social</button>
        <button type="button" class="admin-settings-tab" data-tab="payment">Payment</button>
        <button type="button" class="admin-settings-tab" data-tab="paymob">Paymob</button>
        <button type="button" class="admin-settings-tab" data-tab="email">📧 Email</button>
    </div>

    <!-- ── General ── -->
    <div class="admin-settings-section" data-section="general">
        <h2 class="admin-settings-section__title">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            General
        </h2>
        <div class="admin-form__row">
            <div class="admin-form__group">
                <label>Site Name</label>
                <input type="text" name="site_name" value="<?= h($s('site_name', SITE_NAME)) ?>">
            </div>
            <div class="admin-form__group">
                <label>Tagline</label>
                <input type="text" name="site_tagline" value="<?= h($s('site_tagline', SITE_TAGLINE)) ?>">
            </div>
        </div>
        <div class="admin-form__row">
            <div class="admin-form__group">
                <label>Contact Email</label>
                <input type="email" name="contact_email" value="<?= h($s('contact_email')) ?>">
            </div>
            <div class="admin-form__group">
                <label>Phone Display Name</label>
                <input type="text" name="phone_display_name" value="<?= h($s('phone_display_name', 'Phone Number')) ?>">
                <span class="admin-form__hint">Label shown on checkout phone field</span>
            </div>
        </div>
    </div>

    <!-- ── Theme Colors ── -->
    <div class="admin-settings-section" data-section="colors" hidden>
        <h2 class="admin-settings-section__title">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="10.5" r="2.5"/><circle cx="8.5" cy="7.5" r="2.5"/><circle cx="6.5" cy="12.5" r="2.5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>
            Color Scheme
        </h2>
        <p style="font-size:.75rem;color:var(--a-muted);margin-bottom:16px">Customize the color palette for your site. Changes apply globally after saving.</p>

        <!-- Live preview bar -->
        <div id="color-preview-bar" style="display:flex;gap:2px;height:32px;border-radius:var(--a-radius);overflow:hidden;margin-bottom:20px;border:1px solid var(--a-border)">
            <div style="flex:2;background:<?= h($s('theme_color_bg', '#0A0A0A')) ?>" data-preview="theme_color_bg"></div>
            <div style="flex:1;background:<?= h($s('theme_color_cream', '#F5F5F0')) ?>" data-preview="theme_color_cream"></div>
            <div style="flex:1;background:<?= h($s('theme_color_gold', '#C8B89A')) ?>" data-preview="theme_color_gold"></div>
            <div style="flex:1;background:<?= h($s('theme_color_accent', '#C4714A')) ?>" data-preview="theme_color_accent"></div>
            <div style="flex:1;background:<?= h($s('theme_color_muted', '#888880')) ?>" data-preview="theme_color_muted"></div>
        </div>

        <?php
        $colorFields = [
            ['theme_color_bg',     'Background',       '#0A0A0A'],
            ['theme_color_cream',  'Text / Light',     '#F5F5F0'],
            ['theme_color_gold',   'Gold / Secondary', '#C8B89A'],
            ['theme_color_accent', 'Accent / Primary', '#C4714A'],
            ['theme_color_muted',  'Muted Text',       '#888880'],
        ];
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px">
            <?php foreach ($colorFields as [$key, $label, $default]): ?>
            <div class="color-field" style="text-align:center">
                <label style="display:block;font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:var(--a-muted);margin-bottom:8px"><?= $label ?></label>
                <label style="display:block;cursor:pointer;margin:0 auto;width:48px;height:48px;border-radius:50%;border:2px solid var(--a-border);overflow:hidden;position:relative">
                    <input type="color" name="<?= $key ?>" value="<?= h($s($key, $default)) ?>"
                           style="position:absolute;inset:-8px;width:calc(100% + 16px);height:calc(100% + 16px);border:none;cursor:pointer"
                           data-color-key="<?= $key ?>">
                </label>
                <input type="text" value="<?= h($s($key, $default)) ?>"
                       data-text-for="<?= $key ?>"
                       style="width:100%;max-width:100px;margin:8px auto 0;text-align:center;font-size:.7rem;padding:4px 6px;background:var(--a-bg);border:1px solid var(--a-border);border-radius:4px;color:var(--a-text)">
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Typography ── -->
    <div class="admin-settings-section" data-section="typography" hidden>
        <h2 class="admin-settings-section__title">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>
            Typography
        </h2>
        <p style="font-size:.75rem;color:var(--a-muted);margin-bottom:16px">Choose Google Fonts for headings and body text. This lets you completely change the look for different brands.</p>
        <div class="admin-form__row">
            <div class="admin-form__group">
                <label>Heading Font (Google Fonts name)</label>
                <input type="text" name="theme_font_heading" value="<?= h($s('theme_font_heading', 'Bodoni Moda')) ?>" placeholder="Bodoni Moda">
                <span class="admin-form__hint">Exactly as it appears on fonts.google.com</span>
            </div>
            <div class="admin-form__group">
                <label>Body Font (Google Fonts name)</label>
                <input type="text" name="theme_font_body" value="<?= h($s('theme_font_body', 'Red Hat Display')) ?>" placeholder="Red Hat Display">
            </div>
        </div>
        <div class="admin-form__row">
            <div class="admin-form__group">
                <label>Heading Font Weights</label>
                <input type="text" name="theme_font_heading_weight" value="<?= h($s('theme_font_heading_weight', '400;700;900')) ?>" placeholder="400;700;900">
                <span class="admin-form__hint">Semicolon-separated, e.g. 400;700;900</span>
            </div>
            <div class="admin-form__group">
                <label>Body Font Weights</label>
                <input type="text" name="theme_font_body_weight" value="<?= h($s('theme_font_body_weight', '300;400;500;600')) ?>" placeholder="300;400;500;600">
            </div>
        </div>
    </div>

    <!-- ── Product Display ── -->
    <div class="admin-settings-section" data-section="product" hidden>
        <h2 class="admin-settings-section__title">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
            Product Display
        </h2>
        <div class="admin-form__check">
            <input type="hidden" name="show_stock_indicator" value="0">
            <input type="checkbox" name="show_stock_indicator" value="1" id="show_stock" <?= $s('show_stock_indicator', '1') === '1' ? 'checked' : '' ?>>
            <label for="show_stock">Show stock indicator on product pages (e.g. "In stock (12 available)")</label>
        </div>
        <div class="admin-form__group" style="margin-top:12px;max-width:200px">
            <label>Low Stock Threshold</label>
            <input type="number" name="stock_low_threshold" value="<?= h($s('stock_low_threshold', '5')) ?>" min="0">
            <span class="admin-form__hint">Show "Low stock" warning when stock is at or below this number</span>
        </div>
    </div>

    <!-- ── Footer Sections ── -->
    <div class="admin-settings-section" data-section="footer" hidden>
        <h2 class="admin-settings-section__title">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/></svg>
            Footer Sections
        </h2>
        <p style="font-size:.75rem;color:var(--a-muted);margin-bottom:16px">Control the scrolling marquee banner and newsletter signup section that appear above the footer.</p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
            <div style="padding:16px;border:1px solid var(--a-border);border-radius:var(--a-radius)">
                <div class="admin-form__check" style="margin-bottom:12px">
                    <input type="hidden" name="show_marquee" value="0">
                    <input type="checkbox" name="show_marquee" value="1" id="show_marquee" <?= $s('show_marquee', '1') === '1' ? 'checked' : '' ?>>
                    <label for="show_marquee"><strong>Show Scrolling Marquee</strong></label>
                </div>
                <div class="admin-form__group">
                    <label>Custom Marquee Text</label>
                    <input type="text" name="marquee_text" value="<?= h($s('marquee_text')) ?>" placeholder="Leave empty for defaults">
                    <span class="admin-form__hint">Pipe-separated items: Luxury Fashion | ✦ | Free Shipping</span>
                </div>
            </div>
            <div style="padding:16px;border:1px solid var(--a-border);border-radius:var(--a-radius)">
                <div class="admin-form__check" style="margin-bottom:12px">
                    <input type="hidden" name="show_newsletter" value="0">
                    <input type="checkbox" name="show_newsletter" value="1" id="show_newsletter" <?= $s('show_newsletter', '1') === '1' ? 'checked' : '' ?>>
                    <label for="show_newsletter"><strong>Show Newsletter Section</strong></label>
                </div>
                <div class="admin-form__group">
                    <label>Title</label>
                    <input type="text" name="newsletter_title" value="<?= h($s('newsletter_title', 'The Inner Circle')) ?>">
                </div>
                <div class="admin-form__group">
                    <label>Eyebrow Text</label>
                    <input type="text" name="newsletter_eyebrow" value="<?= h($s('newsletter_eyebrow', 'Join the Ritual')) ?>">
                </div>
                <div class="admin-form__group">
                    <label>Subtitle</label>
                    <input type="text" name="newsletter_subtitle" value="<?= h($s('newsletter_subtitle', 'Early access. Exclusive drops. Members-only offers.')) ?>">
                </div>
            </div>
        </div>

        <div style="margin-top:24px;padding:16px;border:1px solid var(--a-border);border-radius:var(--a-radius)">
            <h3 style="font-size:0.85rem;margin-bottom:12px;font-weight:600">Brand Info</h3>
            <div class="admin-form__group">
                <label>Footer Brand Text</label>
                <textarea name="footer_brand_text" rows="3"><?= h($s('footer_brand_text', 'A luxury fashion brand born in Egypt. Each piece is a ritual — a moment of light in your everyday.')) ?></textarea>
                <span class="admin-form__hint">Short blurb that appears below the logo in the footer.</span>
            </div>
        </div>
    </div>

    <!-- ── Welcome offer ── -->
    <?php
    analytics_ensure_schema();
    $wStats = ['granted' => 0, 'used' => 0, 'given' => 0.0];
    try {
        $wStats = db()->query("SELECT COUNT(welcome_discount_pct) AS granted, COUNT(welcome_discount_order_id) AS used,
            (SELECT COALESCE(SUM(o.discount),0) FROM orders o JOIN users u2 ON u2.welcome_discount_order_id = o.id WHERE o.status NOT IN ('cancelled','refunded')) AS given
            FROM users")->fetch();
    } catch (Exception $e) {}
    ?>
    <div class="admin-settings-section" data-section="welcome" hidden>
        <h2 class="admin-settings-section__title">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
            Welcome offer
        </h2>
        <p class="admin-form__hint" style="margin:-6px 0 16px">Visitors who aren&rsquo;t signed in see a popup offering a discount for creating an account. Each new account gets it <strong>once</strong>: it&rsquo;s applied to their bag automatically and used up by their first order. Emails that already ordered as a guest don&rsquo;t get it. If a card payment fails, the customer gets the discount back.</p>
        <div class="admin-form__check" style="margin-bottom:16px">
            <input type="hidden" name="welcome_offer_enabled" value="0">
            <input type="checkbox" name="welcome_offer_enabled" value="1" id="welcome_on" <?= $s('welcome_offer_enabled', '1') === '1' ? 'checked' : '' ?>>
            <label for="welcome_on">Offer a discount for new accounts (and show the popup)</label>
        </div>
        <div class="admin-form__row">
            <div class="admin-form__group">
                <label for="welcome_pct">Discount (%)</label>
                <input type="number" id="welcome_pct" name="welcome_offer_pct" min="1" max="50" step="0.5" value="<?= h($s('welcome_offer_pct', '5')) ?>">
                <span class="admin-form__hint">On products only, never shipping or the COD fee.</span>
            </div>
            <div class="admin-form__group">
                <label for="welcome_delay">Show popup after (seconds)</label>
                <input type="number" id="welcome_delay" name="welcome_popup_delay" min="2" max="120" value="<?= h($s('welcome_popup_delay', '8')) ?>">
                <span class="admin-form__hint">It also opens when the visitor scrolls well down the page. A closed popup stays hidden for that visitor for 7 days.</span>
            </div>
        </div>
        <div class="admin-form__group">
            <label for="welcome_text">Popup text</label>
            <textarea id="welcome_text" name="welcome_popup_text" rows="2" maxlength="240"><?= h($s('welcome_popup_text', 'Create your account and the discount is added to your bag automatically. No code to remember.')) ?></textarea>
        </div>
        <div class="g-mini-stats">
            <div><b><?= (int)$wStats['granted'] ?></b><span>accounts given the offer</span></div>
            <div><b><?= (int)$wStats['used'] ?></b><span>used it on an order</span></div>
            <div><b><?= money((float)$wStats['given']) ?></b><span>total discount given</span></div>
        </div>
    </div>

    <!-- ── Maintenance Mode ── -->
    <div class="admin-settings-section" data-section="maintenance" hidden>
        <h2 class="admin-settings-section__title">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
            Maintenance Mode
        </h2>
        <div class="admin-form__check">
            <input type="hidden" name="maintenance_mode" value="0">
            <input type="checkbox" name="maintenance_mode" value="1" id="maint_mode" <?= $s('maintenance_mode') === '1' ? 'checked' : '' ?>>
            <label for="maint_mode">Enable maintenance mode (site will show a "Down for maintenance" page to visitors)</label>
        </div>
        <div class="admin-form__group">
            <label>Maintenance Message</label>
            <textarea name="maintenance_message" rows="3"><?= h($s('maintenance_message')) ?></textarea>
        </div>
    </div>

    <!-- ── Branding & Logos ── -->
    <div class="admin-settings-section" data-section="branding" hidden>
        <h2 class="admin-settings-section__title">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            Branding & Logos
        </h2>
        <div class="admin-form__row-3">
            <div class="admin-form__group">
                <label>Site Logo</label>
                <div class="admin-img-upload">
                    <div class="admin-img-preview" id="preview-site_logo" onclick="openSettingsMediaPicker('site_logo')" title="Click to choose from Media" style="cursor:pointer">
                        <?php if ($s('site_logo')): ?>
                            <img src="<?= SITE_URL . '/' . h($s('site_logo')) ?>" alt="Logo">
                        <?php else: ?>
                            No logo
                        <?php endif; ?>
                    </div>
                </div>
                <div style="margin-top:8px">
                    <button type="button" class="admin-btn admin-btn--sm" onclick="openSettingsMediaPicker('site_logo')">Choose from Media</button>
                    <button type="button" class="admin-btn admin-btn--sm admin-btn--danger" onclick="clearSettingsMedia('site_logo')">Clear</button>
                </div>
                <input type="hidden" name="site_logo" id="input-site_logo" value="<?= h($s('site_logo')) ?>">
                <span class="admin-form__hint">Recommended: SVG or PNG, max 300×80px</span>
            </div>
            <div class="admin-form__group">
                <label>Favicon</label>
                <div class="admin-img-upload">
                    <div class="admin-img-preview" id="preview-site_favicon" onclick="openSettingsMediaPicker('site_favicon')" title="Click to choose from Media" style="cursor:pointer;width:64px;height:64px">
                        <?php if ($s('site_favicon')): ?>
                            <img src="<?= SITE_URL . '/' . h($s('site_favicon')) ?>" alt="Favicon">
                        <?php else: ?>
                            No icon
                        <?php endif; ?>
                    </div>
                </div>
                <div style="margin-top:8px">
                    <button type="button" class="admin-btn admin-btn--sm" onclick="openSettingsMediaPicker('site_favicon')">Choose from Media</button>
                    <button type="button" class="admin-btn admin-btn--sm admin-btn--danger" onclick="clearSettingsMedia('site_favicon')">Clear</button>
                </div>
                <input type="hidden" name="site_favicon" id="input-site_favicon" value="<?= h($s('site_favicon')) ?>">
                <span class="admin-form__hint">ICO, PNG or SVG — 32×32 or 180×180</span>
            </div>
            <div class="admin-form__group">
                <label>OG / Share Image</label>
                <div class="admin-img-upload">
                    <div class="admin-img-preview" id="preview-og_image" onclick="openSettingsMediaPicker('og_image')" title="Click to choose from Media" style="cursor:pointer">
                        <?php if ($s('og_image')): ?>
                            <img src="<?= SITE_URL . '/' . h($s('og_image')) ?>" alt="OG">
                        <?php else: ?>
                            No image
                        <?php endif; ?>
                    </div>
                </div>
                <div style="margin-top:8px">
                    <button type="button" class="admin-btn admin-btn--sm" onclick="openSettingsMediaPicker('og_image')">Choose from Media</button>
                    <button type="button" class="admin-btn admin-btn--sm admin-btn--danger" onclick="clearSettingsMedia('og_image')">Clear</button>
                </div>
                <input type="hidden" name="og_image" id="input-og_image" value="<?= h($s('og_image')) ?>">
                <span class="admin-form__hint">1200×630px — shown when shared on social media</span>
            </div>
        </div>
    </div>

    <!-- ── SEO & Meta ── -->
    <div class="admin-settings-section" data-section="seo" hidden>
        <h2 class="admin-settings-section__title">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            SEO & Meta Data
        </h2>
        <div class="admin-form__group">
            <label>Default Meta Title</label>
            <input type="text" name="default_meta_title" value="<?= h($s('default_meta_title')) ?>">
            <span class="admin-form__hint">Fallback title for pages without a custom title.</span>
        </div>
        <div class="admin-form__group">
            <label>Default Meta Description</label>
            <textarea name="default_meta_description" rows="2"><?= h($s('default_meta_description')) ?></textarea>
        </div>
        <div class="admin-form__group">
            <label>Meta Keywords</label>
            <input type="text" name="default_meta_keywords" value="<?= h($s('default_meta_keywords')) ?>">
        </div>
        <div class="admin-form__row">
            <div class="admin-form__group">
                <label>Google Analytics ID</label>
                <input type="text" name="google_analytics_id" value="<?= h($s('google_analytics_id')) ?>" placeholder="G-XXXXXXXXXX">
            </div>
            <div class="admin-form__group">
                <label>Meta Pixel ID</label>
                <input type="text" name="meta_pixel_id" value="<?= h($s('meta_pixel_id')) ?>" placeholder="1234567890123456">
            </div>
        </div>
    </div>

    <!-- ── Social Links ── -->
    <div class="admin-settings-section" data-section="social" hidden>
        <h2 class="admin-settings-section__title">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            Social Links
        </h2>
        <div class="admin-form__row" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px;">
            <div class="admin-form__group">
                <label>Instagram URL</label>
                <input type="url" name="instagram_url" value="<?= h($s('instagram_url')) ?>" placeholder="https://instagram.com/...">
            </div>
            <div class="admin-form__group">
                <label>TikTok URL</label>
                <input type="url" name="tiktok_url" value="<?= h($s('tiktok_url')) ?>" placeholder="https://tiktok.com/@...">
            </div>
            <div class="admin-form__group">
                <label>Facebook URL</label>
                <input type="url" name="facebook_url" value="<?= h($s('facebook_url')) ?>" placeholder="https://facebook.com/...">
            </div>
            <div class="admin-form__group">
                <label>WhatsApp URL</label>
                <input type="url" name="whatsapp_url" value="<?= h($s('whatsapp_url')) ?>" placeholder="https://wa.me/20...">
            </div>
        </div>
    </div>

    <!-- ── Payment ── -->
    <div class="admin-settings-section" data-section="payment" hidden>
        <h2 class="admin-settings-section__title">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            Payment & Currency
        </h2>
        <div class="admin-form__check">
            <input type="hidden" name="cod_enabled" value="0">
            <input type="checkbox" name="cod_enabled" value="1" id="cod_on" <?= $s('cod_enabled', '1') === '1' ? 'checked' : '' ?>>
            <label for="cod_on">Enable Cash on Delivery</label>
        </div>
        <div class="admin-form__row-3">
            <div class="admin-form__group">
                <label>COD Label</label>
                <input type="text" name="cod_label" value="<?= h($s('cod_label', 'Cash on Delivery')) ?>">
            </div>
            <div class="admin-form__group">
                <label>COD Extra Fee (EGP)</label>
                <input type="number" name="cod_extra_fee" value="<?= h($s('cod_extra_fee', '0')) ?>" min="0" step="0.01">
            </div>
        </div>
        <div class="admin-form__row-3">
            <div class="admin-form__group">
                <label>Currency Code</label>
                <input type="text" name="currency" value="<?= h($s('currency', 'EGP')) ?>">
            </div>
            <div class="admin-form__group">
                <label>Currency Symbol</label>
                <input type="text" name="currency_symbol" value="<?= h($s('currency_symbol', 'EGP ')) ?>">
            </div>
            <div class="admin-form__group">
                <label>Free Shipping Over</label>
                <input type="number" name="free_shipping_over" value="<?= h($s('free_shipping_over', '2000')) ?>" min="0" step="1">
                <span class="admin-form__hint">0 = never free shipping</span>
            </div>
        </div>
        
        <hr style="border:none;border-top:1px solid var(--a-border);margin:24px 0">
        <h3 style="font-size:.9rem;font-weight:600;margin-bottom:16px;">InstaPay Setup</h3>
        
        <?php
        /* The inputs used to be pre-filled with example text as their VALUE,
           so every save wrote "your_instapay_username@instapay" into the DB.
           Now they hold only what is really saved. There is no built-in
           fallback: empty fields stay empty. */
        $ipSaved = fn($k, $placeholder) => in_array($s($k), ['', $placeholder], true) ? '' : $s($k);
        ?>
        <div class="admin-form__row-3">
            <div class="admin-form__group">
                <label>InstaPay Address (IPA)</label>
                <input type="text" name="instapay_username" value="<?= h($ipSaved('instapay_username', 'your_instapay_username@instapay')) ?>" placeholder="e.g. yourname@instapay">
            </div>
            <div class="admin-form__group">
                <label>WhatsApp Number</label>
                <input type="text" name="instapay_whatsapp" value="<?= h($ipSaved('instapay_whatsapp', '201000000000')) ?>" placeholder="e.g. 2010XXXXXXXX" inputmode="numeric">
                <span class="admin-form__hint">Include country code (e.g. 20 for Egypt) without + or 00.</span>
            </div>
            <div class="admin-form__group">
                <label>InstaPay Payment Link</label>
                <input type="url" name="instapay_link" value="<?= h($s('instapay_link')) ?>" placeholder="https://ipn.eg/S/…">
                <span class="admin-form__hint">Must start with https://</span>
            </div>
        </div>
        <span class="admin-form__hint">InstaPay is hidden at checkout until the InstaPay Address is filled in. The WhatsApp number and link are optional. Used on checkout, the payment page and order emails.</span>
    </div>

    <!-- ── Paymob Integration ── -->
    <div class="admin-settings-section" data-section="paymob" hidden>
        <h2 class="admin-settings-section__title">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2" ry="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            Paymob Integration
        </h2>
        
        <div style="background:var(--a-surface2);padding:16px;border-radius:var(--a-radius);margin-bottom:20px;font-size:0.82rem;border:1px solid var(--a-border);line-height:1.5;">
            <strong style="display:block;margin-bottom:8px;color:var(--a-text);">How to configure Paymob:</strong>
            <ol style="margin:0;padding-left:20px;color:var(--a-muted);">
                <li>Find your <strong>API Key</strong> in Paymob Dashboard > Settings > API Keys.</li>
                <li>Find your <strong>Integration ID</strong> in Paymob Dashboard > Developers > Payment Integrations. Ensure it is for Online Card integration.</li>
                <li>Find your <strong>Iframe ID</strong> in Paymob Dashboard > Developers > iframes.</li>
                <li>Find your <strong>HMAC Secret</strong> in Paymob Dashboard > Settings > HMAC Secret.</li>
            </ol>
            <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--a-border);">
                <strong style="color:var(--a-accent);">Required Webhook:</strong> 
                Ensure your "Transaction Processed" callback URL in Paymob is set exactly to: 
                <code style="background:var(--a-bg);padding:2px 6px;border-radius:4px;color:var(--a-text);"><?= SITE_URL ?>/api/paymob_callback.php</code>
            </div>
        </div>

        <div class="admin-form__group">
            <label>API Key</label>
            <input type="password" name="paymob_api_key" value="<?= h($s('paymob_api_key')) ?>" placeholder="Enter API Key">
        </div>
        <div class="admin-form__row-3">
            <div class="admin-form__group">
                <label>Integration ID (Card)</label>
                <input type="text" name="paymob_integration_id" value="<?= h($s('paymob_integration_id')) ?>" placeholder="e.g. 4345234">
            </div>
            <div class="admin-form__group">
                <label>Iframe ID</label>
                <input type="text" name="paymob_iframe_id" value="<?= h($s('paymob_iframe_id')) ?>" placeholder="e.g. 123456">
            </div>
            <div class="admin-form__group">
                <label>HMAC Secret</label>
                <input type="password" name="paymob_hmac" value="<?= h($s('paymob_hmac')) ?>" placeholder="Enter HMAC Secret">
                <span class="admin-form__hint">Required to verify secure webhook callbacks.</span>
            </div>
        </div>
    </div>

    <!-- ── Email / SMTP ── -->
    <div class="admin-settings-section" data-section="email" hidden>
        <h2 class="admin-settings-section__title">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            Email Notifications
        </h2>
        <p style="font-size:.75rem;color:var(--a-muted);margin-bottom:20px;line-height:1.6">
            Send automated emails to customers when orders are placed, shipped, or cancelled.<br>
            Use <strong>Resend API Key</strong> (recommended — free 3,000 emails/month at <a href="https://resend.com" target="_blank" style="color:var(--a-accent)">resend.com</a>)
            or fill in SMTP details for Zoho/Hostinger/Gmail.
        </p>

        <div class="admin-form__check" style="margin-bottom:20px">
            <input type="hidden" name="email_enabled" value="0">
            <input type="checkbox" name="email_enabled" value="1" id="email_on" <?= $s('email_enabled', '0') === '1' ? 'checked' : '' ?>>
            <label for="email_on"><strong>Enable transactional emails</strong></label>
        </div>

        <div class="admin-form__row">
            <div class="admin-form__group">
                <label>From Name</label>
                <input type="text" name="email_from_name" value="<?= h($s('email_from_name', $s('site_name', SITE_NAME))) ?>" placeholder="e.g. LUMEEGY">
            </div>
            <div class="admin-form__group">
                <label>From Email Address</label>
                <input type="email" name="email_from_address" value="<?= h($s('email_from_address')) ?>" placeholder="orders@yourdomain.com">
            </div>
        </div>

        <hr style="border:none;border-top:1px solid var(--a-border);margin:20px 0">
        <p style="font-size:.8rem;font-weight:600;color:var(--a-text);margin-bottom:12px">Email Types</p>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px">
            <div style="padding:14px;border:1px solid var(--a-border);border-radius:var(--a-radius)">
                <div class="admin-form__check">
                    <input type="hidden" name="email_confirmation_enabled" value="0">
                    <input type="checkbox" name="email_confirmation_enabled" value="1" id="email_conf" <?= $s('email_confirmation_enabled', '1') === '1' ? 'checked' : '' ?>>
                    <label for="email_conf"><strong>Order Confirmation</strong></label>
                </div>
                <p style="font-size:.72rem;color:var(--a-muted);margin:6px 0 0 22px">Sent when order is placed / payment confirmed</p>
            </div>
            <div style="padding:14px;border:1px solid var(--a-border);border-radius:var(--a-radius)">
                <div class="admin-form__check">
                    <input type="hidden" name="email_shipped_enabled" value="0">
                    <input type="checkbox" name="email_shipped_enabled" value="1" id="email_ship" <?= $s('email_shipped_enabled', '1') === '1' ? 'checked' : '' ?>>
                    <label for="email_ship"><strong>Shipping Update</strong></label>
                </div>
                <p style="font-size:.72rem;color:var(--a-muted);margin:6px 0 0 22px">Sent when you mark an order as Shipped</p>
            </div>
            <div style="padding:14px;border:1px solid var(--a-border);border-radius:var(--a-radius)">
                <div class="admin-form__check">
                    <input type="hidden" name="email_cancelled_enabled" value="0">
                    <input type="checkbox" name="email_cancelled_enabled" value="1" id="email_cancel" <?= $s('email_cancelled_enabled', '1') === '1' ? 'checked' : '' ?>>
                    <label for="email_cancel"><strong>Cancellation Notice</strong></label>
                </div>
                <p style="font-size:.72rem;color:var(--a-muted);margin:6px 0 0 22px">Sent when a payment is declined or order is cancelled</p>
            </div>
        </div>

        <hr style="border:none;border-top:1px solid var(--a-border);margin:20px 0">
        <p style="font-size:.8rem;font-weight:600;color:var(--a-text);margin-bottom:4px">Option 1 — Resend API <span style="font-size:.7rem;font-weight:400;color:var(--a-accent)">(Recommended)</span></p>
        <p style="font-size:.72rem;color:var(--a-muted);margin-bottom:12px">Sign up free at resend.com → Create API Key → paste below. No SMTP config needed.</p>
        <div class="admin-form__group" style="max-width:500px">
            <label>Resend API Key</label>
            <input type="password" name="email_resend_api_key" value="<?= h($s('email_resend_api_key')) ?>" placeholder="re_xxxxxxxxxxxxxxxxxxxx">
            <span class="admin-form__hint">If set, Resend is used. SMTP fields below are ignored.</span>
        </div>

        <hr style="border:none;border-top:1px solid var(--a-border);margin:20px 0">
        <p style="font-size:.8rem;font-weight:600;color:var(--a-text);margin-bottom:4px">Option 2 — SMTP (Zoho / Hostinger / Gmail)</p>
        <p style="font-size:.72rem;color:var(--a-muted);margin-bottom:12px">Only used if Resend API Key is empty.</p>
        <div class="admin-form__row">
            <div class="admin-form__group">
                <label>SMTP Host</label>
                <input type="text" name="smtp_host" value="<?= h($s('smtp_host')) ?>" placeholder="smtp.zoho.com">
            </div>
            <div class="admin-form__group">
                <label>SMTP Port</label>
                <input type="number" name="smtp_port" value="<?= h($s('smtp_port', '587')) ?>" placeholder="587">
                <span class="admin-form__hint">587 (TLS) or 465 (SSL)</span>
            </div>
            <div class="admin-form__group">
                <label>Encryption</label>
                <select name="smtp_secure" style="width:100%;padding:8px 12px;background:var(--a-bg);border:1px solid var(--a-border);border-radius:var(--a-radius);color:var(--a-text)">
                    <option value="tls" <?= $s('smtp_secure', 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (port 587)</option>
                    <option value="ssl" <?= $s('smtp_secure') === 'ssl' ? 'selected' : '' ?>>SSL (port 465)</option>
                </select>
            </div>
        </div>
        <div class="admin-form__row">
            <div class="admin-form__group">
                <label>SMTP Username</label>
                <input type="text" name="smtp_username" value="<?= h($s('smtp_username')) ?>" placeholder="orders@yourdomain.com" autocomplete="off">
            </div>
            <div class="admin-form__group">
                <label>SMTP Password</label>
                <input type="password" name="smtp_password" value="<?= h($s('smtp_password')) ?>" placeholder="••••••••" autocomplete="new-password">
            </div>
        </div>
    </div>

    <div style="display:flex;gap:12px;padding-top:12px">
        <button type="submit" class="admin-btn admin-btn--primary">
            <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            Save Settings
        </button>
    </div>
</form>

<style>
.admin-settings-section{margin-bottom:32px;padding:24px;background:var(--a-surface);border:1px solid var(--a-border);border-radius:var(--a-radius)}
.admin-settings-section__title{display:flex;align-items:center;gap:8px;font-size:.9rem;font-weight:600;margin-bottom:20px;padding-bottom:12px;border-bottom:1px solid var(--a-border)}
.admin-settings-section__title svg{flex-shrink:0}
.hp-modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;z-index:200;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;padding:24px}
@keyframes modalIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.media-grid__item{cursor:pointer;border-radius:4px;overflow:hidden;border:2px solid transparent;transition:all .2s;text-align:center}
.media-grid__item:hover{border-color:var(--a-accent)}
.media-grid__item img{width:100%;height:120px;object-fit:cover;display:block}
.media-grid__item__name{font-size:.65rem;color:var(--a-muted);padding:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
</style>

<script>
// Settings tabs
document.querySelectorAll('.admin-settings-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.admin-settings-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        const target = this.dataset.tab;
        document.querySelectorAll('.admin-settings-section[data-section]').forEach(s => {
            if (s.dataset.section === target) {
                s.removeAttribute('hidden');
            } else {
                s.setAttribute('hidden', '');
            }
        });
        // Keep the open tab across the save (the form posts back to this URL).
        history.replaceState(null, '', '#' + target);
    });
});
// Open the tab named in the URL, e.g. settings.php#welcome from the dashboard.
(function () {
    const t = document.querySelector('.admin-settings-tab[data-tab="' + location.hash.slice(1) + '"]');
    if (t) t.click();
})();

// Bidirectional color sync + live preview
document.querySelectorAll('input[data-color-key]').forEach(picker => {
    const key = picker.dataset.colorKey;
    const textInput = document.querySelector(`input[data-text-for="${key}"]`);
    const preview = document.querySelector(`div[data-preview="${key}"]`);

    function sync(val) {
        if (textInput) textInput.value = val;
        if (preview) preview.style.background = val;
    }

    picker.addEventListener('input', () => sync(picker.value));

    if (textInput) {
        textInput.addEventListener('input', () => {
            const v = textInput.value.trim();
            if (/^#[0-9a-fA-F]{3,6}$/.test(v)) {
                picker.value = v.length === 4 ? '#' + v[1]+v[1]+v[2]+v[2]+v[3]+v[3] : v;
                if (preview) preview.style.background = v;
            }
        });
    }
});

// Media Picker
let pickerTargetField = null;

// Uses the shared media picker (admin/assets/js/media-kit.js): search,
// upload or drag files in, hover for details, double-click to choose.
function openSettingsMediaPicker(field) {
    pickerTargetField = field;
    const titles = { site_logo: 'Choose your logo', site_favicon: 'Choose a favicon', og_image: 'Choose the share image' };
    AdnMedia.pick({ accept: 'image', title: titles[field] }).then(res => {
        if (res && res.length) pickSettingsMedia(res[0].filepath);
    });
}

function pickSettingsMedia(filepath) {
    if (pickerTargetField) {
        document.getElementById('input-' + pickerTargetField).value = filepath;
        const preview = document.getElementById('preview-' + pickerTargetField);
        if (preview) {
            const img = document.createElement('img');
            img.src = '<?= SITE_URL ?>/' + filepath;
            img.alt = 'Preview';
            preview.replaceChildren(img);
        }
    }
    if (window.adminToast) adminToast('Image chosen — click Save Settings to apply it.', 'info');
}

function clearSettingsMedia(field) {
    document.getElementById('input-' + field).value = '';
    const preview = document.getElementById('preview-' + field);
    if (preview) {
        preview.innerHTML = field === 'site_favicon' ? 'No icon' : 'No ' + (field === 'og_image' ? 'image' : 'logo');
    }
}

</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
