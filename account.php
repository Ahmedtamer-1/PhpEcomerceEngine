<?php
require_once __DIR__ . '/includes/functions.php';
lume_session_start();
$action = $_GET['action'] ?? ($_POST['action'] ?? 'login');
$error = ''; $success = '';
$user = current_user();
// Where to go after signing in or creating an account (local paths only).
$redirectTo = safe_redirect_path($_GET['redirect'] ?? null);
$redirectQs = $redirectTo !== '/account.php' ? '&redirect=' . urlencode($redirectTo) : '';

// Handle logout
if ($action === 'logout') { logout_user(); redirect('/account.php'); }

// If logged in, show dashboard
if ($user && !in_array($action, ['logout'])) { $action = 'dashboard'; }

// Handle Google Login Redirect
if ($action === 'google') {
    $clientId = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '';
    if (!$clientId) {
        $error = 'Google login is not configured.';
        $action = 'login';
    } else {
        $redirectUri = rtrim(SITE_URL, '/') . '/google-callback.php';
        $_SESSION['post_login_redirect'] = $redirectTo;
        // One-time state, checked in google-callback.php (prevents login CSRF).
        $_SESSION['google_oauth_state'] = bin2hex(random_bytes(16));
        $googleOauthUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'email profile',
            'access_type' => 'online',
            'state' => $_SESSION['google_oauth_state'],
        ]);
        header('Location: ' . $googleOauthUrl);
        exit;
    }
}

// Handle login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    if (!csrf_verify($_POST['csrf'] ?? '')) { $error = 'Invalid request.'; }
    else {
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';

        // Rate limiting per email+IP, per IP and per email (see login_throttle_keys()).
        if (login_throttled($email)) {
            $error = 'Too many login attempts. Please wait 15 minutes.';
        } else {
            $stmt  = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $row = $stmt->fetch();
            if ($row && password_verify($pass, $row['password_hash'])) {
                login_succeeded($email);
                login_user((int)$row['id'], !empty($_POST['remember']));
                // Local paths only — an absolute URL here was an open redirect
                // (a phishing link could bounce users to any site after login).
                redirect($redirectTo);
            } else {
                login_failed($email);
                $error = 'Invalid email or password.';
            }
        }
    }
}

// Handle register POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'register') {
    if (!csrf_verify($_POST['csrf'] ?? '')) { $error = 'Invalid request.'; }
    else {
        $fn    = trim($_POST['first_name'] ?? '');
        $ln    = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $pass2 = $_POST['password_confirm'] ?? '';

        if (!$fn || !$ln || !$email || !$pass) { $error = 'All fields are required.'; }
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'Invalid email.'; }
        elseif (strlen($pass) < 8) { $error = 'Password must be at least 8 characters.'; }
        elseif (mb_strlen($fn) > 100 || mb_strlen($ln) > 100) { $error = 'Name is too long.'; }
        elseif ($pass !== $pass2) { $error = 'Passwords do not match.'; }
        else {
            $exists = db()->prepare('SELECT id FROM users WHERE email = ?');
            $exists->execute([$email]);
            if ($exists->fetch()) { $error = 'An account with this email already exists.'; }
            else {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                db()->prepare('INSERT INTO users (first_name, last_name, email, password_hash) VALUES (?,?,?,?)')
                    ->execute([$fn, $ln, $email, $hash]);
                $newId = (int)db()->lastInsertId();
                login_user($newId);
                welcome_discount_grant($newId, $email);
                redirect($redirectTo);
            }
        }
    }
}

// Forgotten password: ask for a reset link
if (in_array($action, ['forgot', 'reset'], true)) {
    require_once __DIR__ . '/includes/password-reset.php';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'forgot') {
    if (!csrf_verify($_POST['csrf'] ?? '')) { $error = 'Invalid request.'; }
    else {
        $rateKey = 'reset:' . get_client_ip();
        if (is_rate_limited($rateKey, 5, 3600)) {
            $error = 'Too many requests. Please try again later.';
        } else {
            record_login_attempt($rateKey, false);
            $email = trim($_POST['email'] ?? '');
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) password_reset_request($email);
            // Same answer whether or not the account exists.
            $success = 'If an account uses that email, we have sent a link to reset your password. It expires in ' . PASSWORD_RESET_MINUTES . ' minutes.';
        }
    }
}

