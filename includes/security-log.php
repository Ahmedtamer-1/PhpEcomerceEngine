<?php
/**
 * Security event log (separate from the admin Activity Log, which records what
 * admins changed). This one records what looks like an attack or a sensitive
 * action: failed logins, lockouts, CSRF rejections, access denied, resets...
 * Shown in Admin → Security Events.
 *
 * Never store passwords, session IDs or CSRF tokens here.
 */

const SECURITY_EVENT_SEVERITIES = ['info', 'warning', 'critical'];

function security_log_ensure_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS security_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_type VARCHAR(40) NOT NULL,
        severity VARCHAR(10) NOT NULL DEFAULT 'info',
        user_id INT UNSIGNED NULL,
        identifier VARCHAR(190) NULL,
        ip_address VARCHAR(45) NOT NULL DEFAULT '',
        user_agent VARCHAR(255) NULL,
        path VARCHAR(255) NOT NULL DEFAULT '',
        details TEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY created_at (created_at),
        KEY type_time (event_type, created_at),
        KEY ip_time (ip_address, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Cut a value to $max characters and strip control characters (no log forging). */
function security_log_clean(mixed $value, int $max): ?string
{
    if ($value === null) return null;
    $s = is_scalar($value) ? (string)$value : (string)json_encode($value, JSON_UNESCAPED_UNICODE);
    $s = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s) ?? '';
    return mb_substr($s, 0, $max);
}

/**
 * Record a security event. Never throws: a logging failure must not break the
 * request (or reveal anything), so errors only go to error_log().
 *
 * $context keys: user_id, identifier (e.g. the email tried), details (string or
 * array). Array keys that look like secrets are dropped.
 */
function security_log(string $type, string $severity = 'info', array $context = []): void
{
    try {
        if (!in_array($severity, SECURITY_EVENT_SEVERITIES, true)) $severity = 'info';
        security_log_ensure_table();

        $details = $context['details'] ?? null;
        if (is_array($details)) {
            foreach (array_keys($details) as $k) {
                if (preg_match('/pass|token|csrf|secret|session|cookie|auth/i', (string)$k)) unset($details[$k]);
            }
        }

        $userId = $context['user_id'] ?? ($_SESSION['user_id'] ?? null);
        // Path only: the query string can carry reset tokens or CSRF tokens.
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        db()->prepare(
            'INSERT INTO security_events (event_type, severity, user_id, identifier, ip_address, user_agent, path, details, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            security_log_clean($type, 40),
            $severity,
            $userId !== null ? (int)$userId : null,
            security_log_clean($context['identifier'] ?? null, 190),
            get_client_ip(),
            security_log_clean($_SERVER['HTTP_USER_AGENT'] ?? null, 255),
            security_log_clean($path, 255),
            security_log_clean($details, 2000),
        ]);

        // Housekeeping now and then: keep a year of events.
        if (random_int(1, 500) === 1) {
            db()->exec('DELETE FROM security_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 365 DAY)');
        }
    } catch (\Throwable $e) {
        error_log('security_log: ' . $e->getMessage());
    }
}
