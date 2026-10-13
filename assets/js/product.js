/**
 * Headless Product Page Logic
 */

document.addEventListener('DOMContentLoaded', async () => {
    const container = document.getElementById('product-single-container');
    const recSection = document.getElementById('recommendations-section');
    const recContainer = document.getElementById('recommendations-container');
    const breadcrumbName = document.getElementById('breadcrumb-product-name');
    
    if (!container) return;

    const urlParams = new URLSearchParams(window.location.search);
    const slug = urlParams.get('slug');

    if (!slug) {
        showNotFound();
        return;
    }

    // Skeleton loaders
    container.innerHTML = `
        <div class="lume-gallery" style="opacity: 0.5; pointer-events: none;">
            <div style="background:var(--border); aspect-ratio:3/4; width:100%; animation:pulse 1.5s infinite"></div>
        </div>
        <div class="lume-product-single__info" style="opacity: 0.5;">
            <div style="background:var(--border); height:24px; width:40%; margin-bottom:16px; animation:pulse 1.5s infinite"></div>
            <div style="background:var(--border); height:32px; width:80%; margin-bottom:24px; animation:pulse 1.5s infinite"></div>
            <div style="background:var(--border); height:20px; width:30%; margin-bottom:32px; animation:pulse 1.5s infinite"></div>
            <div style="background:var(--border); height:100px; width:100%; animation:pulse 1.5s infinite"></div>
        </div>
    `;

    try {
        const res = await fetch(`/api/v1/products?slug=${encodeURIComponent(slug)}`);
        const json = await res.json();

        if (json.status === 200 && json.data && json.data.product) {
            renderProduct(json.data);
            if (json.data.recommendations && json.data.recommendations.length > 0) {
                renderRecommendations(json.data.recommendations);
            }
        } else {
            showNotFound();
        }
    } catch (e) {
        console.error("Failed to load product", e);
        showNotFound();
    }

    function showNotFound() {
        container.innerHTML = `<div style="text-align:center; width:100%; padding: 100px 0;"><h1>Product Not Found</h1><p style="color:var(--muted);margin-top:16px">The product you're looking for doesn't exist.</p><a href="/shop.html" class="lume-btn" style="margin-top:32px">Back to Shop</a></div>`;
        if (breadcrumbName) breadcrumbName.textContent = 'Not Found';
    }

    function renderProduct(data) {
        const p = data.product;
        const variants = data.variants || [];
        const colorGalleries = data.color_galleries || [];
        const hasVariants = p.has_variants == 1;
        const showStock = LumeAPI.settings?.show_stock_indicator == '1' || true;
        const lowThreshold = parseInt(LumeAPI.settings?.stock_low_threshold || '5');
        const currency = window.__lumeSymbol || 'EGP ';

        // SEO Meta
        document.title = (p.meta_title || p.name) + ' — ' + (LumeAPI.settings?.site_name || 'Loading...');
        const metaDesc = document.querySelector('meta[name="description"]');
        if (metaDesc) metaDesc.content = p.meta_desc || p.description?.replace(/<[^>]*>?/gm, '').substring(0, 160) || '';

        if (breadcrumbName) breadcrumbName.textContent = p.name;

        let totalVariantStock = 0;
        let sizes = [];
        let colors = {};
        let colorImages = {};

        variants.forEach(v => {
            totalVariantStock += parseInt(v.stock);
            if (v.size && !sizes.includes(v.size)) sizes.push(v.size);
            if (v.color_name) {
                if (!colors[v.color_name]) colors[v.color_name] = v.color_hex || '#888';
                if (v.image && !colorImages[v.color_name]) colorImages[v.color_name] = v.image;
            }
        });

        const inStock = hasVariants ? totalVariantStock > 0 : parseInt(p.stock) > 0;
        const stockDisplay = hasVariants ? totalVariantStock : parseInt(p.stock);
        
        let colorImageArrays = {};
        if (Object.keys(colorGalleries).length > 0) {
            for (const [cName, imgs] of Object.entries(colorGalleries)) {
                if (Array.isArray(imgs) && imgs.length > 0) {
                    colorImageArrays[cName] = imgs;
                }
            }
        }
        variants.forEach(v => {
            if (v.image && v.color_name) {
                if (!colorImageArrays[v.color_name]) colorImageArrays[v.color_name] = [];
                if (!colorImageArrays[v.color_name].includes(v.image)) colorImageArrays[v.color_name].push(v.image);
            }
        });

        const firstColor = Object.keys(colors).length > 0 ? Object.keys(colors)[0] : null;
        let generalImages = [];
        if (firstColor && colorImageArrays[firstColor]) {
            generalImages = colorImageArrays[firstColor];
        } else {
            generalImages.push(p.display_image || '/assets/images/placeholder.jpg');
            let extra = [];
            try { extra = JSON.parse(p.gallery || '[]'); } catch (e) {}
            if (Array.isArray(extra)) {
                extra.forEach(img => { if (img && !generalImages.includes(img)) generalImages.push(img); });
            }
        }

        const activeImages = (firstColor && colorImageArrays[firstColor]) ? colorImageArrays[firstColor] : generalImages;

        // Build HTML
        let html = `
            <div class="lume-gallery lume-reveal-left visible" style="opacity:1; transform:none;">
                <div class="lume-gallery__stage">
                    <button class="lume-gallery__arrow lume-gallery__arrow--prev" id="gallery-prev" aria-label="Previous image" style="${activeImages.length <= 1 ? 'display:none;' : ''}">&#8249;</button>
                    <div class="lume-gallery__main-wrap" id="gallery-main-wrap">
                        <img class="lume-gallery__main" id="gallery-main-img" src="${getImageUrl(activeImages[0])}" alt="${p.name}">
                        <div class="lume-gallery__zoom-hint" id="zoom-hint">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                        </div>
                    </div>
                    <button class="lume-gallery__arrow lume-gallery__arrow--next" id="gallery-next" aria-label="Next image" style="${activeImages.length <= 1 ? 'display:none;' : ''}">&#8250;</button>
                </div>
                <div class="lume-gallery__thumbs" id="gallery-thumbs">
                    ${activeImages.map((img, idx) => `
                        <button class="lume-gallery__thumb ${idx === 0 ? 'active' : ''}" data-index="${idx}" aria-label="View image ${idx + 1}">
                            <img src="${getImageUrl(img)}" alt="" loading="lazy">
                        </button>
                    `).join('')}
                </div>
            </div>

            <div class="lume-product-single__info lume-reveal-right visible" style="opacity:1; transform:none;">
                ${p.category_name ? `<p class="lume-product-single__cat">${p.category_name}</p>` : ''}
                <h1>${p.name}</h1>
                <div class="lume-product-single__price" id="product-price-display">
                    ${p.sale_price ? `<del>${currency}${parseFloat(p.price).toFixed(2)}</del> <ins>${currency}${parseFloat(p.sale_price).toFixed(2)}</ins>` : `<span class="price">${currency}${parseFloat(p.price).toFixed(2)}</span>`}
                </div>
                <div class="lume-product-single__desc">${(p.description || '').replace(/\n/g, '<br>')}</div>

                ${hasVariants && Object.keys(colors).length > 0 ? `
                <div class="lume-variant-section">
                    <label class="lume-variant-section__label">Color: <span id="selected-color-label">${firstColor || 'Select a color'}</span></label>
                    <div class="lume-color-options" id="color-options">
                        ${Object.entries(colors).map(([name, hex]) => `
                            <button type="button" class="lume-color-swatch ${name === firstColor ? 'is-active' : ''}" 
                                    data-color="${name}" data-hex="${hex}" aria-label="${name}" title="${name}">
                                <span class="lume-color-swatch__inner" style="${colorImages[name] ? `background-image:url(${getImageUrl(colorImages[name])});background-size:cover;background-position:center` : `background:${hex}`}"></span>
                                <span class="lume-color-swatch__tooltip">${name}</span>
                            </button>
                        `).join('')}
                    </div>
                </div>` : ''}

                ${hasVariants && sizes.length > 0 ? `
                <div class="lume-variant-section">
                    <label class="lume-variant-section__label">Size: <span id="selected-size-label">Select a size</span></label>
                    <div class="lume-size-swatches" id="size-swatches">
                        ${sizes.map(sz => `
                            <button type="button" class="lume-size-swatch" data-size="${sz}" title="${sz}">${sz}</button>
                        `).join('')}
                    </div>
                </div>` : ''}

                ${p.size_chart ? `
                <button type="button" class="lume-sizechart-btn" id="btn-size-chart">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 3H3v18h18V3z"/><path d="M7 3v4M12 3v6M17 3v4"/></svg>
                    Size Chart
                </button>` : ''}

                ${hasVariants ? `<div class="lume-variant-msg" id="variant-msg">Please select your options above</div>` : ''}

                ${inStock ? `
                <div class="lume-product-single__qty">
                    <button class="lume-qty-btn" data-dir="minus">&#8722;</button>
                    <span class="lume-qty-val" id="qty-val" data-max="${stockDisplay}">1</span>
                    <button class="lume-qty-btn" data-dir="plus">+</button>
                </div>
                <button class="lume-btn lume-btn--full lume-btn--solid" id="btn-add-single" ${hasVariants ? 'disabled style="opacity:.4;cursor:not-allowed"' : ''}>
                    Add to Bag
                </button>
                <p class="lume-product-single__stock in-stock" id="stock-info" style="${showStock ? '' : 'display:none'}">
                    ${!hasVariants ? (stockDisplay <= lowThreshold && stockDisplay > 0 ? `&#9888; Low stock — only ${stockDisplay} left` : `&#10003; In stock (${stockDisplay} available)`) : ''}
                </p>
                ` : `
                <button class="lume-btn lume-btn--full" disabled style="opacity:.4;cursor:not-allowed">Sold Out</button>
                <p class="lume-product-single__stock out-of-stock">&#10005; Currently out of stock</p>
                `}
                
                <p class="lume-product-single__sku" id="variant-sku" style="display:none"></p>
                ${p.sku && !hasVariants ? `<p class="lume-product-single__sku">SKU: ${p.sku}</p>` : ''}

                ${p.material || p.gem ? `
                <div class="lume-product-single__details">
                    ${p.material ? `<div class="lume-product-detail"><span class="lume-product-detail__label">Material</span><span class="lume-product-detail__value">${p.material}</span></div>` : ''}
                    ${p.gem ? `<div class="lume-product-detail"><span class="lume-product-detail__label">Gem / Stone</span><span class="lume-product-detail__value">${p.gem}</span></div>` : ''}
                </div>
                ` : ''}
            </div>
        `;

        container.innerHTML = html;

        // Size chart modal init
        if (p.size_chart) {
            const scModal = document.getElementById('sizechart-modal');
            const scBody = document.getElementById('sizechart-body');
            const scBtn = document.getElementById('btn-size-chart');
            const scClose = document.getElementById('sizechart-close');
            const scBackdrop = document.getElementById('sizechart-backdrop');
            
            if (scBody) scBody.innerHTML = `<img src="${getImageUrl(p.size_chart)}" alt="Size Chart for ${p.name}">`;
            
            function openSC(){ scModal.classList.add('active'); document.body.style.overflow='hidden'; }
            function closeSC(){ scModal.classList.remove('active'); document.body.style.overflow=''; }
            if (scBtn) scBtn.addEventListener('click', openSC);
            if (scClose) scClose.addEventListener('click', closeSC);
            if (scBackdrop) scBackdrop.addEventListener('click', closeSC);
        }

        // Initialize Product JS Logic
        initProductLogic({
            product: p,
            variants: variants,
            generalImages: generalImages,
            colorImageArrays: colorImageArrays,
            firstColor: firstColor,
            showStock: showStock,
            lowThreshold: lowThreshold,
            currency: currency
        });
    }

    function renderRecommendations(recs) {
        recSection.style.display = 'block';
        const currency = window.__lumeSymbol || 'EGP ';
        recContainer.innerHTML = recs.map(r => `
            <div class="lume-product-card lume-reveal visible" style="opacity:1; transform:none">
                <a href="/product.html?slug=${r.slug}" class="lume-product-card__img-wrap">
                    <img src="${r.display_image || '/assets/images/placeholder.jpg'}" alt="${r.name}" class="lume-product-card__img" loading="lazy">
                    ${r.sale_price ? `<span class="lume-product-card__badge">Sale</span>` : ''}
                </a>
                <div class="lume-product-card__body">
                    <h3 class="lume-product-card__name"><a href="/product.html?slug=${r.slug}">${r.name}</a></h3>
                    <div class="lume-product-card__price">
                        ${r.sale_price 
                            ? `<del>${currency}${parseFloat(r.price).toFixed(2)}</del> <ins>${currency}${parseFloat(r.sale_price).toFixed(2)}</ins>` 
                            : `${currency}${parseFloat(r.price).toFixed(2)}`}
                    </div>
                </div>
            </div>
        `).join('');
    }

    function getImageUrl(path) {
        if (!path) return '';
        return path.startsWith('http') || path.startsWith('/') ? path : '/' + path;
    }

    // Extracted logic for variants and galleries
    function initProductLogic(config) {
        // ── Gallery ──
        const mainImg   = document.getElementById('gallery-main-img');
        const mainWrap  = document.getElementById('gallery-main-wrap');
        const thumbWrap = document.getElementById('gallery-thumbs');
        const prevBtn   = document.getElementById('gallery-prev');
        const nextBtn   = document.getElementById('gallery-next');

        let currentImages = [];
        let currentIndex = 0;

        function setGallery(images) {
            if(!images || !images.length) images = config.generalImages || [];
            currentImages = images;
            currentIndex = 0;
            renderThumbs();
            showImage(0);
            if(prevBtn) prevBtn.style.display = images.length > 1 ? '' : 'none';
            if(nextBtn) nextBtn.style.display = images.length > 1 ? '' : 'none';
        }

        function renderThumbs() {
            if(!thumbWrap) return;
            if(currentImages.length <= 1) { thumbWrap.innerHTML = ''; return; }
            thumbWrap.innerHTML = currentImages.map((src, i) =>
                `<button class="lume-gallery__thumb ${i===0?'active':''}" data-index="${i}" aria-label="View image ${i+1}">
                    <img src="${getImageUrl(src)}" alt="" loading="lazy">
                </button>`
            ).join('');
            thumbWrap.querySelectorAll('.lume-gallery__thumb').forEach(t => {
                t.addEventListener('click', () => showImage(parseInt(t.dataset.index)));
            });
        }

        function showImage(idx) {
            if(idx < 0) idx = currentImages.length - 1;
            if(idx >= currentImages.length) idx = 0;
            currentIndex = idx;
            if(mainImg) {
                mainImg.style.opacity = '0';
                setTimeout(() => {
                    mainImg.src = getImageUrl(currentImages[idx]);
                    mainImg.style.opacity = '1';
                }, 150);
            }
            if(thumbWrap) {
                thumbWrap.querySelectorAll('.lume-gallery__thumb').forEach((t, i) =>
                    t.classList.toggle('active', i === idx)
                );
            }
        }

        function filterByColor(colorName) {
            if(colorName && config.colorImageArrays && config.colorImageArrays[colorName] && config.colorImageArrays[colorName].length) {
                setGallery(config.colorImageArrays[colorName]);
            } else {
                setGallery(config.generalImages);
            }
        }

        if(prevBtn) prevBtn.addEventListener('click', () => showImage(currentIndex - 1));
        if(nextBtn) nextBtn.addEventListener('click', () => showImage(currentIndex + 1));

        // Lightbox
        const lightbox  = document.getElementById('lume-lightbox');
        const lbImg     = document.getElementById('lightbox-img');
        const lbClose   = document.getElementById('lightbox-close');
        const lbPrev    = document.getElementById('lightbox-prev');
        const lbNext    = document.getElementById('lightbox-next');
        const lbCounter = document.getElementById('lightbox-counter');

        function openLightbox() {
            if(!lightbox || !currentImages.length) return;
            lbImg.src = getImageUrl(currentImages[currentIndex]);
            if(lbCounter) lbCounter.textContent = (currentIndex+1) + ' / ' + currentImages.length;
            lightbox.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closeLightbox() { if(!lightbox) return; lightbox.classList.remove('active'); document.body.style.overflow = ''; }
        function lbGo(dir) {
            showImage(currentIndex + dir);
            lbImg.src = getImageUrl(currentImages[currentIndex]);
            if(lbCounter) lbCounter.textContent = (currentIndex+1) + ' / ' + currentImages.length;
        }

        if(mainWrap) mainWrap.addEventListener('click', e => { if(!e.target.closest('.lume-gallery__arrow')) openLightbox(); });
        if(lbClose) lbClose.addEventListener('click', closeLightbox);
        if(lbPrev) lbPrev.addEventListener('click', () => lbGo(-1));
        if(lbNext) lbNext.addEventListener('click', () => lbGo(1));

        if(config.firstColor && config.colorImageArrays && config.colorImageArrays[config.firstColor]) {
            setGallery(config.colorImageArrays[config.firstColor]);
        } else {
            setGallery(config.generalImages);
        }

        // ── Variants ──
        const sizeSwatches = document.querySelectorAll('.lume-size-swatch');
        const colorSwatches = document.querySelectorAll('.lume-color-swatch');
        const btnAdd = document.getElementById('btn-add-single');
        const variantMsg = document.getElementById('variant-msg');
        const stockInfo = document.getElementById('stock-info');
        const skuInfo = document.getElementById('variant-sku');
        const priceEl = document.getElementById('product-price-display');
        const defaultPriceHtml = priceEl ? priceEl.innerHTML : '';
        
        const selected = { size: null, color: config.firstColor || null };

        function formatPrice(amount) {
            return '<span class="price">' + config.currency + parseFloat(amount).toFixed(2) + '</span>';
        }

        function stockHtml(stock) {
            if(!config.showStock) return '';
            if(stock <= 0) return '&#10005; Out of stock';
            if(stock <= config.lowThreshold) return '&#9888; Low stock — only ' + stock + ' left';
            return '&#10003; In stock (' + stock + ' available)';
        }

        function updateVariants() {
            const hasVariants = config.product.has_variants == 1;
            if (!hasVariants) {
                if(btnAdd) {
                    btnAdd.onclick = () => {
                        const qty = parseInt(document.getElementById('qty-val')?.textContent || '1');
                        if (window.addToCart) {
                            window.addToCart(config.product.id, qty);
                        } else {
                            LumeAPI.addToCart(config.product.id, qty);
                        }
                    };
                }
                return;
            }

            const needsSize  = sizeSwatches.length > 0;
            const needsColor = colorSwatches.length > 0;

            if(needsSize && selected.color) {
                sizeSwatches.forEach(opt => {
                    const sz = opt.dataset.size;
                    const v = config.variants.find(v => v.color_name === selected.color && v.size === sz);
                    opt.classList.toggle('is-disabled', !v || v.stock <= 0);
                });
            } else if(needsSize) {
                sizeSwatches.forEach(opt => opt.classList.remove('is-disabled'));
            }

            if(selected.size && needsColor) {
                colorSwatches.forEach(s => {
                    const v = config.variants.find(v => v.size === selected.size && v.color_name === s.dataset.color);
                    s.classList.toggle('is-disabled', !v || v.stock <= 0);
                });
            } else {
                colorSwatches.forEach(s => s.classList.remove('is-disabled'));
            }

            let matched = null;
            if((!needsSize || selected.size) && (!needsColor || selected.color)) {
                matched = config.variants.find(v =>
                    (!needsSize  || v.size       === selected.size) &&
                    (!needsColor || v.color_name === selected.color)
                );
            }

            if(matched) {
                if(variantMsg) variantMsg.style.display = 'none';
                if(priceEl) priceEl.innerHTML = matched.price !== null ? formatPrice(matched.price) : defaultPriceHtml;

                if(matched.stock > 0) {
                    btnAdd.disabled = false;
                    btnAdd.style.opacity = '1';
                    btnAdd.style.cursor  = 'pointer';
                    btnAdd.textContent   = 'Add to Bag';
                    const qtyVal = document.getElementById('qty-val');
                    if(qtyVal) {
                        qtyVal.dataset.max = matched.stock;
                        let currentN = parseInt(qtyVal.textContent) || 1;
                        if (currentN > matched.stock) qtyVal.textContent = Math.max(1, matched.stock);
                    }
                    btnAdd.onclick = () => {
                        const qty = parseInt(document.getElementById('qty-val')?.textContent || '1');
                        if (window.addToCart) {
                            window.addToCart(config.product.id, qty, matched.id);
                        } else {
                            // Fallback to LumeAPI
                            fetch('/api/v1/cart', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ action: 'add', product_id: config.product.id, quantity: qty, variant_id: matched.id })
                            }).then(r => r.json()).then(json => {
                                if(json.status===200){alert('Added to bag');}
                            });
                        }
                    };
                } else {
                    btnAdd.disabled      = true;
                    btnAdd.style.opacity = '.4';
                    btnAdd.style.cursor  = 'not-allowed';
                    btnAdd.textContent   = 'Sold Out';
                }

                if(stockInfo) {
                    stockInfo.innerHTML = stockHtml(matched.stock);
                    stockInfo.className = 'lume-product-single__stock ' + (matched.stock > 0 ? 'in-stock' : 'out-of-stock');
                    stockInfo.style.display = config.showStock ? '' : 'none';
                }
                if(skuInfo) {
                    if(matched.sku) { skuInfo.style.display='block'; skuInfo.textContent='SKU: '+matched.sku; }
                    else skuInfo.style.display = 'none';
                }
            } else {
                if(needsSize && !selected.size) {
                    btnAdd.disabled      = true;
                    btnAdd.style.opacity = '.4';
                    btnAdd.style.cursor  = 'not-allowed';
                    btnAdd.textContent   = 'Add to Bag';
                    if(variantMsg) { variantMsg.style.display = ''; variantMsg.textContent = 'Please select a size'; }
                } else {
                    btnAdd.disabled      = true;
                    btnAdd.style.opacity = '.4';
                    btnAdd.style.cursor  = 'not-allowed';
                    btnAdd.textContent   = 'Add to Bag';
                    if(variantMsg) { variantMsg.style.display = ''; variantMsg.textContent = 'Please select your options above'; }
                }
                if(stockInfo)  stockInfo.innerHTML = '';
                if(skuInfo)    skuInfo.style.display = 'none';
                if(priceEl)    priceEl.innerHTML = defaultPriceHtml;
            }
        }

        sizeSwatches.forEach(swatch => {
            swatch.addEventListener('click', () => {
                if(swatch.classList.contains('is-disabled')) return;
                if(swatch.classList.contains('is-active')) return;
                sizeSwatches.forEach(s => s.classList.remove('is-active'));
                swatch.classList.add('is-active');
                selected.size = swatch.dataset.size;
                const lbl = document.getElementById('selected-size-label');
                if(lbl) lbl.textContent = selected.size;
                updateVariants();
            });
        });

        colorSwatches.forEach(swatch => {
            swatch.addEventListener('click', () => {
                if(swatch.classList.contains('is-disabled')) return;
                if(swatch.classList.contains('is-active')) return;
                colorSwatches.forEach(s => s.classList.remove('is-active'));
                swatch.classList.add('is-active');
                selected.color = swatch.dataset.color;
                const lbl = document.getElementById('selected-color-label');
                if(lbl) lbl.textContent = selected.color;
                filterByColor(selected.color);
                updateVariants();
            });
        });

        updateVariants();
    }
});
