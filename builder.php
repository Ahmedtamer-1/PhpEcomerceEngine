<?php
/**
 * Visual page builder.
 *
 * Three panes: section list (drag to reorder) | live iframe preview | inspector.
 *
 * The preview renders through api/section-preview.php, which uses the SAME
 * includes/render-sections.php the live site uses — so what you see here is
 * what the page actually produces, not a second approximation of it.
 *
 * The inspector is generated from includes/section-registry.php rather than
 * hand-written per type, so adding a field to a section means editing one
 * array, not three files.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/section-registry.php';

$targets     = page_section_targets();
$currentPage = $_GET['page'] ?? 'home';
if (!isset($targets[$currentPage])) $currentPage = 'home';

$pageTitle = $targets[$currentPage] . ' — Builder';
$adminPage = 'builder';

$sections = get_homepage_sections(false, $currentPage);

// Hand the browser the rows and the field definitions together.
$initial = [];
foreach ($sections as $s) {
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

// The real storefront background, so the area around a narrowed (mobile)
// preview matches the site instead of being a hardcoded black letterbox.
$siteBg = setting('theme_color_bg', '#0A0A0A');

require_once __DIR__ . '/includes/header.php';
?>
<style>
.bld__stage{background:<?= h($siteBg) ?>}
.bld__frame{background:<?= h($siteBg) ?>}
.bld{display:grid;grid-template-columns:250px 1fr 300px;gap:14px;height:calc(100vh - var(--topbar-h) - 90px);min-height:520px}
.bld__col{background:var(--a-surface);border:1px solid var(--a-border);border-radius:var(--a-radius);display:flex;flex-direction:column;overflow:hidden}
.bld__head{padding:10px 12px;border-bottom:1px solid var(--a-border);font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:var(--a-muted);flex-shrink:0;display:flex;align-items:center;justify-content:space-between;gap:8px}
.bld__body{overflow-y:auto;padding:10px;flex:1}
.bld__item{display:flex;align-items:center;gap:8px;padding:9px 10px;margin-bottom:6px;background:var(--a-bg);border:1px solid var(--a-border);border-radius:var(--a-radius);cursor:grab;font-size:.82rem;color:var(--a-text)}
.bld__item:hover{border-color:var(--a-accent)}
.bld__item.is-active{border-color:var(--a-accent);box-shadow:inset 3px 0 0 var(--a-accent)}
.bld__item.dragging{opacity:.4}
.bld__item.drop-target{border-top:2px solid var(--a-accent)}
.bld__item[data-off="1"]{opacity:.45}
.bld__item__label{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bld__item__del{background:none;border:none;color:var(--a-muted);cursor:pointer;font-size:1rem;line-height:1;padding:2px 4px}
.bld__item__del:hover{color:var(--a-danger)}
.bld__frame{flex:1;border:0;width:100%;display:block}
.bld__field{margin-bottom:14px}
.bld__field label{display:block;font-size:.73rem;font-weight:600;margin-bottom:5px;color:var(--a-text)}
.bld__field input[type=text],.bld__field input[type=url],.bld__field input[type=number],.bld__field textarea,.bld__field select{width:100%;padding:7px 9px;background:var(--a-bg);border:1px solid var(--a-border);border-radius:var(--a-radius);color:var(--a-text);font-size:.8rem;font-family:inherit}
.bld__field textarea{min-height:82px;resize:vertical}
.bld__field input[type=color]{width:100%;height:32px;padding:2px;background:var(--a-bg);border:1px solid var(--a-border);border-radius:var(--a-radius)}
.bld__hint{display:block;font-size:.68rem;color:var(--a-muted);margin-top:4px;line-height:1.4}
.bld__empty{color:var(--a-muted);font-size:.8rem;text-align:center;padding:26px 10px;line-height:1.6}
.bld__addgrid{display:grid;grid-template-columns:1fr 1fr;gap:7px}
.bld__addgrid button{display:flex;flex-direction:column;align-items:center;gap:4px;padding:11px 5px;background:var(--a-bg);border:1px solid var(--a-border);border-radius:var(--a-radius);color:var(--a-text);cursor:pointer;font-size:.68rem;text-align:center;line-height:1.25}
.bld__addgrid button:hover{border-color:var(--a-accent)}
.bld__addgrid span{font-size:1.15rem}
@media (max-width:1100px){.bld{grid-template-columns:1fr;height:auto}.bld__frame{height:460px}.bld__col{max-height:440px}}
</style>

<div class="admin-filter-tabs" style="margin-bottom:12px;flex-wrap:wrap">
    <?php foreach ($targets as $key => $label): ?>
        <a href="?page=<?= h($key) ?>" class="admin-filter-tab <?= $key === $currentPage ? 'active' : '' ?>" style="text-decoration:none"><?= h($label) ?></a>
    <?php endforeach; ?>
</div>

<div class="admin-toolbar" style="margin-bottom:12px">
    <div class="admin-toolbar__left">
        <span style="font-size:.85rem;color:var(--a-muted)">Building <strong style="color:var(--a-text)"><?= h($targets[$currentPage]) ?></strong></span>
        <span id="bld-dirty" style="font-size:.75rem;color:var(--a-gold);display:none">● unsaved changes</span>
    </div>
    <div class="admin-toolbar__right">
        <span id="bld-status" style="font-size:.78rem;color:var(--a-muted)"></span>
        <button class="admin-btn admin-btn--sm" onclick="bldSetWidth('100%')">Desktop</button>
        <button class="admin-btn admin-btn--sm" onclick="bldSetWidth('390px')">Mobile</button>
        <button class="admin-btn admin-btn--primary" id="bld-save" onclick="bldSave()">Save page</button>
    </div>
</div>

<div class="bld">
    <!-- ── Sections ── -->
    <div class="bld__col">
        <div class="bld__head"><span>Sections</span><span id="bld-count"></span></div>
        <div class="bld__body" id="bld-list"></div>
        <div class="bld__head" style="border-bottom:0;border-top:1px solid var(--a-border)">Add a section</div>
        <div class="bld__body" style="flex:0 0 auto;max-height:230px">
            <div class="bld__addgrid" id="bld-add"></div>
        </div>
    </div>

    <!-- ── Preview ── -->
    <div class="bld__col bld__stage">
        <div class="bld__head" style="background:var(--a-surface)">
            <span>Live preview</span>
            <span id="bld-preview-status" style="text-transform:none;letter-spacing:0"></span>
        </div>
        <div class="bld__stage" style="flex:1;display:flex;justify-content:center;overflow:hidden">
            <iframe id="bld-frame" class="bld__frame" title="Page preview"></iframe>
        </div>
    </div>

    <!-- ── Inspector ── -->
    <div class="bld__col">
        <div class="bld__head" id="bld-inspector-title">Settings</div>
        <div class="bld__body" id="bld-inspector">
            <div class="bld__empty">Select a section on the left to edit it.</div>
        </div>
    </div>
</div>

<script>
/* Everything lives inside an IIFE.
   These used to be bare top-level `const`s, which put BASE, REGISTRY, $, esc
   and friends into the global scope — where `const BASE` collided with the
   same declaration in admin/assets/js/products.js. A duplicate `const` is a
   SyntaxError that kills the ENTIRE script block, so the builder rendered
   its markup and then did nothing. Only the handful of functions referenced
   from inline onclick attributes are exported, at the bottom. */
