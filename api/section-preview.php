<?php
/**
 * Live preview renderer for the page builder.
 *
 * Takes an UNSAVED draft (array of section rows) and renders it through
 * includes/render-sections.php — the same file the live site uses — so the
 * preview cannot drift from reality.
 *
 * Modes:
 *   POST JSON [rows]              ?builder=1  → the builder canvas: each section
 *                                               wrapped so it can be hovered,
 *                                               clicked and edited in place
 *   POST JSON {i, section}        ?fragment=1 → just one section's HTML, so an
 *                                               edit refreshes that section
 *                                               without reloading the page
 *   POST form mode=full, sections, page       → the whole storefront page
 *                                               (header, footer, links) with
 *                                               the draft in it, to browse in
 *                                               a new tab before saving
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

$isFull     = ($_POST['mode'] ?? '') === 'full';
$isFragment = !empty($_GET['fragment']);
$isBuilder  = !empty($_GET['builder']);
// Tag text and pictures with their settings so they can be edited in place
// (see bld() in includes/functions.php). Never on the full-page preview.
$GLOBALS['BLD_EDIT'] = ($isBuilder || $isFragment) && !$isFull;

if ($isFull) {
    $draft = json_decode((string)($_POST['sections'] ?? '[]'), true);
} else {
    $draft = json_decode((string)file_get_contents('php://input'), true);
}
if (!is_array($draft)) $draft = [];

/** Normalise one draft row into what render-sections.php expects, or null. */
function preview_row($row): ?array
{
    if (!is_array($row)) return null;
    $type = (string)($row['section_type'] ?? '');
    if (!section_type_exists($type)) return null;
    $settings = $row['settings'] ?? [];
    if (is_string($settings)) {
        $decoded = json_decode($settings, true);
        $settings = is_array($decoded) ? $decoded : [];
    }
    return [
        'id'           => (int)($row['id'] ?? 0),
        'section_type' => $type,
        'title'        => $row['title']       ?? null,
        'subtitle'     => $row['subtitle']    ?? null,
        'content'      => $row['content']     ?? null,
        'image'        => $row['image']       ?? null,
        'button_text'  => $row['button_text'] ?? null,
        'button_url'   => $row['button_url']  ?? null,
        'settings'     => json_encode(is_array($settings) ? $settings : []),
        'is_active'    => empty($row['is_active']) && isset($row['is_active']) ? 0 : 1,
    ];
}

/** Render one section; in builder mode wrapped so the canvas can find it. */
function preview_render(array $row, int $i, bool $wrap): string
{
    $sections = [$row];
    $prev = error_reporting(E_ALL);
    ob_start();
    try {
        require __DIR__ . '/../includes/render-sections.php';
        $html = ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        $html = '<pre style="padding:24px;color:#f66;background:#200;font:12px monospace;white-space:pre-wrap">'
              . h('Preview error: ' . $e->getMessage()) . '</pre>';
    }
    error_reporting($prev);
    if (!$wrap) return $html;

    $def = section_registry()[$row['section_type']] ?? ['label' => $row['section_type'], 'icon' => ''];
    if (trim(strip_tags($html, '<img><video><iframe>')) === '') {
        // Sections with nothing to show yet (e.g. a video with no file) still
        // need something to click on.
        $html = '<div class="bld-empty">' . h($def['icon'] . ' ' . $def['label']) . ' — nothing to show yet. Fill in its settings on the right.</div>';
    }
    $name = trim($def['icon'] . ' ' . $def['label'] . (!empty($row['title']) ? ' · ' . mb_strimwidth((string)$row['title'], 0, 40, '…') : ''));
    return '<div class="bld-sec" data-bld-i="' . $i . '" data-bld-name="' . h($name) . '">' . $html . '</div>';
}

// ── One section only ──
if ($isFragment) {
    header('Content-Type: text/html; charset=utf-8');
    $row = preview_row($draft['section'] ?? null);
    echo $row ? preview_render($row, (int)($draft['i'] ?? 0), true) : '';
    exit;
}

$rows = [];
foreach (array_values($draft) as $i => $r) {
    $row = preview_row($r);
    if ($row && $row['is_active']) $rows[$i] = $row;
}

