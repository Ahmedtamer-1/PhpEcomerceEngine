/**
 * Admin Products & Variants — AJAX Manager
 */
const API = window.PRODUCTS_API;
const MEDIA_API = window.MEDIA_API;
const BASE = window.BASE_URL;
let allProducts = [];
let currentProductId = null;
let galleryPaths = [];
let allMedia = [];
let sizeChartPath = '';
let colorGalleries = {};
let productColors = [];
let selectedProductIds = [];

let currentProductFilter = 'all';
let currentProductSearch = '';

// ── INIT ──
document.addEventListener('DOMContentLoaded', () => {
    loadProducts();
    // The media library is only needed once a picker opens; loading it on
    // page load slowed the product list down for nothing.
    document.getElementById('product-form').addEventListener('submit', saveProduct);
    document.getElementById('variant-form').addEventListener('submit', saveVariant);
    document.getElementById('media-search-input')?.addEventListener('input', function(){
        renderMediaGrid(this.value.trim().toLowerCase());
    });
    document.getElementById('product-search')?.addEventListener('input', function() {
        currentProductSearch = this.value.trim().toLowerCase();
        renderProductList();
    });
    document.querySelectorAll('.admin-filter-tab[data-filter]').forEach(tab => {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.admin-filter-tab[data-filter]').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            currentProductFilter = this.dataset.filter;
            renderProductList();
        });
    });
    document.getElementById('product-category-filter')?.addEventListener('change', function() {
        currentCategoryFilter = this.value; renderProductList();
    });
    document.getElementById('product-sort')?.addEventListener('change', function() {
        currentSort = this.value; renderProductList();
    });
    document.getElementById('product-bulk-action-select')?.addEventListener('change', function() {
        document.getElementById('product-bulk-category').style.display = this.value === 'set_category' ? '' : 'none';
    });

    // Deep links: ?edit=ID (from global search), ?new=1, ?stock=low, ?q=term
    const params = new URLSearchParams(location.search);
    if (['low', 'out'].includes(params.get('stock'))) setProductFilter(params.get('stock'));
    if (params.get('q')) { const s = document.getElementById('product-search'); s.value = params.get('q'); currentProductSearch = s.value.toLowerCase(); }
    if (params.get('edit')) editProduct(parseInt(params.get('edit'), 10));
    else if (params.get('new')) showProductForm(null);

    // ── Media Drag and Drop Upload ──
    const pdDropzone = document.getElementById('product-media-dropzone');
    if (pdDropzone) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(e => pdDropzone.addEventListener(e, ev => ev.preventDefault()));
        ['dragenter', 'dragover'].forEach(e => pdDropzone.addEventListener(e, () => pdDropzone.style.borderColor = 'var(--accent)'));
        ['dragleave', 'drop'].forEach(e => pdDropzone.addEventListener(e, () => pdDropzone.style.borderColor = 'var(--border)'));
        
        pdDropzone.addEventListener('drop', e => {
            const files = e.dataTransfer.files;
            if (!files.length) return;
            pdDropzone.innerHTML = '<p style="color:var(--accent)">Uploading...</p>';
            
            const fd = new FormData();
            fd.append('action', 'upload');
            for (let i = 0; i < files.length; i++) fd.append('files[]', files[i]);
            
            fetch(MEDIA_API, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(d => {
                pdDropzone.innerHTML = '<p style="color:var(--text-muted)">Drag & drop images here to upload</p>';
                if (d.success) {
                    window.adminToast('Upload complete. Select it from the library.', 'success');
                    // Reload media to ensure latest is shown
                    allMedia = [];
                    loadMedia(1, false);
                    openMediaPicker('gallery'); // Automatically open picker
                } else {
                    window.adminToast('Upload failed', 'error');
                }
            }).catch(() => {
                pdDropzone.innerHTML = '<p style="color:var(--text-muted)">Drag & drop images here to upload</p>';
                window.adminToast('Upload failed', 'error');
            });
        });
        
        pdDropzone.addEventListener('click', () => {
            openMediaPicker('gallery');
        });
    }
});

// ══════════════════════════════════════
// PRODUCTS
// ══════════════════════════════════════
function loadProducts(){
    return fetch(API+'?action=list_products',{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(!d.success)return;
        allProducts=d.products;
        renderProductList();
    });
}

let currentCategoryFilter = '';
let currentSort = 'new';
const LOW_STOCK = 5;

function effectivePrice(p){ return parseFloat(p.sale_price) > 0 ? parseFloat(p.sale_price) : parseFloat(p.price); }

function filteredProductList(){
    let list = allProducts.slice();
    if (currentProductFilter === 'active')   list = list.filter(p => p.is_active == 1);
    if (currentProductFilter === 'inactive') list = list.filter(p => p.is_active == 0);
    if (currentProductFilter === 'low')      list = list.filter(p => parseInt(p.stock) > 0 && parseInt(p.stock) <= LOW_STOCK);
    if (currentProductFilter === 'out')      list = list.filter(p => parseInt(p.stock) <= 0);
    if (currentProductFilter === 'featured') list = list.filter(p => p.is_featured == 1);
    if (currentCategoryFilter) list = list.filter(p => String(p.category_id || '') === currentCategoryFilter);
    if (currentProductSearch) {
        const q = currentProductSearch;
        list = list.filter(p =>
            p.name.toLowerCase().includes(q) ||
            (p.sku && p.sku.toLowerCase().includes(q)) ||
            (p.category_name && p.category_name.toLowerCase().includes(q)));
    }
    const sorters = {
        name:       (a,b) => a.name.localeCompare(b.name),
        price_asc:  (a,b) => effectivePrice(a) - effectivePrice(b),
        price_desc: (a,b) => effectivePrice(b) - effectivePrice(a),
        stock_asc:  (a,b) => parseInt(a.stock) - parseInt(b.stock),
    };
    if (sorters[currentSort]) list.sort(sorters[currentSort]);
    return list;
}

