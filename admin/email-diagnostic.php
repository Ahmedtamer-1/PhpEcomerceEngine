<?php
/**
 * Admin — Email Diagnostic (TEMPORARY)
 *
 * Walks the same gates send_order_email() checks, in the same order, and
 * reports which one stops the send. Delete this file once email works.
 *
 * Secrets are never printed — only whether they are set and how long they are.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

$pageTitle = 'Email Diagnostic';
$adminPage = 'settings';

/** Report row helper: [ok?, label, detail] */
$checks = [];
$add = function (bool $ok, string $label, string $detail) use (&$checks) {
    $checks[] = ['ok' => $ok, 'label' => $label, 'detail' => $detail];
};
$mask = function (string $v): string {
    $len = strlen($v);
    if ($len === 0) return '(empty)';
    return 'set — ' . $len . ' chars, starts "' . substr($v, 0, 3) . '…"';
};

// ── Gate 1: global switch ────────────────────────────────────────────
$emailEnabled = setting('email_enabled', '0');
$add(
    $emailEnabled === '1',
    'email_enabled',
    $emailEnabled === '1'
        ? 'ON'
        : 'OFF (value: "' . h($emailEnabled) . '") — send_order_email() returns false immediately. Turn on "Enable Emails" in Settings.'
);

// ── Gate 2: per-type switches ────────────────────────────────────────
foreach (['confirmation', 'shipped', 'cancelled'] as $t) {
    $v = setting('email_' . $t . '_enabled', '1');
    $add($v === '1', 'email_' . $t . '_enabled', $v === '1' ? 'ON' : 'OFF (value: "' . h($v) . '")');
}

// ── Gate 3: sender address ───────────────────────────────────────────
$fromEmail = setting('email_from_address', '');
$add(
    $fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL) !== false,
    'email_from_address',
    $fromEmail === ''
        ? 'EMPTY — _mailer_send() logs "No sender email configured" and aborts.'
        : (filter_var($fromEmail, FILTER_VALIDATE_EMAIL) ? h($fromEmail) : 'INVALID: ' . h($fromEmail))
);

// ── Gate 4: transport — Resend preferred, else SMTP ──────────────────
$resendKey = setting('email_resend_api_key', '');
$smtpHost  = setting('smtp_host', '');
$smtpUser  = setting('smtp_username', '');
$smtpPass  = setting('smtp_password', '');
$smtpPort  = setting('smtp_port', '587');
$smtpSec   = setting('smtp_secure', 'tls');

if ($resendKey !== '') {
    $transport = 'resend';
    $add(true, 'Transport', 'Resend API (key present: ' . $mask($resendKey) . ') — SMTP settings are IGNORED while this is set.');
    $add(function_exists('curl_init'), 'cURL extension', function_exists('curl_init') ? 'available' : 'MISSING — Resend cannot send without cURL.');
} else {
    $transport = 'smtp';
    $add(true, 'Transport', 'SMTP (no Resend key set)');
    $smtpOk = $smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '';
    $add($smtpOk, 'SMTP credentials', $smtpOk
        ? 'host=' . h($smtpHost) . ' port=' . h($smtpPort) . ' secure=' . h($smtpSec) . ' user=' . h($smtpUser) . ' pass=' . $mask($smtpPass)
        : 'INCOMPLETE — host=' . ($smtpHost ?: '(empty)') . ' user=' . ($smtpUser ?: '(empty)') . ' pass=' . ($smtpPass ? 'set' : '(empty)')
          . ' — _mailer_send_smtp() logs "SMTP is not configured" and aborts.');

    // Can we even open a socket to the SMTP host? Many shared hosts block this.
    if ($smtpHost !== '') {
        $errno = 0; $errstr = '';
        $sock = @fsockopen(($smtpSec === 'ssl' ? 'ssl://' : '') . $smtpHost, (int)$smtpPort, $errno, $errstr, 6);
        if ($sock) {
            $banner = trim((string)@fgets($sock, 512));
            fclose($sock);
            $add(true, 'SMTP connection', 'Connected. Server said: ' . h(substr($banner, 0, 120)));
        } else {
            $add(false, 'SMTP connection', 'CANNOT CONNECT to ' . h($smtpHost) . ':' . h($smtpPort)
                . ' — ' . h($errstr) . ' (errno ' . (int)$errno . '). Your host is likely blocking outbound SMTP; use Resend instead.');
        }
    }
}

