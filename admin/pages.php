<?php
/**
 * Admin Pages — Content Management for Static Pages
 */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Pages Content';
$adminPage = 'pages';

$success = '';
$error   = '';

$pageSettings = [
    'page_about_header_title', 'page_about_hero_image', 'page_about_eyebrow', 'page_about_title', 'page_about_text',
    'page_about_values_eyebrow', 'page_about_values_title',
    'page_about_value_1_title', 'page_about_value_1_text',
    'page_about_value_2_title', 'page_about_value_2_text',
    'page_about_value_3_title', 'page_about_value_3_text',
    'page_contact_eyebrow', 'page_contact_title', 'page_contact_text',
    'page_privacy_content', 'page_terms_content', 'page_shipping_content', 'page_returns_content'
];

// Handle form save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt = db()->prepare('INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        foreach ($pageSettings as $key) {
            if (isset($_POST[$key])) {
                $val = trim($_POST[$key]);
                $stmt->execute([$key, $val]);
            }
        }
        log_activity('update_page_content', 'system');
        $success = 'Page content saved successfully.';
    } catch (Exception $e) {
        $error = 'Error saving content: ' . $e->getMessage();
    }
}

// Load current values
$settings = [];
$rows = db()->query('SELECT key_name, value FROM settings WHERE key_name LIKE "page_%"')->fetchAll();
foreach ($rows as $r) {
    $settings[$r['key_name']] = $r['value'];
}
$s = function(string $key, string $default = '') use ($settings) {
    return $settings[$key] ?? $default;
};

require_once __DIR__ . '/includes/header.php';
?>

<!-- Include Quill.js for Rich Text Editing -->
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>

