<?php
/**
 * Admin — Ad Spend Tracking
 */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Ad Spend Tracking';
$adminPage = 'ad-spend';

// Ensure table exists
try {
    db()->exec("CREATE TABLE IF NOT EXISTS ad_spend (
        id INT AUTO_INCREMENT PRIMARY KEY,
        platform VARCHAR(50) NOT NULL,
        campaign_name VARCHAR(100) NULL,
        amount DECIMAL(10,2) NOT NULL,
        date_logged DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {}

// Handle Add/Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $platform = trim($_POST['platform'] ?? '');
        $campaign = trim($_POST['campaign_name'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $date = $_POST['date_logged'] ?? date('Y-m-d');
        if ($platform && $amount >= 0) {
            db()->prepare('INSERT INTO ad_spend (platform, campaign_name, amount, date_logged) VALUES (?,?,?,?)')
                ->execute([$platform, $campaign, $amount, $date]);
            log_activity('add_ad_spend', 'ad_spend', db()->lastInsertId(), "Added {$amount} for {$platform}");
            header('Location: ' . SITE_URL . '/admin/ad-spend.php?msg=added');
            exit;
        }
    }
}
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delId = (int)$_GET['id'];
    db()->prepare('DELETE FROM ad_spend WHERE id = ?')->execute([$delId]);
    log_activity('delete_ad_spend', 'ad_spend', $delId);
    header('Location: ' . SITE_URL . '/admin/ad-spend.php?msg=deleted');
    exit;
}

// Fetch Records
$spends = db()->query('SELECT * FROM ad_spend ORDER BY date_logged DESC, created_at DESC')->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
    <h1 style="font-size:1.5rem;font-weight:700">Ad Spend Tracker</h1>
    <button class="admin-btn admin-btn--primary" onclick="document.getElementById('add-modal').style.display='flex'">+ Log Ad Spend</button>
</div>

<?php if (isset($_GET['msg'])): ?>
    <div class="admin-alert admin-alert--success">
        <?= $_GET['msg'] === 'added' ? 'Ad spend logged successfully.' : 'Record deleted successfully.' ?>
    </div>
<?php endif; ?>

<div class="admin-table-wrap" style="background:var(--a-surface);border:1px solid var(--a-border);border-radius:var(--a-radius);padding:16px;">
    <?php if (empty($spends)): ?>
        <p style="color:var(--a-muted);text-align:center;padding:24px 0;">No ad spend records found. Click "+ Log Ad Spend" to add one.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Platform</th>
                    <th>Campaign</th>
                    <th>Amount</th>
                    <th style="width:80px">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($spends as $s): ?>
                <tr>
                    <td><?= date('M j, Y', strtotime($s['date_logged'])) ?></td>
                    <td style="text-transform:capitalize;font-weight:600"><?= h($s['platform']) ?></td>
                    <td><?= h($s['campaign_name'] ?? '—') ?></td>
                    <td style="color:var(--a-gold);font-weight:600"><?= money((float)$s['amount']) ?></td>
                    <td>
                        <a href="?action=delete&id=<?= $s['id'] ?>" class="admin-btn admin-btn--sm admin-btn--danger" onclick="return confirm('Delete this record?')">✕</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Add Modal -->
<div class="hp-modal-overlay" id="add-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:200;align-items:center;justify-content:center;">
    <div style="background:var(--a-surface);padding:32px;border-radius:var(--a-radius);width:100%;max-width:500px;border:1px solid var(--a-border);">
        <div style="display:flex;justify-content:space-between;margin-bottom:24px">
            <h3 style="font-size:1.1rem;font-weight:600">Log Ad Spend</h3>
            <button onclick="document.getElementById('add-modal').style.display='none'" style="background:none;border:none;color:var(--a-muted);cursor:pointer;font-size:1.2rem">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="add">
            <div class="admin-form__group">
                <label>Date</label>
                <input type="date" name="date_logged" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="admin-form__group">
                <label>Platform</label>
                <select name="platform" required>
                    <option value="facebook">Facebook / Instagram</option>
                    <option value="google">Google Ads</option>
                    <option value="tiktok">TikTok Ads</option>
                    <option value="snapchat">Snapchat</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="admin-form__group">
                <label>Campaign Name (Optional)</label>
                <input type="text" name="campaign_name" placeholder="e.g. Summer Sale Retargeting">
            </div>
            <div class="admin-form__group">
                <label>Amount Spent</label>
                <div style="position:relative">
                    <span style="position:absolute;left:12px;top:10px;color:var(--a-muted)"><?= CURRENCY_SYMBOL ?></span>
                    <input type="number" step="0.01" min="0" name="amount" required style="padding-left:32px">
                </div>
            </div>
            <div style="display:flex;gap:12px;margin-top:24px">
                <button type="submit" class="admin-btn admin-btn--primary">Save Record</button>
                <button type="button" class="admin-btn" onclick="document.getElementById('add-modal').style.display='none'">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
