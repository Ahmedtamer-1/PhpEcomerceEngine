<?php
/**
 * Live preview renderer for the page builder.
 *
 * Takes an UNSAVED draft (array of section rows) and returns the HTML the
 * page would produce, for display inside the builder's iframe.
 *
 * Deliberately renders through includes/render-sections.php — the same file
 * the live site uses — so the preview cannot drift from reality. A separate
 * preview renderer would be a second implementation to keep in sync, which is
 * exactly the bug class this whole change is meant to remove.
 *
 * Admin-only: it renders arbitrary posted content, so it must never be
 * reachable by an anonymous visitor.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/section-registry.php';
lume_session_start();

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Unauthorized');
}
admin_enforce_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}

$raw   = file_get_contents('php://input');
$draft = json_decode($raw, true);
if (!is_array($draft)) $draft = [];

// Normalise into the row shape render-sections.php expects. Only known
// section types are allowed through, so a malformed draft cannot reach the
// renderer's switch with something unexpected.
$sections = [];
foreach ($draft as $row) {
    if (!is_array($row)) continue;
    $type = (string)($row['section_type'] ?? '');
    if (!section_type_exists($type)) continue;
    if (isset($row['is_active']) && !$row['is_active']) continue;

    $settings = $row['settings'] ?? [];
    if (is_string($settings)) {
        $decoded = json_decode($settings, true);
        $settings = is_array($decoded) ? $decoded : [];
    }

    $sections[] = [
        'section_type' => $type,
        'title'        => $row['title']       ?? null,
        'subtitle'     => $row['subtitle']    ?? null,
        'content'      => $row['content']     ?? null,
        'image'        => $row['image']       ?? null,
        'button_text'  => $row['button_text'] ?? null,
        'button_url'   => $row['button_url']  ?? null,
        'settings'     => json_encode(is_array($settings) ? $settings : []),
        'is_active'    => 1,
    ];
}

// Some section types query the DB (featured products, categories). Those run
// normally — the preview should show real data.
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Preview</title>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/base.css">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/components.css">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/variants.css">
<?php
/* Theme variables, using the SAME setting keys as includes/header.php.
   These were previously guessed (theme_bg instead of theme_color_bg), so
   every lookup missed and the preview fell back to the stock near-black
   defaults instead of the store's real colours. */
$themeBg        = setting('theme_color_bg', '#0A0A0A');
$themeCream     = setting('theme_color_cream', '#F5F5F0');
$themeGold      = setting('theme_color_gold', '#C8B89A');
$themeAccent    = setting('theme_color_accent', '#C4714A');
$themeMuted     = setting('theme_color_muted', '#888880');
$themeFontHead  = setting('theme_font_heading', 'Bodoni Moda');
$themeFontBody  = setting('theme_font_body', 'Red Hat Display');
$themeFontHeadW = str_replace(';', ',', setting('theme_font_heading_weight', '400;700;900'));
$themeFontBodyW = str_replace(';', ',', setting('theme_font_body_weight', '300;400;500;600'));
$gfHead = urlencode($themeFontHead) . ':ital,wght@0,' . $themeFontHeadW . ';1,400';
$gfBody = urlencode($themeFontBody) . ':wght@' . $themeFontBodyW;
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=<?= $gfHead ?>&family=<?= $gfBody ?>&display=swap" rel="stylesheet">
<style>
    /* Mirrors the :root block in includes/header.php exactly. */
    :root{
        --bg:<?= h($themeBg) ?>;
        --bg-card:<?= h($themeBg) ?>;
        --cream:<?= h($themeCream) ?>;
        --gold:<?= h($themeGold) ?>;
        --terracotta:<?= h($themeAccent) ?>;
        --muted:<?= h($themeMuted) ?>;
        --font-serif:'<?= h($themeFontHead) ?>', serif;
        --font-sans:'<?= h($themeFontBody) ?>', sans-serif;
        --glass:rgba(<?= hexToRgb($themeBg) ?>, 0.25);
        --glass-s:rgba(<?= hexToRgb($themeBg) ?>, 0.75);
        --border:rgba(<?= hexToRgb($themeCream) ?>, 0.08);
    }
    html, body { background: var(--bg); }
    /* The builder controls its own scrolling; no fixed header in here. */
    .lume-header, .lume-grain-overlay { display: none !important; }
    /* Reveal animations rely on IntersectionObserver from main.js, which the
       preview does not load — show everything immediately instead. */
    .lume-reveal, .lume-reveal-left, .lume-reveal-right, .lume-reveal-scale,
    .js-hidden { opacity: 1 !important; transform: none !important; }

    /* Entry animations start at opacity:0 and animate in. In a preview that
       is re-rendered on every keystroke that reads as a blank pane, so pin
       them visible rather than relying on the animation completing. */
    .lume-hero__title, .lume-hero__subtitle, .lume-hero__cta,
    .lume-product-card, .lume-cart-item {
        opacity: 1 !important;
        transform: none !important;
        animation: none !important;
    }

    /* A hero is min-height:100vh — inside a short iframe that is fine, but
       make sure the document itself never collapses to nothing. */
    body { min-height: 100%; }
</style>
<?php
/* The active custom theme from the DB, loaded last — same as header.php does,
   so a theme that restyles sections shows up in the preview too. */
try {
    $t = db()->query("SELECT css_content FROM store_themes WHERE is_active = 1 LIMIT 1")->fetch();
    if ($t && !empty($t['css_content'])) echo "<style>\n" . $t['css_content'] . "\n</style>\n";
} catch (Exception $e) { /* table may not exist */ }
?>
</head>
<body>
<?php
if (!$sections) {
    // Say WHY there is nothing, rather than just showing an empty pane.
    $why = !$draft
        ? 'The builder sent no data (empty request body).'
        : 'All ' . count($draft) . ' section(s) were filtered out — unknown type, or all hidden.';
    echo '<div style="padding:60px 24px;text-align:center;color:#bbb;font-family:system-ui,sans-serif;line-height:1.7">'
       . '<p style="font-size:15px;margin:0 0 8px">Nothing to preview.</p>'
       . '<p style="font-size:12px;color:#888;margin:0">' . h($why) . '</p>'
       . '</div>';
} else {
    try {
        // Surface anything the renderer complains about instead of dying
        // silently and leaving a blank pane.
        $prevLevel = error_reporting(E_ALL);
        ob_start();
        require __DIR__ . '/../includes/render-sections.php';
        $rendered = ob_get_clean();
        error_reporting($prevLevel);

        if (trim(strip_tags($rendered)) === '' && trim($rendered) === '') {
            echo '<div style="padding:60px 24px;text-align:center;color:#c88;font-family:system-ui,sans-serif">'
               . 'The renderer produced no output for these ' . count($sections) . ' section(s).</div>';
        } else {
            echo $rendered;
        }
    } catch (Throwable $e) {
        echo '<pre style="padding:24px;color:#f66;background:#200;font-family:monospace;white-space:pre-wrap;font-size:12px">'
           . h('Preview error: ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine()) . '</pre>';
    }
}
?>
</body>
</html>