<style>
.admin-tabs { display: flex; gap: 16px; margin-bottom: 24px; border-bottom: 1px solid var(--a-border); padding-bottom: 12px; }
.admin-tab-btn { background: none; border: none; color: var(--a-muted); font-size: .85rem; text-transform: uppercase; letter-spacing: .1em; padding: 8px 16px; cursor: pointer; transition: color .3s; font-weight: 600; }
.admin-tab-btn:hover { color: var(--a-text); }
.admin-tab-btn.active { color: var(--a-accent); border-bottom: 2px solid var(--a-accent); margin-bottom: -13px; }
.admin-tab-content { display: none; }
.admin-tab-content.active { display: block; animation: fadeIn .3s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
.ql-container { font-family: var(--font-sans, inherit) !important; font-size: 14px; background: var(--a-bg); border-color: var(--a-border) !important; color: var(--a-text); }
.ql-toolbar { background: var(--a-surface); border-color: var(--a-border) !important; }
.ql-editor { min-height: 400px; }
.ql-snow .ql-stroke { stroke: var(--a-muted); }
.ql-snow .ql-fill { fill: var(--a-muted); }
.ql-snow .ql-picker { color: var(--a-muted); }
</style>

<?php if ($success): ?>
<div class="admin-alert admin-alert--success"><?= h($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="admin-alert admin-alert--error"><?= h($error) ?></div>
<?php endif; ?>

<div class="admin-tabs">
    <button class="admin-tab-btn active" onclick="switchTab('about')">About Page</button>
    <button class="admin-tab-btn" onclick="switchTab('contact')">Contact Page</button>
    <button class="admin-tab-btn" onclick="switchTab('privacy')">Privacy Policy</button>
    <button class="admin-tab-btn" onclick="switchTab('terms')">Terms of Service</button>
    <button class="admin-tab-btn" onclick="switchTab('shipping')">Shipping</button>
    <button class="admin-tab-btn" onclick="switchTab('returns')">Exchange & Returns</button>
</div>

<form method="post" id="pages-form" class="admin-form">

    <!-- ABOUT PAGE -->
    <div id="tab-about" class="admin-tab-content active">
        <div class="admin-settings-section" style="margin-bottom:32px;padding:24px;background:var(--a-surface);border:1px solid var(--a-border);border-radius:var(--a-radius)">
            <h2 style="font-size:1rem;margin-bottom:20px;padding-bottom:12px;border-bottom:1px solid var(--a-border)">Intro Section</h2>
            
            <div class="admin-form__group">
                <label>Page Header Title</label>
                <input type="text" name="page_about_header_title" value="<?= h($s('page_about_header_title', 'Our Story')) ?>">
                <span class="admin-form__hint" style="color:var(--a-accent)">The top banner title of the page.</span>
            </div>

            <div class="admin-form__group">
                <label>Hero Image</label>
                <div class="admin-img-upload">
                    <div class="admin-img-preview" id="preview-about_hero">
                        <?php if ($s('page_about_hero_image')): ?>
                            <img src="<?= SITE_URL . '/' . h($s('page_about_hero_image')) ?>" alt="Hero">
                        <?php else: ?>
                            No image
                        <?php endif; ?>
                    </div>
                </div>
                <div style="margin-top:8px">
                    <button type="button" class="admin-btn admin-btn--sm" onclick="openPagesMediaPicker('page_about_hero_image', 'preview-about_hero')">Choose from Gallery</button>
                    <button type="button" class="admin-btn admin-btn--sm admin-btn--danger" onclick="clearPagesMedia('page_about_hero_image', 'preview-about_hero')">Clear</button>
                </div>
                <input type="hidden" name="page_about_hero_image" id="input-page_about_hero_image" value="<?= h($s('page_about_hero_image')) ?>">
            </div>

            <div class="admin-form__group">
                <label>Eyebrow Text</label>
                <input type="text" name="page_about_eyebrow" value="<?= h($s('page_about_eyebrow', 'The Beginning')) ?>">
                <span class="admin-form__hint" style="color:var(--a-accent)">Appears directly above the main title in small, uppercase text.</span>
            </div>
            <div class="admin-form__group">
                <label>Title</label>
                <input type="text" name="page_about_title" value="<?= h($s('page_about_title', 'Born from Light')) ?>">
                <span class="admin-form__hint" style="color:var(--a-accent)">The primary large heading displayed on the About page.</span>
            </div>
            <div class="admin-form__group">
                <label>Text Content</label>
                <textarea name="page_about_text" rows="6"><?= h($s('page_about_text', "LUMEEGY was born from a simple belief — that style is a ritual, not a routine. Rooted in the spirit of Egyptian elegance, each piece is crafted to bring a moment of luxury into your everyday.\n\nWe source the finest fabrics and craft each piece with meticulous attention to detail. The result is a collection that honours heritage while delivering timeless, modern style.\n\nEvery texture, every scent, every detail is intentional. Because we believe the way you care for yourself says everything about who you are.")) ?></textarea>
                <span class="admin-form__hint" style="color:var(--a-accent)">The main body paragraph text. It will appear next to or below the hero image depending on screen size.</span>
            </div>
        </div>

        <div class="admin-settings-section" style="margin-bottom:32px;padding:24px;background:var(--a-surface);border:1px solid var(--a-border);border-radius:var(--a-radius)">
            <h2 style="font-size:1rem;margin-bottom:20px;padding-bottom:12px;border-bottom:1px solid var(--a-border)">Values Section</h2>
            <div class="admin-form__group">
                <label>Eyebrow Text</label>
                <input type="text" name="page_about_values_eyebrow" value="<?= h($s('page_about_values_eyebrow', 'Our Values')) ?>">
            </div>
            <div class="admin-form__group">
                <label>Title</label>
                <input type="text" name="page_about_values_title" value="<?= h($s('page_about_values_title', 'What We Stand For')) ?>">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px;margin-top:24px">
                <div style="padding:16px;border:1px solid var(--a-border)">
                    <h3 style="font-size:.85rem;margin-bottom:12px;color:var(--a-muted)">Value 1</h3>
                    <div class="admin-form__group"><label>Title</label><input type="text" name="page_about_value_1_title" value="<?= h($s('page_about_value_1_title', 'Heritage')) ?>"></div>
                    <div class="admin-form__group"><label>Text</label><textarea name="page_about_value_1_text" rows="3"><?= h($s('page_about_value_1_text', 'Inspired by 5,000 years of Egyptian elegance, reimagined for the modern world.')) ?></textarea></div>
                </div>
                <div style="padding:16px;border:1px solid var(--a-border)">
                    <h3 style="font-size:.85rem;margin-bottom:12px;color:var(--a-muted)">Value 2</h3>
                    <div class="admin-form__group"><label>Title</label><input type="text" name="page_about_value_2_title" value="<?= h($s('page_about_value_2_title', 'Luxury')) ?>"></div>
                    <div class="admin-form__group"><label>Text</label><textarea name="page_about_value_2_text" rows="3"><?= h($s('page_about_value_2_text', 'Premium fabrics and tailoring that feel as extraordinary as they look.')) ?></textarea></div>
                </div>
                <div style="padding:16px;border:1px solid var(--a-border)">
                    <h3 style="font-size:.85rem;margin-bottom:12px;color:var(--a-muted)">Value 3</h3>
                    <div class="admin-form__group"><label>Title</label><input type="text" name="page_about_value_3_title" value="<?= h($s('page_about_value_3_title', 'Intention')) ?>"></div>
                    <div class="admin-form__group"><label>Text</label><textarea name="page_about_value_3_text" rows="3"><?= h($s('page_about_value_3_text', 'Every product is designed to transform your routine into a mindful, sensory ritual.')) ?></textarea></div>
                </div>
            </div>
        </div>
    </div>

    <!-- CONTACT PAGE -->
    <div id="tab-contact" class="admin-tab-content">
        <div class="admin-settings-section" style="margin-bottom:32px;padding:24px;background:var(--a-surface);border:1px solid var(--a-border);border-radius:var(--a-radius)">
            <h2 style="font-size:1rem;margin-bottom:20px;padding-bottom:12px;border-bottom:1px solid var(--a-border)">Contact Intro</h2>
            <div class="admin-form__group">
                <label>Eyebrow Text</label>
                <input type="text" name="page_contact_eyebrow" value="<?= h($s('page_contact_eyebrow', 'Get In Touch')) ?>">
            </div>
            <div class="admin-form__group">
                <label>Title</label>
                <input type="text" name="page_contact_title" value="<?= h($s('page_contact_title', 'We\'d Love to Hear From You')) ?>">
            </div>
            <div class="admin-form__group">
                <label>Text Content</label>
                <textarea name="page_contact_text" rows="4"><?= h($s('page_contact_text', 'Whether you have a question about a product, need help with an order, or just want to say hello — our team is here for you.')) ?></textarea>
            </div>
            <p style="font-size:0.75rem;color:var(--a-muted);margin-top:12px">Note: The contact form and contact details (email/address) are managed in the main Settings page.</p>
        </div>
    </div>

    <!-- PRIVACY POLICY -->
    <div id="tab-privacy" class="admin-tab-content">
        <div class="admin-settings-section" style="margin-bottom:32px;padding:24px;background:var(--a-surface);border:1px solid var(--a-border);border-radius:var(--a-radius)">
            <div class="admin-form__group">
                <label>Privacy Policy Content</label>
                <input type="hidden" name="page_privacy_content" id="input-privacy">
                <div id="editor-privacy"><?= $s('page_privacy_content') ?></div>
            </div>
        </div>
    </div>

    <!-- TERMS OF SERVICE -->
    <div id="tab-terms" class="admin-tab-content">
        <div class="admin-settings-section" style="margin-bottom:32px;padding:24px;background:var(--a-surface);border:1px solid var(--a-border);border-radius:var(--a-radius)">
            <div class="admin-form__group">
                <label>Terms of Service Content</label>
                <input type="hidden" name="page_terms_content" id="input-terms">
                <div id="editor-terms"><?= $s('page_terms_content') ?></div>
            </div>
        </div>
    </div>

    <!-- SHIPPING & RETURNS -->
    <div id="tab-shipping" class="admin-tab-content">
        <div class="admin-settings-section" style="margin-bottom:32px;padding:24px;background:var(--a-surface);border:1px solid var(--a-border);border-radius:var(--a-radius)">
            <div class="admin-form__group">
                <label>Shipping Policy Content</label>
                <input type="hidden" name="page_shipping_content" id="input-shipping">
                <div id="editor-shipping"><?= $s('page_shipping_content') ?></div>
            </div>
        </div>
    </div>

    <!-- EXCHANGE & RETURNS (exchange-returns.php reads page_returns_content, which had no editor) -->
    <div id="tab-returns" class="admin-tab-content">
        <div class="admin-settings-section" style="margin-bottom:32px;padding:24px">
            <div class="admin-form__group">
                <label>Exchange & Returns Content</label>
                <input type="hidden" name="page_returns_content" id="input-returns">
                <div id="editor-returns"><?= $s('page_returns_content') ?></div>
            </div>
        </div>
    </div>

    <div style="padding-top:12px;border-top:1px solid var(--a-border)">
        <button type="submit" class="admin-btn admin-btn--primary">Save Pages</button>
    </div>
</form>

<!-- Media Picker Modal -->
<div class="hp-modal-overlay" id="media-picker-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;z-index:200;background:rgba(0,0,0,.6);align-items:center;justify-content:center;padding:24px">
    <div class="hp-modal" style="background:var(--a-surface);border:1px solid var(--a-border);border-radius:var(--a-radius);padding:32px;width:100%;max-width:800px;max-height:90vh;overflow-y:auto">
        <div class="hp-modal__head" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
            <h3 style="font-size:1rem;font-weight:600;margin:0">Select Media</h3>
            <button type="button" onclick="closePagesMediaPicker()" style="background:none;border:none;color:var(--a-muted);font-size:1.2rem;cursor:pointer">✕</button>
        </div>
        <div class="media-grid" id="picker-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:16px;max-height:60vh;overflow-y:auto;padding:16px 0"></div>
    </div>
</div>
<style>
.media-grid__item{cursor:pointer;border-radius:4px;overflow:hidden;border:2px solid transparent;transition:all .2s;text-align:center}
.media-grid__item:hover{border-color:var(--a-accent)}
.media-grid__item img{width:100%;height:120px;object-fit:cover;display:block}
.media-grid__item__name{font-size:.65rem;color:var(--a-muted);padding:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
</style>

<script>
// Tab Switching
function switchTab(tabId) {
    document.querySelectorAll('.admin-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.admin-tab-content').forEach(content => content.classList.remove('active'));
    document.querySelector(`.admin-tab-btn[onclick="switchTab('${tabId}')"]`).classList.add('active');
    document.getElementById('tab-' + tabId).classList.add('active');
}

// Media Picker
let pickerTargetField = null;
let pickerTargetPreview = null;
let pickerCurrentPage = 1;
let pickerTotalPages = 1;
let pickerIsLoading = false;

function openPagesMediaPicker(field, previewId) {
    pickerTargetField = field;
    pickerTargetPreview = previewId;
    pickerCurrentPage = 1;
    pickerTotalPages = 1;
    
    const grid = document.getElementById('picker-grid');
    grid.innerHTML = '<div style="text-align:center;padding:24px;color:var(--a-muted);grid-column:1/-1">Loading…</div>';
    document.getElementById('media-picker-modal').style.display = 'flex';

    grid.onscroll = null;
    loadPagesMediaPickerPage(1);
    
    grid.onscroll = function() {
        if (grid.scrollTop + grid.clientHeight >= grid.scrollHeight - 100 && !pickerIsLoading && pickerCurrentPage < pickerTotalPages) {
            loadPagesMediaPickerPage(pickerCurrentPage + 1);
        }
    };
}

function loadPagesMediaPickerPage(page) {
    pickerIsLoading = true;
    fetch('<?= SITE_URL ?>/api/media.php?type=image&page=' + page, {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r => r.json()).then(data => {
        pickerIsLoading = false;
        const grid = document.getElementById('picker-grid');
        
        if (page === 1) {
            if (!data.items || !data.items.length) {
                grid.innerHTML = '<div style="text-align:center;padding:24px;color:var(--a-muted);grid-column:1/-1">No media uploaded yet.</div>';
                return;
            }
            grid.innerHTML = '';
        }
        
        pickerCurrentPage = data.page;
        pickerTotalPages = data.pages;
        
        const html = data.items.map(m => {
            const thumbUrl = '<?= SITE_URL ?>/thumb.php?src=' + encodeURIComponent(m.filepath) + '&w=200';
            return `
            <div class="media-grid__item" onclick="pickPagesMedia('${m.filepath}')">
                <img src="${thumbUrl}" alt="${m.filename}" loading="lazy">
                <div class="media-grid__item__name">${m.filename}</div>
            </div>
            `;
        }).join('');
        
        grid.insertAdjacentHTML('beforeend', html);
    }).catch(() => { pickerIsLoading = false; });
}

function pickPagesMedia(filepath) {
    if (pickerTargetField) {
        document.getElementById('input-' + pickerTargetField).value = filepath;
        const preview = document.getElementById(pickerTargetPreview);
        if (preview) {
            preview.innerHTML = `<img src="<?= SITE_URL ?>/${filepath}" alt="Preview">`;
        }
    }
    closePagesMediaPicker();
}

function clearPagesMedia(field, previewId) {
    document.getElementById('input-' + field).value = '';
    const preview = document.getElementById(previewId);
    if (preview) {
        preview.innerHTML = 'No image';
    }
}

function closePagesMediaPicker() {
    document.getElementById('media-picker-modal').style.display = 'none';
}

document.getElementById('media-picker-modal').addEventListener('click', function(e) { 
    if (e.target === this) closePagesMediaPicker(); 
});

// Quill Rich Text Editors
const quillOptions = {
    theme: 'snow',
    modules: {
        toolbar: [
            [{ 'header': [2, 3, false] }],
            ['bold', 'italic', 'underline'],
            [{ 'list': 'ordered'}, { 'list': 'bullet' }],
            ['link', 'clean']
        ]
    }
};
const quillPrivacy = new Quill('#editor-privacy', quillOptions);
const quillTerms = new Quill('#editor-terms', quillOptions);
const quillShipping = new Quill('#editor-shipping', quillOptions);
const quillReturns = new Quill('#editor-returns', quillOptions);

// On form submit, copy Quill HTML to hidden inputs
document.getElementById('pages-form').addEventListener('submit', function() {
    document.getElementById('input-privacy').value = quillPrivacy.root.innerHTML;
    document.getElementById('input-terms').value = quillTerms.root.innerHTML;
    document.getElementById('input-shipping').value = quillShipping.root.innerHTML;
    document.getElementById('input-returns').value = quillReturns.root.innerHTML;
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
