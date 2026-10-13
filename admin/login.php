<?php
require_once __DIR__ . '/../includes/functions.php';
lume_session_start();

$error = '';

// Already logged in as admin?
$u = current_user();
if ($u && ($u['role'] ?? '') === 'admin') {
    header('Location: ' . SITE_URL . '/admin/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify($_POST['csrf'] ?? '')) {
    $error = 'Your session expired. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    // Rate limiting
    if (is_rate_limited($email)) {
        $error = 'Too many login attempts. Please wait 15 minutes.';
    } else {
        $stmt = db()->prepare('SELECT id, password_hash, role FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if ($row && password_verify($pass, $row['password_hash'])) {
            if ($row['role'] !== 'admin') {
                $error = 'This account does not have admin access.';
            } else {
                clear_login_attempts($email);
                record_login_attempt($email, true);
                login_user((int)$row['id'], !empty($_POST['remember']));
                log_activity('admin_login', 'user', (int)$row['id']);
                header('Location: ' . SITE_URL . '/admin/');
                exit;
            }
        } else {
            record_login_attempt($email, false);
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — <?= h(setting('site_name', SITE_NAME)) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= SITE_URL ?>/admin/assets/css/admin.css?v=<?= filemtime(__DIR__ . '/assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/admin/assets/css/glass.css?v=<?= filemtime(__DIR__ . '/assets/css/glass.css') ?>">
</head>
<body class="admin-login-page">
<div class="g-backdrop" aria-hidden="true"><span></span><span></span><span></span></div>
    <div class="admin-login-box">
        <h1 class="admin-login-logo"><?= h(setting('site_name', SITE_NAME)) ?></h1>
        <p class="admin-login-sub">Admin Panel</p>
        <?php if ($error): ?>
            <div class="admin-alert admin-alert--error"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post" class="admin-form">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <div class="admin-form__group">
                <label>Email</label>
                <input type="email" name="email" required autofocus>
            </div>
            <div class="admin-form__group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <label style="display:flex;align-items:center;gap:8px;margin:4px 0 16px;font-size:.82rem;cursor:pointer">
                <input type="checkbox" name="remember" value="1" checked style="width:auto;margin:0"> Keep me signed in on this device
            </label>
            <button type="submit" class="admin-btn admin-btn--primary admin-btn--full">Sign In</button>
        </form>
        <p class="admin-login-back"><a href="<?= SITE_URL ?>/">← Back to site</a></p>
    </div>
</body>
</html>
