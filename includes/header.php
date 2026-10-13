<?php
/**
 * LUMEEGY — Shared Header
 * Include at top of every page: require_once 'includes/header.php';
 *
 * Expects $pageTitle and $pageDescription to be set before including.
 */
require_once __DIR__ . '/../includes/functions.php';
// Check maintenance mode first
if (setting('maintenance_mode') === '1'
    && !str_contains($_SERVER['PHP_SELF'], 'maintenance.php')
    && !str_contains($_SERVER['PHP_SELF'], 'account.php')   // allow admin login page
    && !str_contains($_SERVER['PHP_SELF'], 'admin/')
) {
    // Let already-authenticated admin users bypass maintenance mode
    $adminBypass = false;
    lume_session_start();
    $u = current_user();
    if ($u && ($u['role'] ?? '') === 'admin') {
        $adminBypass = true;
    }
    if (!$adminBypass) {
        redirect('/maintenance.php');
    }
}
lume_session_start();
$pageTitle       = $pageTitle       ?? setting('default_meta_title', SITE_NAME . ' — ' . SITE_TAGLINE);
$pageDescription = $pageDescription ?? setting('default_meta_description', 'Discover LUMEEGY — a luxury Egyptian fashion brand.');
$pageKeywords    = $pageKeywords    ?? setting('default_meta_keywords', '');
$ogImage         = $ogImage         ?? (setting('og_image') ? SITE_URL . '/' . setting('og_image') : '');
$ogType          = $ogType          ?? 'website';
$canonicalUrl    = $canonicalUrl    ?? (SITE_URL . strtok($_SERVER['REQUEST_URI'], '?'));
$cartCount       = cart_count();
$currentUser     = current_user();
$csrfToken       = csrf_token();
$pixelId         = setting('meta_pixel_id') ?: META_PIXEL_ID;
$siteLogo        = setting('site_logo');
$siteFavicon     = setting('site_favicon');
$googleAnalyticsId = setting('google_analytics_id');
// ── Theme settings ──
$themeBg        = setting('theme_color_bg', '#0A0A0A');
$themeBgCard    = setting('theme_color_bg_card', 'rgba(10,10,10,0.88)');
$themeCream     = setting('theme_color_cream', '#F5F5F0');
$themeGold      = setting('theme_color_gold', '#C8B89A');
$themeAccent    = setting('theme_color_accent', '#C4714A');
$themeMuted     = setting('theme_color_muted', '#888880');
$themeFontHead  = setting('theme_font_heading', 'Bodoni Moda');
$themeFontBody  = setting('theme_font_body', 'Red Hat Display');
$themeFontHeadW = setting('theme_font_heading_weight', '400;700;900');
$themeFontBodyW = setting('theme_font_body_weight', '300;400;500;600');
$showStock      = setting('show_stock_indicator', '1');
// Build Google Fonts URL dynamically
$googleFontsUrl = google_fonts_url($themeFontHead, $themeFontHeadW, $themeFontBody, $themeFontBodyW);
// Current page for active nav link
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="description" content="<?= h($pageDescription) ?>">
    <?php if ($pageKeywords): ?><meta name="keywords" content="<?= h($pageKeywords) ?>"><?php endif; ?>
    <meta name="theme-color" content="<?= h($themeBg) ?>">
    <meta name="robots" content="index, follow">
    <!-- Canonical URL -->
    <link rel="canonical" href="<?= h($canonicalUrl) ?>">
    <!-- Open Graph -->
    <meta property="og:type"        content="<?= h($ogType) ?>">
    <meta property="og:title"       content="<?= h($pageTitle) ?>">
    <meta property="og:description" content="<?= h($pageDescription) ?>">
    <meta property="og:site_name"   content="<?= h(setting('site_name', SITE_NAME)) ?>">
    <meta property="og:url"         content="<?= h($canonicalUrl) ?>">
    <?php if ($ogImage): ?>
    <meta property="og:image"       content="<?= h($ogImage) ?>">
    <meta property="og:image:alt"   content="<?= h($pageTitle) ?>">
    <?php endif; ?>
    <!-- Twitter Card -->
    <meta name="twitter:card"        content="summary_large_image">
    <meta name="twitter:title"       content="<?= h($pageTitle) ?>">
    <meta name="twitter:description" content="<?= h($pageDescription) ?>">
    <?php if ($ogImage): ?><meta name="twitter:image" content="<?= h($ogImage) ?>"><?php endif; ?>
    <title><?= h($pageTitle) ?></title>
    <?php /* Start the most important images downloading immediately, before
             the browser reaches them in the page (hero on the homepage; the
             logo everywhere). Pages set $preloadImages before including this. */ ?>
    <?php if ($siteLogo): ?><link rel="preload" as="image" href="<?= h(thumb_url($siteLogo, 400)) ?>"><?php endif; ?>
    <?php foreach (($preloadImages ?? []) as $preloadImg): ?>
    <link rel="preload" as="image" href="<?= h($preloadImg) ?>" fetchpriority="high">
    <?php endforeach; ?>
    <?php if ($siteFavicon): ?>
    <link rel="icon" href="<?= h(asset_url($siteFavicon)) ?>">
    <?php endif; ?>
    <script>
    /* Skeleton loading: mark every <img> the moment it finishes (or fails).
       Registered here, before the body is parsed, so no image is missed —
       load/error don't bubble but they do reach a capturing listener. CSS
       shows a shimmer until then (see "Skeleton loading" in components.css). */
    document.documentElement.classList.add('lume-js');
    document.addEventListener('load', function (e) { var t = e.target; if (t && t.tagName === 'IMG') t.classList.add('is-loaded'); }, true);
    document.addEventListener('error', function (e) { var t = e.target; if (t && t.tagName === 'IMG') t.classList.add('is-loaded', 'is-broken'); }, true);
    </script>
    <!-- Fonts (dynamic from theme settings) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="<?= h($googleFontsUrl) ?>" rel="stylesheet">
    <!-- Styles -->
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/base.css?v=<?= filemtime(ROOT_PATH.'/assets/css/base.css') ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/components.css?v=<?= filemtime(ROOT_PATH.'/assets/css/components.css') ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/variants.css?v=<?= filemtime(ROOT_PATH.'/assets/css/variants.css') ?>">
    <!-- Dynamic Theme CSS Variables (loaded AFTER base.css to override defaults) -->
    <style>
    :root {
        --bg: <?= h($themeBg) ?>;
        --bg-card: <?= h($themeBg) ?>;
        --cream: <?= h($themeCream) ?>;
        --gold: <?= h($themeGold) ?>;
        --terracotta: <?= h($themeAccent) ?>;
        --muted: <?= h($themeMuted) ?>;
        --font-serif: '<?= h($themeFontHead) ?>', serif;
        --font-sans: '<?= h($themeFontBody) ?>', sans-serif;
        --glass: rgba(<?= hexToRgb($themeBg) ?>, 0.25);
        --glass-s: rgba(<?= hexToRgb($themeBg) ?>, 0.75);
        --border: rgba(<?= hexToRgb($themeCream) ?>, 0.08);
    }
    </style>
    <?php
    // Fetch active custom theme
    $activeThemeCss = '';
    try {
        $activeThemeStmt = db()->query("SELECT css_content FROM store_themes WHERE is_active = 1 LIMIT 1");
        if ($activeTheme = $activeThemeStmt->fetch()) {
            $activeThemeCss = $activeTheme['css_content'];
        }
    } catch (Exception $e) {
        // Ignore if table doesn't exist yet
    }
    ?>
    <?php if ($activeThemeCss): ?>
    <!-- Active Custom Theme -->
    <style>
