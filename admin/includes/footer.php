
        </div><!-- /.admin-content -->
    </div><!-- /.admin-main -->
</div><!-- /.admin-layout -->

<!-- Scroll to top button -->
<button class="admin-scroll-top" aria-label="Scroll to top">
    <svg viewBox="0 0 24 24"><polyline points="18 15 12 9 6 15"/></svg>
</button>

<!-- Toast container -->
<div class="admin-toast-container"></div>

<!-- Quick Actions FAB Tray -->
<div class="fab-tray" id="fab-tray">
    <div class="fab-tray__actions">
        <a href="<?= SITE_URL ?>/admin/products.php" class="fab-tray__item">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Product
        </a>
        <a href="<?= SITE_URL ?>/admin/orders.php" class="fab-tray__item">
            <svg viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg>
            View Orders
        </a>
        <a href="<?= SITE_URL ?>/admin/ad-spend.php" class="fab-tray__item">
            <svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            Log Ad Spend
        </a>
        <a href="<?= SITE_URL ?>/admin/subscribers.php" class="fab-tray__item">
            <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22 6 12 13 2 6"/></svg>
            Subscribers
        </a>
    </div>
    <button class="fab-tray__trigger" id="fab-tray-trigger" aria-label="Quick Actions">
        <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    </button>
</div>

<!-- Global Admin JS -->
<script>
window.BASE_URL = <?= json_encode(SITE_URL) ?>;
window.CURRENCY = window.CURRENCY || <?= json_encode(currency_symbol()) ?>;
</script>
<script src="<?= SITE_URL ?>/admin/assets/js/admin.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin.js') ?>"></script>
<script src="<?= SITE_URL ?>/admin/assets/js/media-kit.js?v=<?= filemtime(__DIR__ . '/../assets/js/media-kit.js') ?>"></script>
</body>
</html>
