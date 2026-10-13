<?php
/**
 * Admin — Homepage Section Builder
 * Add, remove, reorder, and toggle homepage sections.
 */
require_once __DIR__ . '/includes/auth.php';

// Which page are we building? Same builder, scoped by the `page` column.
$targets     = page_section_targets();
$currentPage = $_GET['page'] ?? 'home';
if (!isset($targets[$currentPage])) $currentPage = 'home';

$pageTitle = $targets[$currentPage] . ' Builder';
$adminPage = 'homepage';

$sections = get_homepage_sections(false, $currentPage);

// Where "Preview" should point for each target.
$previewUrls = [
    'home' => '/', 'about' => '/about.php', 'contact' => '/contact.php',
    'shipping' => '/shipping.php', 'returns' => '/exchange-returns.php',
    'privacy' => '/privacy.php', 'terms' => '/terms.php',
];

require_once __DIR__ . '/includes/header.php';
?>

<!-- Page switcher — one builder, one section system, every page -->
<div class="admin-filter-tabs" style="margin-bottom:18px;flex-wrap:wrap">
    <?php foreach ($targets as $key => $label): ?>
        <a href="?page=<?= h($key) ?>"
           class="admin-filter-tab <?= $key === $currentPage ? 'active' : '' ?>"
           style="text-decoration:none"><?= h($label) ?></a>
    <?php endforeach; ?>
</div>

<div class="admin-toolbar">
    <div class="admin-toolbar__left">
        <span style="font-size:.85rem;color:var(--a-muted)">
            <?= count($sections) ?> section<?= count($sections) === 1 ? '' : 's' ?> on <strong><?= h($targets[$currentPage]) ?></strong>
        </span>
    </div>
    <div class="admin-toolbar__right">
        <a href="<?= SITE_URL . ($previewUrls[$currentPage] ?? '/') ?>" target="_blank" class="admin-btn admin-btn--sm" style="opacity:.7">
            <svg viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            Preview Site
        </a>
        <button class="admin-btn admin-btn--primary" onclick="showAddModal()">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Section
        </button>
    </div>
</div>

<!-- Sections List -->
<div class="hp-sections-list" id="sections-list">
    <?php if (empty($sections)): ?>
    <div class="admin-empty">
        <div class="admin-empty__icon">🏠</div>
        <p class="admin-empty__text">No homepage sections yet. Add your first one!</p>
    </div>
    <?php else: ?>
    <?php foreach ($sections as $i => $s):
        $sett = json_decode($s['settings'] ?? '{}', true) ?: [];
        $typeLabels = [
            'hero' => '🌟 Hero Banner',
            'featured_products' => '📦 Featured Products',
            'brand_story' => '📖 Brand Story',
            'image_banner' => '🖼️ Image Banner',
            'text_block' => '📝 Text Block',
            'category_grid' => '🗂️ Category Grid',
            'social_carousel' => '📸 Social Carousel',
            'testimonials' => '💬 Testimonials',
            'newsletter_cta' => '✉️ Newsletter CTA',
            'video' => '🎬 Video',
        ];
    ?>
    <div class="hp-section-card" data-id="<?= $s['id'] ?>" draggable="true">
        <div class="hp-section-card__drag" title="Drag to reorder">⠿</div>
        <div class="hp-section-card__info">
            <div class="hp-section-card__type"><?= $typeLabels[$s['section_type']] ?? '❓ ' . h($s['section_type']) ?></div>
            <div class="hp-section-card__title"><?= h($s['title'] ?? 'Untitled') ?></div>
            <?php if ($s['subtitle']): ?>
            <div class="hp-section-card__sub"><?= h(mb_substr($s['subtitle'], 0, 80)) ?></div>
            <?php endif; ?>
        </div>
        <div class="hp-section-card__controls">
            <label class="hp-section-toggle">
                <input type="checkbox" <?= $s['is_active'] ? 'checked' : '' ?> onchange="toggleSection(<?= $s['id'] ?>, this)">
                <span class="hp-section-toggle__slider"></span>
            </label>
            <button class="admin-btn admin-btn--sm" onclick="editSection(<?= $s['id'] ?>)">Edit</button>
            <button class="admin-btn admin-btn--sm admin-btn--danger" onclick="deleteSection(<?= $s['id'] ?>)">✕</button>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<p style="text-align:center;margin-top:24px;font-size:.72rem;color:var(--text-muted)">Drag and drop sections to reorder • Toggle to show/hide on the homepage</p>