function renderProductList(){
    const tb=document.getElementById('products-tbody');
    const list = filteredProductList();
    const total = allProducts.length;
    document.getElementById('product-count').textContent =
        list.length === total ? total + ' products' : list.length + ' of ' + total + ' products';

    if(!list.length){
        tb.innerHTML='<tr><td colspan="9"><div class="admin-empty" style="padding:36px 0;text-align:center"><div class="admin-empty__text">No products match these filters.</div></div></td></tr>';
        updateProductBulkActionBar();
        return;
    }

    tb.innerHTML=list.map(p=>{
        const id = parseInt(p.id);
        const stockVal = parseInt(p.stock);
        const variants = p.has_variants == 1;
        const img = p.thumb_url || p.image_url
            ? `<img src="${esc(p.thumb_url || p.image_url)}" alt="" loading="lazy">`
            : `<div class="g-prod-thumb" style="display:flex;align-items:center;justify-content:center;color:var(--text-muted)">—</div>`;
        const stockCls = stockVal <= 0 ? 'g-stock-out' : (stockVal <= LOW_STOCK ? 'g-stock-low' : '');
        const stockCell = variants
            ? `<span class="g-qe is-locked ${stockCls}" title="Stock is the total of this product's variants">${stockVal}</span> <span class="g-pill g-pill--var">variants</span>`
            : `<span class="g-qe ${stockCls}" data-qe="stock" data-id="${id}" title="Click to edit">${stockVal}</span>`;
        const salePrice = parseFloat(p.sale_price) > 0 ? money(p.sale_price) : '<span style="color:var(--text-muted)">—</span>';
        return `<tr data-id="${id}">
            <td><input type="checkbox" class="product-row-checkbox" value="${id}" ${selectedProductIds.includes(id) ? 'checked' : ''} onchange="toggleProductSelect(this, ${id})" aria-label="Select"></td>
            <td><div class="g-prod-cell">${img}<div style="min-width:0">
                <div class="g-prod-name" onclick="editProduct(${id})">${esc(p.name)}</div>
                <div class="g-prod-sub">${esc(p.sku||'')}${p.sku && p.is_active==0 ? ' · ' : ''}${p.is_active==0 ? 'hidden' : ''}</div>
            </div></div></td>
            <td style="color:var(--text-muted)">${esc(p.category_name||'—')}</td>
            <td><span class="g-qe" data-qe="price" data-id="${id}" title="Click to edit">${money(p.price)}</span></td>
            <td><span class="g-qe" data-qe="sale_price" data-id="${id}" title="Click to edit (leave empty to remove the sale)">${salePrice}</span></td>
            <td>${stockCell}</td>
            <td><button type="button" class="g-star ${p.is_featured==1?'is-on':''}" onclick="toggleFeatured(${id}, this)" title="${p.is_featured==1?'Featured — click to remove':'Mark as featured'}">★</button></td>
            <td><label class="hp-section-toggle" style="transform:scale(0.8);transform-origin:left center;margin:0" title="Show in shop"><input type="checkbox" ${p.is_active==1?'checked':''} onchange="toggleProductActive(${id}, this.checked)"><span class="hp-section-toggle__slider"></span></label></td>
            <td><div class="admin-table__actions" style="justify-content:flex-end">
                <button class="admin-btn admin-btn--sm" onclick="editProduct(${id})">Edit</button>
                ${variants ? `<button class="admin-btn admin-btn--sm" onclick="openVariants(${id})">Variants</button>` : ''}
                <button class="admin-btn admin-btn--sm g-icon-btn" onclick="duplicateProduct(${id})" title="Duplicate"><svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>
                <button class="admin-btn admin-btn--sm admin-btn--danger g-icon-btn" onclick="deleteProduct(${id})" title="Delete"><svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg></button>
            </div></td>
        </tr>`;
    }).join('');
    updateProductBulkActionBar();
}

// ── Quick inline edit (price / sale price / stock) ──
function quickUpdate(id, field, value){
    const fd = new FormData();
    fd.append('action','quick_update'); fd.append('id', id); fd.append('field', field); fd.append('value', value);
    return fetch(API,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json());
}

function flashCell(id, field, ok){
    const el = document.querySelector(`.g-qe[data-id="${id}"][data-qe="${field}"]`)
        || document.querySelector(`tr[data-id="${id}"] .g-star`);
    if (!el) return;
    const cls = ok ? 'g-flash-ok' : 'g-flash-err';
    el.classList.remove(cls); void el.offsetWidth; el.classList.add(cls);
}

document.addEventListener('click', function(e){
    const cell = e.target.closest('.g-qe[data-qe]');
    if (!cell || cell.querySelector('input')) return;
    const id = parseInt(cell.dataset.id), field = cell.dataset.qe;
    const p = allProducts.find(x => parseInt(x.id) === id);
    if (!p) return;
    const current = field === 'sale_price' ? (parseFloat(p.sale_price) > 0 ? p.sale_price : '') : p[field];
    const input = document.createElement('input');
    input.type = 'number'; input.min = '0'; input.step = field === 'stock' ? '1' : '0.01';
    input.value = current ?? '';
    input.placeholder = field === 'sale_price' ? 'none' : '';
    const original = cell.innerHTML;
    cell.innerHTML = ''; cell.appendChild(input);
    input.focus(); input.select();
    let done = false;
    const finish = (save) => {
        if (done) return Promise.resolve(); done = true;
        const val = input.value.trim();
        if (!save || val === String(current ?? '')) { cell.innerHTML = original; return Promise.resolve(); }
        cell.classList.add('is-saving');
        return quickUpdate(id, field, val).then(d => {
            cell.classList.remove('is-saving');
            if (d.success) {
                p[field] = d.value;
                renderProductList();
                flashCell(id, field, true);
            } else {
                cell.innerHTML = original;
                flashCell(id, field, false);
                window.adminToast(d.message || 'Could not save', 'error');
            }
        }).catch(() => { cell.classList.remove('is-saving'); cell.innerHTML = original; window.adminToast('Network error — not saved', 'error'); });
    };
    input.addEventListener('keydown', ev => {
        if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
        if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); finish(false); }
        if (ev.key === 'Tab') {
            // Tab moves to the next editable number in the same row
            ev.preventDefault();
            const cells = Array.from(cell.closest('tr').querySelectorAll('.g-qe[data-qe]'));
            const next = cells[cells.indexOf(cell) + (ev.shiftKey ? -1 : 1)];
            const nextField = next && next.dataset.qe;
            finish(true).then(() => {
                if (nextField) document.querySelector(`.g-qe[data-id="${id}"][data-qe="${nextField}"]`)?.click();
            });
        }
    });
    input.addEventListener('blur', () => finish(true));
});