// ── Whole storefront page, to browse before saving ──
if ($isFull) {
    $targets = page_section_targets();
    $pk = $_POST['page'] ?? 'home';
    $pageTitle = 'Preview — ' . ($targets[$pk] ?? 'Page') . ' — ' . setting('site_name', SITE_NAME);
    require __DIR__ . '/../includes/header.php';
    foreach ($rows as $i => $row) echo preview_render($row, $i, false);
    ?>
    <div style="position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:99990;display:flex;align-items:center;gap:14px;padding:10px 12px 10px 18px;border-radius:40px;background:#111;color:#fff;font:500 13px system-ui,sans-serif;box-shadow:0 10px 40px rgba(0,0,0,.5);max-width:calc(100% - 32px)" id="bld-preview-bar">
        <span>Previewing <strong><?= h($targets[$pk] ?? 'page') ?></strong> with unsaved changes. Links open the live site.</span>
        <button type="button" onclick="window.close()" style="border:0;border-radius:20px;padding:6px 14px;background:#fff;color:#111;font:600 12px system-ui;cursor:pointer">Close preview</button>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

// ── Builder canvas ──
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
$themeBg        = setting('theme_color_bg', '#0A0A0A');
$themeCream     = setting('theme_color_cream', '#F5F5F0');
$themeGold      = setting('theme_color_gold', '#C8B89A');
$themeAccent    = setting('theme_color_accent', '#C4714A');
$themeMuted     = setting('theme_color_muted', '#888880');
$themeFontHead  = setting('theme_font_heading', 'Bodoni Moda');
$themeFontBody  = setting('theme_font_body', 'Red Hat Display');
$googleFontsUrl = google_fonts_url($themeFontHead, setting('theme_font_heading_weight', '400;700;900'), $themeFontBody, setting('theme_font_body_weight', '300;400;500;600'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Preview</title>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/base.css?v=<?= filemtime(ROOT_PATH . '/assets/css/base.css') ?>">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/components.css?v=<?= filemtime(ROOT_PATH . '/assets/css/components.css') ?>">
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/variants.css?v=<?= filemtime(ROOT_PATH . '/assets/css/variants.css') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="<?= h($googleFontsUrl) ?>" rel="stylesheet">
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
    .lume-header, .lume-grain-overlay { display: none !important; }
    /* Reveal/entry animations need main.js; show everything immediately. */
    .lume-reveal, .lume-reveal-left, .lume-reveal-right, .lume-reveal-scale,
    .js-hidden { opacity: 1 !important; transform: none !important; }
    .lume-hero__title, .lume-hero__subtitle, .lume-hero__cta,
    .lume-product-card, .lume-cart-item { opacity: 1 !important; transform: none !important; animation: none !important; }
    body { min-height: 100%; }

    /* ── Builder canvas helpers ── */
    .bld-sec { display: contents; }
    .bld-empty { margin: 16px; padding: 40px 20px; border: 2px dashed rgba(255,255,255,.25); border-radius: 10px;
        text-align: center; color: #bbb; font: 500 14px system-ui, sans-serif; }
    .bld-box { position: absolute; z-index: 2147483000; pointer-events: none; border: 2px solid #3b82f6; border-radius: 2px; transition: opacity .12s; }
    .bld-box--hover { border-style: dashed; border-color: rgba(59,130,246,.85); }
    .bld-tag { position: absolute; left: -2px; top: -2px; transform: translateY(-100%); background: #3b82f6; color: #fff;
        font: 600 11.5px/1 system-ui, sans-serif; padding: 5px 8px; border-radius: 5px 5px 0 0; white-space: nowrap; }
    .bld-box.is-top .bld-tag { transform: none; border-radius: 0 0 5px 0; }
    .bld-tools { position: absolute; right: -2px; top: -2px; transform: translateY(-100%); display: flex; gap: 1px; pointer-events: auto;
        background: #3b82f6; border-radius: 5px 5px 0 0; overflow: hidden; }
    .bld-box.is-top .bld-tools { transform: none; border-radius: 0 0 0 5px; }
    .bld-tools button { all: unset; cursor: pointer; color: #fff; font: 600 12px/1 system-ui, sans-serif; padding: 5px 8px; }
    .bld-tools button:hover { background: rgba(0,0,0,.2); }
    /* In-place editing */
    .bld-sec [data-bld-k="text"], .bld-sec [data-bld-k="multi"], .bld-sec [data-bld-k="para"] { cursor: text; }
    .bld-sec [data-bld-k="text"]:hover, .bld-sec [data-bld-k="multi"]:hover, .bld-sec [data-bld-k="para"]:hover {
        outline: 1px dashed rgba(59,130,246,.9); outline-offset: 4px; }
    .bld-editing { outline: 2px solid #3b82f6 !important; outline-offset: 4px; cursor: text !important;
        background: rgba(59,130,246,.08); border-radius: 2px; }
    .bld-hint { position: absolute; z-index: 2147483001; pointer-events: none; padding: 4px 8px; border-radius: 5px;
        background: #111; color: #fff; font: 500 11px/1.3 system-ui, sans-serif; white-space: nowrap; transform: translate(-50%, calc(-100% - 10px)); }
    .bld-media-btn { all: unset; position: absolute; z-index: 2147483001; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
        padding: 7px 12px; border-radius: 20px; background: rgba(17,17,17,.88); color: #fff; font: 600 12px/1 system-ui, sans-serif;
        box-shadow: 0 4px 14px rgba(0,0,0,.35); border: 1px solid rgba(255,255,255,.25); white-space: nowrap; }
    .bld-media-btn:hover { background: #3b82f6; border-color: #3b82f6; }
    .bld-media-target { outline: 2px solid #3b82f6; outline-offset: -2px; }
    .bld-canvas-empty { padding: 120px 24px; text-align: center; color: #bbb; font: 15px/1.7 system-ui, sans-serif; }
</style>
<?php
try {
    $t = db()->query("SELECT css_content FROM store_themes WHERE is_active = 1 LIMIT 1")->fetch();
    if ($t && !empty($t['css_content'])) echo "<style>\n" . $t['css_content'] . "\n</style>\n";
} catch (Exception $e) { /* table may not exist */ }
?>
</head>
<body>
<?php
if (!$rows) {
    echo '<div class="bld-canvas-empty">' . ($draft ? 'Every section on this page is hidden.<br>Turn one back on with its eye button.' : 'This page has no sections yet.<br>Use <strong>Add section</strong> on the left to start.') . '</div>';
} else {
    foreach ($rows as $i => $row) echo preview_render($row, $i, $isBuilder);
}
if ($isBuilder): ?>
<script>
/* Builder canvas: hover shows what a section is, click selects it, and the
   small toolbar on the selected section moves / hides / copies / deletes it.
   Talks to the builder through window.parent.__bldHost. */
(function(){
  const host = (m) => { try { window.parent.__bldHost && window.parent.__bldHost(m); } catch (e) {} };
  const layer = document.createElement('div');
  layer.style.cssText = 'position:absolute;left:0;top:0;width:0;height:0;';
  document.body.appendChild(layer);
  const hover = document.createElement('div'); hover.className = 'bld-box bld-box--hover'; hover.innerHTML = '<span class="bld-tag"></span>';
  const sel = document.createElement('div'); sel.className = 'bld-box'; sel.innerHTML = '<span class="bld-tag"></span><span class="bld-tools">'
    + '<button data-a="up" title="Move up">↑</button><button data-a="down" title="Move down">↓</button>'
    + '<button data-a="dup" title="Duplicate">⧉</button><button data-a="hide" title="Hide section">Hide</button>'
    + '<button data-a="del" title="Delete">✕</button></span>';
  layer.append(hover, sel);
  hover.style.opacity = sel.style.opacity = 0;
  let hoverI = -1, selI = -1;

  const wrap = i => document.querySelector('.bld-sec[data-bld-i="' + i + '"]');
  function rectOf(w) {
    let t = Infinity, l = Infinity, b = -Infinity, r = -Infinity;
    const kids = w ? w.children : [];
    for (const k of kids) {
      const cs = getComputedStyle(k);
      if (cs.position === 'fixed' || cs.display === 'none') continue;
      const q = k.getBoundingClientRect();
      if (!q.width && !q.height) continue;
      t = Math.min(t, q.top); l = Math.min(l, q.left); b = Math.max(b, q.bottom); r = Math.max(r, q.right);
    }
    return t === Infinity ? null : { top: t + scrollY, left: l + scrollX, width: r - l, height: b - t };
  }
  function place(box, i) {
    const w = wrap(i), q = rectOf(w);
    if (!q) { box.style.opacity = 0; return; }
    Object.assign(box.style, { top: q.top + 'px', left: Math.max(0, q.left) + 'px', width: Math.min(q.width, document.documentElement.clientWidth) - 4 + 'px', height: q.height - 4 + 'px', opacity: 1 });
    // Keep the name tag and toolbar visible while the section's top is
    // scrolled out of view.
    const off = scrollY - q.top;
    box.classList.toggle('is-top', off > -26);
    const pin = off > 0 ? Math.min(off, q.height - 30) + 'px' : '';
    box.querySelectorAll('.bld-tag, .bld-tools').forEach(el => { el.style.top = pin; });
    box.querySelector('.bld-tag').textContent = w.dataset.bldName || '';
  }
  function redraw() {
    if (hoverI >= 0 && hoverI !== selI) place(hover, hoverI); else hover.style.opacity = 0;
    if (selI >= 0) place(sel, selI); else sel.style.opacity = 0;
  }
  let raf = 0; const soon = () => { cancelAnimationFrame(raf); raf = requestAnimationFrame(redraw); };
  addEventListener('scroll', soon, { passive: true });
  addEventListener('resize', soon);
  new ResizeObserver(soon).observe(document.body);
  document.addEventListener('load', soon, true); // images finishing

  document.addEventListener('mousemove', e => {
    const w = e.target.closest && e.target.closest('.bld-sec');
    const i = w ? +w.dataset.bldI : -1;
    if (i !== hoverI) { hoverI = i; redraw(); host({ type: 'hover', i }); }
  });
  document.addEventListener('mouseleave', () => { hoverI = -1; redraw(); host({ type: 'hover', i: -1 }); });
  // Clicks select; links, buttons and forms never navigate inside the canvas.
  document.addEventListener('click', e => {
    if (e.target.closest('.bld-media-btn')) return;
    if (editing && editing.el.contains(e.target)) { e.preventDefault(); return; }
    const tool = e.target.closest('.bld-tools button');
    e.preventDefault(); e.stopPropagation();
    if (tool) { host({ type: 'tool', act: tool.dataset.a, i: selI }); return; }
    const w = e.target.closest('.bld-sec');
    if (w) host({ type: 'select', i: +w.dataset.bldI });
  }, true);
  document.addEventListener('submit', e => e.preventDefault(), true);

  // ── In-place editing ──────────────────────────────────────────
  const ask = m => { try { return window.parent.__bldHost && window.parent.__bldHost(m); } catch (e) {} };
  const secOf = el => el.closest('.bld-sec');
  const target = el => ({ i: +secOf(el).dataset.bldI, key: el.dataset.bldF, kind: el.dataset.bldK,
      row: el.dataset.bldRow != null ? +el.dataset.bldRow : null, col: el.dataset.bldCol != null ? +el.dataset.bldCol : null });
  const TEXT_KINDS = ['text', 'multi', 'para'];
  const hint = document.createElement('div'); hint.className = 'bld-hint'; hint.style.display = 'none'; layer.append(hint);
  let editing = null;

  // Hover: say what double-clicking will do.
  document.addEventListener('mouseover', e => {
    if (editing) return;
    const el = e.target.closest && e.target.closest('[data-bld-k]');
    if (!el || !TEXT_KINDS.includes(el.dataset.bldK)) { hint.style.display = 'none'; return; }
    const r = el.getBoundingClientRect();
    hint.textContent = 'Double-click to edit text';
    hint.style.left = (r.left + r.width / 2 + scrollX) + 'px';
    hint.style.top = (r.top + scrollY) + 'px';
    hint.style.display = '';
  });

  function startEdit(el) {
    if (editing) finishEdit(true);
    const t = target(el);
    const raw = ask({ type: 'get', ...t });
    editing = { el, t, html: el.innerHTML };
    hint.style.display = 'none';
    // Edit the stored text (not the on-screen version, which CSS may have
    // turned into capitals), keeping line breaks visible while typing.
    el.textContent = (raw != null && raw !== '') ? raw : el.textContent.trim();
    el.style.whiteSpace = t.kind === 'text' ? '' : 'pre-wrap';
    try { el.contentEditable = 'plaintext-only'; } catch (e) { el.contentEditable = 'true'; }
    if (el.contentEditable !== 'plaintext-only') el.contentEditable = 'true';
    el.classList.add('bld-editing');
    el.focus();
    const sel = getSelection(), rg = document.createRange();
    rg.selectNodeContents(el); rg.collapse(false); sel.removeAllRanges(); sel.addRange(rg);
  }
  function finishEdit(save) {
    if (!editing) return;
    const { el, t, html } = editing;
    editing = null;
    let val = el.textContent.replace(/\u00a0/g, ' ');
    if (t.kind === 'text') val = val.replace(/\s*\n\s*/g, ' ').trim();
    else val = val.replace(/\r/g, '').replace(/\n{3,}/g, '\n\n').trim();
    el.contentEditable = 'false'; el.removeAttribute('contenteditable');
    el.classList.remove('bld-editing'); el.style.whiteSpace = '';
    if (!save) { el.innerHTML = html; return; }
    ask({ type: 'text', ...t, value: val }); // the builder re-renders this section
  }
  document.addEventListener('dblclick', e => {
    const el = e.target.closest && e.target.closest('[data-bld-k]');
    if (!el || !TEXT_KINDS.includes(el.dataset.bldK)) return;
    e.preventDefault();
    host({ type: 'select', i: +secOf(el).dataset.bldI });
    startEdit(el);
  }, true);
  document.addEventListener('keydown', e => {
    if (!editing) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); host({ type: 'save' }); }
      return;
    }
    if (e.key === 'Escape') { e.preventDefault(); finishEdit(false); }
    else if (e.key === 'Enter' && (editing.t.kind === 'text' || e.ctrlKey || e.metaKey)) { e.preventDefault(); finishEdit(true); }
    else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); finishEdit(true); host({ type: 'save' }); }
  }, true);
  document.addEventListener('focusout', e => { if (editing && e.target === editing.el) finishEdit(true); });
  // Clicking outside the text being edited finishes it.
  document.addEventListener('mousedown', e => { if (editing && !editing.el.contains(e.target)) finishEdit(true); }, true);

  // Pictures and videos: a "Change" button on the ones in the hovered section.
  let mediaFor = -1;
  const mediaBtns = [];
  function clearMedia() { mediaBtns.splice(0).forEach(b => { b._el.classList.remove('bld-media-target'); b.remove(); }); mediaFor = -1; }
  function showMedia(i) {
    if (i === mediaFor) return;
    clearMedia();
    mediaFor = i;
    const w = wrap(i); if (!w) return;
    const vw = document.documentElement.clientWidth;
    w.querySelectorAll('[data-bld-k="image"],[data-bld-k="video"],[data-bld-k="bg"]').forEach(el => {
      const r = el.getBoundingClientRect();
      if (r.width < 40 || r.height < 30 || r.right < 0 || r.left > vw) return; // hidden or off-screen slide
      const k = el.dataset.bldK;
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'bld-media-btn'; b._el = el;
      const ico = k === 'video'
        ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><polygon points="10 9 15 12 10 15" fill="currentColor"/></svg>'
        : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
      b.innerHTML = ico + (k === 'video' ? 'Change video' : k === 'bg' ? 'Change background' : 'Change image');
      const big = r.height > 160 && r.width > 220;
      b.style.left = (big ? r.left + 14 : r.left + 6) + scrollX + 'px';
      b.style.top = (big ? r.top + Math.max(14, Math.min(r.height - 50, 56)) : r.top + 6) + scrollY + 'px';
      b.addEventListener('mouseenter', () => el.classList.add('bld-media-target'));
      b.addEventListener('mouseleave', () => el.classList.remove('bld-media-target'));
      b.addEventListener('click', ev => {
        ev.preventDefault(); ev.stopPropagation();
        host({ type: 'select', i });
        host({ type: 'media', ...target(el) });
      });
      layer.append(b); mediaBtns.push(b);
    });
  }
  document.addEventListener('mousemove', e => {
    if (e.target.closest && e.target.closest('.bld-media-btn')) return;
    const w = e.target.closest && e.target.closest('.bld-sec');
    if (w) showMedia(+w.dataset.bldI); else clearMedia();
  });
  addEventListener('scroll', () => { const i = mediaFor; mediaFor = -1; if (i >= 0) showMedia(i); }, { passive: true });

  // Muted looping videos play in the canvas, as they would on the site.
  const playVideos = root => root.querySelectorAll('video[data-lazy-video]').forEach(v => { v.muted = true; const p = v.play(); p && p.catch(() => {}); });
  playVideos(document);

  window.__bld = {
    select(i, scroll) {
      selI = i; redraw();
      const q = rectOf(wrap(i));
      if (scroll && q) {
        const vh = innerHeight;
        if (q.top < scrollY + 40 || q.top > scrollY + vh - 120) scrollTo({ top: Math.max(0, q.top - 60), behavior: 'smooth' });
      }
    },
    highlight(i) { hoverI = i; redraw(); },
    replace(i, html) {
      const old = wrap(i); if (!old) return false;
      const t = document.createElement('template'); t.innerHTML = html.trim();
      const neu = t.content.firstElementChild; if (!neu) return false;
      old.replaceWith(neu); playVideos(neu); soon();
      return true;
    }
  };
  host({ type: 'ready' });
})();
</script>
<?php endif; ?>
</body>
</html>