<!-- Add Section Modal -->
<div class="hp-modal-overlay" id="add-modal" style="display:none">
    <div class="hp-modal">
        <div class="hp-modal__head">
            <h3 id="modal-title">Add Section</h3>
            <button onclick="closeModal()" style="background:none;border:none;color:var(--text-muted);font-size:1.2rem;cursor:pointer">✕</button>
        </div>
        <form id="section-form" onsubmit="saveSection(event)">
            <input type="hidden" name="section_id" id="form-section-id" value="0">

            <div class="admin-form__group">
                <label>Section Type</label>
                <select name="section_type" id="form-type" onchange="onTypeChange()">
                    <option value="hero">🌟 Hero Banner</option>
                    <option value="featured_products">📦 Featured Products</option>
                    <option value="brand_story">📖 Brand Story</option>
                    <option value="image_banner">🖼️ Image Banner</option>
                    <option value="text_block">📝 Text Block</option>
                    <option value="category_grid">🗂️ Category Grid</option>
                    <option value="social_carousel">📸 Social Carousel</option>
                    <option value="testimonials">💬 Testimonials</option>
                    <option value="video">🎬 Video</option>
                </select>
            </div>

            <div class="admin-form__group">
                <label>Title</label>
                <input type="text" name="title" id="form-title" placeholder="Section heading">
            </div>

            <div class="admin-form__group">
                <label>Subtitle / Eyebrow</label>
                <input type="text" name="subtitle" id="form-subtitle" placeholder="Short subtitle text">
            </div>

            <div class="admin-form__group" id="form-content-wrap">
                <label>Content</label>
                <textarea name="content" id="form-content" rows="4" placeholder="Body text or description"></textarea>
            </div>

            <div class="admin-form__group" id="form-image-wrap">
                <label>Image Path</label>
                <div style="display:flex;gap:8px">
                    <input type="text" name="image" id="form-image" placeholder="assets/images/..." style="flex:1">
                    <button type="button" class="admin-btn admin-btn--sm" onclick="openMediaPicker('form-image')">Browse</button>
                </div>
            </div>

            <div class="admin-form__row">
                <div class="admin-form__group">
                    <label>Button Text</label>
                    <input type="text" name="button_text" id="form-btn-text" placeholder="e.g. Shop Now">
                </div>
                <div class="admin-form__group">
                    <label>Button URL</label>
                    <input type="text" name="button_url" id="form-btn-url" placeholder="/shop.php">
                </div>
            </div>

            <div class="admin-form__row">
                <div class="admin-form__group">
                    <label>Padding Top (px)</label>
                    <input type="number" id="form-padding-top" data-setting="padding_top" placeholder="e.g. 120">
                </div>
                <div class="admin-form__group">
                    <label>Padding Bottom (px)</label>
                    <input type="number" id="form-padding-bottom" data-setting="padding_bottom" placeholder="e.g. 120">
                </div>
            </div>

            <div id="dynamic-fields-wrap" style="background: rgba(0,0,0,0.02); padding: 16px; border-radius: 4px; margin-bottom: 16px; border: 0.5px solid var(--border);">
                <!-- Dynamic fields will be injected here via JS -->
            </div>
            <input type="hidden" name="settings" id="form-settings" value="{}">

            <div class="admin-form__check" style="margin-top:8px">
                <input type="checkbox" name="is_active" id="form-active" value="1" checked>
                <label for="form-active">Active (visible on homepage)</label>
            </div>

            <div style="display:flex;gap:12px;margin-top:16px">
                <button type="submit" class="admin-btn admin-btn--primary">Save Section</button>
                <button type="button" class="admin-btn" onclick="closeModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Media Picker Modal (reusable) -->
<div class="hp-modal-overlay" id="media-picker-modal" style="display:none">
    <div class="hp-modal" style="max-width:800px">
        <div class="hp-modal__head">
            <h3>Select Media</h3>
            <button onclick="closeMediaPicker()" style="background:none;border:none;color:var(--text-muted);font-size:1.2rem;cursor:pointer">✕</button>
        </div>
        <div class="media-grid" id="picker-grid" style="max-height:60vh;overflow-y:auto;padding:16px 0"></div>
    </div>
</div>

