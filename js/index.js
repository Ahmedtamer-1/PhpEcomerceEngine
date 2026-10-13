/**
 * Homepage Dynamic Renderer
 */

document.addEventListener('DOMContentLoaded', async () => {
    const container = document.getElementById('homepage-sections-container');
    if (!container) return;

    // Fetch theme data (already cached in LumeAPI if loaded)
    const themeData = await LumeAPI.fetchTheme();
    if (!themeData || !themeData.homepage_sections) return;

    let html = '';
    
    themeData.homepage_sections.forEach(section => {
        let sett = {};
        try { sett = JSON.parse(section.settings || '{}'); } catch(e){}
        
        let padStyle = '';
        if (sett.padding_top) padStyle += `padding-top:${sett.padding_top}px;`;
        if (sett.padding_bottom) padStyle += `padding-bottom:${sett.padding_bottom}px;`;

        if (section.section_type === 'hero') {
            const bgImage = section.image || sett.bg_image || 'assets/images/placeholder.jpg';
            const textColor = sett.text_color || '#ffffff';
            const btnColor = sett.button_color || '#ffffff';
            
            html += `
            <section class="lume-hero" style="color: ${textColor}">
                <div class="lume-hero__bg" style="background-image:url('/${bgImage}')"></div>
                <div class="lume-hero__overlay" style="opacity: ${sett.overlay || '0.5'};"></div>
                <div class="lume-hero__content">
                    <h1 class="lume-hero__title" style="color: ${textColor}">${section.title || ''}</h1>
                    <p class="lume-hero__subtitle" style="color: ${textColor}">${section.subtitle || ''}</p>
                    ${section.button_text ? `<a href="/${section.button_url || 'shop'}" class="lume-hero__cta" style="--btn-color: ${btnColor}; border-color: var(--btn-color); color: var(--btn-color);">${section.button_text}</a>` : ''}
                </div>
            </section>
            `;
        }
        else if (section.section_type === 'image_banner') {
            const bgImage = section.image || sett.bg_image || 'assets/images/placeholder.jpg';
            html += `
            <section class="lume-section" style="padding:0; ${padStyle}">
                <div style="position:relative;aspect-ratio:21/9;overflow:hidden;background:#111">
                    <img src="/${bgImage}" loading="lazy" style="width:100%;height:100%;object-fit:cover;opacity:.7">
                    <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:24px">
                        <h2 style="font-family:var(--font-serif);font-size:clamp(2rem,5vw,4rem);text-transform:uppercase;letter-spacing:.04em;margin-bottom:12px;color:#fff">${section.title || ''}</h2>
                        ${section.subtitle ? `<p style="font-size:.85rem;color:var(--gold);letter-spacing:.15em;text-transform:uppercase;margin-bottom:24px">${section.subtitle}</p>` : ''}
                        ${section.button_text ? `<a href="/${section.button_url}" class="lume-btn">${section.button_text}</a>` : ''}
                    </div>
                </div>
            </section>
            `;
        }
        else if (section.section_type === 'brand_story') {
            const img = section.image || sett.image || 'assets/images/placeholder.jpg';
            const paras = (section.content || '').split('\n\n').map(p => `<p>${p}</p>`).join('');
            html += `
            <section class="lume-section container" style="${padStyle}">
                <div class="lume-about-intro">
                    <div class="lume-about-intro__img">
                        <img src="/${img}" loading="lazy">
                    </div>
                    <div class="lume-about-intro__text">
                        <p class="lume-section__eyebrow">${sett.eyebrow || 'Our Story'}</p>
                        <h2>${section.title || 'Our Story'}</h2>
                        ${paras}
                        ${section.button_text ? `<a href="/${section.button_url}" class="lume-btn" style="margin-top:24px">${section.button_text}</a>` : ''}
                    </div>
                </div>
            </section>
            `;
        }
        // Fallback for featured_products, etc. would require fetching products.
        // For simplicity, we just add a placeholder if it's featured products.
        else if (section.section_type === 'featured_products') {
            html += `
            <section class="lume-section lume-section--center container" style="${padStyle}">
                <p class="lume-section__eyebrow">${sett.eyebrow || 'Curated for You'}</p>
                <h2 class="lume-section__title">${section.title || 'Featured Products'}</h2>
                <div class="lume-divider" style="margin-bottom:16px"></div>
                <div class="lume-products" id="featured-products-grid">
                    <!-- Products loaded via JS -->
                </div>
                ${section.button_text ? `<div style="text-align:center;margin-top:48px"><a href="/${section.button_url}" class="lume-btn">${section.button_text}</a></div>` : ''}
            </section>
            `;
        }
    });

    container.innerHTML = html;
    
    // If there is a featured products grid, populate it
    const featuredGrid = document.getElementById('featured-products-grid');
    if (featuredGrid) {
        const products = await LumeAPI.getProducts();
        const top = products.slice(0, 4);
        featuredGrid.innerHTML = top.map(p => `
            <div class="lume-product-card">
                <a href="/product.php?slug=${p.slug}" class="lume-product-card__img-wrap">
                    <img src="/${p.image || 'assets/images/placeholder.jpg'}" alt="${p.name}" class="lume-product-card__img" loading="lazy">
                </a>
                <div class="lume-product-card__body">
                    <p class="lume-product-card__cat">${p.category_name || ''}</p>
                    <h3 class="lume-product-card__name"><a href="/product.php?slug=${p.slug}">${p.name}</a></h3>
                    <div class="lume-product-card__price">EGP ${parseFloat(p.price).toFixed(2)}</div>
                </div>
            </div>
        `).join('');
    }
});
