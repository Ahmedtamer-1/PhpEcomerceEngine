<?php
/**
 * Admin — Products & Variants (Single-Page AJAX)
 */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Products';
$adminPage = 'products';
$categories = get_categories();
$currency = currency_symbol();
require_once __DIR__ . '/includes/header.php';
?>

<div id="alert-container"></div>

<!-- ═══ LIST VIEW ═══ -->
<div id="view-list">
    <div class="admin-toolbar" style="flex-wrap:wrap;gap:12px">
        <div class="admin-toolbar__left g-toolbar-row">
            <div class="admin-filter-tabs" id="product-filter-tabs">
                <button type="button" class="admin-filter-tab active" data-filter="all">All</button>
                <button type="button" class="admin-filter-tab" data-filter="active">Active</button>
                <button type="button" class="admin-filter-tab" data-filter="inactive">Hidden</button>
                <button type="button" class="admin-filter-tab" data-filter="low">Low stock</button>
                <button type="button" class="admin-filter-tab" data-filter="featured">Featured</button>
            </div>
            <select id="product-category-filter" class="g-select-sm" aria-label="Filter by category">
                <option value="">All categories</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="product-sort" class="g-select-sm" aria-label="Sort products">
                <option value="new">Newest first</option>
                <option value="name">Name A–Z</option>
                <option value="price_asc">Price: low → high</option>
                <option value="price_desc">Price: high → low</option>
                <option value="stock_asc">Stock: lowest first</option>
            </select>
        </div>
        <div class="admin-toolbar__right g-toolbar-row">
            <span style="font-size:.8rem;color:var(--text-muted)" id="product-count">Loading…</span>
            <input type="search" id="product-search" placeholder="Search name, SKU, category…  ( / )" class="admin-search-input">
            <button class="admin-btn admin-btn--primary" onclick="showProductForm(null)" title="New product (N)">
                <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Product
            </button>
        </div>
    </div>
    <div class="admin-table-wrap" style="position:relative">
        <table class="admin-table">
            <thead><tr><th style="width:40px"><input type="checkbox" id="selectAllProducts" onclick="toggleAllProducts(this)" aria-label="Select all"></th><th>Product</th><th>Category</th><th>Price</th><th>Sale</th><th>Stock</th><th style="width:44px" title="Featured">★</th><th>Visible</th><th style="text-align:right">Actions</th></tr></thead>
            <tbody id="products-tbody"><tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-muted)">Loading…</td></tr></tbody>
        </table>
    </div>
    <div class="g-shortcuts">
        <span><strong>Tip:</strong> click a price, sale price or stock number to edit it in place — <span class="g-kbd">Enter</span> saves, <span class="g-kbd">Esc</span> cancels.</span>
        <span><span class="g-kbd">/</span> search</span>
        <span><span class="g-kbd">N</span> new product</span>
        <span><span class="g-kbd">Ctrl</span>+<span class="g-kbd">S</span> save form</span>
    </div>
</div>

<!-- Floating Bulk Action Bar -->
<div id="product-bulk-action-bar" style="display:none; position:fixed; bottom:24px; left:50%; transform:translateX(-50%); padding:10px 12px 10px 20px; border-radius:18px; z-index:300; align-items:center; gap:12px; flex-wrap:wrap; animation:gScaleIn .3s var(--ease)">
    <span id="product-bulk-count" style="font-weight:600; font-size:.85rem">0 selected</span>
    <select id="product-bulk-action-select" class="g-select-sm" style="padding:7px 10px;border-radius:10px;border:1px solid var(--g-line);background:var(--g-input);color:var(--text-primary)">
        <option value="">Choose action…</option>
        <option value="mark_active">Show in shop</option>
        <option value="mark_inactive">Hide from shop</option>
        <option value="mark_featured">Mark featured</option>
        <option value="unmark_featured">Remove featured</option>
        <option value="set_category">Move to category…</option>
        <option value="delete">Delete</option>
    </select>
    <select id="product-bulk-category" style="display:none;padding:7px 10px;border-radius:10px;border:1px solid var(--g-line);background:var(--g-input);color:var(--text-primary)">
        <option value="">— No category —</option>
        <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="button" class="admin-btn admin-btn--primary admin-btn--sm" onclick="applyProductBulkAction()">Apply</button>
    <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" onclick="clearProductSelection()">Clear</button>