<style>
.hp-sections-list{display:flex;flex-direction:column;gap:8px}
.hp-section-card{display:flex;align-items:center;gap:16px;height:56px;padding:0 16px;background:var(--bg-surface);border:0.5px solid var(--border);border-radius:var(--radius-sm);transition:all .2s;cursor:grab}
.hp-section-card:hover{border-color:var(--accent)}
.hp-section-card.dragging{opacity:.5;border-style:dashed}
.hp-section-card.drag-over{border-color:var(--amber);background:rgba(200,184,154,.05)}
.hp-section-card__drag{font-size:1.2rem;color:var(--text-muted);cursor:grab;user-select:none;flex-shrink:0;opacity:0.5;transition:opacity 0.2s}
.hp-section-card:hover .hp-section-card__drag{opacity:1}
.hp-section-card__info{flex:1;min-width:0;display:flex;align-items:center;gap:12px}
.hp-section-card__type{font-size:11px;font-family:monospace;text-transform:uppercase;letter-spacing:0.05em;background:var(--bg-primary);padding:4px 8px;border-radius:4px;border:0.5px solid var(--border);color:var(--text-primary)}
.hp-section-card__title{font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text-primary)}
.hp-section-card__sub{font-size:11px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hp-section-card__controls{display:flex;align-items:center;gap:8px;flex-shrink:0;opacity:0;pointer-events:none;transition:opacity 0.2s}
.hp-section-card:hover .hp-section-card__controls, .hp-section-card__controls:focus-within{opacity:1;pointer-events:auto}
.hp-section-toggle{position:relative;width:40px;height:22px;display:inline-block}
.hp-section-toggle input{display:none}
.hp-section-toggle__slider{position:absolute;top:0;left:0;right:0;bottom:0;background:var(--border);border-radius:22px;cursor:pointer;transition:.3s}
.hp-section-toggle__slider::before{content:'';position:absolute;left:3px;bottom:3px;width:16px;height:16px;background:var(--bg-surface);border-radius:50%;transition:.3s;box-shadow:0 1px 2px rgba(0,0,0,0.1)}
.hp-section-toggle input:checked+.hp-section-toggle__slider{background:var(--green)}
.hp-section-toggle input:checked+.hp-section-toggle__slider::before{transform:translateX(18px)}
.hp-modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;z-index:200;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;padding:24px}
.hp-modal{background:var(--bg-surface);border:0.5px solid var(--border);border-radius:var(--radius-sm);padding:32px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;animation:modalIn .3s ease}
@keyframes modalIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.hp-modal__head{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
.hp-modal__head h3{font-size:1rem;font-weight:600}
</style>

<script>
const BASE = '<?= SITE_URL ?>';
let pickerTargetInput = null;

// ── Show Add Modal ──
function showAddModal() {
    document.getElementById('modal-title').textContent = 'Add Section';
    document.getElementById('form-section-id').value = '0';
    document.getElementById('section-form').reset();
    document.getElementById('form-active').checked = true;
    document.getElementById('form-settings').value = '{}';
    onTypeChange();
    document.getElementById('add-modal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('add-modal').style.display = 'none';
}

// Which page this builder is editing. Every call to the sections API is
// scoped by it, so adding a section on About cannot land on the homepage.
const CURRENT_PAGE = <?= json_encode($currentPage) ?>;

// ── Edit Section ──
function editSection(id) {
    fetch(BASE + '/api/sections.php?page=' + encodeURIComponent(CURRENT_PAGE), {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r => r.json()).then(data => {
        const s = data.sections.find(x => x.id === id);
        if (!s) return;
        document.getElementById('modal-title').textContent = 'Edit Section';
        document.getElementById('form-section-id').value = s.id;
        document.getElementById('form-type').value = s.section_type;
        document.getElementById('form-title').value = s.title || '';
        document.getElementById('form-subtitle').value = s.subtitle || '';
        document.getElementById('form-content').value = s.content || '';
        document.getElementById('form-image').value = s.image || '';
        document.getElementById('form-btn-text').value = s.button_text || '';
        document.getElementById('form-btn-url').value = s.button_url || '';
        document.getElementById('form-settings').value = JSON.stringify(s.settings || {});
        onTypeChange();
        // Populate dynamic fields
        const form = document.getElementById('section-form');
        const inputs = form.querySelectorAll('[data-setting]');
        inputs.forEach(input => {
            const key = input.getAttribute('data-setting');
            if (s.settings && s.settings[key] !== undefined) {
                input.value = s.settings[key];
            }
        });
        document.getElementById('form-active').checked = !!s.is_active;
        document.getElementById('add-modal').style.display = 'flex';
    });
}

// ── Save Section ──
function saveSection(e) {
    e.preventDefault();
    
    // Serialize dynamic fields into JSON string
    const form = document.getElementById('section-form');
    const inputs = form.querySelectorAll('[data-setting]');
    let settingsObj = {};
    inputs.forEach(input => {
        if (input.value !== '') {
            settingsObj[input.getAttribute('data-setting')] = input.value;
        }
    });
    document.getElementById('form-settings').value = JSON.stringify(settingsObj);
    
    const fd = new FormData(form);
    const id = fd.get('section_id');
    fd.append('action', id > 0 ? 'update' : 'add');
    fd.append('page', CURRENT_PAGE);
    if (!fd.has('is_active')) fd.append('is_active', '0');

    fetch(BASE + '/api/sections.php', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else alert(d.message || 'Error');
    });
}

// ── Toggle Section ──
function toggleSection(id, el) {
    const fd = new FormData();
    fd.append('action', 'toggle');
    fd.append('section_id', id);
    fetch(BASE + '/api/sections.php', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}});
}

