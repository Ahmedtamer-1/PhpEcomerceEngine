<?php
require_once __DIR__ . '/includes/functions.php';
// If maintenance mode is OFF, redirect to home
if (setting('maintenance_mode') !== '1') {
    redirect('/');
}
// Allow admins to access the site
lume_session_start();
$u = current_user();
if ($u && ($u['role'] ?? '') === 'admin') {
    redirect('/');
}

$pageTitle = 'Site Maintenance — ' . setting('site_name', SITE_NAME);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,wght@0,400;0,700;0,900;1,400&family=Red+Hat+Display:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css">
    <style>
        body {
            background-color: #0d0d0d;
            color: #e8e8e4;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            text-align: center;
            padding: 24px;
        }
        .maintenance-box {
            max-width: 600px;
        }
        h1 {
            font-family: var(--font-serif);
            font-size: 2.5rem;
            margin-bottom: 24px;
            color: var(--gold);
        }
        p {
            font-size: 1.1rem;
            line-height: 1.6;
            color: var(--muted);
        }
    </style>
</head>
<body>
    <div class="maintenance-box">
        <?php $siteLogo = setting('site_logo'); if ($siteLogo): ?>
            <img src="<?= SITE_URL . '/' . h($siteLogo) ?>" alt="<?= h(setting('site_name', SITE_NAME)) ?>" style="max-height:60px;width:auto;margin:0 auto 32px;display:block">
        <?php else: ?>
            <p class="lume-logo-text" style="font-size:2rem;margin-bottom:32px"><?= h(setting('site_name', SITE_NAME)) ?></p>
        <?php endif; ?>
        
        <h1>We'll be back soon!</h1>
        <p><?= h(setting('maintenance_message', 'We are currently performing scheduled maintenance. We will be back online shortly.')) ?></p>

        <p style="margin-top:48px">
            <a href="<?= SITE_URL ?>/account.php"
               style="font-size:.7rem;text-transform:uppercase;letter-spacing:.12em;color:rgba(200,184,154,.3);text-decoration:none;transition:color .3s"
               onmouseover="this.style.color='rgba(200,184,154,.7)'"
               onmouseout="this.style.color='rgba(200,184,154,.3)'">Admin Login</a>
        </p>
    </div>
</body>
</html>
