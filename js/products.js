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
    loadMedia();
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
    fetch(API+'?action=list_products',{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(!d.success)return;
        allProducts=d.products;
        document.getElementById('product-count').textContent=d.products.length+' products';
        renderProductList();
    });
}

function renderProductList(){
    const tb=document.getElementById('products-tbody');
    let filteredProducts = allProducts;
    
    // Apply filters
    if (currentProductFilter === 'active') {
        filteredProducts = filteredProducts.filter(p => p.is_active == 1);
    } else if (currentProductFilter === 'inactive') {
        filteredProducts = filteredProducts.filter(p => p.is_active == 0);
    }
    
    // Apply search
    if (currentProductSearch) {
        filteredProducts = filteredProducts.filter(p => 
            p.name.toLowerCase().includes(currentProductSearch) || 
            (p.sku && p.sku.toLowerCase().includes(currentProductSearch)) ||
            (p.category_name && p.category_name.toLowerCase().includes(currentProductSearch))
        );
    }

    if(!filteredProducts.length){
        tb.innerHTML='<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--a-muted)">No products found.</td></tr>';
        document.getElementById('product-count').textContent = '0 products';
        return;
    }
    
    document.getElementById('product-count').textContent = filteredProducts.length + ' products';

    tb.innerHTML=filteredProducts.map(p=>{
        const stockVal = parseInt(p.stock);
        const lowStockStyle = stockVal <= 5 ? 'color:var(--amber);font-weight:bold;' : '';
        const rowStyle = stockVal <= 5 ? 'background:rgba(200,184,154,.05);' : '';
        
        const imgStyle = `width:40px;height:40px;object-fit:cover;border-radius:6px;border:0.5px solid var(--border)`;
        const img=p.image_url?`<img src="${p.image_url}" alt="" style="${imgStyle}">`:`<div style="${imgStyle};display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:10px;background:var(--bg-primary)">—</div>`;
        const price=p.sale_price?`<span style="color:var(--accent)">${money(p.sale_price)}</span><br><span style="font-size:.72rem;color:var(--text-muted);text-decoration:line-through">${money(p.price)}</span>`:money(p.price);
        const star=p.is_featured==1?'<span style="color:var(--amber);font-size:.65rem;margin-left:4px">★</span>':'';
        
        const badge = `<label class="hp-section-toggle" style="transform:scale(0.8);transform-origin:left center;margin:0"><input type="checkbox" ${p.is_active==1?'checked':''} onchange="toggleProductActive(${p.id}, this.checked)"><span class="hp-section-toggle__slider"></span></label>`;
        const vBtn=p.has_variants==1?`<button class="admin-btn admin-btn--sm" style="color:var(--accent)" onclick="openVariants(${p.id})">Variants</button>`:'';
        
        return `<tr style="${rowStyle}">
            <td><input type="checkbox" class="product-row-checkbox" value="${p.id}" ${selectedProductIds.includes(p.id) ? 'checked' : ''} onchange="toggleProductSelect(this, ${p.id})"></td>
            <td><div style="display:flex;align-items:center;gap:12px">${img}<div><strong>${esc(p.name)}</strong>${star}<br><span style="font-size:.72rem;color:var(--text-muted)">${esc(p.sku||'')}</span></div></div></td>
            <td style="color:var(--text-muted)">${esc(p.category_name||'—')}</td>
            <td>${price}</td>
            <td style="${lowStockStyle}">${stockVal} ${stockVal <= 5 ? '<span style="font-size:.7rem">⚠️</span>' : ''}</td>
            <td>${badge}</td>
            <td><div class="admin-table__actions">
                <button class="admin-btn admin-btn--sm" onclick="editProduct(${p.id})">Edit</button>
                ${vBtn}
                <button class="admin-btn admin-btn--sm admin-btn--danger" onclick="deleteProduct(${p.id})">Delete</button>
            </div></td>
        </tr>`;
    }).join('');
    updateProductBulkActionBar();
}

function toggleProductActive(id, isActive) {
    const fd = new FormData();
    fd.append('action', 'bulk_action');
    fd.append('bulk_action_type', isActive ? 'mark_active' : 'mark_inactive');
    fd.append('bulk_ids', JSON.stringify([id]));
    fetch(API, {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r => r.json()).then(d => {
        if(d.success) {
            const p = allProducts.find(x => x.id == id);
            if(p) p.is_active = isActive ? 1 : 0;
            updateProductBulkActionBar();
        } else {
            alert('Failed to toggle status');
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
    if (!action) {
        alert('Please choose an action.');
        return;
    }
    if (action === 'delete') {
        if (!confirm('Delete selected products and all their variants?')) return;
    }
    
    const fd = new FormData();
    fd.append('action', 'bulk_action');
    fd.append('bulk_action_type', action);
    fd.append('bulk_ids', JSON.stringify(selectedProductIds));
    
    fetch(API, {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r => r.json()).then(d => {
        if (d.success) {
            selectedProductIds = [];
            loadProducts();
            document.getElementById('product-bulk-action-select').value = '';
        } else {
            alert(d.message || 'Bulk action failed');
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
        if(product.is_featured==1) f.querySelector('[name=is_featured]').checked=true;
        if(product.is_active==1) f.querySelector('[name=is_active]').checked=true;
        if(product.has_variants==1) f.querySelector('[name=has_variants]').checked=true;
        const preorderBox = f.querySelector('[name=preorder_enabled]');
        if(preorderBox) preorderBox.checked = product.preorder_enabled == 1;
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

function saveProduct(e){
    e.preventDefault();
    const f=e.target;
    const fd=new FormData(f);
    fd.append('action','save_product');
    fd.set('gallery_paths',JSON.stringify(galleryPaths));
    fd.set('size_chart', sizeChartPath);
    // checkboxes
    if(!f.querySelector('[name=is_featured]').checked) fd.delete('is_featured');
    if(!f.querySelector('[name=is_active]').checked) fd.delete('is_active');
    if(!f.querySelector('[name=has_variants]').checked) fd.delete('has_variants');

    fetch(API,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            showAlert(d.message,'success');
            showListView();
            loadProducts();
        } else {
            showAlert(d.message||'Error saving product','error');
        }
    });
}

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

function showListView(){
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
    document.getElementById('variant-base-info').textContent=p?`Base price: ${money(p.price)} · SKU: ${p.sku||'—'}`:'';
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
    fetch(MEDIA_API+'?page=' + page,{headers:{'X-Requested-With':'XMLHttpRequest'}})
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
function money(v){return window.CURRENCY+(+v).toFixed(2);}
function esc(s){if(!s)return'';const d=document.createElement('div');d.textContent=s;return d.innerHTML;}

function showAlert(msg,type){
    const c=document.getElementById('alert-container');
    const cls=type==='success'?'admin-alert--success':'admin-alert--error';
    c.innerHTML=`<div class="admin-alert ${cls}">${esc(msg)}</div>`;
    setTimeout(()=>c.innerHTML='',4000);
}