<?= $activeThemeCss ?>
    </style>
    <?php endif; ?>
    <?php if ($pixelId): ?>
    <!-- Meta Pixel -->
    <script>
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
    n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
    document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fbq('set', 'autoConfig', false, '<?= h($pixelId) ?>');
    fbq('init', '<?= h($pixelId) ?>');
    fbq('track', 'PageView');
    </script>
    <noscript><img height="1" width="1" style="display:none"
    src="https://www.facebook.com/tr?id=<?= h($pixelId) ?>&ev=PageView&noscript=1"/></noscript>
    <?php endif; ?>
    <?php if ($googleAnalyticsId): ?>
    <!-- Google Analytics -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= h($googleAnalyticsId) ?>"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', '<?= h($googleAnalyticsId) ?>');
    </script>
    <?php endif; ?>
</head>
<body>
<!-- SPLASH SCREEN -->
<style>
html.hide-splash #lume-splash { display: none !important; }
</style>
<script>
if (sessionStorage.getItem('lumeSplashShown')) {
    document.documentElement.classList.add('hide-splash');
}
</script>
<div id="lume-splash" style="position:fixed;top:0;left:0;width:100%;height:100%;background:var(--bg);z-index:99999;display:flex;align-items:center;justify-content:center;transition:opacity 0.6s ease, visibility 0.6s ease;">
    <div style="text-align:center; animation: splashPulse 2s infinite ease-in-out;">
        <?php if ($siteLogo): ?>
            <img src="<?= h(thumb_url($siteLogo, 400)) ?>" alt="Loading..." style="max-height:80px;width:auto" fetchpriority="high">
        <?php else: ?>
            <span class="lume-logo-text" style="font-size:2.5rem;color:var(--gold)"><?= h(setting('site_name', SITE_NAME)) ?></span>
        <?php endif; ?>
    </div>
