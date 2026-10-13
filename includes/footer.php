<?php
/**
 * LUMEEGY — Shared Footer
 * The marquee section can be toggled via settings.
 */

// Check settings for showing/hiding footer sections
$showMarquee    = setting('show_marquee', '1') === '1';
$marqueeText    = setting('marquee_text', '');
?>
</main><!-- /#main-content -->

<?php if ($showMarquee): ?>
<!-- ═══════════════════════════════════════════
     MARQUEE
═══════════════════════════════════════════ -->
<div class="lume-marquee" aria-hidden="true">
    <div class="lume-marquee__track">
        <?php
        if ($marqueeText) {
            // Custom marquee items from settings (pipe-separated)
            $items = array_map('trim', explode('|', $marqueeText));
        } else {
            $freeShipOver = setting('free_shipping_over', '2000');
            $items = [setting('site_tagline', SITE_TAGLINE), '✦', 'Luxury Fashion', '✦', 'Crafted in Egypt', '✦'];
            if ($freeShipOver > 0) {
                $items[] = 'Free Shipping Over ' . currency_symbol() . number_format((float)$freeShipOver, 0);
                $items[] = '✦';
            }
            $items = array_merge($items, ['New Arrivals', '✦']);
        }
        // Double for infinite scroll effect
        foreach (array_merge($items, $items) as $item):
        ?>
            <span class="lume-marquee__text"><?= h($item) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>



<!-- ═══════════════════════════════════════════
     FOOTER
