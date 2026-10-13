<?php
/**
 * Admin header — shared layout top
 */
$adminPage = $adminPage ?? 'dashboard';

// Get unread message count for notification badge
$unreadMessages = 0;
$pendingOrders = 0;
try {
    $unreadMessages = (int) db()->query('SELECT COUNT(*) FROM contact_messages WHERE is_read = 0')->fetchColumn();
    $pendingOrders = (int) db()->query('SELECT COUNT(*) FROM orders WHERE status = "pending"')->fetchColumn();
} catch (Exception $e) {
    // Tables might not exist yet
}
$totalNotifs = $unreadMessages + $pendingOrders;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle ?? 'Admin') ?> — <?= h(setting('site_name', SITE_NAME)) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <script>
    /* Attach the CSRF token (checked by admin_enforce_csrf()) to every
       state-changing request the admin makes, so individual pages need no
       changes: POST forms get a hidden field, ?action=delete links get a
       query param, same-origin fetch/XHR get an X-CSRF-Token header. */
    (function () {
        var TOKEN = <?= json_encode(csrf_token()) ?>;
        function sameOrigin(url) {
            try { return new URL(url, location.href).origin === location.origin; } catch (e) { return false; }
        }
        function arm(form) {
            // getAttribute(): many admin forms have an <input name="action">,
            // which shadows the form.action / form.method properties.
            if (!form || !form.getAttribute) return;
            if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
            if (!sameOrigin(form.getAttribute('action') || location.href)) return;
            var f = form.querySelector('input[name="csrf"]');
            if (!f) { f = document.createElement('input'); f.type = 'hidden'; f.name = 'csrf'; form.appendChild(f); }
            f.value = TOKEN;
        }
        document.addEventListener('submit', function (e) { arm(e.target); }, true);
        var nativeSubmit = HTMLFormElement.prototype.submit;
        HTMLFormElement.prototype.submit = function () { arm(this); return nativeSubmit.call(this); };
        document.addEventListener('DOMContentLoaded', function () {
            Array.prototype.forEach.call(document.forms, arm);
        });
        document.addEventListener('click', function (e) {
            var a = e.target.closest && e.target.closest('a[href*="action=delete"]');
            if (!a || !sameOrigin(a.href)) return;
            var u = new URL(a.href, location.href);
            u.searchParams.set('csrf', TOKEN);
            a.href = u.toString();
        }, true);
        var nativeFetch = window.fetch;
        if (nativeFetch) {
            window.fetch = function (input, init) {
                var url = (input && input.url) || input;
                if (sameOrigin(url)) {
                    init = init || {};
                    var headers = new Headers(init.headers || (input && input.headers) || {});
                    headers.set('X-CSRF-Token', TOKEN);
                    init.headers = headers;
                }
                return nativeFetch.call(this, input, init);
            };
        }
        var nativeOpen = XMLHttpRequest.prototype.open, nativeSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (m, url) { this._lumeSameOrigin = sameOrigin(url); return nativeOpen.apply(this, arguments); };
        XMLHttpRequest.prototype.send = function () {
            if (this._lumeSameOrigin) { try { this.setRequestHeader('X-CSRF-Token', TOKEN); } catch (e) {} }
            return nativeSend.apply(this, arguments);
        };
    })();
    </script>
    <?php if ($adminPage === 'dashboard'): /* only the dashboard draws charts */ ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
    <?php endif; ?>
    <?php /* Versioned by file mtime: time() made every page view re-download the CSS. */ ?>
    <link rel="stylesheet" href="<?= SITE_URL ?>/admin/assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/admin/assets/css/glass.css?v=<?= filemtime(__DIR__ . '/../assets/css/glass.css') ?>">