</div>
<style>
@keyframes splashPulse {
    0% { opacity: 0.5; transform: scale(0.95); }
    50% { opacity: 1; transform: scale(1.02); }
    100% { opacity: 0.5; transform: scale(0.95); }
}
.splash-hidden {
    opacity: 0 !important;
    visibility: hidden !important;
}
</style>
<script>
if (!sessionStorage.getItem('lumeSplashShown')) {
    // Hide as soon as the page is usable. It used to wait for window "load" —
    // i.e. every image on the page, several MB on the homepage — and then add
    // a further 1s, so first-time visitors stared at the logo for seconds.
    var lumeHideSplash = function() {
        var splash = document.getElementById('lume-splash');
        if (splash && !splash.classList.contains('splash-hidden')) {
            splash.classList.add('splash-hidden');
            setTimeout(function() { splash.remove(); }, 600);
        }
        try { sessionStorage.setItem('lumeSplashShown', 'true'); } catch (e) {}
    };
    document.addEventListener('DOMContentLoaded', function() { setTimeout(lumeHideSplash, 350); });
    setTimeout(lumeHideSplash, 2500); // hard cap, whatever happens
} else {
    // If already shown, remove it from DOM immediately so it doesn't block interactions
    var splash = document.getElementById('lume-splash');
    if(splash) splash.remove();
}
</script>
<!-- Film Grain Overlay -->
<div class="lume-grain-overlay" aria-hidden="true"></div>
<!-- ═══════════════════════════════════════════
     HEADER