═══════════════════════════════════════════ -->
<footer class="lume-footer" aria-label="Site footer">
    <div class="lume-footer__grid">
        <div class="lume-footer__col">
            <?php $siteLogo = setting('site_logo'); if ($siteLogo): ?>
                <img src="<?= h(thumb_url($siteLogo, 400)) ?>" alt="<?= h(setting('site_name', SITE_NAME)) ?>" width="160" height="40" loading="lazy" decoding="async" style="max-height:40px;width:auto;margin-bottom:12px;display:block">
            <?php else: ?>
                <p class="lume-logo-text lume-footer__brand-logo"><?= h(setting('site_name', SITE_NAME)) ?></p>
            <?php endif; ?>
            <p class="lume-footer__brand-text">
                <?= nl2br(h(setting('footer_brand_text', 'Fine jewelry for the woman who is her own occasion. Born in Egypt, made for the everyday.'))) ?>
            </p>
            <div class="lume-footer__social">
                <?php $ig = setting('instagram_url'); if ($ig): ?>
                <a href="<?= h($ig) ?>" class="lume-footer__social-link" target="_blank" rel="noopener" aria-label="Instagram">
                    <svg viewBox="0 0 24 24" width="18" height="18"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
                </a>
                <?php endif; ?>
                <?php $tt = setting('tiktok_url'); if ($tt): ?>
                <a href="<?= h($tt) ?>" class="lume-footer__social-link" target="_blank" rel="noopener" aria-label="TikTok">
                    <svg viewBox="0 0 24 24" width="18" height="18"><path d="M9 12a4 4 0 1 0 4 4V4a5 5 0 0 0 5 5"/></svg>
                </a>
                <?php endif; ?>
                <?php $fb = setting('facebook_url'); if ($fb): ?>
                <a href="<?= h($fb) ?>" class="lume-footer__social-link" target="_blank" rel="noopener" aria-label="Facebook">
                    <svg viewBox="0 0 24 24" width="18" height="18"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                </a>
                <?php endif; ?>
                <?php $wa = setting('whatsapp_url'); if ($wa): ?>
                <a href="<?= h($wa) ?>" class="lume-footer__social-link" target="_blank" rel="noopener" aria-label="WhatsApp">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M17.472 14.382c-.301-.15-1.78-.878-2.056-.978-.276-.1-.477-.15-.678.15-.2.3-.776.978-.952 1.179-.176.2-.351.225-.652.075-.301-.15-1.272-.469-2.423-1.496-.896-.798-1.502-1.784-1.678-2.085-.176-.301-.019-.464.131-.614.135-.135.301-.351.451-.526.15-.176.2-.301.3-.501.1-.2.05-.376-.025-.526-.075-.15-.678-1.631-.93-2.235-.244-.588-.493-.509-.678-.518l-.577-.01c-.2 0-.526.075-.802.376s-1.053 1.028-1.053 2.507c0 1.479 1.078 2.906 1.228 3.107.15.2 2.12 3.237 5.136 4.54.717.31 1.277.495 1.713.634.721.23 1.377.197 1.896.12.578-.086 1.78-.727 2.03-1.429.25-.702.25-1.303.176-1.429-.075-.126-.276-.201-.577-.352z"/><path d="M12 2C6.477 2 2 6.477 2 12c0 2.019.537 3.913 1.473 5.55L2 22l4.633-1.424A9.957 9.957 0 0012 22c5.523 0 10-4.477 10-10S17.523 2 12 2zm0 18c-1.792 0-3.473-.474-4.931-1.304l-.353-.201-2.753.846.858-2.684-.222-.361A7.954 7.954 0 014 12c0-4.411 3.589-8 8-8s8 3.589 8 8-3.589 8-8 8z"/></svg>
                </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="lume-footer__col">
            <h3 class="lume-footer__col-title">Shop</h3>
            <?php foreach (get_categories() as $cat): ?>
            <a href="<?= SITE_URL ?>/shop.php?category=<?= h($cat['slug']) ?>" class="lume-footer__link"><?= h($cat['name']) ?></a>
            <?php endforeach; ?>
            <a href="<?= SITE_URL ?>/shop.php" class="lume-footer__link">All Products</a>
        </div>

        <div class="lume-footer__col">
            <h3 class="lume-footer__col-title">Help</h3>
            <a href="<?= SITE_URL ?>/contact.php" class="lume-footer__link">Contact Us</a>
            <a href="<?= SITE_URL ?>/track-order.php" class="lume-footer__link">Track Order</a>
            <a href="<?= SITE_URL ?>/shipping.php" class="lume-footer__link">Shipping Policy</a>
            <a href="<?= SITE_URL ?>/exchange-returns.php" class="lume-footer__link">Exchange &amp; Returns</a>
        </div>

        <div class="lume-footer__col">
            <h3 class="lume-footer__col-title">Company</h3>
            <a href="<?= SITE_URL ?>/about.php" class="lume-footer__link">About <?= h(setting('site_name', SITE_NAME)) ?></a>
            <a href="<?= SITE_URL ?>/privacy.php" class="lume-footer__link">Privacy Policy</a>
            <a href="<?= SITE_URL ?>/terms.php" class="lume-footer__link">Terms of Service</a>
        </div>
    </div>

    <div class="lume-footer__bottom">
        <p>&copy; <?= date('Y') ?> <?= h(setting('site_name', SITE_NAME)) ?>. All rights reserved. &nbsp;|&nbsp;
           <a href="<?= SITE_URL ?>/privacy.php" class="lume-footer__legal-link">Privacy Policy</a> &nbsp;|&nbsp;
           <a href="<?= SITE_URL ?>/terms.php" class="lume-footer__legal-link">Terms of Service</a>
        </p>
    </div>
</footer>

<!-- Scripts -->
<!-- Cache-bust with the file's mtime, matching how header.php loads the CSS.
     Do not use versioned_asset() here: that helper is not defined in
     includes/functions.php, so calling it fatals the request at this line —
     which silently drops this <script> tag and every header button with it. -->
<script src="<?= SITE_URL ?>/assets/js/main.js?v=<?= filemtime(ROOT_PATH.'/assets/js/main.js') ?>"></script>
<?php require __DIR__ . '/storefront-extras.php'; ?>
<style>
/* Elevate content above the background layer */
section, main, .container, footer {
    position: relative;
    z-index: 1;
}

