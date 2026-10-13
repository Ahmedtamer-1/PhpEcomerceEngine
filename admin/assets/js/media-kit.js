/**
 * Media kit — shared by the Media page, the page builder and Settings.
 *
 *   AdnMedia.upload(files, onProgress)  → Promise<item[]>
 *   AdnMedia.pick({accept, multiple, title}) → Promise<item[] | null>
 *
 * Videos are optimised in this browser before they are sent: re-encoded to
 * H.264 MP4 at most 1920px on the long side with a sensible bitrate, with the
 * index at the front so playback starts at once. A still frame is captured as
 * the poster. Shared hosting has no video encoder, so doing it here is what
 * makes it possible at all. If the browser can't encode (older Safari/Firefox),
 * the original is uploaded and the server does what it can.
 *
 * Uploads go in chunks sized to the server's limit, so large files work.
 */
(function () {
    'use strict';
    const BASE = (window.BASE_URL || document.querySelector('meta[name="site-url"]')?.content || '').replace(/\/$/, '');
    const API = BASE + '/api/media.php';
    const MB = 1024 * 1024;
    const XHR = { 'X-Requested-With': 'XMLHttpRequest' };

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const fmtSize = b => b >= MB ? (b / MB).toFixed(b >= 100 * MB ? 0 : 1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
    const fmtDur = s => { s = Math.round(s || 0); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); };
    const isVideo = f => /^video\//.test(f.type) || /\.(mp4|mov|m4v|webm)$/i.test(f.name);

    let capsP = null;
    function caps() {
        capsP ??= fetch(API + '?caps=1', { headers: XHR }).then(r => r.json()).catch(() => ({ chunk_size: 1.5 * MB }));
        return capsP;
    }

    // ── Video helpers ──────────────────────────────────────────────
    function probe(blob) {
        return new Promise(resolve => {
            const v = document.createElement('video');
            v.muted = true; v.preload = 'metadata'; v.playsInline = true;
            const url = URL.createObjectURL(blob);
            const done = r => { URL.revokeObjectURL(url); resolve(r); };
            v.onloadedmetadata = () => done({ width: v.videoWidth, height: v.videoHeight, duration: v.duration });
            v.onerror = () => done(null);
            setTimeout(() => done(null), 15000);
            v.src = url;
        });
    }

    /** Grab a frame (~0.5s in) as a JPEG, at most 1920px wide. */
    function poster(blob) {
        return new Promise(resolve => {
            const v = document.createElement('video');
            v.muted = true; v.preload = 'auto'; v.playsInline = true;
            const url = URL.createObjectURL(blob);
            let settled = false;
            const finish = r => { if (settled) return; settled = true; URL.revokeObjectURL(url); resolve(r); };
            v.onloadeddata = () => { v.currentTime = Math.min(0.5, (v.duration || 1) / 2); };
            v.onseeked = () => {
                const scale = Math.min(1, 1920 / Math.max(1, v.videoWidth));
                const c = document.createElement('canvas');
                c.width = Math.round(v.videoWidth * scale); c.height = Math.round(v.videoHeight * scale);
                if (!c.width || !c.height) return finish(null);
                c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
                c.toBlob(b => finish(b), 'image/jpeg', 0.82);
            };
            v.onerror = () => finish(null);
            setTimeout(() => finish(null), 20000);
            v.src = url;
        });
    }

    let bunnyP = null;
    async function compress(file, info, onProgress) {
        if (typeof VideoEncoder === 'undefined' || typeof VideoDecoder === 'undefined') return null;
        // Already small and web-friendly: leave it alone.
        const long = Math.max(info?.width || 0, info?.height || 0);
        if (/\.mp4$/i.test(file.name) && file.size < 8 * MB && long && long <= 1920) return null;
        bunnyP ??= import('https://cdn.jsdelivr.net/npm/mediabunny@1.61.0/+esm');
        const mb = await bunnyP;
        const input = new mb.Input({ formats: mb.ALL_FORMATS, source: new mb.BlobSource(file) });
        const track = await input.getPrimaryVideoTrack();
        if (!track) return null;
        const w = track.displayWidth, h = track.displayHeight;
        const scale = Math.min(1, 1920 / Math.max(w, h));
        const outW = Math.max(2, Math.round(w * scale / 2) * 2);
        const bitrate = Math.max(900e3, Math.min(6e6, Math.round(outW * Math.round(h * scale) * 2.1)));
        const output = new mb.Output({ format: new mb.Mp4OutputFormat({ fastStart: 'in-memory' }), target: new mb.BufferTarget() });
        const conv = await mb.Conversion.init({
            input, output,
            video: { width: outW, codec: 'avc', bitrate, forceTranscode: true },
            audio: { codec: 'aac', bitrate: 128e3 },
        });
        if (!conv.isValid) return null;
        conv.onProgress = p => onProgress && onProgress(p);
        await conv.execute();
        const blob = new Blob([output.target.buffer], { type: 'video/mp4' });
        // Keep the original if re-encoding didn't actually help.
        if (blob.size >= file.size * 0.92 && /\.mp4$/i.test(file.name) && long <= 1920) return null;
        return { blob, width: outW, height: Math.round(h * scale / 2) * 2 };
    }

    // ── Upload ─────────────────────────────────────────────────────
    function post(fd, onUp) {
        return new Promise((resolve, reject) => {
            const x = new XMLHttpRequest();
            x.open('POST', API);
            x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            if (onUp) x.upload.onprogress = e => e.lengthComputable && onUp(e.loaded);
            x.onload = () => {
                let d = null; try { d = JSON.parse(x.responseText); } catch (e) {}
                if (x.status >= 200 && x.status < 300 && d && d.success !== false) resolve(d);
                else reject(new Error((d && d.message) || ('Server error ' + x.status)));
            };
            x.onerror = () => reject(new Error('Network error — check the connection and try again.'));
            x.send(fd);
        });
    }

    async function uploadOne(file, progress) {
        const c = await caps();
        if (c.max_size && file.size > c.max_size) throw new Error(file.name + ' is larger than ' + fmtSize(c.max_size) + '.');
        let body = file, name = file.name, meta = { original_size: file.size }, posterBlob = null;

        if (isVideo(file)) {
            progress('Reading video…', 0);
            const info = await probe(file);
            try {
                const out = await compress(file, info, p => progress('Optimizing video… ' + Math.round(p * 100) + '%', p * 0.6));
                if (out) {
                    body = out.blob;
                    name = file.name.replace(/\.[^.]+$/, '') + '.mp4';
                    meta.width = out.width; meta.height = out.height;
                }
            } catch (e) {
                console.warn('[media] in-browser compression failed, uploading original', e);
            }
            if (!meta.width && info) { meta.width = info.width; meta.height = info.height; }
            if (info && isFinite(info.duration)) meta.duration = info.duration;
            progress('Capturing poster…', 0.6);
            posterBlob = await poster(body);
        }

        const share = isVideo(file) ? 0.4 : 1, start = 1 - share;
        const id = Array.from(crypto.getRandomValues(new Uint8Array(12)), b => b.toString(16).padStart(2, '0')).join('');
        const size = c.chunk_size || 1.5 * MB;
        const total = Math.max(1, Math.ceil(body.size / size));
        for (let i = 0; i < total; i++) {
            const fd = new FormData();
            fd.append('action', 'chunk'); fd.append('upload_id', id); fd.append('index', i);
            fd.append('chunk', body.slice(i * size, (i + 1) * size), 'chunk');
            let tries = 0;
            for (;;) {
                try {
                    await post(fd, sent => progress('Uploading… ' + fmtSize(Math.min(body.size, i * size + sent)) + ' of ' + fmtSize(body.size),
                                                     start + share * Math.min(1, (i * size + sent) / body.size)));
                    break;
                } catch (e) {
                    if (++tries >= 3 || /out of order|expired|larger/.test(e.message)) throw e;
                    await new Promise(r => setTimeout(r, 1200 * tries));
                }
            }
        }
        progress(isVideo(file) ? 'Finishing up…' : 'Optimizing…', 0.99);
        const fd = new FormData();
        fd.append('action', 'finish'); fd.append('upload_id', id); fd.append('filename', name);
        Object.entries(meta).forEach(([k, v]) => v != null && fd.append(k, v));
        if (posterBlob) fd.append('poster', posterBlob, 'poster.jpg');
        const d = await post(fd);
        return d.item;
    }

    /**
     * Upload files one after another. onProgress({index, count, name, label, pct})
     * Resolves with the items that uploaded; errors are reported per file.
     */
    async function upload(files, onProgress) {
        files = Array.from(files || []);
        const done = [], errors = [];
        for (let i = 0; i < files.length; i++) {
            const f = files[i];
            const report = (label, pct) => onProgress && onProgress({ index: i, count: files.length, name: f.name, label, pct: (i + pct) / files.length });
            try {
                done.push(await uploadOne(f, report));
            } catch (e) {
                errors.push(f.name + ': ' + e.message);
                onProgress && onProgress({ index: i, count: files.length, name: f.name, label: 'Failed — ' + e.message, pct: (i + 1) / files.length, error: true });
            }
        }
        if (errors.length && window.adminToast) adminToast(errors.join('\n'), 'error');
        return done;
    }

    // ── Picker modal ───────────────────────────────────────────────
    let modal = null, state = null;

    function buildModal() {
        modal = document.createElement('div');
        modal.className = 'mk-overlay';
        modal.innerHTML = `
        <div class="mk" role="dialog" aria-modal="true" aria-labelledby="mk-title">
            <div class="mk__head">
                <h3 id="mk-title">Choose media</h3>
                <div class="mk__tabs" role="tablist"></div>
                <input type="search" class="mk__search" placeholder="Search files…" aria-label="Search media">
                <button type="button" class="admin-btn admin-btn--primary admin-btn--sm mk__upload-btn">Upload</button>
                <input type="file" class="mk__file" multiple hidden>
                <button type="button" class="mk__x" aria-label="Close">×</button>
            </div>
            <div class="mk__progress" hidden><div class="mk__bar"><span></span></div><span class="mk__plabel"></span></div>
            <div class="mk__body"><div class="mk__grid"></div><div class="mk__drop">Drop files to upload</div></div>
            <div class="mk__foot">
                <span class="mk__info">Hover a file for details · double-click to choose it</span>
                <button type="button" class="admin-btn admin-btn--sm mk__cancel">Cancel</button>
                <button type="button" class="admin-btn admin-btn--primary admin-btn--sm mk__ok" disabled>Choose</button>
            </div>
        </div>`;
        document.body.appendChild(modal);
        const $ = s => modal.querySelector(s);
        $('.mk__x').onclick = $('.mk__cancel').onclick = () => close(null);
        modal.addEventListener('mousedown', e => { if (e.target === modal) close(null); });
        $('.mk__ok').onclick = () => close(state.selected.slice());
        $('.mk__upload-btn').onclick = () => $('.mk__file').click();
        $('.mk__file').onchange = e => { doUpload(e.target.files); e.target.value = ''; };
        let t; $('.mk__search').oninput = e => { clearTimeout(t); t = setTimeout(() => { state.search = e.target.value.trim(); load(1); }, 250); };
        const body = $('.mk__body');
        body.addEventListener('scroll', () => {
            if (body.scrollTop + body.clientHeight > body.scrollHeight - 200 && !state.loading && state.page < state.pages) load(state.page + 1);
        });
        ['dragenter', 'dragover'].forEach(ev => body.addEventListener(ev, e => { if (e.dataTransfer?.types?.includes('Files')) { e.preventDefault(); body.classList.add('is-drag'); } }));
        ['dragleave', 'drop'].forEach(ev => body.addEventListener(ev, e => { e.preventDefault(); if (ev === 'drop' || e.target === body) body.classList.remove('is-drag'); }));
        body.addEventListener('drop', e => doUpload(e.dataTransfer.files));
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && modal.classList.contains('is-open')) close(null); });
    }

    function renderTabs(counts) {
        const tabs = state.accept === 'any' ? [['', 'All'], ['image', 'Images'], ['video', 'Videos']] : [];
        modal.querySelector('.mk__tabs').innerHTML = tabs.map(([k, l]) =>
            `<button type="button" role="tab" class="${state.type === k ? 'is-on' : ''}" data-t="${k}">${l}${counts ? ' <small>' + (counts[k || 'all'] ?? '') + '</small>' : ''}</button>`).join('');
        modal.querySelectorAll('.mk__tabs button').forEach(b => b.onclick = () => { state.type = b.dataset.t; load(1); });
    }

    function tile(m) {
        const sel = state.selected.some(x => x.id === m.id);
        const dims = m.width ? m.width + '×' + m.height : '';
        const tip = [m.filename, m.filetype === 'video' ? 'Video' + (m.duration ? ' · ' + fmtDur(m.duration) : '') : 'Image', dims, fmtSize(m.filesize)].filter(Boolean).join(' · ');
        const img = m.thumb ? `<img src="${esc(m.thumb)}" alt="" loading="lazy">` : `<span class="mk__noposter">🎬</span>`;
        return `<button type="button" class="mk__tile ${sel ? 'is-sel' : ''}" data-id="${m.id}" title="${esc(tip)}">
            ${img}
            ${m.filetype === 'video' ? `<span class="mk__badge">▶ ${m.duration ? fmtDur(m.duration) : 'Video'}</span>` : ''}
            <span class="mk__check">✓</span>
            <span class="mk__name">${esc(m.filename)}<small>${esc([dims, fmtSize(m.filesize)].filter(Boolean).join(' · '))}</small></span>
        </button>`;
    }

    function load(page) {
        state.loading = true;
        const grid = modal.querySelector('.mk__grid');
        if (page === 1) { state.items = []; grid.innerHTML = '<div class="mk__empty">Loading…</div>'; renderTabs(state.counts); }
        const q = new URLSearchParams({ page });
        if (state.search) q.set('search', state.search);
        if (state.type) q.set('type', state.type);
        const req = ++state.req;
        fetch(API + '?' + q, { headers: XHR }).then(r => r.json()).then(d => {
            if (req !== state.req) return;
            state.loading = false; state.page = d.page; state.pages = d.pages; state.counts = d.counts;
            state.items = state.items.concat(d.items || []);
            if (page === 1) renderTabs(d.counts);
            if (!state.items.length) {
                grid.innerHTML = `<div class="mk__empty">${state.search ? 'Nothing matches “' + esc(state.search) + '”.' : 'Nothing here yet. Upload or drop files to add some.'}</div>`;
                return;
            }
            if (page === 1) grid.innerHTML = '';
            grid.insertAdjacentHTML('beforeend', (d.items || []).map(tile).join(''));
        }).catch(() => { state.loading = false; grid.innerHTML = '<div class="mk__empty">Could not load media.</div>'; });
    }

    function toggle(id, choose) {
        const m = state.items.find(x => x.id === id);
        if (!m) return;
        const i = state.selected.findIndex(x => x.id === id);
        if (state.multiple) { i >= 0 ? state.selected.splice(i, 1) : state.selected.push(m); }
        else state.selected = i >= 0 && !choose ? [] : [m];
        modal.querySelectorAll('.mk__tile').forEach(t => t.classList.toggle('is-sel', state.selected.some(x => x.id === +t.dataset.id)));
        const ok = modal.querySelector('.mk__ok');
        ok.disabled = !state.selected.length;
        ok.textContent = state.multiple && state.selected.length > 1 ? 'Choose ' + state.selected.length : 'Choose';
        const s = state.selected[state.selected.length - 1];
        modal.querySelector('.mk__info').textContent = s
            ? s.filename + ' · ' + [s.width ? s.width + '×' + s.height : '', fmtSize(s.filesize), s.duration ? fmtDur(s.duration) : ''].filter(Boolean).join(' · ')
            : 'Hover a file for details · double-click to choose it';
        if (choose && state.selected.length) close(state.selected.slice());
    }

    async function doUpload(files) {
        files = Array.from(files || []).filter(f => state.accept === 'any' || (state.accept === 'video' ? isVideo(f) : !isVideo(f)));
        if (!files.length) { window.adminToast && adminToast('Those files don’t fit this field (' + state.accept + 's only).', 'error'); return; }
        const pr = modal.querySelector('.mk__progress');
        pr.hidden = false;
        const items = await upload(files, p => {
            pr.querySelector('.mk__bar span').style.width = Math.round(p.pct * 100) + '%';
            pr.querySelector('.mk__plabel').textContent = (p.count > 1 ? `(${p.index + 1}/${p.count}) ` : '') + p.name + ' — ' + p.label;
        });
        pr.hidden = true;
        state.search = ''; modal.querySelector('.mk__search').value = '';
        load(1);
        // Select what was just uploaded so one more click finishes the job.
        if (items.length) {
            state.selected = state.multiple ? state.selected.concat(items) : [items[items.length - 1]];
            state.items = state.items.concat(items);
            const ok = modal.querySelector('.mk__ok'); ok.disabled = false;
            setTimeout(() => modal.querySelectorAll('.mk__tile').forEach(t => t.classList.toggle('is-sel', state.selected.some(x => x.id === +t.dataset.id))), 600);
        }
    }

    let resolver = null;
    function close(result) {
        modal.classList.remove('is-open');
        document.body.style.overflow = state?.prevOverflow ?? '';
        const r = resolver; resolver = null;
        r && r(result);
    }

    function pick(opts = {}) {
        if (!modal) buildModal();
        if (resolver) close(null);
        const accept = opts.accept || 'image';
        state = { accept, multiple: !!opts.multiple, type: accept === 'any' ? '' : accept, search: '', page: 1, pages: 1,
                  items: [], selected: [], loading: false, req: 0, counts: null, prevOverflow: document.body.style.overflow };
        modal.querySelector('#mk-title').textContent = opts.title || (accept === 'video' ? 'Choose a video' : accept === 'image' ? (state.multiple ? 'Choose images' : 'Choose an image') : 'Choose media');
        modal.querySelector('.mk__file').accept = accept === 'video' ? 'video/*' : accept === 'image' ? 'image/*' : 'image/*,video/*';
        modal.querySelector('.mk__ok').disabled = true;
        modal.querySelector('.mk__ok').textContent = 'Choose';
        modal.querySelector('.mk__search').value = '';
        modal.querySelector('.mk__info').textContent = 'Hover a file for details · double-click to choose it';
        const grid = modal.querySelector('.mk__grid');
        grid.onclick = e => { const t = e.target.closest('.mk__tile'); if (t) toggle(+t.dataset.id, false); };
        grid.ondblclick = e => { const t = e.target.closest('.mk__tile'); if (t) toggle(+t.dataset.id, true); };
        modal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        load(1);
        setTimeout(() => modal.querySelector('.mk__search').focus(), 50);
        return new Promise(r => { resolver = r; });
    }

    window.AdnMedia = { upload, pick, caps, fmtSize, fmtDur, isVideo };
})();