// ── Delete Section ──
function deleteSection(id) {
    if (!confirm('Remove this section from the homepage?')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('section_id', id);
    fetch(BASE + '/api/sections.php', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r => r.json()).then(d => { if (d.success) location.reload(); });
}

// ── Drag & Drop Reorder ──
let dragSrc = null;
document.querySelectorAll('.hp-section-card').forEach(card => {
    card.addEventListener('dragstart', function(e) { dragSrc = this; this.classList.add('dragging'); });
    card.addEventListener('dragend', function() { this.classList.remove('dragging'); });
    card.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('drag-over'); });
    card.addEventListener('dragleave', function() { this.classList.remove('drag-over'); });
    card.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('drag-over');
        if (dragSrc === this) return;
        const list = document.getElementById('sections-list');
        const items = [...list.querySelectorAll('.hp-section-card')];
        const fromIdx = items.indexOf(dragSrc);
        const toIdx = items.indexOf(this);
        if (fromIdx < toIdx) this.after(dragSrc);
        else this.before(dragSrc);
        // Save new order
        const newOrder = [...list.querySelectorAll('.hp-section-card')].map(c => c.dataset.id);
        const fd = new FormData();
        fd.append('action', 'reorder');
        fd.append('order', JSON.stringify(newOrder));
        fetch(BASE + '/api/sections.php', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}});
    });
});

// ── Media Picker ──
let pickerCurrentPage = 1;
let pickerTotalPages = 1;
let pickerIsLoading = false;

function openMediaPicker(inputId) {
    pickerTargetInput = document.getElementById(inputId);
    pickerCurrentPage = 1;
    pickerTotalPages = 1;
    
    const grid = document.getElementById('picker-grid');
    grid.innerHTML = '<div style="text-align:center;padding:24px;color:var(--text-muted)">Loading…</div>';
    document.getElementById('media-picker-modal').style.display = 'flex';
    
    grid.onscroll = null;
    loadMediaPickerPage(1);
    
    grid.onscroll = function() {
        if (grid.scrollTop + grid.clientHeight >= grid.scrollHeight - 100 && !pickerIsLoading && pickerCurrentPage < pickerTotalPages) {
            loadMediaPickerPage(pickerCurrentPage + 1);
        }
    };
}

