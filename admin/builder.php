<?php
/**
 * Visual page builder — full screen, Shopify-style.
 *
 *   top bar   exit · page picker · device sizes · undo/redo · preview · save
 *   left      the page's sections: drag to reorder, eye to hide, add new
 *   centre    the real page (rendered by api/section-preview.php through the
 *             same includes/render-sections.php the site uses). Hover a
 *             section to see what it is, click it to edit it.
 *   right     the selected section's settings, generated from
 *             includes/section-registry.php, with media pickers, switches,
 *             sliders and list editors.
 *
 * Nothing is saved until "Save". "Preview" opens the draft as a full
 * storefront page in a new tab so it can be browsed first.
 * Logic lives in admin/assets/js/builder.js.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/section-registry.php';

$targets     = page_section_targets();
$currentPage = $_GET['page'] ?? 'home';
if (!isset($targets[$currentPage])) $currentPage = 'home';

$initial = [];
foreach (get_homepage_sections(false, $currentPage) as $s) {
    $initial[] = [
        'id'           => (int)$s['id'],
        'section_type' => $s['section_type'],
        'title'        => $s['title'],
        'subtitle'     => $s['subtitle'],
        'content'      => $s['content'],
        'image'        => $s['image'],
        'button_text'  => $s['button_text'],
        'button_url'   => $s['button_url'],
        'settings'     => json_decode($s['settings'] ?? '{}', true) ?: (object)[],
        'is_active'    => (int)$s['is_active'],
    ];
}

// Choices for "category" fields and suggestions for link fields.
$categories = [];
try { $categories = db()->query('SELECT id, name, slug FROM categories ORDER BY name')->fetchAll(); } catch (Exception $e) {}
$links = [
    ['shop.php', 'Shop — all products'], ['about.php', 'About'], ['contact.php', 'Contact'],
    ['track-order.php', 'Track order'], ['shipping.php', 'Shipping policy'], ['exchange-returns.php', 'Exchange & returns'],
    ['account.php?action=register', 'Create account'],
];
foreach ($categories as $c) $links[] = ['shop.php?category=' . $c['slug'], 'Shop — ' . $c['name']];

$siteBg = setting('theme_color_bg', '#0A0A0A');
$v = fn(string $f) => filemtime(__DIR__ . '/assets/' . $f);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($targets[$currentPage]) ?> — Builder — <?= h(setting('site_name', SITE_NAME)) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php require __DIR__ . '/includes/csrf-script.php'; ?>
    <link rel="stylesheet" href="<?= SITE_URL ?>/admin/assets/css/admin.css?v=<?= $v('css/admin.css') ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/admin/assets/css/glass.css?v=<?= $v('css/glass.css') ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/admin/assets/css/media-kit.css?v=<?= $v('css/media-kit.css') ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/admin/assets/css/builder.css?v=<?= $v('css/builder.css') ?>">
    <style>.bx-canvas{--site-bg:<?= h($siteBg) ?>}</style>
</head>
<body class="bx-body">
<div class="bx" id="bx">
    <header class="bx-top">
        <a class="bx-exit" href="<?= SITE_URL ?>/admin/" data-tip="Back to the admin" aria-label="Exit builder">
            <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg><span>Exit</span>
        </a>
        <div class="bx-page">
            <label for="bx-page" class="bx-sr">Page</label>
            <select id="bx-page" data-tip="Switch page">
                <?php foreach ($targets as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= $key === $currentPage ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="bx-dirty" id="bx-dirty" hidden>Unsaved changes</span>
        </div>
        <div class="bx-devices" role="group" aria-label="Preview size">
            <button type="button" data-w="100%" class="is-on" data-tip="Desktop"><svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="13" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg></button>
            <button type="button" data-w="820px" data-tip="Tablet"><svg viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="11" y1="18" x2="13" y2="18"/></svg></button>
            <button type="button" data-w="390px" data-tip="Phone"><svg viewBox="0 0 24 24"><rect x="7" y="2" width="10" height="20" rx="2"/><line x1="11" y1="18" x2="13" y2="18"/></svg></button>
        </div>
        <div class="bx-actions">
            <button type="button" class="bx-icon" id="bx-undo" data-tip="Undo (Ctrl+Z)" disabled><svg viewBox="0 0 24 24"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg></button>
            <button type="button" class="bx-icon" id="bx-redo" data-tip="Redo (Ctrl+Shift+Z)" disabled><svg viewBox="0 0 24 24"><polyline points="15 14 20 9 15 4"/><path d="M4 20v-7a4 4 0 0 1 4-4h12"/></svg></button>
            <span class="bx-status" id="bx-status" role="status"></span>
            <button type="button" class="admin-btn admin-btn--sm" id="bx-preview" data-tip="Open this page with your changes in a new tab, to browse before saving">
                <svg viewBox="0 0 24 24" width="14" height="14"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> Preview
            </button>
            <button type="button" class="admin-btn admin-btn--primary admin-btn--sm" id="bx-save" data-tip="Publish changes (Ctrl+S)">Save</button>
        </div>
    </header>

    <aside class="bx-left" aria-label="Sections">
        <div class="bx-panel-head"><span><?= h($targets[$currentPage]) ?></span><span class="bx-count" id="bx-count"></span></div>
        <div class="bx-list" id="bx-list"></div>
        <div class="bx-addwrap">
            <button type="button" class="bx-add" id="bx-add">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                Add section
            </button>
        </div>
    </aside>

    <main class="bx-canvas" id="bx-canvas">
        <div class="bx-frame-wrap" id="bx-frame-wrap">
            <iframe id="bx-frame" title="Page preview"></iframe>
            <div class="bx-loading" id="bx-loading"><span></span></div>
        </div>
    </main>

    <aside class="bx-right" aria-label="Section settings">
        <div id="bx-inspector"></div>
    </aside>
</div>

<!-- Add-section picker -->
<div class="bx-modal" id="bx-addmodal" hidden>
    <div class="bx-modal__card" role="dialog" aria-modal="true" aria-labelledby="bx-add-title">
        <div class="bx-modal__head"><h3 id="bx-add-title">Add a section</h3><button type="button" class="mk__x" data-close aria-label="Close">×</button></div>
        <div class="bx-types" id="bx-types"></div>
    </div>
</div>

<div class="bx-tip" id="bx-tip" role="tooltip" hidden></div>
<div class="admin-toast-container"></div>

<script>
window.BASE_URL = <?= json_encode(SITE_URL) ?>;
window.CURRENCY = <?= json_encode(currency_symbol()) ?>;
window.BX = {
    page:     <?= json_encode($currentPage) ?>,
    pages:    <?= json_encode($targets, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    registry: <?= json_encode(section_registry(), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    blocks:   <?= json_encode($initial, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    categories: <?= json_encode(array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], $categories), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    links:    <?= json_encode($links, JSON_HEX_TAG | JSON_HEX_AMP) ?>
};
</script>
<script src="<?= SITE_URL ?>/admin/assets/js/admin.js?v=<?= $v('js/admin.js') ?>"></script>
<script src="<?= SITE_URL ?>/admin/assets/js/media-kit.js?v=<?= $v('js/media-kit.js') ?>"></script>
<script src="<?= SITE_URL ?>/admin/assets/js/builder.js?v=<?= $v('js/builder.js') ?>"></script>
</body>
</html>
