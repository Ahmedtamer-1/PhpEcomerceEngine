/**
 * Lume API Client
 * Centralized API logic for headless frontend
 */

const LumeAPI = {
    baseUrl: '/api/v1',
    settings: null,
    cssInjected: false,

    async fetchTheme() {
        if (this.settings) return this.settings;
        try {
            const res = await fetch(`${this.baseUrl}/theme`);
            const json = await res.json();
            if (json.status === 200) {
                this.settings = json.data.settings;
                
                // Cache CSS for fast load (prevents FOUC)
                if (json.data.css) {
                    localStorage.setItem('lume_theme_css', json.data.css);
                }

                // Inject custom CSS if not already injected by head script
                if (json.data.css && !this.cssInjected) {
                    let style = document.getElementById('lume-dynamic-theme');
                    if (!style) {
                        style = document.createElement('style');
                        style.id = 'lume-dynamic-theme';
                        document.head.appendChild(style);
                    }
                    if (style.innerHTML !== json.data.css) {
                        style.innerHTML = json.data.css;
                    }
                    this.cssInjected = true;
                }

                // Apply CSS variables dynamically ONLY IF no active theme CSS is found
                if (!json.data.css) {
                    const s = this.settings;
                    const root = document.documentElement;
                    if (s.theme_color_bg) {
                        root.style.setProperty('--bg', s.theme_color_bg);
                        root.style.setProperty('--bg-card', s.theme_color_bg_card || s.theme_color_bg);
                    }
                    if (s.theme_color_cream) root.style.setProperty('--cream', s.theme_color_cream);
                    if (s.theme_color_gold) root.style.setProperty('--gold', s.theme_color_gold);
                    if (s.theme_color_accent) root.style.setProperty('--terracotta', s.theme_color_accent);
                    if (s.theme_color_muted) root.style.setProperty('--muted', s.theme_color_muted);
                    if (s.theme_font_heading) root.style.setProperty('--font-serif', `"${s.theme_font_heading}", serif`);
                    if (s.theme_font_body) root.style.setProperty('--font-sans', `"${s.theme_font_body}", sans-serif`);
                }

                // Update text elements marked with data-setting
                const getImageUrl = (path) => path.startsWith('/') || path.startsWith('http') ? path : '/' + path;
                const s = this.settings;
                document.querySelectorAll('[data-setting]').forEach(el => {
                    const key = el.getAttribute('data-setting');
                    
                    if (key === 'site_name' && s.site_logo && el.classList.contains('lume-logo-text')) {
                        // Replace text span with Image Logo
                        const img = document.createElement('img');
                        img.src = getImageUrl(s.site_logo);
                        img.alt = s.site_name || 'Logo';
                        img.style.maxHeight = '40px';
                        img.style.width = 'auto';
                        img.setAttribute('data-setting', 'site_name'); // Keep it tagged
                        el.replaceWith(img);
                    } else if (s[key]) {
                        if (el.tagName === 'IMG') {
                            el.src = getImageUrl(s[key]);
                        } else {
                            el.textContent = s[key];
                        }
                    }
                });
                
                return json.data;
            }
        } catch (e) {
            console.error('Failed to fetch theme:', e);
        }
        return null;
    },

    async fetchAuthStatus() {
        try {
            const res = await fetch(`${this.baseUrl}/auth`);
            const json = await res.json();
            if (json.status === 200) {
                const data = json.data;
                const dropdown = document.getElementById('lume-account-dropdown');
                if (!dropdown) return;
                
                let html = '';
                if (data.logged_in) {
                    html += `<span class="lume-dropdown-label">Hi, ${data.first_name || 'User'}</span>`;
                    if (data.role === 'admin') {
                        html += `<a href="/admin/" role="menuitem">Admin Panel</a>`;
                    }
                    html += `<a href="/account.php" role="menuitem">My Account</a>`;
                    html += `<a href="/account.php?action=orders" role="menuitem">My Orders</a>`;
                    html += `<a href="/account.php?action=logout" role="menuitem">Logout</a>`;
                } else {
                    html += `<a href="/account.php" role="menuitem">Login / Register</a>`;
                }
                dropdown.innerHTML = html;
            }
        } catch (e) {
            console.error('Failed to fetch auth status:', e);
        }
    },

    async getCategories() {
        try {
            const res = await fetch(`${this.baseUrl}/categories`);
            const json = await res.json();
            return json.status === 200 ? json.data : [];
        } catch (e) {
            console.error(e);
            return [];
        }
    },

    async getProducts(categoryId = null) {
        try {
            const url = categoryId ? `${this.baseUrl}/products?category=${categoryId}` : `${this.baseUrl}/products`;
            const res = await fetch(url);
            const json = await res.json();
            return json.status === 200 ? json.data : [];
        } catch (e) {
            console.error(e);
            return [];
        }
    },

    async addToCart(productId, qty = 1) {
        try {
            const res = await fetch(`${this.baseUrl}/cart`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'add', product_id: productId, quantity: qty })
            });
            const json = await res.json();
            if (json.status === 200) {
                // Update cart badge
                const badge = document.getElementById('cart-count-badge');
                if (badge) {
                    badge.textContent = json.cart_count;
                    badge.style.display = json.cart_count > 0 ? 'inline-block' : 'none';
                }
                alert('Added to cart!');
            } else {
                alert(json.error || 'Failed to add to cart');
            }
        } catch (e) {
            alert('Network error');
        }
    }
};

// Initialize theme on load
document.addEventListener('DOMContentLoaded', () => {
    LumeAPI.fetchTheme();
    LumeAPI.fetchAuthStatus();
});
