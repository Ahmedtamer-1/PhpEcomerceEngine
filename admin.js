/**
 * LUMEEGY Admin Panel — Global JavaScript
 * Handles: sidebar toggle, global search, toasts, keyboard shortcuts, scroll-to-top
 */
(function() {
    'use strict';

    // ═══════════════════════════════════════════════════
    // SIDEBAR TOGGLE
    // ═══════════════════════════════════════════════════
    const sidebar = document.querySelector('.admin-sidebar');
    const overlay = document.querySelector('.admin-sidebar-overlay');
    const hamburger = document.querySelector('.admin-hamburger');

    function openSidebar() {
        if (!sidebar) return;
        sidebar.classList.add('open');
        if (overlay) overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    if (hamburger) {
        hamburger.addEventListener('click', function(e) {
            e.stopPropagation();
            if (sidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }

    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    // Close sidebar on nav link click (mobile)
    document.querySelectorAll('.admin-nav-link').forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                closeSidebar();
            }
        });
    });

    // Close sidebar on resize if going desktop
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            closeSidebar();
        }
    });

    // ═══════════════════════════════════════════════════
    // NOTIFICATION DROPDOWN
    // ═══════════════════════════════════════════════════
    const notifBtn = document.getElementById('admin-notif-btn');
    const notifDropdown = document.getElementById('admin-notif-dropdown');

    if (notifBtn && notifDropdown) {
        notifBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            notifDropdown.classList.toggle('show');
        });

        // Close when clicking outside
        document.addEventListener('click', function(e) {
            if (!notifBtn.contains(e.target) && !notifDropdown.contains(e.target)) {
                notifDropdown.classList.remove('show');
            }
        });
    }

    // ═══════════════════════════════════════════════════
    // QUICK ACTIONS FAB TRAY
    // ═══════════════════════════════════════════════════
    const fabTray = document.getElementById('fab-tray');
    const fabTrigger = document.getElementById('fab-tray-trigger');

    if (fabTray && fabTrigger) {
        fabTrigger.addEventListener('click', function(e) {
            e.stopPropagation();
            fabTray.classList.toggle('open');
        });

        document.addEventListener('click', function(e) {
            if (!fabTray.contains(e.target)) {
                fabTray.classList.remove('open');
            }
        });
    }

    // ═══════════════════════════════════════════════════
    // GLOBAL SEARCH (Ctrl+K)
    // ═══════════════════════════════════════════════════
    const searchOverlay = document.getElementById('admin-search-overlay');
    const searchInput = document.getElementById('admin-global-search-input');
    const searchResults = document.getElementById('admin-search-results');
    let searchTimer = null;

    function openSearch() {
        if (!searchOverlay) return;
        searchOverlay.classList.add('active');
        if (searchInput) {
            searchInput.value = '';
            searchInput.focus();
        }
        if (searchResults) {
            renderResults(paletteCommands.slice(0, 6).map(([name, path]) => ({ group: 'Quick jump', type: 'Page', title: name, sub: '', url: baseUrl + path })), '');
        }
    }

    function closeSearch() {
        if (!searchOverlay) return;
        searchOverlay.classList.remove('active');
    }

    if (searchOverlay) {
        searchOverlay.addEventListener('click', function(e) {
            if (e.target === searchOverlay) closeSearch();
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const q = this.value.trim();
            clearTimeout(searchTimer);
            if (q.length < 2) {
                const cmds = q ? matchCommands(q) : [];
                if (cmds.length) renderResults(cmds, q);
                else if (searchResults) searchResults.innerHTML = '<div class="admin-search-modal__empty">Search products, orders, customers — or type a page name</div>';
                return;
            }
            searchTimer = setTimeout(() => performSearch(q), 180);
        });

        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeSearch();
            else if (e.key === 'ArrowDown') { e.preventDefault(); setActive(activeIndex + 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(activeIndex - 1); }
            else if (e.key === 'Enter') {
                const link = searchResults.querySelector('.admin-search-modal__result.is-active');
                if (link) { e.preventDefault(); window.location.href = link.href; }
            }
        });
    }

    // Pages and actions the palette can jump to by name.
    const baseUrl = window.BASE_URL || '';
    const paletteCommands = [
        ['Dashboard', '/admin/', 'dashboard home stats'],
        ['Products', '/admin/products.php', 'products catalog items inventory'],
        ['Add new product', '/admin/products.php?new=1', 'new product create add'],
        ['Orders', '/admin/orders.php', 'orders sales'],
        ['Pending orders', '/admin/orders.php?status_filter=pending', 'pending orders new'],
        ['Awaiting InstaPay payment', '/admin/orders.php?status_filter=pending_payment', 'instapay unpaid awaiting payment'],
        ['Create manual order', '/admin/order-new.php', 'new order manual create'],
        ['Categories', '/admin/categories.php', 'categories collections'],
        ['Shipping zones', '/admin/shipping.php', 'shipping zones delivery cities'],
        ['Page builder', '/admin/builder.php', 'builder pages homepage sections'],
        ['Policy & page text', '/admin/pages.php', 'privacy terms shipping returns exchange about contact text'],
        ['Media library', '/admin/media.php', 'media images uploads photos'],
        ['Messages', '/admin/messages.php', 'messages contact inbox'],
        ['Subscribers', '/admin/subscribers.php', 'subscribers newsletter emails'],
        ['Ad spend', '/admin/ad-spend.php', 'ads spend marketing facebook'],
        ['Users', '/admin/users.php', 'users customers accounts'],
        ['Settings', '/admin/settings.php', 'settings configuration payment instapay paymob email'],
        ['Theme manager', '/admin/themes.php', 'themes colors css'],
        ['Export / Import', '/admin/export.php', 'export import csv'],
        ['Activity log', '/admin/activity.php', 'activity log history'],
        ['View store', '/', 'site store storefront'],
    ];
    let activeIndex = -1;

    function renderResults(items, query) {
        if (!items.length) {
            searchResults.innerHTML = '<div class="admin-search-modal__empty">No results for “' + escHtml(query) + '”</div>';
            return;
        }
        let lastGroup = '';
        searchResults.innerHTML = items.map((r, i) => {
            const group = r.group !== lastGroup ? `<div class="admin-search-modal__group">${escHtml(r.group)}</div>` : '';
            lastGroup = r.group;
            const icon = r.image
                ? `<img src="${escHtml(r.image)}" alt="" loading="lazy">`
                : `<span>${escHtml((r.type || '?').charAt(0))}</span>`;
            return group + `
                <a href="${escHtml(r.url)}" class="admin-search-modal__result" data-index="${i}" style="--i:${i}">
                    <div class="admin-search-modal__result-icon">${icon}</div>
                    <div class="admin-search-modal__result-text">
                        <div class="admin-search-modal__result-title">${escHtml(r.title)}</div>
                        <div class="admin-search-modal__result-sub">${escHtml(r.sub || '')}</div>
                    </div>
                    <span class="admin-search-modal__result-type">${escHtml(r.type || '')}</span>
                </a>`;
        }).join('');
        setActive(0);
    }

    function setActive(i) {
        const links = searchResults.querySelectorAll('.admin-search-modal__result');
        if (!links.length) { activeIndex = -1; return; }
        activeIndex = (i + links.length) % links.length;
        links.forEach((l, n) => l.classList.toggle('is-active', n === activeIndex));
        links[activeIndex].scrollIntoView({ block: 'nearest' });
    }

    function matchCommands(query) {
        const q = query.toLowerCase();
        return paletteCommands
            .filter(([name, , keys]) => name.toLowerCase().includes(q) || keys.includes(q))
            .slice(0, 5)
            .map(([name, path]) => ({ group: 'Go to', type: 'Page', title: name, sub: path === '/' ? 'Open storefront' : '', url: baseUrl + path }));
    }

    function performSearch(query) {
        if (!searchResults) return;
        const commands = matchCommands(query);
        if (commands.length) renderResults(commands, query);
        else searchResults.innerHTML = '<div class="admin-search-modal__empty is-loading">Searching…</div>';

        fetch(baseUrl + '/admin/api/search.php?q=' + encodeURIComponent(query), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(r => r.json()).then(d => {
            if (searchInput.value.trim() !== query) return; // a newer query is in flight
            const found = (d.results || []).map(r => Object.assign({ group: r.type === 'Order' ? 'Orders' : (r.type === 'Product' ? 'Products' : 'People') }, r));
            renderResults(commands.concat(found), query);
        }).catch(() => renderResults(commands, query));
    }

    // ═══════════════════════════════════════════════════
    // KEYBOARD SHORTCUTS
    // ═══════════════════════════════════════════════════
    document.addEventListener('keydown', function(e) {
        // Ctrl+K or Cmd+K → Open search
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            openSearch();
        }
        // Escape → Close modals/search/sidebar/FAB
        if (e.key === 'Escape') {
            closeSearch();
            closeSidebar();
            if (fabTray) fabTray.classList.remove('open');
            // Close any open modal
            document.querySelectorAll('.hp-modal-overlay, .media-detail-overlay, #media-picker-modal, #media-picker-overlay').forEach(m => {
                if (m.style.display !== 'none') m.style.display = 'none';
            });
        }
    });

    // ═══════════════════════════════════════════════════
    // TOAST NOTIFICATIONS
    // ═══════════════════════════════════════════════════
    window.adminToast = function(message, type, duration) {
        type = type || 'info';
        duration = duration || 4000;
        let container = document.querySelector('.admin-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'admin-toast-container';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = 'admin-toast admin-toast--' + type;
        toast.textContent = message;
        container.appendChild(toast);

        setTimeout(() => {
            toast.classList.add('fade-out');
            setTimeout(() => toast.remove(), 300);
        }, duration);
    };

    // ═══════════════════════════════════════════════════
    // AUTO-DISMISS ALERTS
    // ═══════════════════════════════════════════════════
    document.querySelectorAll('.admin-alert--success').forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity .5s, transform .5s';
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-8px)';
            setTimeout(() => alert.remove(), 500);
        }, 5000);
    });

    // ═══════════════════════════════════════════════════
    // SCROLL TO TOP
    // ═══════════════════════════════════════════════════
    const scrollBtn = document.querySelector('.admin-scroll-top');
    if (scrollBtn) {
        window.addEventListener('scroll', function() {
            if (window.scrollY > 400) {
                scrollBtn.classList.add('visible');
            } else {
                scrollBtn.classList.remove('visible');
            }
        }, { passive: true });

        scrollBtn.addEventListener('click', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    // ═══════════════════════════════════════════════════
    // RESPONSIVE TABLE WRAPPER
    // ═══════════════════════════════════════════════════
    // Auto-wrap admin tables that aren't already wrapped
    document.querySelectorAll('.admin-table-wrap').forEach(wrap => {
        if (!wrap.classList.contains('admin-responsive-table')) {
            const inner = document.createElement('div');
            inner.className = 'admin-responsive-table';
            while (wrap.firstChild) {
                inner.appendChild(wrap.firstChild);
            }
            wrap.appendChild(inner);
        }
    });

    // ═══════════════════════════════════════════════════
    // ANIMATED STAT COUNTERS
    // ═══════════════════════════════════════════════════
    function animateCounters() {
        document.querySelectorAll('.admin-stat-card__value[data-count]').forEach(el => {
            const target = parseFloat(el.dataset.count);
            const prefix = el.dataset.prefix || '';
            const suffix = el.dataset.suffix || '';
            const decimals = el.dataset.decimals ? parseInt(el.dataset.decimals) : 0;
            const duration = 1000;
            const start = performance.now();

            function update(now) {
                const progress = Math.min((now - start) / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3); // ease-out cubic
                const current = target * eased;
                el.textContent = prefix + current.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + suffix;
                if (progress < 1) requestAnimationFrame(update);
            }
            requestAnimationFrame(update);
        });
    }

    // Run counter animation if visible
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    animateCounters();
                    observer.disconnect();
                }
            });
        }, { threshold: 0.2 });
        
        const stats = document.querySelector('.admin-stats');
        if (stats) observer.observe(stats);
    } else {
        animateCounters();
    }

    // Radial Dials Animation
    if ('IntersectionObserver' in window) {
        const dialObserver = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-dial');
                    dialObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.2 });
        document.querySelectorAll('.radial-dial-svg').forEach(dial => dialObserver.observe(dial));
    } else {
        document.querySelectorAll('.radial-dial-svg').forEach(dial => dial.classList.add('animate-dial'));
    }

    // ═══════════════════════════════════════════════════
    // CHART.JS & SPARKLINES
    // ═══════════════════════════════════════════════════
    function drawSparkline(svgElement, data) {
        if (!svgElement || !data || !data.length) return;
        const max = Math.max(...data, 1);
        const min = Math.min(...data, 0);
        const range = max - min;
        const width = 100;
        const height = 32;
        const padding = 2; // leave room for stroke
        const step = data.length > 1 ? width / (data.length - 1) : width;
        
        let pathD = `M 0 ${height - padding}`;
        data.forEach((val, i) => {
            const x = i * step;
            const y = height - padding - ((val - min) / range) * (height - padding * 2);
            if (i === 0) pathD = `M ${x} ${y}`;
            else pathD += ` L ${x} ${y}`;
        });
        
        svgElement.innerHTML = `<path d="${pathD}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>`;
    }

    if (window.sparklineData) {
        const sparklineRev = document.getElementById('sparkline-revenue');
        if (sparklineRev) drawSparkline(sparklineRev, window.sparklineData);
    }

    // Chart.js is a deferred script, so it only exists after parsing ends —
    // checking for it right here always failed and the chart never drew.
    function initRevenueChart() {
    if (window.chartLabels && window.chartData && typeof Chart !== 'undefined') {
        const ctx = document.getElementById('revenueChart');
        if (ctx) {
            // Colors come from the CSS tokens so the chart matches the theme.
            const css = getComputedStyle(document.documentElement);
            const accent = css.getPropertyValue('--g-accent').trim() || '#7c6cff';
            const muted = css.getPropertyValue('--text-muted').trim() || '#888';
            const gridC = css.getPropertyValue('--g-grid').trim() || 'rgba(128,128,128,.12)';
            const g = ctx.getContext('2d').createLinearGradient(0, 0, 0, ctx.parentElement.clientHeight || 260);
            g.addColorStop(0, accent + '55');
            g.addColorStop(1, accent + '00');
            const currency = (window.CURRENCY || '').trim();
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: window.chartLabels,
                    datasets: [{
                        label: 'Revenue',
                        data: window.chartData,
                        borderColor: accent,
                        backgroundColor: g,
                        fill: true,
                        borderWidth: 2.5,
                        tension: 0.4,
                        pointRadius: 0,
                        pointHoverRadius: 5,
                        pointHoverBackgroundColor: accent,
                        pointHoverBorderColor: '#fff',
                        pointHoverBorderWidth: 2,
                        pointHitRadius: 12
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    animation: { duration: 900, easing: 'easeOutQuart' },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(20,20,30,.85)', padding: 10, cornerRadius: 10, displayColors: false,
                            callbacks: { label: c => ' ' + Number(c.parsed.y).toLocaleString(undefined, { maximumFractionDigits: 0 }) + (currency ? ' ' + currency : '') }
                        }
                    },
                    scales: {
                        x: { grid: { display: false }, border: { display: false }, ticks: { color: muted, font: { family: 'Inter', size: 10 }, maxRotation: 0, autoSkipPadding: 12 } },
                        y: { grid: { color: gridC }, border: { display: false }, beginAtZero: true, ticks: { color: muted, font: { family: 'Inter', size: 10 }, maxTicksLimit: 5 } }
                    }
                }
            });
        }
    }

    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initRevenueChart);
    else initRevenueChart();

    // ═══════════════════════════════════════════════════
    // CONFIRM DELETE ENHANCEMENT
    // ═══════════════════════════════════════════════════
    document.querySelectorAll('a[onclick*="confirm"]').forEach(link => {
        // Add visual feedback
        link.addEventListener('click', function() {
            if (this.dataset.confirming) return;
            this.dataset.confirming = 'true';
            setTimeout(() => delete this.dataset.confirming, 100);
        });
    });

    // ═══════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════
    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Expose for use by other scripts
    window.AdminUI = {
        openSearch: openSearch,
        closeSearch: closeSearch,
        openSidebar: openSidebar,
        closeSidebar: closeSidebar,
        toast: window.adminToast
    };

})();