</div>

<!-- ═══ PRODUCT FORM VIEW ═══ -->
<div id="view-form" style="display:none">
    <div style="margin-bottom:20px;display:flex;align-items:center;gap:12px">
        <button class="admin-btn admin-btn--sm" style="opacity:.7" onclick="showListView()">← Back to Products</button>
        <h2 style="font-size:1rem;color:var(--a-text,#eee);margin:0" id="form-title">Add Product</h2>
    </div>
    <div style="max-width:720px">
        <form id="product-form" class="admin-form">
            <input type="hidden" name="id" value="0">
            <input type="hidden" name="existing_image" value="">

            <!-- Basic Info Card -->
            <div class="card" style="margin-bottom: 24px;">
                <h3 style="font-size:14px; margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 8px;">Basic Info</h3>

            <div class="admin-form__group">
                <label>Product Name *</label>
                <input type="text" name="name" required autocomplete="off">
            </div>
            <div class="admin-form__group">
                <label>Slug</label>
                <input type="text" name="slug">
                <span class="admin-form__hint">Leave empty to auto-generate</span>
            </div>
            <div class="admin-form__row">
                <div class="admin-form__group">
                    <label>Category</label>
                    <select name="category_id">
                        <option value="">— No category —</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-form__group">
                    <label>SKU</label>
                    <input type="text" name="sku">
                </div>
            </div>
            <div class="admin-form__group">
                <label>Description</label>
                <textarea name="description"></textarea>
            </div>

            </div>

            <!-- SEO / Meta Card -->
            <div class="card" style="margin-bottom: 24px;">
                <h3 style="font-size:14px; margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 8px;">SEO / Meta Tags</h3>
                <div class="admin-form__group">
                    <label>Meta Title <span style="font-weight:400;color:var(--a-muted)">(leave empty to auto-generate)</span></label>
                    <input type="text" name="meta_title" maxlength="200" placeholder="Product Name — Brand">
                    <span class="admin-form__hint">Ideal length: 50–60 characters. Shown in Google search results.</span>
                </div>
                <div class="admin-form__group" style="margin-top:10px">
                    <label>Meta Description <span style="font-weight:400;color:var(--a-muted)">(leave empty to use product description)</span></label>
                    <textarea name="meta_desc" rows="2" maxlength="300" placeholder="Compelling description for search results…"></textarea>
                    <span class="admin-form__hint">Ideal length: 150–160 characters. Shown under title in Google.</span>
                </div>
            </div>

            <!-- Pricing & Inventory Card -->
            <div class="card" style="margin-bottom: 24px;">
                <h3 style="font-size:14px; margin-bottom: 16px; border-bottom: 1px solid var(--a-border); padding-bottom: 8px;">Pricing &amp; Inventory</h3>
                <div class="admin-form__row-3">
                    <div class="admin-form__group">
                        <label>Price (EGP) *</label>
                        <input type="number" name="price" step="0.01" min="0" required>
                    </div>
                    <div class="admin-form__group">
                        <label>Sale Price</label>
                        <input type="number" name="sale_price" step="0.01" min="0">
                        <span class="admin-form__hint">Leave empty for no sale</span>
                    </div>
                    <div class="admin-form__group">
                        <label>Cost Price (COGS)</label>
                        <input type="number" name="cost_price" step="0.01" min="0" placeholder="For profit tracking">
                    </div>
                </div>
                <div class="g-hint" id="price-margin" style="margin:-4px 0 10px"></div>
                <div class="admin-form__group" id="stock-group" style="margin-top:4px;max-width:220px">
                    <label>Stock</label>
                    <input type="number" name="stock" min="0" value="0">
                    <span class="admin-form__hint" id="stock-hint">Units available for sale</span>
                </div>
            </div>

            <!-- Media Card -->
            <div class="card" id="media-upload-card" style="margin-bottom: 24px;">
                <h3 style="font-size:14px; margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 8px;">Media</h3>
                
                <div id="product-media-dropzone" class="media-dropzone" style="border: 2px dashed var(--border); border-radius: var(--radius); padding: 30px; text-align: center; margin-bottom: 20px; cursor: pointer; transition: all 0.3s;">
                    <p style="color: var(--text-muted); pointer-events: none;">Drag & drop images here to upload to media library</p>
                </div>

                <div class="admin-form__group">
                <label>Product Image</label>
                <div style="display:flex;align-items:flex-start;gap:16px">
                    <div id="main-img-preview" style="width:90px;height:90px;border-radius:6px;border:1px solid var(--a-border);background:var(--a-bg,#111);display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;color:var(--a-muted);font-size:.72rem;text-align:center">No image</div>
                    <div style="display:flex;flex-direction:column;gap:8px;justify-content:center">
                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                            <button type="button" class="admin-btn admin-btn--sm" onclick="openMediaPicker('main_image')">📷 Choose from Media</button>
                            <button type="button" id="main-img-remove-btn" class="admin-btn admin-btn--sm" onclick="removeMainImage()" style="color:var(--a-danger,#c44);display:none">✕ Remove</button>
                        </div>
                        <span class="admin-form__hint">Select from your media library</span>
                    </div>
                </div>
            </div>

            <!-- Gallery -->
            <div class="admin-form__group">
                <label>Gallery Images</label>
                <div id="gallery-preview" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px">
                    <span style="color:var(--a-muted);font-size:.75rem">No gallery images</span>
                </div>
                <button type="button" class="admin-btn admin-btn--sm" onclick="openMediaPicker('gallery')">📷 Add from Media</button>
            </div>

            <!-- Size Chart -->
            <div class="admin-form__group">
                <label>Size Chart Image</label>
                <div class="admin-img-upload">
                    <div class="admin-img-preview" id="sizechart-img-preview" style="max-width:200px">No size chart</div>
                    <div>
                        <button type="button" class="admin-btn admin-btn--sm" onclick="openMediaPicker('size_chart')">📐 Choose Size Chart</button>
                        <button type="button" class="admin-btn admin-btn--sm" onclick="removeSizeChart()" style="margin-left:4px;color:var(--a-danger,#c44)">✕ Remove</button>
                        <span class="admin-form__hint" style="margin-top:6px;display:block">Image shown in the size chart popup on product page</span>
                    </div>
                </div>
            </div>
            </div><!-- /Media card -->

            <!-- Product Details Card -->
            <div class="card" style="margin-bottom: 24px;">
                <h3 style="font-size:14px; margin-bottom: 16px; border-bottom: 1px solid var(--a-border); padding-bottom: 8px;">Product Details</h3>
                <div class="admin-form__row">
                    <div class="admin-form__group">
                        <label>Material <span style="font-weight:400;color:var(--a-muted)">(leave empty to hide)</span></label>
                        <input type="text" name="material" placeholder="e.g. 18K Gold, Sterling Silver">
                    </div>
                    <div class="admin-form__group">
                        <label>Gem / Stone <span style="font-weight:400;color:var(--a-muted)">(leave empty to hide)</span></label>
                        <input type="text" name="gem" placeholder="e.g. Diamond, Ruby, Emerald">
                    </div>
                </div>
            </div>

            <!-- Options Card -->
            <div class="card" style="margin-bottom: 24px;">
                <h3 style="font-size:14px; margin-bottom: 4px; border-bottom: 1px solid var(--a-border); padding-bottom: 8px;">Options</h3>

                <p style="font-size:.72rem;color:var(--a-muted);margin:12px 0 6px;text-transform:uppercase;letter-spacing:.08em">Visibility</p>
                <div class="admin-form__check">
                    <input type="checkbox" name="is_active" id="is_active" checked>
                    <label for="is_active">Active
                        <span style="display:block;font-weight:400;color:var(--a-muted);font-size:.75rem">Uncheck to hide this product from the shop entirely.</span>
                    </label>
                </div>
                <div class="admin-form__check" style="margin-top:10px">
                    <input type="checkbox" name="is_featured" id="is_featured">
                    <label for="is_featured">Featured
                        <span style="display:block;font-weight:400;color:var(--a-muted);font-size:.75rem">Shows in the featured section on the homepage.</span>
                    </label>
                </div>

                <p style="font-size:.72rem;color:var(--a-muted);margin:20px 0 6px;padding-top:14px;border-top:1px solid var(--a-border);text-transform:uppercase;letter-spacing:.08em">Selling behaviour</p>
                <div class="admin-form__check">
                    <input type="checkbox" name="has_variants" id="has_variants">
                    <label for="has_variants">Has variants (sizes / colours)
                        <span style="display:block;font-weight:400;color:var(--a-muted);font-size:.75rem">Stock is then tracked per variant and the Stock field above is ignored. Manage them with the <strong>Variants</strong> button on the product list.</span>
                    </label>
                </div>
                <div class="admin-form__check" style="margin-top:10px">
                    <input type="checkbox" name="preorder_enabled" id="preorder_enabled">
                    <label for="preorder_enabled">Allow pre-order when out of stock
                        <span style="display:block;font-weight:400;color:var(--a-muted);font-size:.75rem">Shows &ldquo;Pre-order&rdquo; instead of &ldquo;Sold Out&rdquo; at zero stock. Cash on delivery is disabled for carts containing a pre-order.</span>
                    </label>
                </div>
            </div>

            <!-- Sticky save bar -->
            <div class="g-savebar" id="product-savebar">
                <span class="g-savebar__status" id="form-save-status">All changes saved</span>
                <div class="g-savebar__actions">
                    <button type="button" class="admin-btn admin-btn--ghost" onclick="showListView()">Cancel <span class="g-kbd">Esc</span></button>
                    <button type="button" class="admin-btn" id="product-save-stay-btn" onclick="submitProductForm(false)">Save <span class="g-kbd">Ctrl S</span></button>
                    <button type="submit" class="admin-btn admin-btn--primary" id="product-save-btn">Save &amp; close</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ═══ VARIANTS VIEW ═══ -->