═══════════════════════════════════════════ -->
<header class="lume-header" id="lume-header">
    <!-- Left Nav -->
    <nav class="lume-header__nav-left" aria-label="Primary navigation">
        <button class="lume-header__hamburger" id="lume-hamburger" aria-label="Open menu" aria-expanded="false">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <line x1="3" y1="6"  x2="21" y2="6" />
                <line x1="3" y1="12" x2="21" y2="12"/>
                <line x1="3" y1="18" x2="21" y2="18"/>
            </svg>
        </button>
        <a href="<?= SITE_URL ?>/" class="lume-header__nav-link <?= $currentPage === 'index.php' ? 'is-active' : '' ?>">Home</a>
        <a href="<?= SITE_URL ?>/shop.php" class="lume-header__nav-link <?= $currentPage === 'shop.php' ? 'is-active' : '' ?>">Shop</a>
        <a href="<?= SITE_URL ?>/track-order.php" class="lume-header__nav-link <?= $currentPage === 'track-order.php' ? 'is-active' : '' ?>">Track Order</a>
        <a href="<?= SITE_URL ?>/about.php" class="lume-header__nav-link <?= $currentPage === 'about.php' ? 'is-active' : '' ?>">About</a>
        <a href="<?= SITE_URL ?>/contact.php" class="lume-header__nav-link <?= $currentPage === 'contact.php' ? 'is-active' : '' ?>">Contact</a>
    </nav>
    <!-- Centered Logo -->
    <div class="lume-header__logo">
        <a href="<?= SITE_URL ?>/" aria-label="<?= h(setting('site_name', SITE_NAME)) ?> — Home">
            <?php if ($siteLogo): ?>
                <img src="<?= h(thumb_url($siteLogo, 400)) ?>" alt="<?= h(setting('site_name', SITE_NAME)) ?>" style="max-height:40px;width:auto" fetchpriority="high">
            <?php else: ?>
                <span class="lume-logo-text"><?= h(setting('site_name', SITE_NAME)) ?></span>
            <?php endif; ?>
        </a>
    </div>
    <!-- Right Nav — Icons -->
    <nav class="lume-header__nav-right" aria-label="Utility navigation">
        <!-- Search -->
        <button class="lume-header__icon-btn" id="btn-search" aria-label="Search">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
        </button>
        <!-- Account -->
        <div style="position:relative;">
            <button class="lume-header__icon-btn" id="btn-account" aria-label="Account" aria-haspopup="true" aria-expanded="false">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
            </button>
            <div class="lume-account-dropdown" id="lume-account-dropdown" role="menu">
                <?php if ($currentUser): ?>
                    <span class="lume-dropdown-label">Hi, <?= h($currentUser['first_name']) ?></span>
                    <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                        <a href="<?= SITE_URL ?>/admin/" role="menuitem">Admin Panel</a>
                    <?php endif; ?>
                    <a href="<?= SITE_URL ?>/account.php" role="menuitem">My Account</a>
                    <a href="<?= SITE_URL ?>/account.php?action=orders" role="menuitem">My Orders</a>
                    <a href="<?= SITE_URL ?>/account.php?action=logout" role="menuitem">Logout</a>
                <?php else: ?>
                    <a href="<?= SITE_URL ?>/account.php" role="menuitem">Login</a>
                    <a href="<?= SITE_URL ?>/account.php?action=register" role="menuitem">Register</a>
                <?php endif; ?>
            </div>
        </div>
        <!-- Cart -->
        <button class="lume-header__icon-btn" id="btn-cart" aria-label="Cart (<?= $cartCount ?> items)">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                <line x1="3" y1="6" x2="21" y2="6"/>
                <path d="M16 10a4 4 0 0 1-8 0"/>
            </svg>
            <span class="lume-cart-count" id="cart-count-badge" <?= $cartCount === 0 ? 'style="display:none"' : '' ?>>
                <?= $cartCount ?>
            </span>
        </button>
    </nav>
</header>
<!-- ═══════════════════════════════════════════
     MOBILE MENU DRAWER