function toggleFeatured(id, btn){
    const p = allProducts.find(x => parseInt(x.id) === id);
    if (!p) return;
    const next = p.is_featured == 1 ? 0 : 1;
    btn.classList.toggle('is-on', !!next); // optimistic
    quickUpdate(id, 'is_featured', String(next)).then(d => {
        if (d.success) { p.is_featured = next; window.adminToast(next ? 'Marked as featured' : 'Removed from featured', 'success', 1800); }
        else { btn.classList.toggle('is-on', !next); window.adminToast(d.message || 'Could not update', 'error'); }
    });
}

function duplicateProduct(id){
    const fd = new FormData(); fd.append('action','duplicate_product'); fd.append('id', id);
    fetch(API,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json()).then(d => {
        if (!d.success) { window.adminToast(d.message || 'Could not duplicate', 'error'); return; }
        window.adminToast(d.message, 'success');
        loadProducts().then(() => editProduct(d.id));
    });
}

function toggleProductActive(id, isActive) {
    quickUpdate(id, 'is_active', isActive ? '1' : '0').then(d => {
        if(d.success) {
            const p = allProducts.find(x => x.id == id);
            if(p) p.is_active = isActive ? 1 : 0;
            window.adminToast(isActive ? 'Now visible in the shop' : 'Hidden from the shop', 'success', 1800);
        } else {
            window.adminToast(d.message || 'Failed to change visibility', 'error');
            loadProducts(); // reload to reset state
        }
    });
}

function toggleAllProducts(masterCheckbox) {
    const checkboxes = document.querySelectorAll('.product-row-checkbox');
    selectedProductIds = [];
    checkboxes.forEach(cb => {
        cb.checked = masterCheckbox.checked;
        if (masterCheckbox.checked) selectedProductIds.push(parseInt(cb.value));
    });
    updateProductBulkActionBar();
}

function toggleProductSelect(cb, id) {
    if (cb.checked) {
        if (!selectedProductIds.includes(id)) selectedProductIds.push(id);
    } else {
        selectedProductIds = selectedProductIds.filter(x => x !== id);
    }
    updateProductBulkActionBar();
}

function clearProductSelection() {
    selectedProductIds = [];
    document.querySelectorAll('.product-row-checkbox').forEach(cb => cb.checked = false);
    updateProductBulkActionBar();
}

function updateProductBulkActionBar() {
    const bar = document.getElementById('product-bulk-action-bar');
    const count = document.getElementById('product-bulk-count');
    if (selectedProductIds.length > 0) {
        bar.style.display = 'flex';
        count.innerText = selectedProductIds.length + ' selected';
    } else {
        bar.style.display = 'none';
        const selectAll = document.getElementById('selectAllProducts');
        if (selectAll) selectAll.checked = false;
    }
}

function applyProductBulkAction() {
    if (selectedProductIds.length === 0) return;
    const action = document.getElementById('product-bulk-action-select').value;
    if (!action) { window.adminToast('Choose an action first', 'info'); return; }
    if (action === 'delete' && !confirm('Delete ' + selectedProductIds.length + ' product(s) and all their variants? This cannot be undone.')) return;

    const fd = new FormData();
    fd.append('action', 'bulk_action');
    fd.append('bulk_action_type', action);
    fd.append('bulk_ids', JSON.stringify(selectedProductIds));
    if (action === 'set_category') fd.append('category_id', document.getElementById('product-bulk-category').value);

    fetch(API, {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r => r.json()).then(d => {
        if (d.success) {
            window.adminToast('Updated ' + selectedProductIds.length + ' product(s)', 'success');
            selectedProductIds = [];
            loadProducts();
            document.getElementById('product-bulk-action-select').value = '';
            document.getElementById('product-bulk-category').style.display = 'none';
        } else {
            window.adminToast(d.message || 'Bulk action failed', 'error');
        }
    });
}

function showProductForm(product){
    document.getElementById('view-list').style.display='none';
    document.getElementById('view-form').style.display='block';
    document.getElementById('view-variants').style.display='none';
    const f=document.getElementById('product-form');
    f.reset();
    galleryPaths=[];
    sizeChartPath='';
    if(product){
        document.getElementById('form-title').textContent='Edit Product';
        f.querySelector('[name=id]').value=product.id;
        f.querySelector('[name=name]').value=product.name||'';
        f.querySelector('[name=slug]').value=product.slug||'';
        f.querySelector('[name=category_id]').value=product.category_id||'';
        f.querySelector('[name=description]').value=product.description||'';
        f.querySelector('[name=meta_title]').value=product.meta_title||'';
        f.querySelector('[name=meta_desc]').value=product.meta_desc||'';
        f.querySelector('[name=price]').value=product.price||'';
        f.querySelector('[name=sale_price]').value=product.sale_price||'';
        f.querySelector('[name=cost_price]').value=product.cost_price||'';
        f.querySelector('[name=sku]').value=product.sku||'';
        f.querySelector('[name=stock]').value=product.stock||0;
        f.querySelector('[name=existing_image]').value=product.image||'';
        // Set every checkbox explicitly. They used to be only ever ticked, and
        // "Active" starts ticked in the HTML — so opening a HIDDEN product and
        // pressing Save silently published it.
        f.querySelector('[name=is_featured]').checked  = product.is_featured == 1;
        f.querySelector('[name=is_active]').checked    = product.is_active == 1;
        f.querySelector('[name=has_variants]').checked = product.has_variants == 1;
        // Was never filled in, so re-saving a pre-order product switched pre-order off.
        const po = f.querySelector('[name=preorder_enabled]');
        if (po) po.checked = product.preorder_enabled == 1;
        // Show current image
        const prev=document.getElementById('main-img-preview');
        const removeBtn=document.getElementById('main-img-remove-btn');
        if(product.image_url){
            prev.innerHTML=`<img src="${product.image_url}" alt="" style="width:100%;height:100%;object-fit:cover">`;
            if(removeBtn) removeBtn.style.display='inline-block';
        } else {
            prev.innerHTML='No image';
            if(removeBtn) removeBtn.style.display='none';
        }
        // Gallery
        galleryPaths=product.gallery_arr||[];
        renderGalleryPreview();
        // Size chart
        sizeChartPath=product.size_chart||'';
        renderSizeChartPreview();
        // Material & gem
        const matInput = f.querySelector('[name=material]');
        const gemInput = f.querySelector('[name=gem]');
        if(matInput) matInput.value = product.material||'';
        if(gemInput) gemInput.value = product.gem||'';
    } else {
        document.getElementById('form-title').textContent='Add Product';
        f.querySelector('[name=id]').value='0';
        f.querySelector('[name=is_active]').checked=true;
        document.getElementById('main-img-preview').innerHTML='No image';
        const removeBtn=document.getElementById('main-img-remove-btn');
        if(removeBtn) removeBtn.style.display='none';
        renderGalleryPreview();
        renderSizeChartPreview();
    }
    
    // Disable stock input if has_variants is checked
    const stockInput = f.querySelector('[name=stock]');
    const hasVariantsCb = f.querySelector('[name=has_variants]');
    if (stockInput && hasVariantsCb) {
        stockInput.readOnly = hasVariantsCb.checked;
        stockInput.style.opacity = hasVariantsCb.checked ? '0.5' : '1';
        stockInput.title = hasVariantsCb.checked ? 'Stock is calculated automatically from variants' : '';
    }
    slugTouched = !!(product && product.slug);
    updateMarginHint();
    setFormDirty(false);
    window.scrollTo({ top: 0, behavior: 'smooth' });
    setTimeout(() => f.querySelector('[name=name]').focus({ preventScroll: true }), 50);
}

// Add event listener for has_variants toggle
document.addEventListener('DOMContentLoaded', () => {
    const hasVariantsCb = document.querySelector('#product-form [name=has_variants]');
    if (hasVariantsCb) {
        hasVariantsCb.addEventListener('change', function() {
            const stockInput = document.querySelector('#product-form [name=stock]');
            if (stockInput) {
                stockInput.readOnly = this.checked;
                stockInput.style.opacity = this.checked ? '0.5' : '1';
                stockInput.title = this.checked ? 'Stock is calculated automatically from variants' : '';
            }
        });
    }
});

function editProduct(id){
    fetch(API+'?action=get_product&id='+id,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.success) showProductForm(d.product);
    });
}

