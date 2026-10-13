<?php
/**
 * Forgotten-password reset links.
 *
 * Same "selector:validator" scheme as the remember-me cookie: the link holds
 * both halves, the database keeps only a SHA-256 of the validator, so a
 * leaked database can't be used to reset anyone's password. Links last an
 * hour and work once.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';

const PASSWORD_RESET_MINUTES = 60;

function password_reset_ensure_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        selector CHAR(24) NOT NULL,
        validator_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY selector (selector),
        KEY user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Email a reset link if an account uses this address. Always "succeeds" from
 * the caller's point of view, so the form can't be used to find out which
 * emails have accounts.
 */
function password_reset_request(string $email): void
{
    $stmt = db()->prepare('SELECT id, first_name, email FROM users WHERE email = ?');
    $stmt->execute([trim($email)]);
    $user = $stmt->fetch();
    if (!$user) return;

    password_reset_ensure_table();
    // One live link per account: asking again replaces the old one.
    db()->prepare('DELETE FROM password_resets WHERE user_id = ? OR expires_at < NOW()')->execute([$user['id']]);

    $selector  = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO password_resets (user_id, selector, validator_hash, expires_at)
                   VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))')
        ->execute([$user['id'], $selector, hash('sha256', $validator), PASSWORD_RESET_MINUTES]);

    $link     = SITE_URL . '/account.php?action=reset&t=' . $selector . ':' . $validator;
    $siteName = setting('site_name', SITE_NAME);
    $html = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#222;max-width:520px">'
          . '<p>Hi ' . htmlspecialchars($user['first_name'] ?: 'there') . ',</p>'
          . '<p>We received a request to reset the password for your ' . htmlspecialchars($siteName) . ' account.</p>'
          . '<p style="margin:28px 0"><a href="' . htmlspecialchars($link) . '" style="display:inline-block;padding:12px 28px;background:#111;color:#fff;text-decoration:none;border-radius:2px">Choose a new password</a></p>'
          . '<p>This link works once and expires in ' . PASSWORD_RESET_MINUTES . ' minutes. If you didn&rsquo;t ask for it, you can ignore this email &mdash; your password won&rsquo;t change.</p>'
          . '</div>';

    if (!_mailer_send($user['email'], (string)$user['first_name'], 'Reset your ' . $siteName . ' password', $html)) {
        error_log('password_reset_request: could not send the reset email for user #' . $user['id']);
    }
}

/** The user id a reset token belongs to, or null if it is malformed, unknown, used or expired. */
function password_reset_user(string $token): ?int
{
    if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $token, $m)) return null;
    password_reset_ensure_table();
    $st = db()->prepare('SELECT user_id, validator_hash FROM password_resets WHERE selector = ? AND expires_at > NOW()');
    $st->execute([$m[1]]);
    $row = $st->fetch();
    if (!$row || !hash_equals($row['validator_hash'], hash('sha256', $m[2]))) return null;
    return (int)$row['user_id'];
}

/**
 * Set a new password from a valid token. Uses up the token and signs the
 * account out of every remembered browser.
 */
function password_reset_complete(string $token, string $newPassword): ?int
{
    $userId = password_reset_user($token);
    if ($userId === null) return null;

    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($newPassword, PASSWORD_BCRYPT), $userId]);
    db()->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
    remember_forget_user($userId);
    return $userId;
}