═══════════════════════════════════════════ -->
<div class="lume-mobile-menu" id="lume-mobile-menu" role="dialog" aria-modal="true" aria-label="Mobile navigation">
    <div class="lume-mobile-menu__overlay" id="mobile-menu-overlay"></div>
    <nav class="lume-mobile-menu__panel">
        <div class="lume-mobile-menu__head">
            <span class="lume-logo-text"><?= h(setting('site_name', SITE_NAME)) ?></span>
            <button class="lume-mobile-menu__close" id="mobile-menu-close" aria-label="Close menu">✕</button>
        </div>
        <a href="<?= SITE_URL ?>/" class="lume-mobile-menu__link">Home</a>
        <a href="<?= SITE_URL ?>/shop.php" class="lume-mobile-menu__link">Shop</a>
        <a href="<?= SITE_URL ?>/track-order.php" class="lume-mobile-menu__link">Track Order</a>
        <a href="<?= SITE_URL ?>/about.php" class="lume-mobile-menu__link">About</a>
        <a href="<?= SITE_URL ?>/contact.php" class="lume-mobile-menu__link">Contact</a>
        <div class="lume-mobile-menu__divider"></div>
        <?php if ($currentUser): ?>
            <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                <a href="<?= SITE_URL ?>/admin/" class="lume-mobile-menu__link">Admin Panel</a>
            <?php endif; ?>
            <a href="<?= SITE_URL ?>/account.php" class="lume-mobile-menu__link">My Account</a>
            <a href="<?= SITE_URL ?>/account.php?action=logout" class="lume-mobile-menu__link">Logout</a>
        <?php else: ?>
            <a href="<?= SITE_URL ?>/account.php" class="lume-mobile-menu__link">Login / Register</a>
        <?php endif; ?>
    </nav>
</div>
<!-- ═══════════════════════════════════════════
     SEARCH OVERLAY
═══════════════════════════════════════════ -->
<div class="lume-search-overlay" id="lume-search-overlay" role="dialog" aria-modal="true" aria-label="Search">
    <div class="lume-search-container">
        <div class="lume-search-input-wrap">
            <input type="search" class="lume-search-input" id="lume-search-input"
                   placeholder="Search products…" autocomplete="off" aria-label="Search products">
            <button class="lume-search-close" id="search-close" aria-label="Close search">✕</button>
        </div>
        <div class="lume-search-results" id="lume-search-results" role="listbox" aria-label="Search results"></div>
    </div>
</div>
<!-- ═══════════════════════════════════════════
     CART DRAWER
═══════════════════════════════════════════ -->
<aside class="lume-cart-drawer" id="lume-cart-drawer" role="dialog" aria-modal="true" aria-label="Shopping cart">
    <div class="lume-cart-drawer-header">
        <span class="lume-cart-drawer-title">Your Bag</span>
        <button class="lume-cart-drawer-close" id="cart-close" aria-label="Close cart">✕</button>
    </div>
    <div class="lume-cart-drawer-items" id="cart-drawer-items">
        <!-- Populated by JS -->
    </div>
    <div class="lume-cart-drawer-footer" id="cart-drawer-footer" style="display:none;">
        <div class="lume-cart-discount" id="cart-drawer-discount" hidden>
            <span id="cart-drawer-discount-label"></span>
            <span id="cart-drawer-discount-amount"></span>
        </div>
        <div class="lume-cart-total">
            <span>Total</span>
            <span id="cart-drawer-total">EGP 0.00</span>
        </div>
        <a href="<?= SITE_URL ?>/checkout.php" class="lume-cart-checkout-btn">Proceed to Checkout</a>
        <a href="<?= SITE_URL ?>/cart.php" class="lume-cart-view-btn">View Cart</a>
    </div>
</aside>
<div class="lume-drawer-overlay" id="lume-drawer-overlay"></div>
<!-- Hidden tokens for JS use -->
<input type="hidden" id="lume-csrf" value="<?= h($csrfToken) ?>">
<input type="hidden" id="lume-base-url" value="<?= SITE_URL ?>">
<meta name="lume-currency-symbol" content="<?= h(currency_symbol()) ?>">
<meta name="lume-show-stock" content="<?= h($showStock) ?>">
<?php track_visitor(); ?>
<!-- Page content starts below -->
<main id="main-content">
