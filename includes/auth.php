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