let closeAfterSave = true;
function submitProductForm(close){
    closeAfterSave = close;
    const f = document.getElementById('product-form');
    if (!f.reportValidity()) return;
    f.dispatchEvent(new Event('submit', { cancelable: true }));
}

function saveProduct(e){
    e.preventDefault();
    const close = closeAfterSave; closeAfterSave = true;
    const f=e.target;
    const fd=new FormData(f);
    fd.append('action','save_product');
    fd.set('gallery_paths',JSON.stringify(galleryPaths));
    fd.set('size_chart', sizeChartPath);
    // checkboxes
    if(!f.querySelector('[name=is_featured]').checked) fd.delete('is_featured');
    if(!f.querySelector('[name=is_active]').checked) fd.delete('is_active');
    if(!f.querySelector('[name=has_variants]').checked) fd.delete('has_variants');

    const btns = [document.getElementById('product-save-btn'), document.getElementById('product-save-stay-btn')];
    btns.forEach(b => b && b.classList.add('is-busy'));
    const status = document.getElementById('form-save-status');
    status.textContent = 'Saving…';

    fetch(API,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        btns.forEach(b => b && b.classList.remove('is-busy'));
        if(d.success){
            window.adminToast(d.message || 'Product saved', 'success');
            setFormDirty(false);
            const newId = d.product_id || d.id;
            if (newId && f.querySelector('[name=id]').value === '0') {
                f.querySelector('[name=id]').value = newId;
                document.getElementById('form-title').textContent = 'Edit Product';
            }
            status.textContent = 'Saved at ' + new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
            loadProducts();
            if (close) showListView(true);
        } else {
            status.textContent = 'Not saved';
            window.adminToast(d.message||'Error saving product','error');
        }
    }).catch(() => {
        btns.forEach(b => b && b.classList.remove('is-busy'));
        status.textContent = 'Not saved — connection problem';
        window.adminToast('Network error — product not saved', 'error');
    });
}

// ── Unsaved-changes tracking ──
let formDirty = false;
function setFormDirty(dirty){
    formDirty = dirty;
    const bar = document.getElementById('product-savebar');
    if (bar) bar.classList.toggle('is-dirty', dirty);
    const st = document.getElementById('form-save-status');
    if (st && dirty) st.textContent = 'Unsaved changes';
    else if (st && !dirty && !/^Saved/.test(st.textContent)) st.textContent = 'All changes saved';
}
window.addEventListener('beforeunload', e => {
    if (formDirty && document.getElementById('view-form').style.display !== 'none') { e.preventDefault(); e.returnValue = ''; }
});

// ── Slug follows the name until edited by hand; live margin hint ──
let slugTouched = false;
function slugify(v){ return v.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g,'').replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,''); }
function updateMarginHint(){
    const f = document.getElementById('product-form');
    const el = document.getElementById('price-margin');
    if (!f || !el) return;
    const price = parseFloat(f.querySelector('[name=price]').value) || 0;
    const sale  = parseFloat(f.querySelector('[name=sale_price]').value) || 0;
    const cost  = parseFloat(f.querySelector('[name=cost_price]').value) || 0;
    const sell  = sale > 0 ? sale : price;
    const parts = [];
    if (sale > 0 && price > 0) {
        if (sale >= price) parts.push('<span style="color:var(--red)">Sale price should be lower than the price</span>');
        else parts.push(Math.round((1 - sale / price) * 100) + '% off');
    }
    if (cost > 0 && sell > 0) {
        const m = sell - cost, pct = Math.round(m / sell * 100);
        parts.push('Profit per item: <strong style="color:' + (m >= 0 ? 'var(--green)' : 'var(--red)') + '">' + money(m) + '</strong> (' + pct + '% margin)');
    }
    el.innerHTML = parts.join(' · ');
}
document.addEventListener('DOMContentLoaded', () => {
    const f = document.getElementById('product-form');
    if (!f) return;
    f.addEventListener('input', e => {
        setFormDirty(true);
        if (e.target.name === 'slug') slugTouched = e.target.value.trim() !== '';
        if (e.target.name === 'name' && !slugTouched) f.querySelector('[name=slug]').value = slugify(e.target.value);
        if (['price','sale_price','cost_price'].includes(e.target.name)) updateMarginHint();
    });
    f.addEventListener('change', () => setFormDirty(true));
});

