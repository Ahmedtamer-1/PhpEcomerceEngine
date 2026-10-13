<?php
/**
 * Admin — Abandoned checkouts
 * People who typed their details on the checkout page but didn't order.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/checkout-leads.php';
$pageTitle = 'Abandoned Checkouts';
$adminPage = 'abandoned';

// Message pre-filled when you tap WhatsApp. {name} and {items} are replaced.
const LEAD_WA_MESSAGE = "Hi {name}, this is Adorna 🤍 We saw you were checking out {items} but the order wasn't completed. Did something go wrong, or is there anything we can help with?";
// Leads you haven't turned into orders are deleted after this many days.
const LEAD_KEEP_DAYS = 60;

checkout_leads_ensure_table();

// Housekeeping: don't keep people's details forever.
db()->prepare("DELETE FROM checkout_leads WHERE status <> 'ordered' AND updated_at < (NOW() - INTERVAL ? DAY)")
    ->execute([LEAD_KEEP_DAYS]);

// Actions (CSRF is added/checked automatically by the admin includes)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['id'])) {
    $id = (int)$_POST['id'];
    $act = $_POST['do'] ?? '';
    if ($act === 'contacted') {
        db()->prepare("UPDATE checkout_leads SET status='contacted', contacted_at=NOW() WHERE id=? AND status<>'ordered'")->execute([$id]);
    } elseif ($act === 'dismiss') {
        db()->prepare("UPDATE checkout_leads SET status='dismissed' WHERE id=? AND status<>'ordered'")->execute([$id]);
    } elseif ($act === 'reopen') {
        db()->prepare("UPDATE checkout_leads SET status='open' WHERE id=? AND status<>'ordered'")->execute([$id]);
    } elseif ($act === 'delete') {
        db()->prepare('DELETE FROM checkout_leads WHERE id=?')->execute([$id]);
    }
    header('Location: ' . SITE_URL . '/admin/abandoned.php?show=' . urlencode($_GET['show'] ?? 'open'));
    exit;
}

$show = $_GET['show'] ?? 'open';
$valid = ['open', 'contacted', 'dismissed', 'ordered', 'all'];
if (!in_array($show, $valid, true)) $show = 'open';

// Rows still being typed into right now aren't "abandoned" yet.
$where = $show === 'all' ? '1=1' : 'status = ' . db()->quote($show);
if ($show === 'open') $where .= ' AND updated_at < (NOW() - INTERVAL 30 MINUTE)';
$leads = db()->query("SELECT * FROM checkout_leads WHERE $where ORDER BY updated_at DESC LIMIT 300")->fetchAll();

$counts = [];
foreach (db()->query("SELECT status, COUNT(*) c FROM checkout_leads GROUP BY status") as $r) $counts[$r['status']] = (int)$r['c'];
$recovered = (int)db()->query("SELECT COUNT(*) FROM checkout_leads WHERE status='ordered'")->fetchColumn();

require_once __DIR__ . '/includes/header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:12px">
    <h1 style="font-size:1.5rem;font-weight:700">Abandoned Checkouts</h1>
</div>
<p style="color:var(--a-muted);margin-bottom:20px;max-width:720px">
    People who typed a phone number or email on the checkout page but didn't place an order.
    They show up here 30 minutes after they stop typing. Details are deleted after <?= LEAD_KEEP_DAYS ?> days.
</p>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
    <?php foreach (['open' => 'To contact', 'contacted' => 'Contacted', 'dismissed' => 'Dismissed', 'ordered' => 'Ordered', 'all' => 'All'] as $k => $label):
        $n = $k === 'all' ? array_sum($counts) : ($counts[$k] ?? 0); ?>
        <a href="?show=<?= $k ?>" class="admin-btn admin-btn--sm <?= $show === $k ? 'admin-btn--primary' : '' ?>"><?= $label ?> (<?= $n ?>)</a>
    <?php endforeach; ?>
</div>

<div class="admin-table-wrap" style="background:var(--a-surface);border:1px solid var(--a-border);border-radius:var(--a-radius);padding:16px;overflow-x:auto">
<?php if (!$leads): ?>
    <p style="color:var(--a-muted);text-align:center;padding:24px 0">Nothing here yet.</p>
<?php else: ?>
    <table class="admin-table">
        <thead>
            <tr>
                <th>When</th>
                <th>Customer</th>
                <th>Bag</th>
                <th>Value</th>
                <th>Status</th>
                <th style="min-width:220px">Follow up</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($leads as $l):
            $cart = json_decode((string)$l['cart_json'], true) ?: [];
            $itemNames = array_map(fn($c) => $c['name'] . ($c['variant'] ? ' (' . $c['variant'] . ')' : '') . ($c['qty'] > 1 ? ' ×' . $c['qty'] : ''), $cart);
            $first = trim(explode(' ', trim((string)$l['name']))[0] ?? '');
            $msg = strtr(LEAD_WA_MESSAGE, [
                '{name}'  => $first !== '' ? $first : 'there',
                '{items}' => count($cart) === 1 ? 'the ' . $cart[0]['name'] : 'your bag',
            ]);
            $wa = $l['phone'] ? 'https://wa.me/' . checkout_lead_wa_number($l['phone']) . '?text=' . rawurlencode($msg) : '';
        ?>
            <tr>
                <td style="white-space:nowrap"><?= date('M j, g:ia', strtotime($l['updated_at'])) ?></td>
                <td>
                    <strong><?= h($l['name'] ?: '—') ?></strong>
                    <?php if ($l['phone']): ?><br><a href="tel:<?= h($l['phone']) ?>"><?= h($l['phone']) ?></a><?php endif; ?>
                    <?php if ($l['email']): ?><br><a href="mailto:<?= h($l['email']) ?>" style="color:var(--a-muted)"><?= h($l['email']) ?></a><?php endif; ?>
                    <?php if ($l['city']): ?><br><span style="color:var(--a-muted);font-size:12px"><?= h($l['city']) ?></span><?php endif; ?>
                </td>
                <td style="font-size:13px"><?= h(implode(', ', $itemNames)) ?></td>
                <td style="color:var(--a-gold);font-weight:600;white-space:nowrap"><?= money((float)$l['subtotal']) ?></td>
                <td>
                    <?php if ($l['status'] === 'ordered' && $l['order_id']): ?>
                        <a href="<?= SITE_URL ?>/admin/orders.php?id=<?= (int)$l['order_id'] ?>" class="admin-badge admin-badge--paid">Ordered</a>
                    <?php else: ?>
                        <span class="admin-badge admin-badge--<?= $l['status'] === 'open' ? 'pending' : ($l['status'] === 'contacted' ? 'shipped' : 'inactive') ?>"><?= h(ucfirst($l['status'])) ?></span>
                        <?php if ($l['contacted_at']): ?><br><span style="font-size:11px;color:var(--a-muted)"><?= date('M j', strtotime($l['contacted_at'])) ?></span><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <?php if ($l['status'] !== 'ordered'): ?>
                        <?php if ($wa): ?>
                            <a href="<?= h($wa) ?>" target="_blank" rel="noopener" class="admin-btn admin-btn--sm" style="background:#25D366;border-color:#25D366;color:#fff">WhatsApp</a>
                        <?php endif; ?>
                        <?php if ($l['status'] !== 'contacted'): ?>
                        <form method="post" action="?show=<?= h($show) ?>"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button name="do" value="contacted" class="admin-btn admin-btn--sm">Mark contacted</button></form>
                        <?php endif; ?>
                        <?php if ($l['status'] === 'open'): ?>
                        <form method="post" action="?show=<?= h($show) ?>"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button name="do" value="dismiss" class="admin-btn admin-btn--sm admin-btn--ghost">Dismiss</button></form>
                        <?php else: ?>
                        <form method="post" action="?show=<?= h($show) ?>"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button name="do" value="reopen" class="admin-btn admin-btn--sm admin-btn--ghost">Reopen</button></form>
                        <?php endif; ?>
                    <?php endif; ?>
                        <form method="post" action="?show=<?= h($show) ?>" onsubmit="return confirm('Delete this person\'s details?')"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button name="do" value="delete" class="admin-btn admin-btn--sm admin-btn--danger">✕</button></form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
