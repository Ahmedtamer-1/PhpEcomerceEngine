<?php
/**
 * Storefront extras, included by footer.php:
 *  - the visitor-tracking beacon
 *  - the welcome-offer popup (guests only)
 *  - the "discount added to your bag" notice right after signing up
 */
$sxScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$sxType = match ($sxScript) {
    'index.php'    => 'home',
    'shop.php'     => 'shop',
    'product.php'  => 'product',
    'cart.php'     => 'cart',
    'checkout.php' => !empty($showSuccess) ? 'order' : 'checkout',
    'account.php'  => 'account',
    'track-order.php' => 'track',
    default        => 'page',
};
$sxProductId = ($sxType === 'product' && !empty($product['id'])) ? (int)$product['id'] : null;
$sxUser = current_user();

// Admins browsing their own shop would inflate every number.
if (!analytics_is_staff()) {
    echo analytics_beacon_script($sxType, $sxProductId);
}

$sxPct = rtrim(rtrim(number_format(welcome_offer_pct(), 2), '0'), '.');
$sxShowPopup = !$sxUser && welcome_offer_enabled() && !in_array($sxType, ['account', 'checkout', 'order'], true);
$sxFlash = !empty($_SESSION['welcome_flash']) && welcome_discount_available() > 0;
unset($_SESSION['welcome_flash']);
$sxBack = $_SERVER['REQUEST_URI'] ?? '/';
?>
<?php if ($sxShowPopup): ?>
<div class="lume-welcome" id="lume-welcome" role="dialog" aria-modal="true" aria-labelledby="lume-welcome-title" hidden>
    <div class="lume-welcome__backdrop" data-welcome-close></div>
    <div class="lume-welcome__card">
        <button type="button" class="lume-welcome__x" data-welcome-close aria-label="Close">&times;</button>
        <p class="lume-welcome__eyebrow">A welcome gift</p>
        <h2 class="lume-welcome__title" id="lume-welcome-title"><?= h($sxPct) ?>% off your first order</h2>
        <p class="lume-welcome__text"><?= h(setting('welcome_popup_text', 'Create your account and the discount is added to your bag automatically. No code to remember.')) ?></p>
        <a class="lume-btn lume-btn--solid lume-btn--full" href="<?= SITE_URL ?>/account.php?action=register&amp;redirect=<?= urlencode(safe_redirect_path($sxBack, '/')) ?>">Create my account</a>
        <?php if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID): ?>
        <a class="lume-welcome__alt" href="<?= SITE_URL ?>/account.php?action=google&amp;redirect=<?= urlencode(safe_redirect_path($sxBack, '/')) ?>">or continue with Google</a>
        <?php endif; ?>
        <button type="button" class="lume-welcome__skip" data-welcome-close>No thanks</button>
        <p class="lume-welcome__fine">One use per account, on products only (not shipping).</p>
    </div>
</div>
<script>
(function(){
    var KEY = 'adn_welcome_hide_until', el = document.getElementById('lume-welcome');
    if (!el) return;
    try { if (+localStorage.getItem(KEY) > Date.now()) return; } catch (e) {}
    var shown = false, lastFocus = null;
    function show() {
        if (shown || document.querySelector('.lume-cart-drawer.active, .lume-search-overlay.active')) return;
        shown = true; lastFocus = document.activeElement;
        el.hidden = false;
        requestAnimationFrame(function(){ el.classList.add('is-open'); });
        var cta = el.querySelector('.lume-btn'); cta && cta.focus({preventScroll: true});
    }
    function hide() {
        el.classList.remove('is-open');
        setTimeout(function(){ el.hidden = true; }, 300);
        // Don't ask again for a week.
        try { localStorage.setItem(KEY, String(Date.now() + 7 * 864e5)); } catch (e) {}
        lastFocus && lastFocus.focus && lastFocus.focus({preventScroll: true});
    }
    el.addEventListener('click', function(e){ if (e.target.closest('[data-welcome-close]')) hide(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && shown && !el.hidden) hide(); });
    // After a few seconds, or as soon as the visitor scrolls well into the page,
    // or (desktop) when the pointer heads for the tab bar to leave.
    setTimeout(show, <?= max(2, (int)setting('welcome_popup_delay', '8')) ?> * 1000);
    addEventListener('scroll', function onS(){
        if (scrollY > innerHeight * 1.5) { removeEventListener('scroll', onS); show(); }
    }, {passive: true});
    document.addEventListener('mouseout', function(e){ if (!e.relatedTarget && e.clientY < 8) show(); });
})();
</script>
<?php endif; ?>
<?php if ($sxFlash): ?>
<div class="lume-welcome-toast" role="status" id="lume-welcome-toast">
    <strong><?= h($sxPct) ?>% off is in your bag</strong>
    <span>It applies automatically to your first order.</span>
    <button type="button" aria-label="Dismiss" onclick="this.parentNode.remove()">&times;</button>
</div>
<script>setTimeout(function(){ var t = document.getElementById('lume-welcome-toast'); t && t.classList.add('is-gone'); }, 9000);</script>
<?php endif; ?>
