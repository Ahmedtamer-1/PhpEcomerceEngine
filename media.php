<?php
/**
 * Admin — Media Library
 * WordPress-style media manager with upload, browse, and select.
 */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Media Library';
$adminPage = 'media';

require_once __DIR__ . '/includes/header.php';
?>


<div class="admin-toolbar">
    <div class="admin-toolbar__left" style="display:flex;align-items:center;gap:12px">
        <span style="font-size:.85rem;color:var(--a-muted)" id="media-count">Loading…</span>
        <button class="admin-btn admin-btn--sm" id="btn-select-all" onclick="toggleSelectAll()" style="display:none">Select All</button>
    </div>
    <div class="admin-toolbar__right">
        <button class="admin-btn admin-btn--primary" id="btn-upload-media" onclick="document.getElementById('media-upload-input').click()">
            <svg viewBox="0 0 24 24"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
            Upload Files
        </button>
        <input type="file" id="media-upload-input" multiple accept="image/*" style="display:none">
    </div>
</div>

<!-- Upload Drop Zone -->
<div class="media-dropzone" id="media-dropzone">
    <div class="media-dropzone__inner">
        <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" style="opacity:.4;margin-bottom:16px">
            <polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/>
            <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>
        </svg>
        <p>Drop files here to upload</p>
        <span style="font-size:.72rem;color:var(--a-muted);margin-top:4px">or click "Upload Files" above</span>
    </div>
</div>

<!-- Upload Progress -->
<div class="media-upload-progress" id="media-upload-progress" style="display:none">
    <div class="media-upload-progress__bar"><div class="media-upload-progress__fill" id="upload-progress-fill"></div></div>
    <span class="media-upload-progress__text" id="upload-progress-text">Uploading…</span>
</div>

<!-- Search -->
<div style="margin-bottom:20px">
    <input type="search" id="media-search" placeholder="Search media…"
           style="width:100%;max-width:400px;padding:10px 14px;background:var(--a-bg);border:1px solid var(--a-border);border-radius:var(--a-radius);color:var(--a-text);font-size:.85rem;outline:none">
</div>

<!-- Media Grid -->
<div class="media-grid" id="media-grid">
    <!-- Populated by JS -->
</div>

<!-- Media Detail Sidebar -->
<div class="media-detail-overlay" id="media-detail-overlay" style="display:none">
    <div class="media-detail-panel" id="media-detail-panel">
        <div class="media-detail-panel__head">
            <h3>Media Details</h3>
            <button onclick="closeMediaDetail()" style="background:none;border:none;color:var(--a-muted);font-size:1.2rem;cursor:pointer">✕</button>
        </div>
        <div class="media-detail-panel__preview" id="media-detail-preview"></div>
        <div class="media-detail-panel__info" id="media-detail-info"></div>
        <div class="media-detail-panel__actions">
            <div class="admin-form__group">
                <label>Alt Text</label>
                <input type="text" id="media-detail-alt" placeholder="Describe this image…">
            </div>
            <div class="admin-form__group" style="margin-top:8px">
                <label>File URL</label>
                <input type="text" id="media-detail-url" readonly onclick="this.select()" style="cursor:pointer;font-size:.72rem">
            </div>
            <div style="display:flex;gap:8px;margin-top:12px">
                <button class="admin-btn admin-btn--primary admin-btn--sm" onclick="saveMediaAlt()">Save Alt</button>
                <button class="admin-btn admin-btn--sm" onclick="copyMediaUrl()">Copy URL</button>
                <button class="admin-btn admin-btn--sm admin-btn--danger" onclick="deleteMedia()" style="margin-left:auto">Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- Floating Bulk Action Bar -->
<div id="bulk-action-bar" style="display:none; position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:var(--a-surface); border:1px solid var(--a-border); padding:12px 24px; border-radius:30px; box-shadow:0 10px 30px rgba(0,0,0,0.5); z-index:300; align-items:center; gap:16px;">
    <span id="bulk-count" style="font-weight:600; font-size:.9rem; color:var(--a-text)">0 selected</span>
    <div style="width:1px; height:20px; background:var(--a-border)"></div>
    <button type="button" class="admin-btn admin-btn--danger admin-btn--sm" onclick="deleteSelectedMedia()">Delete Selected</button>
</div>