(function(){
'use strict';

const BASE       = <?= json_encode(SITE_URL) ?>;
const PAGE_KEY   = <?= json_encode($currentPage) ?>;
const REGISTRY   = <?= json_encode(section_registry(), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
let   blocks     = <?= json_encode($initial, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
let   activeIdx  = blocks.length ? 0 : -1;
let   dirty      = false;

const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

/* <input type="color"> only accepts a full #rrggbb. Anything else — empty,
   a 3-digit hex, a bare "320606", "rgba(...)", a colour name — makes the
   swatch silently snap to #000000 and look like the field was cleared.
   Returns '' when the value cannot be represented, so callers can fall back. */
function normHex(v){
    if(v === null || v === undefined) return '';
    let s = String(v).trim();
    if(!s) return '';
    if(s[0] !== '#'){
        if(/^[0-9a-f]{3}$/i.test(s) || /^[0-9a-f]{6}$/i.test(s)) s = '#' + s;
        else return '';
    }
    if(/^#[0-9a-f]{3}$/i.test(s)) s = '#' + s[1]+s[1] + s[2]+s[2] + s[3]+s[3];
    return /^#[0-9a-f]{6}$/i.test(s) ? s.toLowerCase() : '';
}

function markDirty(){ dirty = true; $('bld-dirty').style.display = ''; }

// Warn before losing unsaved work — this was the single easiest way to lose
// edits in the old modal flow.
window.addEventListener('beforeunload', e => { if(dirty){ e.preventDefault(); e.returnValue = ''; } });

// ── Value access. "settings.foo" reads/writes the nested settings object. ──
function getVal(block, key){
    if(key.startsWith('settings.')) return (block.settings || {})[key.slice(9)] ?? '';
    return block[key] ?? '';
}
function setVal(block, key, v){
    if(key.startsWith('settings.')){
        block.settings = block.settings || {};
        block.settings[key.slice(9)] = v;
    } else block[key] = v;
}

// ── Left pane ──
function renderList(){
    const list = $('bld-list');
    $('bld-count').textContent = blocks.length || '';
    if(!blocks.length){
        list.innerHTML = '<div class="bld__empty">No sections yet.<br>Add one below.</div>';
        return;
    }
    list.innerHTML = blocks.map((b,i)=>{
        const def = REGISTRY[b.section_type] || {label:b.section_type, icon:'❓'};
        const name = b.title || def.label;
        return `<div class="bld__item ${i===activeIdx?'is-active':''}" draggable="true" data-i="${i}" data-off="${b.is_active?0:1}">
            <span>${def.icon}</span>
            <span class="bld__item__label" title="${esc(name)}">${esc(name)}</span>
            <button class="bld__item__del" title="${b.is_active?'Hide':'Show'}" onclick="event.stopPropagation();toggleBlock(${i})">${b.is_active?'👁':'🚫'}</button>
            <button class="bld__item__del" title="Delete" onclick="event.stopPropagation();deleteBlock(${i})">✕</button>
        </div>`;
    }).join('');

    list.querySelectorAll('.bld__item').forEach(el=>{
        const i = +el.dataset.i;
        el.addEventListener('click', ()=>selectBlock(i));
        el.addEventListener('dragstart', e=>{ dragFrom = i; el.classList.add('dragging'); e.dataTransfer.effectAllowed='move'; });
        el.addEventListener('dragend',   ()=>{ el.classList.remove('dragging'); list.querySelectorAll('.bld__item').forEach(x=>x.classList.remove('drop-target')); });
        el.addEventListener('dragover',  e=>{ e.preventDefault(); el.classList.add('drop-target'); });
        el.addEventListener('dragleave', ()=> el.classList.remove('drop-target'));
        el.addEventListener('drop', e=>{
            e.preventDefault();
            el.classList.remove('drop-target');
            if(dragFrom === null || dragFrom === i) return;
            const [moved] = blocks.splice(dragFrom,1);
            blocks.splice(i,0,moved);
            activeIdx = i; dragFrom = null;
            markDirty(); renderList(); renderInspector(); refreshPreview();
        });
    });
}
let dragFrom = null;

function renderAddButtons(){
    $('bld-add').innerHTML = Object.entries(REGISTRY).map(([type,def])=>
        `<button type="button" onclick="addBlock('${type}')"><span>${def.icon}</span>${esc(def.label)}</button>`
    ).join('');
}

function addBlock(type){
    const def = REGISTRY[type]; if(!def) return;
    const b = { id:0, section_type:type, title:'', subtitle:'', content:'', image:'',
                button_text:'', button_url:'', settings:{}, is_active:1 };
    (def.fields||[]).forEach(f=>{ if(f.default !== undefined) setVal(b, f.key, f.default); });
    blocks.push(b);
    activeIdx = blocks.length - 1;
    markDirty(); renderList(); renderInspector(); refreshPreview();
}

function deleteBlock(i){
    const def = REGISTRY[blocks[i].section_type];
    if(!confirm(`Delete this ${def ? def.label : 'section'}? It is removed when you save the page.`)) return;
    blocks.splice(i,1);
    if(activeIdx >= blocks.length) activeIdx = blocks.length - 1;
    markDirty(); renderList(); renderInspector(); refreshPreview();
}

function toggleBlock(i){
    blocks[i].is_active = blocks[i].is_active ? 0 : 1;
    markDirty(); renderList(); refreshPreview();
}

function selectBlock(i){
    activeIdx = i;
    try { renderList(); }      catch(e){ bldError('renderList', e); }
    try { renderInspector(); } catch(e){ bldError('renderInspector', e); }
}

// ── Inspector, generated from the registry ──
function renderInspector(){
    const box = $('bld-inspector');
    if(activeIdx < 0 || !blocks[activeIdx]){
        $('bld-inspector-title').textContent = 'Settings';
        box.innerHTML = '<div class="bld__empty">Select a section on the left to edit it.</div>';
        return;
    }
    const b = blocks[activeIdx];
    const def = REGISTRY[b.section_type];
    $('bld-inspector-title').textContent = (def ? def.icon + ' ' + def.label : b.section_type);
    if(!def){ box.innerHTML = '<div class="bld__empty">Unknown section type.</div>'; return; }

    box.innerHTML = (def.fields||[]).map((f,fi)=>{
        const v = getVal(b, f.key);
        const hint = f.hint ? `<span class="bld__hint">${esc(f.hint)}</span>` : '';
        let ctl;
        switch(f.type){
            case 'textarea':
                ctl = `<textarea data-f="${fi}">${esc(v)}</textarea>`; break;
            case 'color': {
                // Swatch + hex together. A bare <input type=color> filled with
                // #ffffff renders as a blank white bar on this dark panel and
                // reads as "empty" rather than "white", so pair it with the
                // literal value and give the swatch a border of its own.
                const cv = normHex(v) || normHex(f.default) || '#000000';
                ctl = `<div class="bld__color">
                         <input type="color" data-f="${fi}" data-role="swatch" value="${esc(cv)}">
                         <input type="text"  data-f="${fi}" data-role="hex" value="${esc(v || f.default || '')}"
                                placeholder="#ffffff" spellcheck="false" maxlength="7">
                       </div>`;
                break;
            }
            case 'number':
                ctl = `<input type="number" step="any" data-f="${fi}" value="${esc(v)}">`; break;
            case 'select':
                ctl = `<select data-f="${fi}">` + Object.entries(f.options||{}).map(([ov,ol])=>
                        `<option value="${esc(ov)}" ${String(v)===String(ov)?'selected':''}>${esc(ol)}</option>`).join('') + `</select>`; break;
            case 'image':
                ctl = `<input type="text" data-f="${fi}" value="${esc(v)}" placeholder="assets/images/…">
                       <span class="bld__hint">Paste a path, or copy one from Media.</span>`; break;
            default:
                ctl = `<input type="${f.type==='url'?'text':'text'}" data-f="${fi}" value="${esc(v)}">`;
        }
        return `<div class="bld__field"><label>${esc(f.label)}</label>${ctl}${hint}</div>`;
    }).join('');

    box.querySelectorAll('[data-f]').forEach(el=>{
        const f = def.fields[+el.dataset.f];
        const handler = ()=>{
            let value = el.value;

            // Colour fields have two inputs bound to one key — keep the other
            // one in step so the swatch and the hex never disagree.
            if(f.type === 'color'){
                const pair = el.closest('.bld__color');
                if(el.dataset.role === 'swatch'){
                    const hex = pair.querySelector('[data-role=hex]');
                    if(hex) hex.value = value;
                } else {
                    const norm = normHex(value);
                    // Only push a half-typed hex to the swatch once it is valid,
                    // otherwise it snaps to black on every keystroke.
                    if(norm){
                        const sw = pair.querySelector('[data-role=swatch]');
                        if(sw) sw.value = norm;
                    }
                }
            }

            setVal(blocks[activeIdx], f.key, value);
            markDirty();
            if(f.key === 'title') renderList(); // list shows the heading
            schedulePreview();
        };
        el.addEventListener('input', handler);
        el.addEventListener('change', handler);
    });
}

// ── Live preview (debounced so typing does not spam the server) ──
let previewTimer = null;
function schedulePreview(){
    clearTimeout(previewTimer);
    $('bld-preview-status').textContent = '…';
    previewTimer = setTimeout(refreshPreview, 450);
}

function refreshPreview(){
    clearTimeout(previewTimer);
    $('bld-preview-status').textContent = 'updating…';
    fetch(BASE + '/api/section-preview.php', {
        method:'POST',
        headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify(blocks)
    })
    .then(r=>r.text())
    .then(html=>{
        const f = $('bld-frame');
        f.srcdoc = html;                       // srcdoc avoids a server round-trip for the URL
        $('bld-preview-status').textContent = '';
    })
    .catch(err=>{ $('bld-preview-status').textContent = 'preview failed'; });
}

function bldSetWidth(w){
    const f = $('bld-frame');
    f.style.width = w;
    f.style.margin = w === '100%' ? '0' : '0 auto';
    f.style.borderLeft = f.style.borderRight = w === '100%' ? '0' : '1px solid #333';
}

// ── Save ──
function bldSave(){
    const btn = $('bld-save'), status = $('bld-status');
    btn.disabled = true; btn.textContent = 'Saving…'; status.textContent = '';

    const fd = new FormData();
    fd.append('action','save_all');
    fd.append('page', PAGE_KEY);
    fd.append('sections', JSON.stringify(blocks));

    fetch(BASE + '/api/sections.php', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json())
    .then(d=>{
        btn.disabled = false; btn.textContent = 'Save page';
        if(d.success){
            // Adopt the ids the server assigned, so a second save updates
            // rather than creating duplicates.
            if(Array.isArray(d.ids)) d.ids.forEach((id,i)=>{ if(blocks[i]) blocks[i].id = id; });
            dirty = false; $('bld-dirty').style.display = 'none';
            status.style.color = 'var(--a-green)';
            status.textContent = d.message || 'Saved.';
            setTimeout(()=>{ status.textContent = ''; }, 4000);
        } else {
            status.style.color = 'var(--a-danger)';
            status.textContent = d.message || 'Save failed — nothing was changed.';
        }
    })
    .catch(err=>{
        btn.disabled = false; btn.textContent = 'Save page';
        status.style.color = 'var(--a-danger)';
        status.textContent = 'Could not reach the server — nothing was saved.';
    });
}

/* Surface JS errors in the UI.
   Previously a single exception during init stopped the script silently:
   the section list would render, the inspector would keep its placeholder,
   and refreshPreview() never ran — leaving an empty iframe that just showed
   the background colour. That looked like "the preview is broken" when the
   real fault was somewhere else entirely. */
function bldError(where, err){
    console.error('[builder]', where, err);
    let bar = document.getElementById('bld-errbar');
    if(!bar){
        bar = document.createElement('div');
        bar.id = 'bld-errbar';
        bar.style.cssText = 'margin-bottom:12px;padding:10px 14px;border-radius:6px;'
            + 'background:rgba(196,68,68,.12);border:1px solid var(--a-danger);'
            + 'color:var(--a-danger);font-size:.8rem;line-height:1.5;white-space:pre-wrap';
        document.querySelector('.bld').before(bar);
    }
    bar.textContent = 'Builder error in ' + where + ': ' + (err && err.message ? err.message : err)
                    + '\n(Full details in the browser console — F12.)';
}
window.addEventListener('error', e => bldError('page', e.error || e.message));

// Each step runs independently, so one failure cannot take out the rest —
// in particular the preview still loads even if the inspector chokes.
try { renderAddButtons(); } catch(e){ bldError('renderAddButtons', e); }
try { renderList();       } catch(e){ bldError('renderList', e); }
try { renderInspector();  } catch(e){ bldError('renderInspector', e); }
try { refreshPreview();   } catch(e){ bldError('refreshPreview', e); }

/* Only what inline onclick attributes call needs to be global. Everything
   else stays private so it cannot clash with other admin scripts. */
window.bldSetWidth = bldSetWidth;
window.bldSave     = bldSave;
window.addBlock    = addBlock;
window.toggleBlock = toggleBlock;
window.deleteBlock = deleteBlock;

})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
