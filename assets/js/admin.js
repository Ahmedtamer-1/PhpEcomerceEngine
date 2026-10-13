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
            searchResults.innerHTML = '<div class="admin-search-modal__empty">Type to search orders, products, users…</div>';
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
                if (searchResults) searchResults.innerHTML = '<div class="admin-search-modal__empty">Type at least 2 characters…</div>';
                return;
            }
            searchTimer = setTimeout(() => performSearch(q), 300);
        });

        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeSearch();
        });
    }

    function performSearch(query) {
        if (!searchResults) return;
        searchResults.innerHTML = '<div class="admin-search-modal__empty" style="animation:pulse 1s infinite">Searching…</div>';

        const baseUrl = window.BASE_URL || '';

        // Search across products, orders, users
        const searches = [];
        
        // Try to search products API
        if (window.PRODUCTS_API) {
            searches.push(
                fetch(window.PRODUCTS_API + '?search=' + encodeURIComponent(query), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(r => r.json()).then(d => {
                    return (d.products || []).slice(0, 3).map(p => ({
                        type: 'product',
                        icon: '📦',
                        title: p.name,
                        sub: (p.sku || '') + (p.price ? ' · ' + p.price : ''),
                        url: baseUrl + '/admin/products.php'
                    }));
                }).catch(() => [])
            );
        }

        // Also search by checking if the query could be an order number
        searches.push(Promise.resolve(
            query.match(/^(LME|ORD|#)/i) ? [{
                type: 'order',
                icon: '🛍️',
                title: 'Search orders for "' + query + '"',
                sub: 'Go to orders page',
                url: baseUrl + '/admin/orders.php?search=' + encodeURIComponent(query)
            }] : []
        ));

        // Search users link
        searches.push(Promise.resolve([{
            type: 'user',
            icon: '👤',
            title: 'Search users for "' + query + '"',
            sub: 'Go to users page',
            url: baseUrl + '/admin/users.php?q=' + encodeURIComponent(query)
        }]));

        Promise.all(searches).then(results => {
            const all = results.flat();
            if (all.length === 0) {
                searchResults.innerHTML = '<div class="admin-search-modal__empty">No results for "' + escHtml(query) + '"</div>';
                return;
            }
            searchResults.innerHTML = all.map(r => `
                <a href="${r.url}" class="admin-search-modal__result" style="text-decoration:none">
                    <div class="admin-search-modal__result-icon">${r.icon}</div>
                    <div class="admin-search-modal__result-text">
                        <div class="admin-search-modal__result-title">${escHtml(r.title)}</div>
                        <div class="admin-search-modal__result-sub">${escHtml(r.sub)}</div>
                    </div>
                </a>
            `).join('');
        });
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

    if (window.chartLabels && window.chartData && typeof Chart !== 'undefined') {
        const ctx = document.getElementById('revenueChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: window.chartLabels,
                    datasets: [{
                        label: 'Revenue',
                        data: window.chartData,
                        borderColor: '#1A1A1A', // Accent color
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        tension: 0.4,
                        pointRadius: 0,
                        pointHitRadius: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: {
                            grid: { display: false, drawBorder: false },
                            ticks: { color: '#888', font: { family: 'Inter', size: 10 } }
                        },
                        y: {
                            grid: { color: '#E5E5E5', drawBorder: false },
                            ticks: { color: '#888', font: { family: 'Inter', size: 10 }, maxTicksLimit: 5 }
                        }
                    }
                }
            });

            // Adjust colors for dark mode dynamically
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                const chart = Chart.getChart(ctx);
                if (chart) {
                    chart.data.datasets[0].borderColor = '#FAFAFA';
                    chart.options.scales.y.grid.color = '#1E1E1E';
                    chart.update();
                }
            }
        }
    }

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