// Forgotten password: set the new one from the emailed link
$resetToken = (string)($_POST['t'] ?? $_GET['t'] ?? '');
if ($action === 'reset') {
    if (password_reset_user($resetToken) === null) {
        $error = 'This reset link is invalid or has expired. Please ask for a new one.';
        $action = 'forgot';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pass  = $_POST['password'] ?? '';
        $pass2 = $_POST['password_confirm'] ?? '';
        if (!csrf_verify($_POST['csrf'] ?? '')) { $error = 'Invalid request.'; }
        elseif (strlen($pass) < 8) { $error = 'Password must be at least 8 characters.'; }
        elseif ($pass !== $pass2) { $error = 'Passwords do not match.'; }
        elseif ($userId = password_reset_complete($resetToken, $pass)) {
            $em = db()->prepare('SELECT email FROM users WHERE id = ?');
            $em->execute([$userId]);
            login_succeeded((string)$em->fetchColumn());
            login_user($userId);
            redirect('/account.php');
        } else {
            $error = 'This reset link is invalid or has expired. Please ask for a new one.';
            $action = 'forgot';
        }
    }
}

$pageTitle = match ($action) {
    'dashboard' => 'My Account',
    'register'  => 'Register',
    'forgot', 'reset' => 'Reset Password',
    default     => 'Login',
} . ' — ' . setting('site_name', SITE_NAME);
require_once __DIR__ . '/includes/header.php';

if ($action === 'dashboard'):
    $orders = db()->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
    $orders->execute([$user['id']]);
    $orderList = $orders->fetchAll();
?>
<section class="lume-page-header">
    <div class="container">
        <h1 class="lume-page-header__title">My Account</h1>
        <p class="lume-page-header__breadcrumb">Welcome back, <?= h($user['first_name']) ?> &nbsp;|&nbsp; <a href="<?= SITE_URL ?>/account.php?action=logout">Logout</a></p>
    </div>
</section>
<section class="lume-section container">
    <h2 style="font-family:var(--font-serif);font-size:1.4rem;text-transform:uppercase;margin-bottom:24px">Recent Orders</h2>
    <?php if (empty($orderList)): ?>
    <p style="color:var(--muted)">No orders yet. <a href="<?= SITE_URL ?>/shop.php" style="color:var(--terracotta)">Start shopping</a></p>
    <?php else: ?>
    <table class="lume-cart-table">
        <thead><tr><th>Order</th><th>Date</th><th>Status</th><th>Total</th></tr></thead>
        <tbody>
        <?php foreach ($orderList as $o): ?>
        <tr>
            <td><?= h($o['order_number']) ?></td>
            <td><?= date('d M Y', strtotime($o['created_at'])) ?></td>
            <td>
                <span style="text-transform:capitalize; display:block; margin-bottom:4px;"><?= h($o['status']) ?></span>
                <?php if ($o['status'] === 'pending_payment'): ?>
                    <a href="<?= SITE_URL ?>/instapay-upload.php?order_id=<?= $o['id'] ?>" class="lume-btn lume-btn--solid" style="padding: 4px 8px; font-size: 0.75rem; text-decoration: none;">Verify Payment</a>
                <?php endif; ?>
            </td>
            <td><?= money((float)$o['total']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<?php elseif ($action === 'register'): ?>
<section class="lume-auth">
    <h1 class="lume-auth__title">Create Account</h1>
    <p class="lume-auth__sub">Join the <?= h(setting('site_name', SITE_NAME)) ?> ritual</p>
    <?php if (welcome_offer_enabled()): ?>
    <p class="lume-welcome-nudge" style="text-align:center"><?= h(rtrim(rtrim(number_format(welcome_offer_pct(), 2), '0'), '.')) ?>% off your first order &mdash; applied to your bag automatically.</p>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?><div class="lume-alert lume-alert--error"><?= h($_GET['error']) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="lume-alert lume-alert--error"><?= h($error) ?></div><?php endif; ?>
    
    <?php if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID): ?>
    <a href="<?= SITE_URL ?>/account.php?action=google<?= h($redirectQs) ?>" class="lume-btn lume-btn--full" style="background:#fff;color:#333;border:1px solid #ccc;display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:20px;text-decoration:none;">
        <svg width="20" height="20" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/><path fill="none" d="M0 0h48v48H0z"/></svg>
        Continue with Google
    </a>
    <div style="text-align:center;margin-bottom:20px;color:var(--muted);position:relative;">
        <span style="background:var(--bg);padding:0 10px;position:relative;z-index:1;">or</span>
        <div style="position:absolute;top:50%;left:0;right:0;height:1px;background:#eee;z-index:0;"></div>
    </div>
    <?php endif; ?>

    <form method="post" class="lume-form">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="register">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <div class="lume-form__group"><label class="lume-form__label">First Name</label><input class="lume-form__input" type="text" name="first_name" required></div>
            <div class="lume-form__group"><label class="lume-form__label">Last Name</label><input class="lume-form__input" type="text" name="last_name" required></div>
        </div>
        <div class="lume-form__group"><label class="lume-form__label">Email</label><input class="lume-form__input" type="email" name="email" required></div>
        <div class="lume-form__group"><label class="lume-form__label">Password</label><input class="lume-form__input" type="password" name="password" required></div>
        <div class="lume-form__group"><label class="lume-form__label">Confirm Password</label><input class="lume-form__input" type="password" name="password_confirm" required></div>
        <button type="submit" class="lume-btn lume-btn--full">Create Account</button>
    </form>
    <p class="lume-auth__toggle">Already have an account? <a href="<?= SITE_URL ?>/account.php<?= $redirectQs ? '?' . h(ltrim($redirectQs, '&')) : '' ?>">Login</a></p>