// ── Keyboard shortcuts ──
function setProductFilter(name){
    currentProductFilter = name;
    document.querySelectorAll('.admin-filter-tab[data-filter]').forEach(t => t.classList.toggle('active', t.dataset.filter === name));
    renderProductList();
}
document.addEventListener('keydown', e => {
    const formOpen = document.getElementById('view-form').style.display !== 'none';
    const tag = document.activeElement ? document.activeElement.tagName : '';
    const typing = /INPUT|TEXTAREA|SELECT/.test(tag) || (document.activeElement && document.activeElement.isContentEditable);
    const pickerOpen = document.getElementById('media-picker-overlay')?.style.display === 'flex';
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's' && formOpen) {
        e.preventDefault(); submitProductForm(false); return;
    }
    if (e.key === 'Escape' && formOpen && !pickerOpen) { showListView(); return; }
    if (typing || e.ctrlKey || e.metaKey || e.altKey || formOpen) return;
    if (e.key === '/') { e.preventDefault(); document.getElementById('product-search')?.focus(); }
    if (e.key.toLowerCase() === 'n') { e.preventDefault(); showProductForm(null); }
});

function deleteProduct(id){
    if(!confirm('Delete this product and all its variants?'))return;
    const fd=new FormData();
    fd.append('action','delete_product');
    fd.append('id',id);
    fetch(API,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.success){showAlert('Product deleted.','success');loadProducts();}
    });
}

function showListView(force){
    if (!force && formDirty && document.getElementById('view-form').style.display !== 'none'
        && !confirm('You have unsaved changes. Leave without saving?')) return;
    setFormDirty(false);
    const qs = new URLSearchParams(location.search);
    if (qs.has('edit') || qs.has('new')) history.replaceState(null, '', location.pathname);
    document.getElementById('view-list').style.display='block';
    document.getElementById('view-form').style.display='none';
    document.getElementById('view-variants').style.display='none';
}

// ── Gallery management ──
function renderGalleryPreview(){
    const c=document.getElementById('gallery-preview');
    if(!galleryPaths.length){c.innerHTML='<span style="color:var(--a-muted);font-size:.75rem">No gallery images</span>';return;}
    c.innerHTML=galleryPaths.map((p,i)=>`<div style="position:relative;display:inline-block">
        <img src="${BASE}/${p}" style="width:72px;height:72px;object-fit:cover;border-radius:4px;border:1px solid var(--a-border)">
        <button type="button" onclick="removeGalleryImage(${i})" style="position:absolute;top:-6px;right:-6px;width:18px;height:18px;background:var(--a-danger,#c44);color:#fff;border:none;border-radius:50%;font-size:.6rem;cursor:pointer;display:flex;align-items:center;justify-content:center">✕</button>
    </div>`).join('');
}
function removeGalleryImage(i){galleryPaths.splice(i,1);renderGalleryPreview();}

// ── Size chart management ──
function renderSizeChartPreview(){
    const prev=document.getElementById('sizechart-img-preview');
    if(sizeChartPath){
        prev.innerHTML=`<img src="${BASE}/${sizeChartPath}" alt="Size Chart" style="max-width:180px;border-radius:4px">`;
    } else {
        prev.innerHTML='No size chart';
    }
}
function removeSizeChart(){
    sizeChartPath='';
    renderSizeChartPreview();
}

function removeMainImage() {
    document.querySelector('[name=existing_image]').value = '';
    document.getElementById('main-img-preview').innerHTML = 'No image';
    const removeBtn=document.getElementById('main-img-remove-btn');
    if(removeBtn) removeBtn.style.display='none';
}

function removeVariantImage() {
    document.querySelector('#variant-form [name=media_image_path]').value = '';
    document.getElementById('variant-img-preview').innerHTML = '<span style="color:var(--a-muted);font-size:.65rem">None</span>';
    const removeBtn=document.getElementById('variant-img-remove-btn');
    if(removeBtn) removeBtn.style.display='none';
}

// ══════════════════════════════════════
// VARIANTS
// ══════════════════════════════════════
let variantList=[];
let editingVariantId=0;

function openVariants(productId){
    currentProductId=productId;
    const p=allProducts.find(x=>x.id==productId);
    document.getElementById('view-list').style.display='none';
    document.getElementById('view-form').style.display='none';
    document.getElementById('view-variants').style.display='block';
    document.getElementById('variant-product-name').textContent=p?p.name:'Product';
    document.getElementById('variant-base-info').textContent=p?`Base price: ${moneyText(p.price)} · SKU: ${p.sku||'—'}`:'';
    resetVariantForm();
    loadVariants();
}