// ── Gate 5: is there an order to send about? ─────────────────────────
$recent = db()->query("SELECT id, order_number, status, guest_email, user_id FROM orders ORDER BY id DESC LIMIT 1")->fetch();
if ($recent) {
    $to = null;
    if (!empty($recent['user_id'])) {
        $st = db()->prepare('SELECT email FROM users WHERE id = ?');
        $st->execute([$recent['user_id']]);
        if ($u = $st->fetch()) $to = $u['email'];
    }
    if (!$to && !empty($recent['guest_email'])) $to = $recent['guest_email'];
    $add(
        (bool)$to && filter_var($to, FILTER_VALIDATE_EMAIL) !== false,
        'Latest order recipient',
        $to ? ('#' . h($recent['order_number']) . ' → ' . h($to)) : ('#' . h($recent['order_number']) . ' has NO email (guest_email empty and no user account)')
    );
} else {
    $add(false, 'Latest order recipient', 'No orders in the database yet.');
}

$allOk = !in_array(false, array_column($checks, 'ok'), true);

// ── Live send test ───────────────────────────────────────────────────
$testResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['test_to'])) {
    $testTo = trim($_POST['test_to']);
    if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        $testResult = ['ok' => false, 'msg' => 'That is not a valid email address.'];
    } else {
        // Call the real transport directly, bypassing the enabled/order gates,
        // so this tests delivery itself rather than the switches above.
        $ok = _mailer_send($testTo, 'Test', 'Test email from ' . setting('site_name', SITE_NAME), '<p>If you are reading this, email delivery works.</p>');
        $testResult = [
            'ok'  => $ok,
            'msg' => $ok
                ? 'Send returned SUCCESS. Check the inbox (and spam folder) for ' . h($testTo) . '.'
                : 'Send FAILED. The reason was written to your PHP error log, prefixed [Mailer].',
        ];
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="card">
    <h2 style="margin-bottom:4px">Email Diagnostic</h2>
    <p style="color:var(--a-muted);font-size:.85rem;margin-bottom:20px">
        Checks the same conditions <code>send_order_email()</code> checks, in order.
        The first ✕ is what is stopping your emails. Delete this file when done.
    </p>

    <div style="padding:12px 16px;border-radius:6px;margin-bottom:20px;background:<?= $allOk ? 'rgba(40,167,69,.12)' : 'rgba(220,53,69,.12)' ?>">
        <strong><?= $allOk ? 'All configuration checks passed.' : 'Configuration problem found — see the ✕ rows below.' ?></strong>
    </div>

    <table class="admin-table" style="width:100%">
        <thead><tr><th style="width:40px"></th><th style="width:220px">Check</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($checks as $c): ?>
            <tr>
                <td style="font-size:1.1rem;color:<?= $c['ok'] ? '#28a745' : '#dc3545' ?>"><?= $c['ok'] ? '✓' : '✕' ?></td>
                <td><code><?= h($c['label']) ?></code></td>
                <td style="font-size:.85rem"><?= $c['detail'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <hr style="border:none;border-top:1px solid var(--a-border);margin:28px 0">

    <h3 style="font-size:.95rem;margin-bottom:12px">Send a real test email</h3>
    <p style="color:var(--a-muted);font-size:.85rem;margin-bottom:12px">
        Bypasses the on/off switches and calls the transport directly, so it tests delivery itself.
    </p>
    <?php if ($testResult): ?>
        <div style="padding:12px 16px;border-radius:6px;margin-bottom:16px;background:<?= $testResult['ok'] ? 'rgba(40,167,69,.12)' : 'rgba(220,53,69,.12)' ?>">
            <?= $testResult['msg'] ?>
        </div>
    <?php endif; ?>
    <form method="post" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <input type="email" name="test_to" required placeholder="you@example.com"
               value="<?= h($_POST['test_to'] ?? '') ?>" style="flex:1 1 260px;padding:10px 12px">
        <button type="submit" class="admin-btn admin-btn--primary">Send test</button>
    </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