function loadMediaPickerPage(page) {
    pickerIsLoading = true;
    fetch(BASE + '/api/media.php?page=' + page, {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r => r.json()).then(data => {
        pickerIsLoading = false;
        const grid = document.getElementById('picker-grid');
        
        if (page === 1) {
            if (!data.items || !data.items.length) {
                grid.innerHTML = '<div style="text-align:center;padding:24px;color:var(--text-muted)">No media uploaded yet.</div>';
                return;
            }
            grid.innerHTML = '';
        }
        
        pickerCurrentPage = data.page;
        pickerTotalPages = data.pages;
        
        const html = data.items.map(m => {
            const thumbUrl = BASE + '/thumb.php?src=' + encodeURIComponent(m.filepath) + '&w=200';
            return `
            <div class="media-grid__item" onclick="pickMedia('${m.filepath}')">
                <img src="${thumbUrl}" alt="${m.filename}" loading="lazy">
                <div class="media-grid__item__name">${m.filename}</div>
            </div>
            `;
        }).join('');
        
        grid.insertAdjacentHTML('beforeend', html);
    }).catch(() => { pickerIsLoading = false; });
}

function pickMedia(filepath) {
    if (pickerTargetInput) pickerTargetInput.value = filepath;
    closeMediaPicker();
}

function closeMediaPicker() {
    document.getElementById('media-picker-modal').style.display = 'none';
}

// Close modals on overlay click
document.getElementById('add-modal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
document.getElementById('media-picker-modal').addEventListener('click', function(e) { if (e.target === this) closeMediaPicker(); });

function onTypeChange() {
    const type = document.getElementById('form-type').value;
    const wrap = document.getElementById('dynamic-fields-wrap');
    let html = '';
    
    if (type === 'hero') {
        html += '<div class="admin-form__group"><label>Text Color</label><input type="color" data-setting="text_color" value="#ffffff" style="height:38px;padding:2px"></div>';
        html += '<div class="admin-form__group"><label>Button Color</label><input type="color" data-setting="button_color" value="#ffffff" style="height:38px;padding:2px"></div>';
        html += '<div class="admin-form__group"><label>Text Alignment</label><select data-setting="alignment"><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select></div>';
        html += '<div class="admin-form__group"><label>Overlay Opacity (0.0 to 1.0)</label><input type="number" step="0.1" min="0" max="1" data-setting="overlay" value="0.5"></div>';
        html += '<div class="admin-form__group"><label>Show Particles Effect</label><select data-setting="show_particles"><option value="true">Yes</option><option value="false">No</option></select></div>';
    } else if (type === 'featured_products') {
        html += '<div class="admin-form__group"><label>Product Count</label><input type="number" data-setting="product_count" value="4"></div>';
        html += '<div class="admin-form__group"><label>Specific Category ID (Optional)</label><input type="number" data-setting="category_id" placeholder="Leave empty for all products"></div>';
    } else if (type === 'category_grid') {
        html += '<div class="admin-form__group"><label>Grid Columns</label><select data-setting="columns"><option value="3">3 Columns</option><option value="4">4 Columns</option></select></div>';
    } else if (type === 'brand_story' || type === 'text_block') {
        html += '<div class="admin-form__group"><label>Background Color</label><input type="color" data-setting="bg_color" value="#ffffff" style="height:38px;padding:2px"></div>';
    } else if (type === 'social_carousel') {
        html += '<div class="admin-form__group"><label>Carousel Images (One URL per line)</label><textarea data-setting="images" rows="5" placeholder="/assets/images/social1.jpg\n/assets/images/social2.jpg"></textarea></div>';
    } else if (type === 'testimonials') {
        html += '<div class="admin-form__group"><label>Reviews (One per line: Text | Author | Stars)</label><textarea data-setting="reviews" rows="5" placeholder="Amazing quality! | John Doe | 5\nFits perfectly | Jane Smith | 4"></textarea></div>';
    } else if (type === 'video') {
        html += '<div class="admin-form__group"><label>Video URL</label>'
              + '<input type="text" data-setting="video_url" placeholder="https://youtu.be/… or assets/videos/clip.mp4">'
              + '<span class="admin-form__hint">Paste a YouTube or Vimeo link, or the path to an uploaded .mp4 / .webm. '
              + 'You can also leave this empty and pick the file with the Image button above.</span></div>';
        html += '<div class="admin-form__group"><label>Poster Image (optional)</label>'
              + '<input type="text" data-setting="poster" placeholder="assets/images/poster.jpg">'
              + '<span class="admin-form__hint">Shown before the video loads. Self-hosted files only.</span></div>';
        html += '<div class="admin-form__group"><label>Autoplay</label>'
              + '<select data-setting="autoplay"><option value="">No — show player controls</option>'
              + '<option value="1">Yes — muted, looping background video</option></select>'
              + '<span class="admin-form__hint">Autoplay only works for uploaded files, not YouTube/Vimeo, and is always muted (browsers require it).</span></div>';
    } else {
        html = '<div style="font-size:0.8rem;color:var(--text-muted)">No specific extra settings for this section type.</div>';
    }
    
    wrap.innerHTML = '<h4 style="font-size:0.85rem;margin-bottom:12px;text-transform:uppercase;color:var(--text-muted)">Specific Settings</h4>' + html;
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