<div id="view-variants" style="display:none">
    <div style="margin-bottom:20px">
        <button class="admin-btn admin-btn--sm" style="opacity:.7" onclick="showListView()">← Back to Products</button>
    </div>
    <h2 style="font-size:1rem;margin-bottom:4px;color:var(--a-text,#eee)">Variants for: <span id="variant-product-name"></span></h2>
    <p style="font-size:.75rem;color:var(--a-muted);margin-bottom:24px" id="variant-base-info"></p>

    <div class="admin-table-wrap" style="margin-bottom:32px">
        <table class="admin-table">
            <thead><tr><th>Size</th><th>Color</th><th>SKU</th><th>Price Override</th><th>Stock</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody id="variants-tbody"><tr><td colspan="7" style="text-align:center;padding:30px;color:var(--a-muted)">Loading…</td></tr></tbody>
        </table>
    </div>

    <!-- Define Product Colors -->
    <div class="card" style="margin-bottom:24px;">
        <h3 style="font-size:14px; margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 8px;">🎨 Define Product Colors</h3>
        <p style="font-size:.72rem;color:var(--text-muted);margin-bottom:14px">Define colors first, then add size variants for each color below.</p>
        <div id="product-colors-list" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px"><span style="color:var(--a-muted);font-size:.75rem">No colors defined yet</span></div>
        <div style="display:flex;gap:8px;align-items:center">
            <input type="text" id="new-color-name" placeholder="Color name (e.g. Black)" style="padding:8px 12px;background:var(--bg-surface2);border:1px solid var(--border);border-radius:4px;color:var(--text-primary);font-size:.82rem;width:180px">
            <input type="color" id="new-color-hex" value="#888888" style="height:34px;width:40px;padding:2px;border:1px solid var(--border);border-radius:4px;cursor:pointer">
            <button type="button" class="btn-primary" style="min-height:34px;" onclick="addNewColor()">+ Add Color</button>
        </div>
    </div>

    <!-- Bulk Variant Generator -->
    <div class="card" style="margin-bottom:24px;">
        <h3 style="font-size:14px; margin-bottom: 6px; border-bottom: 1px solid var(--a-border); padding-bottom: 8px;">⚡ Generate variants</h3>
        <p style="font-size:.75rem;color:var(--a-muted);margin:12px 0 14px">
            Type your sizes and hit Generate — every size × colour combination is created at once,
            at zero stock. Combinations that already exist are skipped, so it&rsquo;s safe to run again
            after adding a size or a colour.
        </p>
        <div class="admin-form__group" style="max-width:520px">
            <label>Sizes</label>
            <input type="text" id="bulk-sizes" placeholder="6, 7, 8, 9, 10, 11">
            <span class="admin-form__hint">Comma separated. Leave empty for a colour-only product.</span>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px">
            <button type="button" class="admin-btn admin-btn--primary" id="bulk-generate-btn" onclick="generateVariants()">Generate variants</button>
            <span id="bulk-generate-preview" style="font-size:.78rem;color:var(--a-muted)"></span>
        </div>
    </div>

    <!-- Variant Form -->
    <div class="card admin-variant-form">
        <h3 class="admin-variant-form__title" id="variant-form-title" style="font-size:14px; margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 8px;">+ Add Variant</h3>
        <form id="variant-form">
            <input type="hidden" name="media_image_path" value="">
            <input type="hidden" name="color_name" value="">
            <input type="hidden" name="color_hex" value="">
            <div class="admin-variant-form__row">
                <div class="admin-variant-form__group">
                    <label>Color</label>
                    <div style="display:flex;gap:8px;align-items:center">
                        <select name="color_select" id="variant-color-select" onchange="handleColorSelect(this)" style="flex:1;padding:8px 12px;background:var(--a-bg,#111);border:1px solid var(--a-border);border-radius:4px;color:var(--a-text,#eee);font-size:.82rem">
                            <option value="">— No Color —</option>
                        </select>
                        <span id="variant-color-dot" style="width:24px;height:24px;border-radius:50%;border:1px solid rgba(255,255,255,.15);background:#888;flex-shrink:0"></span>
                    </div>
                </div>
                <div class="admin-variant-form__group">
                    <label>Size</label>
                    <input type="text" name="size" placeholder="e.g. S, M, L, XL">
                </div>
            </div>
            <div class="admin-variant-form__row">
                <div class="admin-variant-form__group">
                    <label>SKU</label>
                    <input type="text" name="sku" placeholder="e.g. LME-TP-001-M">
                </div>
                <div class="admin-variant-form__group">
                    <label>Price Override (EGP)</label>
                    <input type="number" name="price_override" step="0.01" min="0" placeholder="Blank = base price">
                </div>
                <div class="admin-variant-form__group">
                    <label>Cost Price (COGS)</label>
                    <input type="number" name="cost_price" step="0.01" min="0" placeholder="Blank = base cost">
                </div>
                <div class="admin-variant-form__group">
                    <label>Stock</label>
                    <input type="number" name="stock" min="0" value="0">
                </div>
                <div class="admin-variant-form__group">
                    <label>Sort Order</label>
                    <input type="number" name="sort_order" min="0" value="0">
                </div>
            </div>
            <div style="display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-top:8px">
                <div>
                    <label style="font-size:.75rem;color:var(--a-muted);display:block;margin-bottom:6px">Variant Image</label>
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                        <div id="variant-img-preview" style="width:60px;height:60px;border-radius:4px;border:1px solid var(--a-border);background:var(--a-bg,#111);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0"><span style="color:var(--a-muted);font-size:.65rem">None</span></div>
                        <div style="display:flex;flex-direction:column;gap:6px">
                            <button type="button" class="admin-btn admin-btn--sm" onclick="openMediaPicker('variant')">📷 Choose Image</button>
                            <button type="button" id="variant-img-remove-btn" class="admin-btn admin-btn--sm" onclick="removeVariantImage()" style="color:var(--a-danger,#c44);display:none">✕ Remove</button>
                        </div>
                    </div>
                </div>
                <div class="admin-form__check">
                    <input type="checkbox" name="is_active" id="v_is_active" checked>
                    <label for="v_is_active">Active</label>
                </div>
                <button type="submit" class="admin-btn admin-btn--primary">Save Variant</button>
                <button type="button" class="admin-btn" id="variant-cancel-btn" style="display:none" onclick="resetVariantForm()">Cancel</button>
            </div>
        </form>
    </div>

    <!-- Color Galleries -->
    <div class="card" style="margin-top:24px;">
        <h3 style="font-size:14px; margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 8px;">🖼️ Color Galleries</h3>
        <p style="font-size:.72rem;color:var(--text-muted);margin-bottom:16px">Assign specific images to each color variant. These will show on the storefront when the color is selected.</p>
        <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
            <button class="admin-btn admin-btn--sm admin-btn--primary" onclick="saveColorGalleries()">💾 Save Galleries</button>
        </div>
        <div id="color-galleries-container">
            <p style="color:var(--a-muted);font-size:.8rem">Add variants with colors first, then manage their galleries here.</p>
        </div>
    </div>