.lume-hieroglyphs-bg {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    z-index: 0; /* Behind relative sections, but above html background */
    pointer-events: none;
    overflow: hidden;
    /* Keep the glyph pulses from invalidating paint for the rest of the page. */
    contain: layout paint;
}

.lume-h-char {
    position: absolute;
    color: var(--gold);
    opacity: 0.05;
    font-family: serif;
    font-size: 2.5rem;
    animation: hieroglyph-wave 6s infinite linear;
}

/* Only `opacity` is animated, so the pulse runs entirely on the compositor.
   This used to animate `color` as well (gold -> #fff), which is a main-thread
   repaint of every glyph on every frame — with ~1000+ glyphs on the page that
   alone was enough to blow the frame budget and make every other animation
   stutter. The brightness flash still reads; it just stays gold.

   Note also: no `will-change` here, deliberately. It was `opacity, color`,
   which asks the browser for one compositor layer per glyph. */
@keyframes hieroglyph-wave {
    0%   { opacity: 0.05; }
    10%  { opacity: 0.40; }
    20%  { opacity: 0.05; }
    100% { opacity: 0.05; }
}
</style>
<script>
window.addEventListener('load', function() {
    // Users who asked for less motion get no animated background at all.
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    /* Hard ceiling on animated elements. The grid below is sized to the whole
       document, so on a long page an uncapped fill produced well over a
       thousand permanently-animating spans. Frame cost scales with element
       count, not with page length, so cap it and spread the same number of
       glyphs across however tall the page happens to be. */
    const MAX_CHARS = 140;

    const bgContainer = document.createElement('div');
    bgContainer.className = 'lume-hieroglyphs-bg';

    const hieroglyphs = [
        '𓀀', '𓀁', '𓀂', '𓀃', '𓀄', '𓀅', '𓀆', '𓀇', '𓀈', '𓀉', 
        '𓃀', '𓃁', '𓃂', '𓃃', '𓃄', '𓃅', '𓃆', '𓃇', '𓃈', '𓃉', 
        '𓄀', '𓄁', '𓄂', '𓄃', '𓄄', '𓄅', '𓄆', '𓄇', '𓄈', '𓄉',
        '𓅀', '𓅁', '𓅂', '𓅃', '𓅄', '𓅅', '𓅆', '𓅇', '𓅈', '𓅉',
        '𓆀', '𓆁', '𓆂', '𓆃', '𓆄', '𓆅', '𓆆', '𓆇', '𓆈', '𓆉'
    ];

    const docHeight = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight);

    const colWidth = 90;
    const rowHeight = 90;
    const numCols = Math.ceil(window.innerWidth / colWidth);
    const numRows = Math.ceil(docHeight / rowHeight);
    const cells = numCols * numRows;
    if (!cells) return;

    /* Chance of filling any given cell. On a short page this stays at the
       original 0.85 (occasional gaps, like worn ancient text); on a tall one
       it thins out so the total lands near MAX_CHARS. */
    const keepProb = Math.min(0.85, MAX_CHARS / cells);

    const frag = document.createDocumentFragment();

    for (let i = 0; i < numCols; i++) {
        for (let j = 0; j < numRows; j++) {
            if (Math.random() > keepProb) continue;

            const span = document.createElement('span');
            span.className = 'lume-h-char';
            span.textContent = hieroglyphs[Math.floor(Math.random() * hieroglyphs.length)];

            // Perfect grid alignment
            const x = i * colWidth;
            const y = j * rowHeight;

            span.style.left = `${x}px`;
            span.style.top = `${y}px`;

            const waveSpeed = 250;
            const baseDelay = x / waveSpeed;
            // Slight delay variation so the wave isn't a rigid perfectly straight line
            const randomDelay = baseDelay + (Math.random() * 0.2);

            span.style.animationDelay = `-${randomDelay % 6}s`;

            frag.appendChild(span);
        }
    }

    // Build off-document, then attach once: one layout pass instead of one per glyph.
    bgContainer.appendChild(frag);
    bgContainer.style.height = docHeight + 'px';
    document.body.prepend(bgContainer);
});
</script>
</body>
</html>