function loadVariants(){
    fetch(API+'?action=get_variants&product_id='+currentProductId,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(!d.success)return;
        variantList=d.variants;
        extractColorsFromVariants();
        renderColorsSection();
        renderVariantTable();
        loadColorGalleries();
    });
}
function extractColorsFromVariants(){
    const seen={};
    variantList.forEach(v=>{if(v.color_name&&!seen[v.color_name])seen[v.color_name]=v.color_hex||'#888';});
    productColors.forEach(c=>{if(!seen[c.name])seen[c.name]=c.hex;});
    productColors=Object.entries(seen).map(([name,hex])=>({name,hex}));
}
function renderColorsSection(){
    const el=document.getElementById('product-colors-list');if(!el)return;
    if(!productColors.length){el.innerHTML='<span style="color:var(--a-muted);font-size:.75rem">No colors defined yet</span>';}
    else{el.innerHTML=productColors.map(c=>`<div style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:var(--a-bg,#111);border:1px solid var(--a-border);border-radius:20px"><span style="width:16px;height:16px;border-radius:50%;background:${esc(c.hex)};border:1px solid rgba(255,255,255,.15)"></span><span style="font-size:.8rem;color:var(--a-text,#eee)">${esc(c.name)}</span><button type="button" onclick="removeColor('${esc(c.name).replace(/'/g,"\\'")}')" style="background:none;border:none;color:var(--a-muted);cursor:pointer;font-size:.85rem;padding:0 2px;line-height:1">&times;</button></div>`).join('');}
    populateColorDropdown();
}
function addNewColor(){
    const n=document.getElementById('new-color-name'),h=document.getElementById('new-color-hex');
    const name=n.value.trim(),hex=h.value||'#888';
    if(!name){showAlert('Enter a color name','error');return;}
    if(productColors.some(c=>c.name.toLowerCase()===name.toLowerCase())){showAlert('Color already exists','error');return;}
    productColors.push({name,hex});n.value='';h.value='#888888';
    renderColorsSection();
}
function removeColor(name){
    if(variantList.some(v=>v.color_name===name)&&!confirm('Color "'+name+'" has variants. Remove from list?'))return;
    productColors=productColors.filter(c=>c.name!==name);renderColorsSection();
}
function populateColorDropdown(){
    const s=document.getElementById('variant-color-select');if(!s)return;
    const cur=s.value;
    s.innerHTML='<option value="">— No Color —</option>'+productColors.map(c=>`<option value="${esc(c.name)}" data-hex="${esc(c.hex)}">${esc(c.name)}</option>`).join('');
    if(cur)s.value=cur;
}
function handleColorSelect(sel){
    const hex=sel.selectedOptions[0]?.dataset?.hex||'';
    document.querySelector('#variant-form [name=color_name]').value=sel.value;
    document.querySelector('#variant-form [name=color_hex]').value=hex;
    const dot=document.getElementById('variant-color-dot');if(dot)dot.style.background=hex||'#888';
}

function renderVariantTable(){
    const tb=document.getElementById('variants-tbody');
    if(!variantList.length){
        tb.innerHTML='<tr><td colspan="8" style="text-align:center;padding:30px;color:var(--a-muted)">No variants yet. Add one below.</td></tr>';
        return;
    }
    tb.innerHTML=variantList.map(v=>{
        const colorDot=v.color_hex?`<span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:${esc(v.color_hex)};border:1px solid rgba(255,255,255,.15);vertical-align:middle;margin-right:4px"></span>`:'';
        const imgThumb=v.image_url?`<img src="${v.image_url}" style="width:28px;height:28px;object-fit:cover;border-radius:3px;margin-top:4px;display:block">`:'';
        const badge=v.is_active==1?'<span class="admin-badge admin-badge--active">Active</span>':'<span class="admin-badge admin-badge--inactive">Inactive</span>';
        const priceCol=v.price_override!==null?money(v.price_override):'<span style="color:var(--a-muted)">—</span>';
        return `<tr>
            <td>${esc(v.size||'—')}</td>
            <td>${v.color_name?colorDot+esc(v.color_name):'—'}${imgThumb}</td>
            <td style="font-size:.75rem;color:var(--a-muted)">${esc(v.sku||'—')}</td>
            <td>${priceCol}</td>
            <td>${parseInt(v.stock)}</td>
            <td>${badge}</td>
            <td><div class="admin-table__actions">
                <button class="admin-btn admin-btn--sm" onclick="editVariant(${v.id})">Edit</button>
                <button class="admin-btn admin-btn--sm admin-btn--danger" onclick="deleteVariant(${v.id})">Delete</button>
            </div></td>
        </tr>`;
    }).join('');
}

function editVariant(id){
    fetch(API+'?action=get_variant&id='+id+'&product_id='+currentProductId,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(!d.success||!d.variant)return;
        const v=d.variant;
        editingVariantId=v.id;
        const f=document.getElementById('variant-form');
        f.querySelector('[name=size]').value=v.size||'';
        f.querySelector('[name=color_name]').value=v.color_name||'';
        f.querySelector('[name=color_hex]').value=v.color_hex||'';
        const sel=document.getElementById('variant-color-select');
        if(sel){sel.value=v.color_name||'';}
        const dot=document.getElementById('variant-color-dot');if(dot)dot.style.background=v.color_hex||'#888';
        f.querySelector('[name=sku]').value=v.sku||'';
        f.querySelector('[name=price_override]').value=v.price_override??'';
        f.querySelector('[name=cost_price]').value=v.cost_price??'';
        f.querySelector('[name=stock]').value=v.stock||0;
        f.querySelector('[name=sort_order]').value=v.sort_order||0;
        f.querySelector('[name=is_active]').checked=v.is_active==1;
        f.querySelector('[name=media_image_path]').value=v.image||'';
        // Show selected image
        const prev=document.getElementById('variant-img-preview');
        const removeBtn=document.getElementById('variant-img-remove-btn');
        if(v.image_url){
            prev.innerHTML=`<img src="${v.image_url}" style="width:100%;height:100%;object-fit:cover">`;
            if(removeBtn) removeBtn.style.display='inline-block';
        } else {
            prev.innerHTML='<span style="color:var(--a-muted);font-size:.65rem">None</span>';
            if(removeBtn) removeBtn.style.display='none';
        }
        document.getElementById('variant-form-title').textContent='✎ Edit Variant';
        document.getElementById('variant-cancel-btn').style.display='inline-block';
    });
}

function resetVariantForm(){
    editingVariantId=0;
    const f=document.getElementById('variant-form');
    f.reset();
    f.querySelector('[name=is_active]').checked=true;
    f.querySelector('[name=color_name]').value='';
    f.querySelector('[name=color_hex]').value='';
    f.querySelector('[name=media_image_path]').value='';
    const sel=document.getElementById('variant-color-select');if(sel)sel.value='';
    const dot=document.getElementById('variant-color-dot');if(dot)dot.style.background='#888';
    document.getElementById('variant-img-preview').innerHTML='<span style="color:var(--a-muted);font-size:.65rem">None</span>';
    const removeBtn=document.getElementById('variant-img-remove-btn');
    if(removeBtn) removeBtn.style.display='none';
    document.getElementById('variant-form-title').textContent='+ Add Variant';
    document.getElementById('variant-cancel-btn').style.display='none';
}