</div>

<!-- ═══ MEDIA PICKER MODAL ═══ -->
<div id="media-picker-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;z-index:300;background:rgba(0,0,0,.6);justify-content:center;align-items:center">
    <div style="width:90%;max-width:900px;max-height:85vh;background:var(--a-surface,#1a1a1a);border:1px solid var(--a-border);border-radius:var(--a-radius,6px);display:flex;flex-direction:column;overflow:hidden">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--a-border)">
            <h3 style="font-size:.9rem;font-weight:600;color:var(--a-text,#eee)" id="media-picker-title">Select from Media Library</h3>
            <button onclick="closeMediaPicker()" style="background:none;border:none;color:var(--a-muted);font-size:1.2rem;cursor:pointer">✕</button>
        </div>
        <div style="padding:12px 20px;border-bottom:1px solid var(--a-border);display:flex;align-items:center;gap:12px">
            <input type="search" id="media-search-input" placeholder="Search media…" style="flex:1;max-width:300px;padding:8px 12px;background:var(--a-bg,#111);border:1px solid var(--a-border);border-radius:4px;color:var(--a-text,#eee);font-size:.82rem;outline:none">
            <span id="media-selected-count" style="font-size:.75rem;color:var(--a-accent);display:none">0 selected</span>
            <button id="media-done-btn" class="admin-btn admin-btn--sm admin-btn--primary" style="display:none;margin-left:auto" onclick="confirmMultiSelect()">✓ Add Selected</button>
        </div>
        <div id="media-picker-grid" style="padding:20px;overflow-y:auto;flex:1;display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px">
            <p style="color:var(--a-muted);grid-column:1/-1;text-align:center">Loading…</p>
        </div>
    </div>
