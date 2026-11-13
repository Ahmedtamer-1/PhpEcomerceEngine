<?php
/**
 * Admin — Security Events
 * Read-only view of the security_events table (see includes/security-log.php).
 */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Security Events';
$adminPage = 'security';

security_log_ensure_table();

// ── Filters: every value is checked against a whitelist or a strict format ──
$knownTypes = ['login_success', 'login_failed', 'login_locked', 'csrf_rejected', 'access_denied',
               'password_reset_requested', 'password_reset_completed', 'upload_rejected',
               'role_changed', 'logout', 'account_unlocked'];
$fType = (string)($_GET['event_type'] ?? '');
if (!in_array($fType, $knownTypes, true)) $fType = '';
$fSev = (string)($_GET['severity'] ?? '');
if (!in_array($fSev, SECURITY_EVENT_SEVERITIES, true)) $fSev = '';
$fIp = trim((string)($_GET['ip'] ?? ''));
if ($fIp !== '' && !filter_var($fIp, FILTER_VALIDATE_IP)) $fIp = '';
$validDate = fn(string $d): bool => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
$fFrom = trim((string)($_GET['from'] ?? ''));
if (!$validDate($fFrom)) $fFrom = '';
$fTo = trim((string)($_GET['to'] ?? ''));
if (!$validDate($fTo)) $fTo = '';

$where = []; $params = [];
if ($fType !== '') { $where[] = 'e.event_type = ?'; $params[] = $fType; }
if ($fSev !== '')  { $where[] = 'e.severity = ?';   $params[] = $fSev; }
if ($fIp !== '')   { $where[] = 'e.ip_address = ?'; $params[] = $fIp; }
if ($fFrom !== '') { $where[] = 'e.created_at >= ?'; $params[] = $fFrom . ' 00:00:00'; }
if ($fTo !== '')   { $where[] = 'e.created_at <= ?'; $params[] = $fTo . ' 23:59:59'; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Last 24 hours counters ──
$counter = function (string $type): int {
    $st = db()->prepare('SELECT COUNT(*) FROM security_events WHERE event_type = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)');
    $st->execute([$type]);
    return (int)$st->fetchColumn();
};
$counters = [
    'Failed logins'    => $counter('login_failed'),
    'Lockouts'         => $counter('login_locked'),
    'CSRF rejections'  => $counter('csrf_rejected'),
    'Access denied'    => $counter('access_denied'),
];

// ── Pagination (50 per page, newest first) ──
$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$cst = db()->prepare("SELECT COUNT(*) FROM security_events e $whereSql");
$cst->execute($params);
$totalItems = (int)$cst->fetchColumn();
$totalPages = max(1, (int)ceil($totalItems / $perPage));
$page   = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$st = db()->prepare("SELECT e.*, u.email AS user_email
                     FROM security_events e LEFT JOIN users u ON u.id = e.user_id
                     $whereSql ORDER BY e.created_at DESC, e.id DESC LIMIT ? OFFSET ?");
foreach ($params as $i => $v) $st->bindValue($i + 1, $v);
$st->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$st->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$st->execute();
$events = $st->fetchAll();

// Query string for pagination links (only whitelisted filters).
$qs = http_build_query(array_filter(['event_type' => $fType, 'severity' => $fSev, 'ip' => $fIp, 'from' => $fFrom, 'to' => $fTo]));
$pageUrl = fn(int $p): string => SITE_URL . '/admin/security.php?' . ($qs ? $qs . '&' : '') . 'page=' . $p;

$sevStyle = ['info' => 'color:var(--a-muted)', 'warning' => 'color:#b7791f;font-weight:600', 'critical' => 'color:#c53030;font-weight:700'];

require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-stats">
    <?php foreach ($counters as $label => $n): ?>
    <div class="admin-stat-card">
        <div class="admin-stat-card__label"><?= h($label) ?> · last 24 h</div>
        <div class="admin-stat-card__value"><?= $n ?></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="admin-toolbar">
    <div class="admin-toolbar__left">
        <span style="font-size:.85rem;color:var(--a-muted)"><?= $totalItems ?> events</span>
    </div>
    <div class="admin-toolbar__right">
        <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <select name="event_type" class="admin-search-input">
                <option value="">All types</option>
                <?php foreach ($knownTypes as $t): ?>
                    <option value="<?= h($t) ?>" <?= $fType === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="severity" class="admin-search-input">
                <option value="">All severities</option>
                <?php foreach (SECURITY_EVENT_SEVERITIES as $s): ?>
                    <option value="<?= h($s) ?>" <?= $fSev === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="ip" value="<?= h((string)($_GET['ip'] ?? '')) ?>" placeholder="IP address" class="admin-search-input" style="width:140px">
            <input type="date" name="from" value="<?= h($fFrom) ?>" class="admin-search-input" title="From">
            <input type="date" name="to" value="<?= h($fTo) ?>" class="admin-search-input" title="To">
            <button type="submit" class="admin-btn admin-btn--sm">Filter</button>
            <?php if ($where || isset($_GET['ip'])): ?>
                <a href="<?= SITE_URL ?>/admin/security.php" class="admin-btn admin-btn--sm">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if (empty($events)): ?>
<div class="admin-empty">
    <div class="admin-empty__icon">🛡️</div>
    <p class="admin-empty__text">No security events match.</p>
</div>
<?php else: ?>
<div class="admin-table-wrap">
    <table class="admin-table">
        <thead>
            <tr><th>Time</th><th>Event</th><th>Severity</th><th>User / identifier</th><th>IP</th><th>Path</th><th>Details</th></tr>
        </thead>
        <tbody>
        <?php foreach ($events as $ev): ?>
            <tr>
                <td style="white-space:nowrap"><?= h($ev['created_at']) ?></td>
                <td><code><?= h($ev['event_type']) ?></code></td>
                <td style="<?= $sevStyle[$ev['severity']] ?? '' ?>"><?= h($ev['severity']) ?></td>
                <td>
                    <?php if ($ev['user_id']): ?>#<?= (int)$ev['user_id'] ?> <?= h((string)$ev['user_email']) ?><?php endif; ?>
                    <?php if ($ev['identifier'] !== null && $ev['identifier'] !== ''): ?><div style="color:var(--a-muted);font-size:.78rem"><?= h($ev['identifier']) ?></div><?php endif; ?>
                </td>
                <td><?= h($ev['ip_address']) ?></td>
                <td style="font-size:.78rem"><?= h($ev['path']) ?></td>
                <td style="font-size:.78rem;color:var(--a-muted);max-width:320px;word-break:break-word"><?= h((string)$ev['details']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="admin-pagination">
    <?php if ($page > 1): ?><a href="<?= h($pageUrl($page - 1)) ?>">← Prev</a><?php endif; ?>
    <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
        <?php if ($p === $page): ?><span class="current"><?= $p ?></span>
        <?php else: ?><a href="<?= h($pageUrl($p)) ?>"><?= $p ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?><a href="<?= h($pageUrl($page + 1)) ?>">Next →</a><?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