function saveVariant(e){
    e.preventDefault();
    const f=e.target;
    const fd=new FormData(f);
    fd.append('action','save_variant');
    fd.append('product_id',currentProductId);
    fd.append('variant_id',editingVariantId);
    if(!f.querySelector('[name=is_active]').checked) fd.delete('is_active');

    fetch(API,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.success){showAlert(d.message,'success');resetVariantForm();loadVariants();}
        else showAlert(d.message||'Error','error');
    });
}

function deleteVariant(id){
    if(!confirm('Delete this variant?'))return;
    const fd=new FormData();
    fd.append('action','delete_variant');
    fd.append('id',id);
    fd.append('product_id',currentProductId);
    fetch(API,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.success){showAlert('Variant deleted.','success');loadVariants();}
    });
}

// ══════════════════════════════════════
// COLOR GALLERIES
// ══════════════════════════════════════
let colorGalleryPickerTarget = null;

function loadColorGalleries(){
    fetch(API+'?action=get_color_galleries&product_id='+currentProductId,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        colorGalleries = (d.success && d.color_galleries && !Array.isArray(d.color_galleries)) ? d.color_galleries : {};
        renderColorGalleriesSection();
    }).catch(()=>{
        colorGalleries = {};
        renderColorGalleriesSection();
    });
}

function renderColorGalleriesSection(){
    const container = document.getElementById('color-galleries-container');
    // Extract unique colors from variants
    const uniqueColors = {};
    variantList.forEach(v=>{
        if(v.color_name && !uniqueColors[v.color_name]){
            uniqueColors[v.color_name] = v.color_hex || '#888';
        }
    });

    const colorNames = Object.keys(uniqueColors);
    if(!colorNames.length){
        container.innerHTML='<p style="color:var(--a-muted);font-size:.8rem">Add variants with colors first, then manage their galleries here.</p>';
        return;
    }

    container.innerHTML = colorNames.map(cn => {
        const hex = uniqueColors[cn];
        const imgs = colorGalleries[cn] || [];
        const thumbs = imgs.map((p,i)=>`
            <div style="position:relative;display:inline-block">
                <img src="${BASE}/${p}" style="width:72px;height:72px;object-fit:cover;border-radius:4px;border:1px solid var(--a-border)">
                <button type="button" onclick="removeColorGalleryImage('${esc(cn)}',${i})" style="position:absolute;top:-6px;right:-6px;width:18px;height:18px;background:var(--a-danger,#c44);color:#fff;border:none;border-radius:50%;font-size:.6rem;cursor:pointer;display:flex;align-items:center;justify-content:center">✕</button>
            </div>
        `).join('');
        return `
        <div style="margin-bottom:20px;padding:16px;background:var(--a-bg,#0d0d0d);border:1px solid var(--a-border);border-radius:6px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
                <span style="display:inline-block;width:20px;height:20px;border-radius:50%;background:${esc(hex)};border:1px solid rgba(255,255,255,.15)"></span>
                <strong style="color:var(--a-text,#eee);font-size:.85rem">${esc(cn)}</strong>
                <span style="font-size:.7rem;color:var(--a-muted);margin-left:auto">${imgs.length} image${imgs.length!==1?'s':''}</span>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px">
                ${thumbs || '<span style="color:var(--a-muted);font-size:.75rem">No images yet</span>'}
            </div>
            <button type="button" class="admin-btn admin-btn--sm" onclick="openColorGalleryPicker('${esc(cn)}')">📷 Add Image</button>
        </div>`;
    }).join('');
}

function openColorGalleryPicker(colorName){
    colorGalleryPickerTarget = colorName;
    openMediaPicker('color_gallery');
}

function removeColorGalleryImage(colorName, index){
    if(colorGalleries[colorName]) {
        colorGalleries[colorName].splice(index, 1);
        renderColorGalleriesSection();
    }
}

function saveColorGalleries(){
    const fd = new FormData();
    fd.append('action','save_color_galleries');
    fd.append('product_id', currentProductId);
    fd.append('color_galleries', JSON.stringify(colorGalleries));
    fetch(API,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.success) showAlert(d.message,'success');
        else showAlert(d.message || 'Error saving galleries','error');
    }).catch(e=>{
        showAlert('Error: ' + e.message, 'error');
    });
}

// ══════════════════════════════════════
// MEDIA PICKER MODAL (with multi-select)
// ══════════════════════════════════════
let mediaPickerCallback=null;
let multiSelectMode = false;
let selectedMediaPaths = []; // [{filepath, url}, ...]

function isMultiMode(mode) {
    return mode === 'gallery' || mode === 'color_gallery';
}

function openMediaPicker(mode){
    mediaPickerCallback=mode;
    multiSelectMode = isMultiMode(mode);
    selectedMediaPaths = [];
    pickerCurrentPage = 1;
    pickerTotalPages = 1;
    document.getElementById('media-picker-overlay').style.display='flex';
    
    // Update title and show/hide multi-select controls
    const title = document.getElementById('media-picker-title');
    const countEl = document.getElementById('media-selected-count');
    const doneBtn = document.getElementById('media-done-btn');
    const grid = document.getElementById('media-picker-grid');
    
    if(multiSelectMode) {
        if(title) title.textContent = 'Select Images (click multiple)';
        if(countEl) { countEl.style.display = 'inline'; countEl.textContent = '0 selected'; }
        if(doneBtn) doneBtn.style.display = 'inline-block';
    } else {
        if(title) title.textContent = 'Select from Media Library';
        if(countEl) countEl.style.display = 'none';
        if(doneBtn) doneBtn.style.display = 'none';
    }
    
    grid.onscroll = null;
    loadMedia(1, false);
    
    grid.onscroll = function() {
        if (grid.scrollTop + grid.clientHeight >= grid.scrollHeight - 100 && !pickerIsLoading && pickerCurrentPage < pickerTotalPages) {
            loadMedia(pickerCurrentPage + 1, true);
        }
    };
}