</div>

<style>
.media-picker__item{padding-top:100%;overflow:hidden;border-radius:4px;cursor:pointer;position:relative;border:2px solid transparent;transition:all .2s;background:var(--a-bg,#111)}
.media-picker__item:hover{border-color:var(--a-accent);transform:translateY(-2px)}
.media-picker__item.selected{border-color:var(--a-accent);box-shadow:0 0 12px rgba(200,149,108,.3)}
.media-picker__item img{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;display:block}
.media-picker__item span{position:absolute;bottom:0;left:0;right:0;padding:4px 6px;background:linear-gradient(transparent,rgba(0,0,0,.8));font-size:.55rem;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.media-picker__check{position:absolute;top:6px;right:6px;width:22px;height:22px;border-radius:50%;background:rgba(0,0,0,.5);border:2px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;font-size:.6rem;color:transparent;transition:all .2s}
.media-picker__check.checked{background:var(--a-accent,#c8956c);border-color:var(--a-accent,#c8956c);color:#fff}
</style>

<script>
window.PRODUCTS_API = '<?= SITE_URL ?>/api/admin-products.php';
window.MEDIA_API = '<?= SITE_URL ?>/api/media.php';
window.BASE_URL = '<?= SITE_URL ?>';
window.CURRENCY = '<?= h($currency) ?>';
</script>
<script src="<?= SITE_URL ?>/admin/assets/js/products.js?v=<?= filemtime(__DIR__ . '/assets/js/products.js') ?>"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
