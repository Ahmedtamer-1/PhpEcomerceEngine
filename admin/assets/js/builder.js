/**
 * Full-screen page builder (admin/builder.php).
 *
 * State is one array of section rows (`blocks`). Every edit changes it,
 * updates the canvas and records an undo step; nothing reaches the database
 * until Save. The canvas is the real page rendered by api/section-preview.php;
 * it reports hovers, clicks and toolbar buttons back through window.__bldHost.
 */
(function () {
    'use strict';
    const BASE = window.BASE_URL;
    const { page: PAGE, registry: REG, categories: CATS, links: LINKS } = window.BX;
    let blocks = window.BX.blocks;
    let sel = blocks.length ? 0 : -1;
    let saved = JSON.stringify(blocks);
    const undoStack = [], redoStack = [];
    let lastSnapKey = '', lastSnapAt = 0;

    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const h = (tag, attrs = {}, ...kids) => {
        const el = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (v == null || v === false) continue;
            if (k === 'class') el.className = v;
            else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
            else if (k === 'html') el.innerHTML = v;
            else el.setAttribute(k, v === true ? '' : v);
        }
        kids.flat().forEach(c => c != null && el.append(c.nodeType ? c : document.createTextNode(c)));
        return el;
    };
    const ICON = {
        eye: '<svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
        eyeOff: '<svg viewBox="0 0 24 24"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>',
        dots: '<svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>',
        img: '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
        film: '<svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><polygon points="10 9 15 12 10 15 10 9"/></svg>',
        click: '<svg viewBox="0 0 24 24"><path d="M9 9l5 12 1.8-5.2L21 14z"/><path d="M7.2 2.2L8 5.1M5.1 8l-2.9-.8M14 4.1L12 6M6 12l-1.9 2"/></svg>',
    };

    // ── Values ("settings.foo" lives in the JSON settings object) ──
    const raw = (b, key) => key.startsWith('settings.') ? (b.settings || {})[key.slice(9)] : b[key];
    const getVal = (b, key) => raw(b, key) ?? '';
    function setVal(b, key, v) {
        if (key.startsWith('settings.')) { if (!b.settings || Array.isArray(b.settings)) b.settings = {}; b.settings[key.slice(9)] = v; }
        else b[key] = v;
    }
    const def = b => REG[b.section_type] || { label: b.section_type, icon: '❓', fields: [] };
    const assetUrl = p => !p ? '' : /^https?:/i.test(p) ? p : BASE + '/' + String(p).replace(/^\//, '');
    const thumb = (p, w = 160) => !p ? '' : /^https?:/i.test(p) ? p : /\.svg$/i.test(p) ? assetUrl(p) : BASE + '/thumb.php?src=' + encodeURIComponent(String(p).replace(/^\//, '')) + '&w=' + w;
    const isVideoPath = p => /\.(mp4|webm|mov|m4v)(\?|$)/i.test(p || '');

    // ── History / dirty ──
    function snap(key) {
        const now = Date.now();
        // Typing in one field within a second counts as one undo step.
        if (key && key === lastSnapKey && now - lastSnapAt < 1000) { lastSnapAt = now; return; }
        undoStack.push(JSON.stringify({ blocks, sel }));
        if (undoStack.length > 100) undoStack.shift();
        redoStack.length = 0;
        lastSnapKey = key || ''; lastSnapAt = now;
        updateChrome();
    }
    function restore(from, to) {
        if (!from.length) return;
        to.push(JSON.stringify({ blocks, sel }));
        const s = JSON.parse(from.pop());
        blocks = s.blocks; sel = Math.min(s.sel, blocks.length - 1);
        lastSnapKey = '';
        renderList(); renderInspector(); fullRefresh(); updateChrome();
    }
    const isDirty = () => JSON.stringify(blocks) !== saved;
    function updateChrome() {
        $('bx-dirty').hidden = !isDirty();
        $('bx-undo').disabled = !undoStack.length;
        $('bx-redo').disabled = !redoStack.length;
        $('bx-count').textContent = blocks.length ? blocks.length + (blocks.length === 1 ? ' section' : ' sections') : '';
        document.title = (isDirty() ? '● ' : '') + document.title.replace(/^● /, '');
    }
    window.addEventListener('beforeunload', e => { if (isDirty()) { e.preventDefault(); e.returnValue = ''; } });

    function status(msg, kind) {
        const s = $('bx-status');
        s.textContent = msg || ''; s.className = 'bx-status' + (kind ? ' is-' + kind : '');
        if (kind === 'ok') setTimeout(() => { if (s.textContent === msg) s.textContent = ''; }, 3500);
    }

    // ── Section list ──
    let dragFrom = null;
    function renderList() {
        const list = $('bx-list');
        list.innerHTML = '';
        if (!blocks.length) {
            list.innerHTML = '<div class="bx-empty">No sections yet.<br>Click <strong>Add section</strong> below.</div>';
            return;
        }
        blocks.forEach((b, i) => {
            const d = def(b);
            const item = h('div', {
                class: 'bx-item' + (i === sel ? ' is-sel' : '') + (b.is_active ? '' : ' is-off'), draggable: 'true', 'data-i': i,
                title: d.label + (b.is_active ? '' : ' (hidden)') + ' — click to edit, drag to move',
                onclick: () => select(i, true),
                onmouseenter: () => frameCall('highlight', i), onmouseleave: () => frameCall('highlight', -1),
            },
                h('span', { class: 'bx-grip', 'aria-hidden': 'true' }, '⋮⋮'),
                h('span', { class: 'bx-item__icon' }, d.icon),
                h('span', { class: 'bx-item__name', html: esc(d.label) + (b.title ? '<small>' + esc(b.title) + '</small>' : '') }),
                h('button', { type: 'button', class: 'bx-eye', 'data-tip': b.is_active ? 'Hide this section' : 'Show this section',
                    'aria-label': b.is_active ? 'Hide section' : 'Show section', html: b.is_active ? ICON.eye : ICON.eyeOff,
                    onclick: e => { e.stopPropagation(); toggleVisible(i); } })
            );
            item.addEventListener('dragstart', e => { dragFrom = i; item.classList.add('is-drag'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', i); });
            item.addEventListener('dragend', () => { dragFrom = null; list.querySelectorAll('.bx-item').forEach(x => x.classList.remove('is-drag', 'drop-before', 'drop-after')); });
            item.addEventListener('dragover', e => {
                if (dragFrom === null) return;
                e.preventDefault();
                const after = e.offsetY > item.offsetHeight / 2;
                list.querySelectorAll('.bx-item').forEach(x => x.classList.remove('drop-before', 'drop-after'));
                item.classList.add(after ? 'drop-after' : 'drop-before');
            });
            item.addEventListener('drop', e => {
                e.preventDefault();
                if (dragFrom === null) return;
                let to = i + (item.classList.contains('drop-after') ? 1 : 0);
                if (dragFrom < to) to--;
                if (to !== dragFrom) moveTo(dragFrom, to);
            });
            list.append(item);
        });
    }

    // ── Section actions ──
    function select(i, scroll) {
        sel = i;
        document.querySelectorAll('.bx-item').forEach(x => x.classList.toggle('is-sel', +x.dataset.i === i));
        renderInspector();
        frameCall('select', i, scroll);
        const it = document.querySelector('.bx-item[data-i="' + i + '"]');
        it && it.scrollIntoView({ block: 'nearest' });
    }
    function moveTo(from, to) {
        if (to < 0 || to >= blocks.length || from === to) return;
        snap();
        const [m] = blocks.splice(from, 1);
        blocks.splice(to, 0, m);
        scrollToSel = true;
        sel = to;
        renderList(); renderInspector(); fullRefresh(); updateChrome();
    }
    function toggleVisible(i) {
        snap();
        blocks[i].is_active = blocks[i].is_active ? 0 : 1;
        if (sel !== i) sel = i;
        renderList(); renderInspector(); fullRefresh(); updateChrome();
        status(blocks[i].is_active ? 'Section shown' : 'Section hidden — it stays in the list', 'ok');
    }
    function duplicate(i) {
        snap();
        const copy = JSON.parse(JSON.stringify(blocks[i]));
        copy.id = 0;
        blocks.splice(i + 1, 0, copy);
        scrollToSel = true;
        sel = i + 1;
        renderList(); renderInspector(); fullRefresh(); updateChrome();
    }
    function remove(i) {
        const d = def(blocks[i]);
        if (!confirm('Delete this ' + d.label + '? You can undo, and nothing changes on the site until you save.')) return;
        snap();
        blocks.splice(i, 1);
        sel = Math.min(i, blocks.length - 1);
        renderList(); renderInspector(); fullRefresh(); updateChrome();
    }
    function addSection(type) {
        const d = REG[type]; if (!d) return;
        snap();
        const b = { id: 0, section_type: type, title: '', subtitle: '', content: '', image: '', button_text: '', button_url: '', settings: {}, is_active: 1 };
        (d.fields || []).forEach(f => { if (f.default !== undefined) setVal(b, f.key, f.default); });
        const at = sel >= 0 ? sel + 1 : blocks.length;
        blocks.splice(at, 0, b);
        scrollToSel = true;
        sel = at;
        closeAdd();
        renderList(); renderInspector(); fullRefresh(); updateChrome();
    }

    // ── Inspector ──
    const GROUPS = { content: 'Content', media: 'Media', style: 'Style', layout: 'Spacing & layout' };
    function renderInspector() {
        const box = $('bx-inspector');
        box.innerHTML = '';
        const b = blocks[sel];
        if (!b) {
            box.append(h('div', { class: 'bx-insp-empty', html: ICON.click + '<p><strong>Click any section</strong> in the preview or the list to edit it.</p><p>Hover over the page to see what each part is.</p>' }));
            return;
        }
        const d = def(b);
        const menu = h('div', { class: 'bx-menu' },
            h('button', { type: 'button', class: 'bx-icon', 'aria-label': 'More actions', 'data-tip': 'More actions', html: ICON.dots,
                onclick: e => { e.stopPropagation(); menu.classList.toggle('is-open'); } }),
            h('div', { class: 'bx-menu__list' },
                h('button', { type: 'button', onclick: () => moveTo(sel, sel - 1), disabled: sel === 0 }, '↑  Move up'),
                h('button', { type: 'button', onclick: () => moveTo(sel, sel + 1), disabled: sel === blocks.length - 1 }, '↓  Move down'),
                h('button', { type: 'button', onclick: () => duplicate(sel) }, '⧉  Duplicate'),
                h('button', { type: 'button', class: 'is-danger', onclick: () => remove(sel) }, '✕  Delete section')));
        const sw = h('input', { type: 'checkbox', 'aria-label': 'Show this section on the page', onchange: () => toggleVisible(sel) });
        sw.checked = !!b.is_active;
        box.append(h('div', { class: 'bx-insp-head' },
            h('span', { style: 'font-size:18px' }, d.icon),
            h('h2', { html: esc(d.label) + '<small>' + esc(d.desc || '') + '</small>' }),
            h('label', { class: 'bx-switch', 'data-tip': b.is_active ? 'Visible — click to hide' : 'Hidden — click to show' }, sw, h('span')),
            menu));
        if (!b.is_active) box.append(h('div', { class: 'bx-hidden-note' }, 'Hidden — visitors won’t see this section. Switch it on at the top to show it.'));

        const groups = {};
        (d.fields || []).forEach(f => { (groups[f.group || 'content'] ||= []).push(f); });
        Object.keys(GROUPS).concat(Object.keys(groups).filter(g => !GROUPS[g])).forEach(g => {
            if (!groups[g]) return;
            const det = h('details', { class: 'bx-group', open: g !== 'layout' },
                h('summary', {}, GROUPS[g] || g),
                h('div', { class: 'bx-group__body' }, groups[g].map(f => field(b, f))));
            box.append(det);
        });
        box.append(h('div', { class: 'bx-insp-foot' },
            h('button', { type: 'button', class: 'admin-btn admin-btn--sm', onclick: () => remove(sel) }, 'Delete section')));
    }
    document.addEventListener('click', () => document.querySelectorAll('.bx-menu.is-open').forEach(m => m.classList.remove('is-open')));

    /** A field changed: record it, update the canvas section. */
    function changed(b, key, v) {
        snap(sel + ':' + key);
        setVal(b, key, v);
        if (key === 'title') {
            const n = document.querySelector('.bx-item[data-i="' + sel + '"] .bx-item__name');
            if (n) n.innerHTML = esc(def(b).label) + (v ? '<small>' + esc(v) + '</small>' : '');
        }
        updateChrome();
        sectionRefresh(sel);
    }

    function field(b, f) {
        const wrap = h('div', { class: 'bx-field' });
        const label = h('label', {}, f.label);
        const hint = f.hint ? h('span', { class: 'bx-hint' }, f.hint) : null;
        const v = getVal(b, f.key);
        const set = val => changed(b, f.key, val);
        let ctl;
        switch (f.type) {
            case 'textarea': {
                ctl = h('textarea', { rows: 5, oninput: e => set(e.target.value) }); ctl.value = v; break;
            }
            case 'number': {
                ctl = h('input', { type: 'number', class: 'bx-input', step: 'any', value: v, oninput: e => set(e.target.value) }); break;
            }
            case 'select': {
                ctl = h('select', { onchange: e => set(e.target.value) },
                    Object.entries(f.options || {}).map(([ov, ol]) => { const o = h('option', { value: ov }, ol); o.selected = String(v) === String(ov); return o; }));
                break;
            }
            case 'category': {
                ctl = h('select', { onchange: e => set(e.target.value) },
                    h('option', { value: '' }, 'Featured products'),
                    CATS.map(c => { const o = h('option', { value: c.id }, 'Newest in ' + c.name); o.selected = String(v) === String(c.id); return o; }));
                break;
            }
            case 'link': {
                ctl = h('input', { type: 'text', class: 'bx-input', list: 'bx-links', value: v, placeholder: 'Pick a page or paste a link', oninput: e => set(e.target.value) });
                break;
            }
            case 'color': {
                const norm = normHex(v) || normHex(f.default) || '#000000';
                const sw = h('input', { type: 'color', value: norm, 'aria-label': f.label });
                const tx = h('input', { type: 'text', class: 'bx-input', value: v || f.default || '', placeholder: 'Theme default', maxlength: 7, spellcheck: 'false' });
                sw.addEventListener('input', () => { tx.value = sw.value; set(sw.value); });
                tx.addEventListener('input', () => { const n = normHex(tx.value); if (n) sw.value = n; set(tx.value.trim()); });
                ctl = h('div', { class: 'bx-color' }, sw, tx);
                break;
            }
            case 'toggle': {
                const on = f.on ?? '1', off = f.off ?? '';
                const r = raw(b, f.key);
                const cur = r === undefined || r === null ? (f.default ?? off) : String(r);
                const cb = h('input', { type: 'checkbox', onchange: e => set(e.target.checked ? on : off) });
                cb.checked = cur === on;
                wrap.append(h('label', { class: 'bx-toggle' }, h('span', {}, f.label), h('span', { class: 'bx-switch' }, cb, h('span'))));
                if (hint) wrap.append(hint);
                return wrap;
            }
            case 'range': {
                const unit = f.unit || '';
                const hasDefault = f.default !== undefined;
                const cur = v === '' ? (hasDefault ? f.default : '') : v;
                const out = h('output', {}, cur === '' ? 'Default' : cur + unit);
                const rng = h('input', { type: 'range', min: f.min ?? 0, max: f.max ?? 100, step: f.step ?? 1, value: cur === '' ? (f.min ?? 0) : cur, 'aria-label': f.label });
                rng.addEventListener('input', () => { out.textContent = rng.value + unit; set(rng.value); });
                ctl = h('div', { class: 'bx-range' }, rng, out);
                if (!hasDefault) label.append(h('button', { type: 'button', class: 'bx-reset', 'data-tip': 'Use the theme’s default spacing',
                    onclick: () => { out.textContent = 'Default'; rng.value = f.min ?? 0; set(''); } }, 'Reset'));
                break;
            }
            case 'image':
            case 'video': {
                ctl = mediaControl(f.type, v, set);
                break;
            }
            case 'list': {
                ctl = listControl(f, v, set);
                break;
            }
            default:
                ctl = h('input', { type: 'text', class: 'bx-input', value: v, oninput: e => set(e.target.value) });
        }
        wrap.append(label, ctl);
        if (hint) wrap.append(hint);
        return wrap;
    }

    /** Thumbnail + Choose/Remove, backed by the shared media picker. */
    function mediaControl(kind, value, set, compact) {
        const box = h('div', { class: 'bx-media' });
        function draw(val) {
            box.innerHTML = '';
            const isVid = kind === 'video' || isVideoPath(val);
            const th = h('button', { type: 'button', class: 'bx-media__thumb', 'data-tip': val ? 'Change ' + kind : 'Choose ' + (kind === 'video' ? 'a video' : 'an image'), onclick: pickIt });
            if (val && !isVid) th.append(h('img', { src: thumb(val), alt: '', loading: 'lazy' }));
            else if (val && isVid && box._poster) th.append(h('img', { src: thumb(box._poster), alt: '' }));
            else th.innerHTML = isVid ? ICON.film : ICON.img;
            const meta = h('div', { class: 'bx-media__meta' },
                h('span', { class: 'bx-media__name', title: val || '' }, val ? String(val).split('/').pop() : (kind === 'video' ? 'No video chosen' : 'No image chosen')),
                h('div', { class: 'bx-media__btns' },
                    h('button', { type: 'button', class: 'bx-mini', onclick: pickIt }, val ? 'Change' : (compact ? 'Choose' : 'Choose / upload')),
                    val ? h('button', { type: 'button', class: 'bx-mini bx-mini--ghost', onclick: () => { box._poster = null; draw(''); set(''); } }, 'Remove') : null));
            box.append(th, meta);
        }
        async function pickIt() {
            const res = await AdnMedia.pick({ accept: kind });
            if (!res || !res.length) return;
            box._poster = res[0].poster || null;
            draw(res[0].filepath);
            set(res[0].filepath);
        }
        draw(value);
        return box;
    }

    /** Repeating rows stored as lines of "a | b | c" (the format the renderer reads). */
    function listControl(f, value, set) {
        const cols = f.columns || [];
        const parse = s => String(s || '').split('\n').map(l => l.trim()).filter(Boolean)
            .map(l => { const p = l.split('|').map(x => x.trim()); return cols.map((c, i) => p[i] ?? ''); });
        const serialize = rows => rows.map(r => { const a = r.slice(); while (a.length > 1 && a[a.length - 1] === '') a.pop(); return a.join(' | '); }).join('\n');
        let rows = parse(value);
        const openSet = new Set();
        const box = h('div', { class: 'bx-listed' });
        const commit = () => set(serialize(rows));
        const mediaCol = cols.findIndex(c => c.type === 'image' || c.type === 'video');
        const textCol = cols.findIndex(c => c.type === 'text');
        const single = cols.length === 1 && mediaCol === 0;

        function rowTitle(r, i) {
            const t = textCol >= 0 && r[textCol] ? r[textCol] : (mediaCol >= 0 && r[mediaCol] ? r[mediaCol].split('/').pop() : '');
            return t || (f.item || 'Item') + ' ' + (i + 1);
        }
        function draw() {
            box.innerHTML = '';
            rows.forEach((r, i) => {
                const m = mediaCol >= 0 ? r[mediaCol] : '';
                const th = m && !isVideoPath(m) ? h('img', { class: 'bx-row__thumb', src: thumb(m, 80), alt: '' })
                         : h('span', { class: 'bx-row__thumb', html: m ? '▶' : (single ? '' : '#') });
                const row = h('div', { class: 'bx-row' + (openSet.has(i) ? ' is-open' : '') });
                const head = h('div', { class: 'bx-row__head', onclick: () => { if (single) return; openSet.has(i) ? openSet.delete(i) : openSet.add(i); row.classList.toggle('is-open'); } },
                    th, h('span', { class: 'bx-row__title' }, rowTitle(r, i)),
                    h('button', { type: 'button', class: 'bx-row__btn', 'data-tip': 'Move up', disabled: i === 0, onclick: e => { e.stopPropagation(); if (i) { [rows[i - 1], rows[i]] = [rows[i], rows[i - 1]]; commit(); draw(); } } }, '↑'),
                    h('button', { type: 'button', class: 'bx-row__btn', 'data-tip': 'Move down', onclick: e => { e.stopPropagation(); if (i < rows.length - 1) { [rows[i + 1], rows[i]] = [rows[i], rows[i + 1]]; commit(); draw(); } } }, '↓'),
                    h('button', { type: 'button', class: 'bx-row__btn is-del', 'data-tip': 'Remove', onclick: e => { e.stopPropagation(); rows.splice(i, 1); openSet.clear(); commit(); draw(); } }, '✕'));
                row.append(head);
                if (!single) {
                    const body = h('div', { class: 'bx-row__body' });
                    cols.forEach((c, ci) => {
                        const setCell = val => { rows[i][ci] = val; commit(); head.querySelector('.bx-row__title').textContent = rowTitle(rows[i], i); };
                        let ctl;
                        if (c.type === 'image' || c.type === 'video') ctl = mediaControl(c.type, r[ci], val => { setCell(val); draw(); }, true);
                        else if (c.type === 'number') ctl = h('input', { type: 'number', class: 'bx-input', min: c.min, max: c.max, value: r[ci], placeholder: c.placeholder || '', oninput: e => setCell(e.target.value) });
                        else if (c.key === 'text') { ctl = h('textarea', { rows: 3, placeholder: c.placeholder || '', oninput: e => setCell(e.target.value.replace(/\s*\n\s*/g, ' ').replace(/\|/g, '/')) }); ctl.value = r[ci]; }
                        else ctl = h('input', { type: 'text', class: 'bx-input', value: r[ci], placeholder: c.placeholder || '', oninput: e => setCell(e.target.value.replace(/\|/g, '/')) });
                        body.append(h('div', {}, h('span', { class: 'bx-label' }, c.label), ctl));
                    });
                    row.append(body);
                }
                box.append(row);
            });
            box.append(h('button', { type: 'button', class: 'bx-list-add', onclick: addRow }, '+ Add ' + (f.item || 'item').toLowerCase() + (mediaCol === 0 ? 's' : '')));
        }
        async function addRow() {
            // Lists that start with a picture or video: pick several at once,
            // one new row each; captions etc. can be filled in afterwards.
            if (mediaCol === 0) {
                const res = await AdnMedia.pick({ accept: cols[0].type, multiple: true, title: 'Choose ' + (f.item || 'item').toLowerCase() + 's' });
                if (!res || !res.length) return;
                res.forEach(m => rows.push(cols.map((c, ci) => ci === 0 ? m.filepath : (c.type === 'number' ? '5' : ''))));
                if (!single) { openSet.clear(); openSet.add(rows.length - 1); }
            } else {
                rows.push(cols.map(c => c.type === 'number' ? '5' : ''));
                openSet.clear(); openSet.add(rows.length - 1);
            }
            commit(); draw();
        }
        draw();
        return box;
    }

    function normHex(v) {
        let s = String(v ?? '').trim();
        if (!s) return '';
        if (s[0] !== '#') { if (/^([0-9a-f]{3}|[0-9a-f]{6})$/i.test(s)) s = '#' + s; else return ''; }
        if (/^#[0-9a-f]{3}$/i.test(s)) s = '#' + s[1] + s[1] + s[2] + s[2] + s[3] + s[3];
        return /^#[0-9a-f]{6}$/i.test(s) ? s.toLowerCase() : '';
    }

    // ── In-place edits from the canvas ──
    // A target is {i, key, kind, row?, col?}: a plain setting, one paragraph
    // of a body text (kind 'para', row = paragraph), or one cell of a list
    // setting such as slides or reviews (row/col).
    function fieldDef(b, key) { return (def(b).fields || []).find(f => f.key === key); }
    function listRows(b, key) {
        const cols = (fieldDef(b, key) || {}).columns || [{}];
        return String(getVal(b, key) || '').split('\n').map(l => l.trim()).filter(Boolean)
            .map(l => { const p = l.split('|').map(x => x.trim()); return cols.map((c, ci) => p[ci] ?? ''); });
    }
    const listJoin = rows => rows.map(r => { const a = r.slice(); while (a.length > 1 && a[a.length - 1] === '') a.pop(); return a.join(' | '); }).join('\n');
    function inlineGet(t) {
        const b = blocks[t.i]; if (!b) return '';
        if (t.kind === 'para') return String(getVal(b, t.key) || '').replace(/\r\n/g, '\n').split('\n\n')[t.row] ?? '';
        if (t.row != null) return (listRows(b, t.key)[t.row] || [])[t.col] ?? '';
        return getVal(b, t.key);
    }
    function inlineSet(t, value) {
        const b = blocks[t.i]; if (!b) return;
        let next;
        if (t.kind === 'para') {
            const parts = String(getVal(b, t.key) || '').replace(/\r\n/g, '\n').split('\n\n');
            parts[t.row] = value;
            next = parts.filter((p, n) => p.trim() !== '' || n !== t.row).join('\n\n');
        } else if (t.row != null) {
            const rows = listRows(b, t.key);
            if (!rows[t.row]) return;
            rows[t.row][t.col] = String(value).replace(/\|/g, '/');
            next = listJoin(rows);
        } else next = value;
        if (next === getVal(b, t.key)) { sectionRefresh(t.i); return; } // unchanged: just restore the look
        sel = t.i;
        changed(b, t.key, next);   // undo step + canvas refresh
        renderList(); renderInspector();
    }

    // ── Canvas ──
    const frame = $('bx-frame');
    let frameReady = false, pendingScroll = 0, fullReq = 0, scrollToSel = false;
    function frameCall(fn, ...args) {
        try { const api = frame.contentWindow && frame.contentWindow.__bld; if (frameReady && api) return api[fn](...args); } catch (e) {}
    }
    function loading(on) { $('bx-loading').classList.toggle('is-on', on); }

    window.__bldHost = msg => {
        if (msg.type === 'ready') {
            frameReady = true; loading(false);
            try { frame.contentWindow.scrollTo(0, pendingScroll); } catch (e) {}
            if (sel >= 0) frameCall('select', sel, scrollToSel);
            scrollToSel = false;
        } else if (msg.type === 'hover') {
            document.querySelectorAll('.bx-item').forEach(x => x.classList.toggle('is-hover', +x.dataset.i === msg.i));
        } else if (msg.type === 'select') {
            select(msg.i, false);
        } else if (msg.type === 'get') {
            return inlineGet(msg);
        } else if (msg.type === 'text') {
            inlineSet(msg, msg.value);
        } else if (msg.type === 'media') {
            const kind = msg.kind === 'video' ? 'video' : 'image';
            AdnMedia.pick({ accept: kind, title: kind === 'video' ? 'Choose a video' : 'Choose an image' }).then(res => {
                if (res && res.length) inlineSet(msg, res[0].filepath);
            });
        } else if (msg.type === 'save') {
            save();
        } else if (msg.type === 'tool') {
            const i = msg.i; if (i < 0) return;
            ({ up: () => moveTo(i, i - 1), down: () => moveTo(i, i + 1), dup: () => duplicate(i), hide: () => toggleVisible(i), del: () => remove(i) }[msg.act] || (() => {}))();
        }
    };

    function fullRefresh() {
        clearTimeout(secTimer);
        const req = ++fullReq;
        try { pendingScroll = frame.contentWindow ? frame.contentWindow.scrollY : 0; } catch (e) { pendingScroll = 0; }
        loading(true);
        fetch(BASE + '/api/section-preview.php?builder=1', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(blocks)
        }).then(r => r.text()).then(html => {
            if (req !== fullReq) return;
            frameReady = false;
            frame.srcdoc = html;
        }).catch(() => { loading(false); status('Preview failed to load', 'err'); });
    }
    let secTimer = null;
    function sectionRefresh(i) {
        clearTimeout(secTimer);
        secTimer = setTimeout(() => {
            const b = blocks[i];
            if (!b) return;
            if (!b.is_active) return; // hidden: nothing on the canvas to update
            loading(true);
            const req = fullReq;
            fetch(BASE + '/api/section-preview.php?fragment=1', {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ i, section: b })
            }).then(r => r.text()).then(html => {
                loading(false);
                if (req !== fullReq) return; // a full reload replaced the canvas meanwhile
                if (!frameCall('replace', i, html)) fullRefresh();
                else frameCall('select', sel, false);
            }).catch(() => { loading(false); fullRefresh(); });
        }, 300);
    }

    // ── Top bar ──
    document.querySelectorAll('.bx-devices button').forEach(btn => btn.addEventListener('click', () => {
        document.querySelectorAll('.bx-devices button').forEach(x => x.classList.toggle('is-on', x === btn));
        $('bx-frame-wrap').style.width = btn.dataset.w;
        setTimeout(() => frameCall('select', sel, false), 350);
    }));
    $('bx-undo').onclick = () => restore(undoStack, redoStack);
    $('bx-redo').onclick = () => restore(redoStack, undoStack);
    $('bx-page').addEventListener('change', e => {
        if (isDirty() && !confirm('You have unsaved changes on this page. Leave without saving?')) { e.target.value = PAGE; return; }
        saved = JSON.stringify(blocks); // skip the beforeunload prompt
        location.href = BASE + '/admin/builder.php?page=' + encodeURIComponent(e.target.value);
    });
    $('bx-preview').onclick = () => {
        const form = h('form', { method: 'post', action: BASE + '/api/section-preview.php', target: '_blank' },
            h('input', { type: 'hidden', name: 'mode', value: 'full' }),
            h('input', { type: 'hidden', name: 'page', value: PAGE }),
            h('input', { type: 'hidden', name: 'sections', value: JSON.stringify(blocks) }));
        document.body.append(form); form.submit(); form.remove();
    };
    $('bx-save').onclick = save;
    function save() {
        const btn = $('bx-save');
        btn.disabled = true; btn.textContent = 'Saving…'; status('');
        const fd = new FormData();
        fd.append('action', 'save_all'); fd.append('page', PAGE); fd.append('sections', JSON.stringify(blocks));
        fetch(BASE + '/api/sections.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json()).then(d => {
                btn.disabled = false; btn.textContent = 'Save';
                if (!d.success) { status(d.message || 'Save failed — nothing was changed.', 'err'); return; }
                if (Array.isArray(d.ids)) d.ids.forEach((id, i) => { if (blocks[i]) blocks[i].id = id; });
                saved = JSON.stringify(blocks);
                updateChrome();
                status('Saved — live on the site', 'ok');
            }).catch(() => { btn.disabled = false; btn.textContent = 'Save'; status('Could not reach the server — nothing was saved.', 'err'); });
    }

    // ── Add-section modal ──
    $('bx-types').innerHTML = '';
    Object.entries(REG).forEach(([type, d]) => $('bx-types').append(
        h('button', { type: 'button', class: 'bx-type', onclick: () => addSection(type) },
            h('span', { class: 'bx-type__icon' }, d.icon),
            h('span', {}, h('b', {}, d.label), d.desc || ''))));
    function openAdd() { $('bx-addmodal').hidden = false; $('bx-types').querySelector('button')?.focus(); }
    function closeAdd() { $('bx-addmodal').hidden = true; }
    $('bx-add').onclick = openAdd;
    $('bx-addmodal').addEventListener('click', e => { if (e.target.id === 'bx-addmodal' || e.target.closest('[data-close]')) closeAdd(); });

    // ── Shortcuts ──
    document.addEventListener('keydown', e => {
        const mod = e.ctrlKey || e.metaKey;
        if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); save(); }
        else if (mod && e.key.toLowerCase() === 'z' && !e.shiftKey && !/^(INPUT|TEXTAREA)$/.test(e.target.tagName)) { e.preventDefault(); restore(undoStack, redoStack); }
        else if (mod && (e.key.toLowerCase() === 'y' || (e.key.toLowerCase() === 'z' && e.shiftKey)) && !/^(INPUT|TEXTAREA)$/.test(e.target.tagName)) { e.preventDefault(); restore(redoStack, undoStack); }
        else if (e.key === 'Escape' && !$('bx-addmodal').hidden) closeAdd();
    });

    // ── Tooltips for anything with data-tip ──
    const tip = $('bx-tip');
    let tipT = null;
    document.addEventListener('mouseover', e => {
        const t = e.target.closest && e.target.closest('[data-tip]');
        clearTimeout(tipT);
        if (!t) { tip.hidden = true; return; }
        tipT = setTimeout(() => {
            tip.textContent = t.dataset.tip; tip.hidden = false;
            const r = t.getBoundingClientRect(), w = tip.offsetWidth;
            tip.style.left = Math.max(6, Math.min(innerWidth - w - 6, r.left + r.width / 2 - w / 2)) + 'px';
            tip.style.top = (r.bottom + 8 + tip.offsetHeight > innerHeight ? r.top - tip.offsetHeight - 8 : r.bottom + 8) + 'px';
        }, 350);
    });
    document.addEventListener('mousedown', () => { clearTimeout(tipT); tip.hidden = true; });

    // Shared suggestions for link fields.
    document.body.append(h('datalist', { id: 'bx-links' }, LINKS.map(([v, l]) => h('option', { value: v }, l))));

    renderList(); renderInspector(); updateChrome(); fullRefresh();
})();
