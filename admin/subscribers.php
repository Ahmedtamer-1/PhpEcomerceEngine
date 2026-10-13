<?php
/**
 * Admin — Newsletter Subscribers
 */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Subscribers';
$adminPage = 'subscribers';

$success = '';
$error   = '';

// ── CSV EXPORT ──
if (($_GET['export'] ?? '') === 'csv') {
    $filter = $_GET['filter'] ?? 'all';
    $sql = 'SELECT email, status, source, subscribed_at FROM newsletter_subscribers';
    if ($filter === 'active') {
        $sql .= " WHERE status = 'active'";
    } elseif ($filter === 'unsubscribed') {
        $sql .= " WHERE status = 'unsubscribed'";
    }
    $sql .= ' ORDER BY subscribed_at DESC';
    $rows = db()->query($sql)->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="subscribers_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Email', 'Status', 'Source', 'Subscribed At']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['email'], $r['status'], $r['source'], $r['subscribed_at']]);
    }
    fclose($out);
    exit;
}

// ── TOGGLE STATUS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subscriber_id'], $_POST['new_status'])) {
    $sid = (int) $_POST['subscriber_id'];
    $newStatus = $_POST['new_status'];
    if (in_array($newStatus, ['active', 'unsubscribed'])) {
        db()->prepare('UPDATE newsletter_subscribers SET status = ? WHERE id = ?')->execute([$newStatus, $sid]);
        header('Location: ' . SITE_URL . '/admin/subscribers.php?msg=updated');
        exit;
    }
}

// ── DELETE ──
if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    $delId = (int) $_GET['id'];
    db()->prepare('DELETE FROM newsletter_subscribers WHERE id = ?')->execute([$delId]);
    header('Location: ' . SITE_URL . '/admin/subscribers.php?msg=deleted');
    exit;
}

if (isset($_GET['msg'])) {
    $msgs = ['updated' => 'Subscriber status updated.', 'deleted' => 'Subscriber deleted.'];
    $success = $msgs[$_GET['msg']] ?? '';
}

// ── LIST ──
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['q'] ?? '');

$sql    = 'SELECT * FROM newsletter_subscribers';
$where  = [];
$params = [];

if ($filter === 'active') {
    $where[] = "status = 'active'";
} elseif ($filter === 'unsubscribed') {
    $where[] = "status = 'unsubscribed'";
}

if ($search) {
    $where[]  = 'email LIKE ?';
    $params[] = '%' . $search . '%';
}

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY subscribed_at DESC';

if ($params) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
} else {
    $stmt = db()->query($sql);
}
$subscribers = $stmt->fetchAll();

// Counts
$totalCount  = (int) db()->query('SELECT COUNT(*) FROM newsletter_subscribers')->fetchColumn();
$activeCount = (int) db()->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status = 'active'")->fetchColumn();

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($success): ?><div class="admin-alert admin-alert--success"><?= h($success) ?></div><?php endif; ?>

<div class="admin-toolbar">
    <div class="admin-toolbar__left">
        <span style="font-size:.85rem;color:var(--a-muted)">
            <?= $totalCount ?> subscribers · <strong style="color:var(--a-green)"><?= $activeCount ?> active</strong>
        </span>
    </div>
    <div class="admin-toolbar__right">
        <form method="get" style="display:flex;gap:8px">
            <?php if ($filter !== 'all'): ?>
                <input type="hidden" name="filter" value="<?= h($filter) ?>">
            <?php endif; ?>
            <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search by email…"
                   style="padding:8px 14px;background:var(--a-bg);border:1px solid var(--a-border);border-radius:var(--a-radius);color:var(--a-text);font-size:.82rem;width:220px">
            <button type="submit" class="admin-btn admin-btn--sm">Search</button>
        </form>
        <a href="<?= SITE_URL ?>/admin/subscribers.php?export=csv&filter=<?= h($filter) ?>" class="admin-btn admin-btn--sm admin-btn--primary">
            Export CSV
        </a>
    </div>
</div>

<!-- Filter Tabs -->
<div class="sub-filters">
    <a href="<?= SITE_URL ?>/admin/subscribers.php<?= $search ? '?q=' . urlencode($search) : '' ?>"
       class="sub-filters__tab <?= $filter === 'all' ? 'active' : '' ?>">All</a>
    <a href="<?= SITE_URL ?>/admin/subscribers.php?filter=active<?= $search ? '&q=' . urlencode($search) : '' ?>"
       class="sub-filters__tab <?= $filter === 'active' ? 'active' : '' ?>">Active</a>
    <a href="<?= SITE_URL ?>/admin/subscribers.php?filter=unsubscribed<?= $search ? '&q=' . urlencode($search) : '' ?>"
       class="sub-filters__tab <?= $filter === 'unsubscribed' ? 'active' : '' ?>">Unsubscribed</a>
</div>

<?php if (empty($subscribers)): ?>
<div class="admin-empty">
    <div class="admin-empty__icon">📧</div>
    <p class="admin-empty__text">No subscribers found.</p>
</div>
<?php else: ?>
<div class="admin-table-wrap admin-responsive-table">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Email</th>
                <th>Status</th>
                <th>Source</th>
                <th>Subscribed</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($subscribers as $s): ?>
            <tr>
                <td><strong><?= h($s['email']) ?></strong></td>
                <td>
                    <span class="admin-badge admin-badge--<?= $s['status'] === 'active' ? 'active' : 'inactive' ?>">
                        <?= h($s['status']) ?>
                    </span>
                </td>
                <td style="color:var(--a-muted)"><?= h($s['source'] ?? '—') ?></td>
                <td style="color:var(--a-muted)"><?= date('d M Y', strtotime($s['subscribed_at'])) ?></td>
                <td style="white-space:nowrap">
                    <form method="post" style="display:inline">
                        <input type="hidden" name="subscriber_id" value="<?= $s['id'] ?>">
                        <input type="hidden" name="new_status" value="<?= $s['status'] === 'active' ? 'unsubscribed' : 'active' ?>">
                        <button type="submit" class="admin-btn admin-btn--sm"
                                title="<?= $s['status'] === 'active' ? 'Unsubscribe' : 'Reactivate' ?>">
                            <?= $s['status'] === 'active' ? 'Unsubscribe' : 'Activate' ?>
                        </button>
                    </form>
                    <a href="<?= SITE_URL ?>/admin/subscribers.php?action=delete&id=<?= $s['id'] ?>"
                       class="admin-btn admin-btn--sm admin-btn--danger"
                       onclick="return confirm('Delete this subscriber?')">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<style>
.sub-filters{display:flex;gap:0;margin-bottom:24px;border-bottom:1px solid var(--a-border)}
.sub-filters__tab{padding:10px 20px;font-size:.82rem;color:var(--a-muted);border-bottom:2px solid transparent;transition:all .2s;text-transform:uppercase;letter-spacing:.06em}
.sub-filters__tab:hover{color:var(--a-text)}
.sub-filters__tab.active{color:var(--a-accent);border-bottom-color:var(--a-accent)}
.admin-responsive-table{overflow-x:auto;-webkit-overflow-scrolling:touch}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