</section>

<?php elseif ($action === 'forgot'): ?>
<section class="lume-auth">
    <h1 class="lume-auth__title">Forgot Password</h1>
    <p class="lume-auth__sub">Enter your email and we&rsquo;ll send you a link to choose a new password.</p>
    <?php if ($error): ?><div class="lume-alert lume-alert--error"><?= h($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="lume-alert lume-alert--success"><?= h($success) ?></div><?php endif; ?>
    <form method="post" class="lume-form" action="<?= SITE_URL ?>/account.php?action=forgot">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="forgot">
        <div class="lume-form__group"><label class="lume-form__label">Email</label><input class="lume-form__input" type="email" name="email" required autocomplete="email"></div>
        <button type="submit" class="lume-btn lume-btn--full">Send Reset Link</button>
    </form>
    <p class="lume-auth__toggle">Remembered it? <a href="<?= SITE_URL ?>/account.php">Sign in</a></p>
</section>

<?php elseif ($action === 'reset'): ?>
<section class="lume-auth">
    <h1 class="lume-auth__title">Choose a New Password</h1>
    <p class="lume-auth__sub">This will sign you out on every other device.</p>
    <?php if ($error): ?><div class="lume-alert lume-alert--error"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="lume-form" action="<?= SITE_URL ?>/account.php?action=reset">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="t" value="<?= h($resetToken) ?>">
        <div class="lume-form__group"><label class="lume-form__label">New Password</label><input class="lume-form__input" type="password" name="password" required minlength="8" autocomplete="new-password"></div>
        <div class="lume-form__group"><label class="lume-form__label">Confirm Password</label><input class="lume-form__input" type="password" name="password_confirm" required minlength="8" autocomplete="new-password"></div>
        <button type="submit" class="lume-btn lume-btn--full">Save Password</button>
    </form>
</section>

<?php else: ?>
<section class="lume-auth">
    <h1 class="lume-auth__title">Welcome Back</h1>
    <p class="lume-auth__sub">Sign in to your account</p>
    <?php if (isset($_GET['error'])): ?><div class="lume-alert lume-alert--error"><?= h($_GET['error']) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="lume-alert lume-alert--error"><?= h($error) ?></div><?php endif; ?>
    
    <?php if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID): ?>
    <a href="<?= SITE_URL ?>/account.php?action=google<?= h($redirectQs) ?>" class="lume-btn lume-btn--full" style="background:#fff;color:#333;border:1px solid #ccc;display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:20px;text-decoration:none;">
        <svg width="20" height="20" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/><path fill="none" d="M0 0h48v48H0z"/></svg>
        Continue with Google
    </a>
    <div style="text-align:center;margin-bottom:20px;color:var(--muted);position:relative;">
        <span style="background:var(--bg);padding:0 10px;position:relative;z-index:1;">or</span>
        <div style="position:absolute;top:50%;left:0;right:0;height:1px;background:#eee;z-index:0;"></div>
    </div>
    <?php endif; ?>

    <form method="post" class="lume-form">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="login">
        <div class="lume-form__group"><label class="lume-form__label">Email</label><input class="lume-form__input" type="email" name="email" required></div>
        <div class="lume-form__group"><label class="lume-form__label">Password</label><input class="lume-form__input" type="password" name="password" required></div>
        <label style="display:flex;align-items:center;gap:10px;margin:-4px 0 20px;font-size:.8rem;color:var(--muted);cursor:pointer">
            <input type="checkbox" name="remember" value="1" checked style="accent-color:var(--gold);width:16px;height:16px"> Keep me signed in
        </label>
        <button type="submit" class="lume-btn lume-btn--full">Sign In</button>
    </form>
    <p class="lume-auth__toggle" style="margin-top:12px"><a href="<?= SITE_URL ?>/account.php?action=forgot">Forgot your password?</a></p>
    <p class="lume-auth__toggle">Don't have an account? <a href="<?= SITE_URL ?>/account.php?action=register<?= h($redirectQs) ?>">Register</a></p>
</section>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