</head>
<body data-page="<?= h($adminPage) ?>">
<div class="g-backdrop" aria-hidden="true"><span></span><span></span><span></span></div>
<div class="admin-layout">
    <!-- Sidebar Overlay (mobile) -->
    <div class="admin-sidebar-overlay" id="sidebar-overlay"></div>

    <!-- Sidebar -->
    <aside class="admin-sidebar" id="admin-sidebar">
        <div class="admin-sidebar__logo">
            <a href="<?= SITE_URL ?>/admin/"><?= h(setting('site_name', SITE_NAME)) ?></a>
        </div>
        <nav class="admin-sidebar__nav">
            <!-- MAIN -->
            <div class="admin-nav-section">Main</div>
            <a href="<?= SITE_URL ?>/admin/" class="admin-nav-link <?= $adminPage === 'dashboard' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                <span>Dashboard</span>
            </a>

            <!-- STORE -->
            <div class="admin-nav-section">Store</div>
            <a href="<?= SITE_URL ?>/admin/products.php" class="admin-nav-link <?= $adminPage === 'products' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                <span>Products</span>
            </a>
            <a href="<?= SITE_URL ?>/admin/categories.php" class="admin-nav-link <?= $adminPage === 'categories' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                <span>Categories</span>
            </a>
            <a href="<?= SITE_URL ?>/admin/orders.php" class="admin-nav-link <?= $adminPage === 'orders' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                <span>Orders</span>
                <?php if ($pendingOrders > 0): ?>
                    <span class="admin-nav-badge"><?= $pendingOrders ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= SITE_URL ?>/admin/shipping.php" class="admin-nav-link <?= $adminPage === 'shipping' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                <span>Shipping Zones</span>
            </a>

            <!-- CONTENT -->
            <div class="admin-nav-section">Content</div>
            <?php /* ── "Pages" hidden from the nav ─────────────────────────
                 Superseded by the section Builder below, which now covers
                 Home, About, Contact, Shipping, Returns, Privacy and Terms.

                 The file admin/pages.php is intentionally NOT deleted: a page
                 with zero sections still falls back to its old static layout,
                 and that static text is what pages.php edits. Until every page
                 has sections, it is the only way to change those words.

                 Still reachable directly at /admin/pages.php.
                 To bring the link back, delete this comment wrapper. */ ?>
            <?php if (false): ?>
            <a href="<?= SITE_URL ?>/admin/pages.php" class="admin-nav-link <?= $adminPage === 'pages' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <span>Pages</span>
            </a>
            <?php endif; ?>
            <?php /* Visual builder — live preview, drag to reorder. The older
                     list-and-modal editor stays at homepage.php as a fallback
                     until this has been used in anger for a while. */ ?>
            <a href="<?= SITE_URL ?>/admin/builder.php" class="admin-nav-link <?= $adminPage === 'builder' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                <span>Builder</span>
            </a>
            <a href="<?= SITE_URL ?>/admin/homepage.php" class="admin-nav-link <?= $adminPage === 'homepage' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>Pages (list)</span>
            </a>
            <a href="<?= SITE_URL ?>/admin/media.php" class="admin-nav-link <?= $adminPage === 'media' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                <span>Media</span>
            </a>

            <!-- MARKETING -->
            <div class="admin-nav-section">Marketing</div>
            <a href="<?= SITE_URL ?>/admin/subscribers.php" class="admin-nav-link <?= $adminPage === 'subscribers' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22 6 12 13 2 6"/></svg>
                <span>Subscribers</span>
            </a>
            <a href="<?= SITE_URL ?>/admin/messages.php" class="admin-nav-link <?= $adminPage === 'messages' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span>Messages</span>
                <?php if ($unreadMessages > 0): ?>
                    <span class="admin-nav-badge"><?= $unreadMessages ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= SITE_URL ?>/admin/ad-spend.php" class="admin-nav-link <?= $adminPage === 'ad-spend' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <span>Ad Spend</span>
            </a>

            <!-- SYSTEM -->
            <div class="admin-nav-section">System</div>
            <a href="<?= SITE_URL ?>/admin/settings.php" class="admin-nav-link <?= $adminPage === 'settings' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                <span>Settings</span>
            </a>
            <a href="<?= SITE_URL ?>/admin/themes.php" class="admin-nav-link <?= $adminPage === 'themes' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><path d="M12 2.69l5.66 4.2c.83.61 1.34 1.58 1.34 2.61v8c0 1.66-1.34 3-3 3H8c-1.66 0-3-1.34-3-3v-8c0-1.03.51-2 1.34-2.61L12 2.69zM12 2.69V21"/></svg>
                <span>Theme Manager</span>
            </a>
            <a href="<?= SITE_URL ?>/admin/export.php" class="admin-nav-link <?= $adminPage === 'export' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <span>Export / Import</span>
            </a>
            <a href="<?= SITE_URL ?>/admin/users.php" class="admin-nav-link <?= $adminPage === 'users' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <span>Users</span>
            </a>
            <a href="<?= SITE_URL ?>/admin/activity.php" class="admin-nav-link <?= $adminPage === 'activity' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                <span>Activity Log</span>
            </a>

        </nav>
        <div class="admin-sidebar__bottom">
            <a href="<?= SITE_URL ?>/" class="admin-nav-link" target="_blank">
                <svg viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                <span>View Site</span>
            </a>
            <a href="<?= SITE_URL ?>/account.php?action=logout" class="admin-nav-link">
                <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <!-- Main content -->
    <div class="admin-main">
        <header class="admin-topbar">
            <div class="admin-topbar__left">
                <!-- Hamburger (mobile) -->
                <button class="admin-hamburger" id="admin-hamburger" aria-label="Toggle menu">
                    <svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <h1 class="admin-topbar__title"><?= h($pageTitle ?? 'Dashboard') ?></h1>
            </div>
            <div class="admin-topbar__right">
                <?php if (($adminPage ?? '') === 'dashboard' && isset($rangeLabel)): ?>
                <div class="admin-date-pill">
                    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    <?= h($rangeLabel) ?>
                </div>
                <?php endif; ?>
                <!-- Global Search (desktop) -->
                <div class="admin-topbar__search">
                    <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" placeholder="Search…" readonly onclick="AdminUI.openSearch()" id="topbar-search-trigger">
                    <span class="admin-topbar__search-shortcut">⌘K</span>
                </div>
                <!-- Notification bell -->
                <div style="position:relative" id="admin-notif-wrapper">
                    <button class="admin-notif-btn" id="admin-notif-btn" title="<?= $totalNotifs ?> notifications">
                        <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        <?php if ($totalNotifs > 0): ?>
                            <span class="admin-notif-badge"><?= $totalNotifs > 9 ? '9+' : $totalNotifs ?></span>
                        <?php endif; ?>
                    </button>
                    
                    <div class="admin-notif-dropdown" id="admin-notif-dropdown">
                        <div class="admin-notif-dropdown__header">Notifications</div>
                        <div class="admin-notif-dropdown__list">
                            <?php if ($pendingOrders > 0): ?>
                            <a href="<?= SITE_URL ?>/admin/orders.php?status_filter=pending" class="admin-notif-item">
                                <div class="admin-notif-icon" style="background:rgba(88,129,87,.1);color:var(--a-green)">
                                    <svg viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg>
                                </div>
                                <div class="admin-notif-text">
                                    <strong><?= $pendingOrders ?> pending order(s)</strong>
                                    <span>Needs processing</span>
                                </div>
                            </a>
                            <?php endif; ?>
                            <?php if ($unreadMessages > 0): ?>
                            <a href="<?= SITE_URL ?>/admin/messages.php?filter=unread" class="admin-notif-item">
                                <div class="admin-notif-icon" style="background:rgba(212,163,115,.1);color:var(--a-accent)">
                                    <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                </div>
                                <div class="admin-notif-text">
                                    <strong><?= $unreadMessages ?> unread message(s)</strong>
                                    <span>Check your inbox</span>
                                </div>
                            </a>
                            <?php endif; ?>
                            <?php if ($totalNotifs === 0): ?>
                            <div style="padding:16px;text-align:center;color:var(--a-muted);font-size:.85rem">No new notifications</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <span class="admin-topbar__user" data-initial="<?= h(function_exists('mb_substr') ? mb_strtoupper(mb_substr($adminUser['first_name'] ?? 'A', 0, 1)) : strtoupper(substr($adminUser['first_name'] ?? 'A', 0, 1))) ?>">Hi, <?= h($adminUser['first_name']) ?></span>
            </div>
        </header>
        <div class="admin-content">

<!-- Global Search Overlay -->
<div class="admin-search-overlay" id="admin-search-overlay">
    <div class="admin-search-modal">
        <div class="admin-search-modal__input-wrap">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" class="admin-search-modal__input" id="admin-global-search-input" placeholder="Search products, orders, users…" autocomplete="off">
        </div>
        <div class="admin-search-modal__results" id="admin-search-results">
            <div class="admin-search-modal__empty">Type to search orders, products, users…</div>
        </div>
        <div class="admin-search-modal__footer">
            <span>ESC to close</span>
            <span>⌘K to open</span>
        </div>
    </div>
</div>
