<?php
/**
 * Admin Auth Guard
 * Include at top of every admin page.
 */
require_once __DIR__ . '/../../includes/functions.php';
lume_session_start();

$adminUser = current_user();
if (!$adminUser || ($adminUser['role'] ?? '') !== 'admin') {
    // Not logged in or not admin → redirect to admin login
    header('Location: ' . SITE_URL . '/admin/login.php');
    exit;
}

// Every admin POST (and ?action=delete link) must carry the session CSRF token.
// admin/includes/header.php adds it to forms, links and fetch/XHR automatically.
admin_enforce_csrf();

// Remember this browser as the shop's own, so its storefront visits aren't
// counted in Visitors / Product Demand even after signing out.
if (empty($_COOKIE[STAFF_COOKIE])) analytics_mark_staff();
