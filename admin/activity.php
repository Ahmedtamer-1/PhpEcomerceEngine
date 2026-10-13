<?php
/**
 * Admin — Activity Log
 */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Activity Log';
$adminPage = 'activity';

// ── Helper: time ago ──
function time_ago(string $datetime): string {
    $now  = new DateTime();
    $past = new DateTime($datetime);
    $diff = $now->diff($past);

    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'Just now';
}

// ── Helper: icon for action type ──
function action_icon(string $action): string {
    $icons = [
        'create'  => '➕',
        'update'  => '✏️',
        'delete'  => '🗑️',
        'login'   => '🔑',
        'logout'  => '🚪',
        'upload'  => '📤',
        'export'  => '📦',
        'import'  => '📥',
        'publish' => '🌐',
    ];
    $key = strtolower($action);
    foreach ($icons as $k => $icon) {
        if (str_contains($key, $k)) return $icon;
    }
    return '📋';
}

// ── Filter + Pagination ──
$filterAction = trim($_GET['action_type'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

// Get distinct action types for dropdown
$actionTypes = db()->query('SELECT DISTINCT action FROM activity_log ORDER BY action ASC')->fetchAll(PDO::FETCH_COLUMN);

// Build query
$where  = '';
$params = [];
if ($filterAction) {
    $where    = 'WHERE a.action = ?';
    $params[] = $filterAction;
}

// Count total
$countSql = "SELECT COUNT(*) FROM activity_log a $where";
if ($params) {
    $countStmt = db()->prepare($countSql);
    $countStmt->execute($params);
    $totalItems = (int) $countStmt->fetchColumn();
} else {
    $totalItems = (int) db()->query($countSql)->fetchColumn();
}
$totalPages = max(1, (int) ceil($totalItems / $perPage));

// Fetch entries
$sql = "SELECT a.*, u.first_name, u.last_name
        FROM activity_log a
        LEFT JOIN users u ON a.user_id = u.id
        $where
        ORDER BY a.created_at DESC
        LIMIT $perPage OFFSET $offset";

if ($params) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
} else {
    $stmt = db()->query($sql);
}
$entries = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-toolbar">
    <div class="admin-toolbar__left">
        <span style="font-size:.85rem;color:var(--a-muted)"><?= $totalItems ?> entries</span>
    </div>
    <div class="admin-toolbar__right">
        <form method="get" style="display:flex;gap:8px;align-items:center">
            <select name="action_type"
                    style="padding:8px 30px 8px 14px;background:var(--a-bg);border:1px solid var(--a-border);border-radius:var(--a-radius);color:var(--a-text);font-size:.82rem;appearance:none;background-image:url(&quot;data:image/svg+xml,%3Csvg width='10' height='6' viewBox='0 0 10 6' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23777' fill='none' stroke-width='1.5'/%3E%3C/svg%3E&quot;);background-repeat:no-repeat;background-position:right 10px center">
                <option value="">All Actions</option>
                <?php foreach ($actionTypes as $at): ?>
                    <option value="<?= h($at) ?>" <?= $filterAction === $at ? 'selected' : '' ?>><?= h(ucfirst($at)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="admin-btn admin-btn--sm">Filter</button>
            <?php if ($filterAction): ?>
                <a href="<?= SITE_URL ?>/admin/activity.php" class="admin-btn admin-btn--sm">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if (empty($entries)): ?>
<div class="admin-empty">
    <div class="admin-empty__icon">📋</div>
    <p class="admin-empty__text">No activity recorded yet.</p>
</div>
<?php else: ?>

<!-- Timeline -->
<div class="activity-timeline">
    <?php foreach ($entries as $entry): ?>
    <div class="activity-timeline__item">
        <div class="activity-timeline__dot">
            <span class="activity-timeline__icon"><?= action_icon($entry['action']) ?></span>
        </div>
        <div class="activity-timeline__content">
            <div class="activity-timeline__header">
                <span class="activity-timeline__action">
                    <?= h(ucfirst($entry['action'])) ?>
                    <?php if ($entry['entity_type']): ?>
                        <span style="color:var(--a-muted)">·</span>
                        <span style="color:var(--a-gold)"><?= h($entry['entity_type']) ?></span>
                    <?php endif; ?>
                    <?php if ($entry['entity_id']): ?>
                        <span style="color:var(--a-muted)">#<?= h($entry['entity_id']) ?></span>
                    <?php endif; ?>
                </span>
                <span class="activity-timeline__time"><?= time_ago($entry['created_at']) ?></span>
            </div>
            <?php if ($entry['details']): ?>
                <p class="activity-timeline__details"><?= h($entry['details']) ?></p>
            <?php endif; ?>
            <div class="activity-timeline__meta">
                <?php
                    $userName = trim(($entry['first_name'] ?? '') . ' ' . ($entry['last_name'] ?? ''));
                    if (!$userName) $userName = 'Unknown User';
                ?>
                <span class="activity-timeline__user"><?= h($userName) ?></span>
                <?php if ($entry['ip_address']): ?>
                    <span class="activity-timeline__ip"><?= h($entry['ip_address']) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="admin-pagination">
    <?php if ($page > 1): ?>
        <a href="<?= SITE_URL ?>/admin/activity.php?page=<?= $page - 1 ?><?= $filterAction ? '&action_type=' . urlencode($filterAction) : '' ?>">← Prev</a>
    <?php endif; ?>

    <?php
    $startP = max(1, $page - 2);
    $endP   = min($totalPages, $page + 2);
    for ($p = $startP; $p <= $endP; $p++):
    ?>
        <?php if ($p === $page): ?>
            <span class="current"><?= $p ?></span>
        <?php else: ?>
            <a href="<?= SITE_URL ?>/admin/activity.php?page=<?= $p ?><?= $filterAction ? '&action_type=' . urlencode($filterAction) : '' ?>"><?= $p ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($page < $totalPages): ?>
        <a href="<?= SITE_URL ?>/admin/activity.php?page=<?= $page + 1 ?><?= $filterAction ? '&action_type=' . urlencode($filterAction) : '' ?>">Next →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<style>
/* ── Activity Timeline ── */
.activity-timeline{position:relative;padding-left:32px}
.activity-timeline::before{content:'';position:absolute;left:11px;top:0;bottom:0;width:2px;background:var(--a-border)}
.activity-timeline__item{position:relative;padding-bottom:24px}
.activity-timeline__item:last-child{padding-bottom:0}
.activity-timeline__dot{position:absolute;left:-32px;top:2px;width:24px;height:24px;border-radius:50%;background:var(--a-surface2);border:2px solid var(--a-border);display:flex;align-items:center;justify-content:center;z-index:1}
.activity-timeline__icon{font-size:.65rem;line-height:1}
.activity-timeline__content{background:var(--a-surface);border:1px solid var(--a-border);border-radius:var(--a-radius);padding:16px 20px}
.activity-timeline__header{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:4px}
.activity-timeline__action{font-size:.88rem;font-weight:500}
.activity-timeline__time{font-size:.72rem;color:var(--a-muted);white-space:nowrap}
.activity-timeline__details{font-size:.82rem;color:var(--a-muted);margin-top:6px;line-height:1.5}
.activity-timeline__meta{display:flex;gap:16px;margin-top:8px;font-size:.72rem}
.activity-timeline__user{color:var(--a-accent)}
.activity-timeline__ip{color:var(--a-muted)}
@media(max-width:768px){
    .activity-timeline{padding-left:28px}
    .activity-timeline__dot{left:-28px;width:20px;height:20px}
    .activity-timeline::before{left:9px}
    .activity-timeline__content{padding:12px 14px}
    .activity-timeline__header{flex-direction:column;align-items:flex-start;gap:4px}
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
