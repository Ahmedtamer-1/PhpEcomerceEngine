/**
 * Headless Shop Logic
 */

document.addEventListener('DOMContentLoaded', async () => {
    const grid = document.getElementById('shop-grid-container');
    const section = document.querySelector('.lume-section.container');
    if (!grid || !section) return;

    // Parse URL for initial category
    const urlParams = new URLSearchParams(window.location.search);
    let currentCategory = urlParams.get('category');

    // Create Filters Container
    const filtersDiv = document.createElement('div');
    filtersDiv.className = 'lume-filters lume-reveal';
    filtersDiv.style.opacity = '1';
    filtersDiv.style.transform = 'none';
    section.insertBefore(filtersDiv, grid);

    // Fetch Categories
    const categories = await LumeAPI.getCategories();
    
    // Render Filters
    const renderFilters = () => {
        let html = `<button class="lume-filter-btn ${!currentCategory ? 'active' : ''}" data-cat="">All</button>`;
        categories.forEach(c => {
            html += `<button class="lume-filter-btn ${currentCategory === c.id.toString() ? 'active' : ''}" data-cat="${c.id}">${c.name}</button>`;
        });
        filtersDiv.innerHTML = html;

        // Attach click events
        filtersDiv.querySelectorAll('.lume-filter-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                currentCategory = e.target.getAttribute('data-cat') || null;
                // Update URL without reload
                const newUrl = currentCategory ? `/shop.html?category=${currentCategory}` : '/shop.html';
                window.history.pushState({path: newUrl}, '', newUrl);
                
                renderFilters();
                loadProducts();
            });
        });
    };
    renderFilters();

    // Load Products
    const loadProducts = async () => {
        // Show skeleton loaders
        grid.innerHTML = Array(8).fill(`
            <div class="lume-product-card">
                <div style="background:var(--border); aspect-ratio:3/4; animation:pulse 1.5s infinite"></div>
                <div style="padding:16px">
                    <div style="background:var(--border); height:12px; width:40%; margin-bottom:8px"></div>
                    <div style="background:var(--border); height:16px; width:80%; margin-bottom:8px"></div>
                    <div style="background:var(--border); height:14px; width:30%"></div>
                </div>
            </div>
        `).join('');

        const products = await LumeAPI.getProducts(currentCategory);

        if (products.length === 0) {
            grid.innerHTML = '<p style="text-align:center;width:100%;grid-column:1/-1;">No products found.</p>';
            return;
        }

        // Render products
        grid.innerHTML = products.map(p => `
            <div class="lume-product-card lume-reveal" style="opacity:1; transform:none">
                <a href="/product.php?slug=${p.slug}" class="lume-product-card__img-wrap">
                    <img src="${p.display_image || '/assets/images/placeholder.jpg'}" alt="${p.name}" class="lume-product-card__img" loading="lazy">
                    ${p.sale_price ? `<span class="lume-product-card__badge">Sale</span>` : ''}
                </a>
                <div class="lume-product-card__body">
                    <p class="lume-product-card__cat">${p.category_name || ''}</p>
                    <h3 class="lume-product-card__name"><a href="/product.php?slug=${p.slug}">${p.name}</a></h3>
                    <div class="lume-product-card__price">
                        ${p.sale_price 
                            ? `<del>EGP ${parseFloat(p.price).toFixed(2)}</del> <ins>EGP ${parseFloat(p.sale_price).toFixed(2)}</ins>` 
                            : `EGP ${parseFloat(p.price).toFixed(2)}`}
                    </div>
                    <div class="lume-product-card__actions">
                        <button class="btn-add-cart" onclick="LumeAPI.addToCart(${p.id})">Add to Bag</button>
                    </div>
                </div>
            </div>
        `).join('');
    };

    // Initial load
    loadProducts();
});