<style>
.media-dropzone{border:2px dashed var(--a-border);border-radius:var(--a-radius);padding:40px;text-align:center;margin-bottom:24px;transition:all .3s;cursor:pointer}
.media-dropzone:hover,.media-dropzone.drag-over{border-color:var(--a-accent);background:rgba(196,113,74,.05)}
.media-dropzone__inner{color:var(--a-muted);font-size:.9rem}
.media-upload-progress{margin-bottom:20px}
.media-upload-progress__bar{height:4px;background:var(--a-border);border-radius:2px;overflow:hidden}
.media-upload-progress__fill{height:100%;background:var(--a-accent);width:0;transition:width .3s;border-radius:2px}
.media-upload-progress__text{font-size:.72rem;color:var(--a-muted);margin-top:4px;display:block}
.media-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px}
.media-grid__item{position:relative;padding-top:100%;overflow:hidden;border-radius:var(--a-radius);border:2px solid transparent;cursor:pointer;transition:all .2s;background:var(--a-surface)}
.media-grid__item:hover{border-color:var(--a-accent);transform:translateY(-2px)}
.media-grid__item.selected{border-color:var(--a-accent);box-shadow:0 0 12px rgba(200,149,108,.3)}
.media-grid__item img{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;display:block}
.media-grid__item__name{position:absolute;bottom:0;left:0;right:0;padding:6px 8px;background:linear-gradient(transparent,rgba(0,0,0,.8));font-size:.6rem;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.media-picker__check{position:absolute;top:6px;right:6px;width:22px;height:22px;border-radius:50%;background:rgba(0,0,0,.5);border:2px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;font-size:.6rem;color:transparent;transition:all .2s;z-index:2}
.media-picker__check.checked{background:var(--a-accent,#c8956c);border-color:var(--a-accent,#c8956c);color:#fff}
.media-detail-overlay{position:fixed;top:0;left:0;width:100%;height:100%;z-index:200;background:rgba(0,0,0,.5);display:flex;justify-content:flex-end}
.media-detail-panel{width:400px;max-width:90vw;height:100%;background:var(--a-surface);border-left:1px solid var(--a-border);padding:24px;overflow-y:auto;animation:slideInRight .3s ease}
@keyframes slideInRight{from{transform:translateX(100%)}to{transform:translateX(0)}}
.media-detail-panel__head{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.media-detail-panel__head h3{font-size:.9rem;font-weight:600}
.media-detail-panel__preview{aspect-ratio:16/10;overflow:hidden;border-radius:var(--a-radius);background:var(--a-bg);margin-bottom:16px;display:flex;align-items:center;justify-content:center}
.media-detail-panel__preview img{max-width:100%;max-height:100%;object-fit:contain}
.media-detail-panel__info{font-size:.75rem;color:var(--a-muted);margin-bottom:16px;line-height:1.8}
</style>

<script>
const BASE = '<?= SITE_URL ?>';
let allMedia = [];
let selectedMediaIds = [];
let currentMediaId = null;
let searchTimer;
let currentPage = 1;
let totalPages = 1;
let isLoading = false;
let currentSearch = '';

// ── Load media ──
function loadMedia(search = '', page = 1, append = false) {
    if (isLoading) return;
    isLoading = true;
    currentSearch = search;
    
    const url = BASE + '/api/media.php?page=' + page + (search ? '&search=' + encodeURIComponent(search) : '');
    
    if (!append) {
        document.getElementById('media-grid').innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:20px;color:var(--a-muted)">Loading...</div>';
    } else {
        const loader = document.createElement('div');
        loader.id = 'media-loader';
        loader.style.cssText = 'grid-column:1/-1;text-align:center;padding:20px;color:var(--a-muted)';
        loader.innerText = 'Loading more...';
        document.getElementById('media-grid').appendChild(loader);
    }
    
    fetch(url, {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r => r.json()).then(data => {
        isLoading = false;
        if (!data.success) return;
        
        const loader = document.getElementById('media-loader');
        if (loader) loader.remove();

        currentPage = data.page;
        totalPages = data.pages;

        if (append) {
            allMedia = allMedia.concat(data.items);
        } else {
            allMedia = data.items;
        }

        document.getElementById('media-count').textContent = data.total + ' items';
        renderGrid(append, data.items);
    }).catch(() => { isLoading = false; });
}

function renderGrid(append = false, newItems = []) {
    const grid = document.getElementById('media-grid');
    if (!allMedia.length) {
        grid.innerHTML = '<div class="admin-empty" style="grid-column:1/-1"><div class="admin-empty__icon">🖼️</div><p class="admin-empty__text">No media files yet. Upload your first one!</p></div>';
        return;
    }
    
    const itemsToRender = append ? newItems : allMedia;
    const html = itemsToRender.map(m => {
        // Use thumb.php for much faster loading
        const thumbUrl = BASE + '/thumb.php?src=' + encodeURIComponent(m.filepath) + '&w=300';
        return `
        <div class="media-grid__item ${selectedMediaIds.includes(m.id) ? 'selected' : ''}" data-id="${m.id}" onclick="openMediaDetail(${m.id})">
            <div class="media-picker__check ${selectedMediaIds.includes(m.id) ? 'checked' : ''}" onclick="toggleSelectMedia(event, ${m.id})">✓</div>
            <img src="${thumbUrl}" alt="${m.alt_text || m.filename}" loading="lazy">
            <div class="media-grid__item__name">${m.filename}</div>
        </div>
        `;
    }).join('');

    if (append) {
        grid.insertAdjacentHTML('beforeend', html);
    } else {
        grid.innerHTML = html;
    }
    updateBulkActionBar();
}

// ── Infinite Scroll Observer ──
const observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && currentPage < totalPages && !isLoading) {
        loadMedia(currentSearch, currentPage + 1, true);
    }
}, { rootMargin: '100px' });

// Add a sentry element at the bottom of the page to trigger the observer
const sentry = document.createElement('div');
document.body.appendChild(sentry);
observer.observe(sentry);

function toggleSelectMedia(e, id) {
    e.stopPropagation();
    const idx = selectedMediaIds.indexOf(id);
    if (idx > -1) {
        selectedMediaIds.splice(idx, 1);
    } else {
        selectedMediaIds.push(id);
    }
    renderGrid();
}

function toggleSelectAll() {
    if (allMedia.length === 0) return;
    
    // Check if all currently loaded media are selected
    const allLoadedIds = allMedia.map(m => m.id);
    const allSelected = allLoadedIds.length > 0 && allLoadedIds.every(id => selectedMediaIds.includes(id));
    
    if (allSelected) {
        selectedMediaIds = [];
    } else {
        // Only select the ones currently loaded in view
        selectedMediaIds = [...allLoadedIds];
    }
    renderGrid();
}

function updateBulkActionBar() {
    const bar = document.getElementById('bulk-action-bar');
    const count = document.getElementById('bulk-count');
    const selectAllBtn = document.getElementById('btn-select-all');
    
    if (selectedMediaIds.length > 0) {
        bar.style.display = 'flex';
        count.innerText = selectedMediaIds.length + ' selected';
    } else {
        bar.style.display = 'none';
    }
    
    // Update Select All button
    if (allMedia.length > 0) {
        selectAllBtn.style.display = 'block';
        const allLoadedIds = allMedia.map(m => m.id);
        const allSelected = allLoadedIds.length > 0 && allLoadedIds.every(id => selectedMediaIds.includes(id));
        selectAllBtn.textContent = allSelected ? 'Deselect All' : 'Select All';
    } else {
        selectAllBtn.style.display = 'none';
    }
}

function deleteSelectedMedia() {
    if (selectedMediaIds.length === 0) return;
    if (!confirm('Are you sure you want to delete the selected ' + selectedMediaIds.length + ' files permanently?')) return;
    
    const fd = new FormData();
    fd.append('action', 'bulk_delete');
    fd.append('media_ids', JSON.stringify(selectedMediaIds));
    
    fetch(BASE + '/api/media.php', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r => r.json()).then(d => {
        if (d.success) {
            selectedMediaIds = [];
            loadMedia();
        } else {
            alert(d.message || 'Error deleting files');
        }
    });
}

// ── Detail panel ──
function openMediaDetail(id) {
    currentMediaId = id;
    const m = allMedia.find(x => x.id === id);
    if (!m) return;

    document.getElementById('media-detail-preview').innerHTML = `<img src="${m.url}" alt="">`;
    document.getElementById('media-detail-info').innerHTML = `
        <strong>${m.filename}</strong><br>
        ${m.width ? m.width + '×' + m.height + 'px' : ''}<br>
        ${(m.filesize / 1024).toFixed(1)} KB<br>
        Uploaded: ${new Date(m.created_at).toLocaleDateString()}
    `;
    document.getElementById('media-detail-alt').value = m.alt_text || '';
    document.getElementById('media-detail-url').value = m.filepath;
    document.getElementById('media-detail-overlay').style.display = 'flex';
}

function closeMediaDetail() {
    document.getElementById('media-detail-overlay').style.display = 'none';
    currentMediaId = null;
}

function saveMediaAlt() {
    if (!currentMediaId) return;
    const alt = document.getElementById('media-detail-alt').value;
    const fd = new FormData();
    fd.append('action', 'update_alt');
    fd.append('media_id', currentMediaId);
    fd.append('alt_text', alt);
    fetch(BASE + '/api/media.php', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r => r.json()).then(d => { if (d.success) { loadMedia(); } });
}

function copyMediaUrl() {
    const input = document.getElementById('media-detail-url');
    input.select();
    navigator.clipboard.writeText(input.value);
}

function deleteMedia(force) {
    if (!currentMediaId) return;
    if (!force && !confirm('Delete this media file permanently?')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('media_id', currentMediaId);
    if (force) fd.append('force', '1');
    fetch(BASE + '/api/media.php', {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r => r.json()).then(d => {
        if (d.success) { closeMediaDetail(); loadMedia(); if (window.adminToast) adminToast('Image deleted', 'success'); return; }
        // Still in use somewhere — make the admin confirm knowingly.
        if (d.in_use && confirm(d.message + '\n\nDelete it anyway?')) deleteMedia(true);
        else if (!d.in_use && window.adminToast) adminToast(d.message || 'Could not delete', 'error');
    });
}

// ── Upload Queue ──
let uploadQueue = [];
let totalUploads = 0;
let currentUploadIndex = 0;

function uploadFiles(files) {
    if (!files.length) return;
    
    const wasEmpty = uploadQueue.length === 0;
    for (let i = 0; i < files.length; i++) {
        uploadQueue.push(files[i]);
    }
    
    if (wasEmpty) {
        totalUploads = uploadQueue.length;
        currentUploadIndex = 0;
        document.getElementById('media-upload-progress').style.display = 'block';
        processNextUpload();
    } else {
        totalUploads += files.length;
    }
}

function processNextUpload() {
    const progress = document.getElementById('media-upload-progress');
    const fill     = document.getElementById('upload-progress-fill');
    const text     = document.getElementById('upload-progress-text');

    if (uploadQueue.length === 0) {
        fill.style.width = '100%';
        text.textContent = 'All uploads complete!';
        setTimeout(() => { 
            progress.style.display = 'none'; 
            fill.style.width = '0'; 
            loadMedia();
        }, 2000);
        return;
    }

    const file = uploadQueue.shift();
    currentUploadIndex++;

    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('files[]', file);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', BASE + '/api/media.php');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    
    xhr.upload.onprogress = e => {
        if (e.lengthComputable) {
            const fileProgress = e.loaded / e.total;
            const overallProgress = ((currentUploadIndex - 1 + fileProgress) / totalUploads) * 100;
            fill.style.width = overallProgress + '%';
            text.textContent = `Uploading file ${currentUploadIndex} of ${totalUploads} (${file.name})… ${Math.round(fileProgress * 100)}%`;
        }
    };
    
    xhr.onload = () => {
        processNextUpload();
    };
    
    xhr.onerror = () => {
        console.error('Upload failed for', file.name);
        processNextUpload();
    };
    
    xhr.send(fd);
}

// Event listeners
document.getElementById('media-upload-input').addEventListener('change', function() {
    uploadFiles(this.files);
    this.value = '';
});

// Drag & drop
const dropzone = document.getElementById('media-dropzone');
['dragenter','dragover'].forEach(e => dropzone.addEventListener(e, ev => { ev.preventDefault(); dropzone.classList.add('drag-over'); }));
['dragleave','drop'].forEach(e => dropzone.addEventListener(e, ev => { ev.preventDefault(); dropzone.classList.remove('drag-over'); }));
dropzone.addEventListener('drop', e => uploadFiles(e.dataTransfer.files));
dropzone.addEventListener('click', () => document.getElementById('media-upload-input').click());

// Search
document.getElementById('media-search').addEventListener('input', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadMedia(this.value.trim()), 300);
});

// Close detail on overlay click
document.getElementById('media-detail-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeMediaDetail();
});

// Init
loadMedia();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