function closeMediaPicker(){
    document.getElementById('media-picker-overlay').style.display='none';
    mediaPickerCallback=null;
    colorGalleryPickerTarget=null;
    multiSelectMode=false;
    selectedMediaPaths=[];
}

let pickerCurrentPage = 1;
let pickerTotalPages = 1;
let pickerIsLoading = false;

function loadMedia(page = 1, append = false){
    if (pickerIsLoading) return;
    pickerIsLoading = true;
    fetch(MEDIA_API+'?type=image&page=' + page,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        pickerIsLoading = false;
        if(!d.success)return;
        pickerCurrentPage = d.page;
        pickerTotalPages = d.pages;
        if (append) {
            allMedia = allMedia.concat(d.items);
        } else {
            allMedia = d.items;
        }
        const search = document.getElementById('media-search-input')?.value?.trim().toLowerCase() || '';
        renderMediaGrid(search);
    }).catch(() => { pickerIsLoading = false; });
}

function renderMediaGrid(search){
    const grid=document.getElementById('media-picker-grid');
    let items=allMedia;
    if(search) items=items.filter(m=>m.filename.toLowerCase().includes(search));
    if(!items.length){grid.innerHTML='<p style="color:var(--a-muted);grid-column:1/-1;text-align:center;padding:30px">No media found.</p>';return;}
    
    grid.innerHTML=items.map(m=>{
        const thumbUrl = BASE + '/thumb.php?src=' + encodeURIComponent(m.filepath) + '&w=200';
        const isSelected = selectedMediaPaths.some(s => s.filepath === m.filepath);
        const checkmark = multiSelectMode ? `<div class="media-picker__check ${isSelected?'checked':''}" data-fp="${esc(m.filepath)}">✓</div>` : '';
        return `<div class="media-picker__item ${isSelected?'selected':''}" onclick="handleMediaClick('${esc(m.filepath)}','${esc(m.url)}')">
            <img src="${thumbUrl}" alt="${esc(m.filename)}" loading="lazy">
            <span>${esc(m.filename)}</span>
            ${checkmark}
        </div>`;
    }).join('');
}

function handleMediaClick(filepath, url) {
    if(multiSelectMode) {
        // Toggle selection
        const idx = selectedMediaPaths.findIndex(s => s.filepath === filepath);
        if(idx >= 0) {
            selectedMediaPaths.splice(idx, 1);
        } else {
            selectedMediaPaths.push({filepath, url});
        }
        // Update counter
        const countEl = document.getElementById('media-selected-count');
        if(countEl) countEl.textContent = selectedMediaPaths.length + ' selected';
        // Re-render to show checkmarks
        const search = document.getElementById('media-search-input')?.value?.trim().toLowerCase() || '';
        renderMediaGrid(search);
    } else {
        // Single select — immediate apply
        selectMedia(filepath, url);
    }
}

function confirmMultiSelect() {
    if(!selectedMediaPaths.length) { closeMediaPicker(); return; }
    
    if(mediaPickerCallback === 'gallery') {
        selectedMediaPaths.forEach(s => {
            if(!galleryPaths.includes(s.filepath)) galleryPaths.push(s.filepath);
        });
        renderGalleryPreview();
    } else if(mediaPickerCallback === 'color_gallery' && colorGalleryPickerTarget) {
        if(!colorGalleries[colorGalleryPickerTarget]) colorGalleries[colorGalleryPickerTarget] = [];
        selectedMediaPaths.forEach(s => {
            if(!colorGalleries[colorGalleryPickerTarget].includes(s.filepath)) {
                colorGalleries[colorGalleryPickerTarget].push(s.filepath);
            }
        });
        renderColorGalleriesSection();
    }
    closeMediaPicker();
}

function selectMedia(filepath,url){
    if(mediaPickerCallback==='main_image'){
        document.querySelector('[name=existing_image]').value=filepath;
        document.getElementById('main-img-preview').innerHTML=`<img src="${url}" alt="" style="width:100%;height:100%;object-fit:cover">`;
        const removeBtn=document.getElementById('main-img-remove-btn');
        if(removeBtn) removeBtn.style.display='inline-block';
    } else if(mediaPickerCallback==='gallery'){
        if(!galleryPaths.includes(filepath)){
            galleryPaths.push(filepath);
            renderGalleryPreview();
        }
    } else if(mediaPickerCallback==='variant'){
        document.querySelector('#variant-form [name=media_image_path]').value=filepath;
        document.getElementById('variant-img-preview').innerHTML=`<img src="${url}" style="width:100%;height:100%;object-fit:cover">`;
        const removeBtn=document.getElementById('variant-img-remove-btn');
        if(removeBtn) removeBtn.style.display='inline-block';
    } else if(mediaPickerCallback==='size_chart'){
        sizeChartPath = filepath;
        renderSizeChartPreview();
    } else if(mediaPickerCallback==='color_gallery' && colorGalleryPickerTarget){
        if(!colorGalleries[colorGalleryPickerTarget]) colorGalleries[colorGalleryPickerTarget]=[];
        if(!colorGalleries[colorGalleryPickerTarget].includes(filepath)){
            colorGalleries[colorGalleryPickerTarget].push(filepath);
            renderColorGalleriesSection();
        }
    }
    closeMediaPicker();
}

// ══════════════════════════════════════
// HELPERS
// ══════════════════════════════════════
function moneyText(v){ return (+v || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + String(window.CURRENCY || '').trim(); }
function money(v){
    const n = (+v || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    return '<span style="white-space:nowrap">' + n + ' ' + esc(String(window.CURRENCY || '').trim()) + '</span>';
}
function esc(s){if(!s)return'';const d=document.createElement('div');d.textContent=s;return d.innerHTML;}

function showAlert(msg,type){
    const c=document.getElementById('alert-container');
    const cls=type==='success'?'admin-alert--success':'admin-alert--error';
    c.innerHTML=`<div class="admin-alert ${cls}">${esc(msg)}</div>`;
    setTimeout(()=>c.innerHTML='',4000);
}
