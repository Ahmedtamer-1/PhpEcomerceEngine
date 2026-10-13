<?php
/**
 * Admin — Shipping Zones
 */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Shipping Zones';
$adminPage = 'shipping';

$success = '';
$error   = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $cost = (float)($_POST['cost'] ?? 0);
        if ($name) {
            try {
                // Fetch max sort_order first — MySQL forbids subqueries on the
                // same table inside an INSERT statement.
                $maxSort = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM shipping_zones')->fetchColumn();
                db()->prepare('INSERT INTO shipping_zones (name, cost, sort_order) VALUES (?, ?, ?)')
                    ->execute([$name, $cost, $maxSort + 1]);
                log_activity('create_shipping_zone', 'shipping_zone', (int)db()->lastInsertId());
                $success = "Zone '$name' added.";
            } catch (Exception $e) {
                $error = "Error adding zone (maybe duplicate name?): " . $e->getMessage();
            }
        }
    } elseif ($action === 'edit') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $cost = (float)($_POST['cost'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($id && $name) {
            db()->prepare('UPDATE shipping_zones SET name=?, cost=?, is_active=? WHERE id=?')
                ->execute([$name, $cost, $active, $id]);
            log_activity('update_shipping_zone', 'shipping_zone', $id);
            $success = "Zone updated.";
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            db()->prepare('DELETE FROM shipping_zones WHERE id=?')->execute([$id]);
            log_activity('delete_shipping_zone', 'shipping_zone', $id);
            $success = "Zone deleted.";
        }
    }
}

$zones = db()->query('SELECT * FROM shipping_zones ORDER BY sort_order ASC, name ASC')->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($success): ?><div class="admin-alert admin-alert--success"><?= h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="admin-alert admin-alert--error"><?= h($error) ?></div><?php endif; ?>

<div class="admin-toolbar">
    <div class="admin-toolbar__left">
        <h2 style="font-size:1.1rem">Manage Shipping Zones</h2>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 350px;gap:24px;">
    <!-- Left: List -->
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Zone / Governorate</th>
                    <th>Cost</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($zones)): ?>
                <tr><td colspan="4" style="text-align:center;color:var(--a-muted)">No zones found.</td></tr>
                <?php else: ?>
                    <?php foreach ($zones as $z): ?>
                    <tr>
                        <td><strong><?= h($z['name']) ?></strong></td>
                        <td><?= money((float)$z['cost']) ?></td>
                        <td>
                            <?php if ($z['is_active']): ?>
                                <span class="admin-badge admin-badge--active">Active</span>
                            <?php else: ?>
                                <span class="admin-badge admin-badge--inactive">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="admin-table__actions">
                                <button type="button" class="admin-btn admin-btn--sm" onclick="editZone(<?= htmlspecialchars(json_encode($z)) ?>)">Edit</button>
                                <form method="post" onsubmit="return confirm('Delete this zone?');" style="display:inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $z['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger">Del</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Right: Form -->
    <div>
        <div style="background:var(--a-surface);border:1px solid var(--a-border);border-radius:var(--a-radius);padding:24px;">
            <h3 id="form-title" style="font-size:1rem;margin-bottom:16px;color:var(--a-gold)">Add New Zone</h3>
            <form method="post" class="admin-form" id="zone-form">
                <input type="hidden" name="action" value="add" id="form-action">
                <input type="hidden" name="id" value="" id="form-id">
                
                <div class="admin-form__group">
                    <label>Governorate / City Name</label>
                    <input type="text" name="name" id="form-name" required>
                </div>
                <div class="admin-form__group">
                    <label>Shipping Cost (EGP)</label>
                    <input type="number" name="cost" id="form-cost" min="0" step="0.01" value="0" required>
                </div>
                <div class="admin-form__check" id="form-active-wrap" style="display:none;margin-top:8px">
                    <input type="checkbox" name="is_active" id="form-active" value="1">
                    <label for="form-active">Active (Available at checkout)</label>
                </div>
                <div style="margin-top:16px;display:flex;gap:8px">
                    <button type="submit" class="admin-btn admin-btn--primary">Save Zone</button>
                    <button type="button" class="admin-btn" onclick="resetForm()" id="btn-cancel" style="display:none">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editZone(z) {
    document.getElementById('form-title').innerText = 'Edit Zone';
    document.getElementById('form-action').value = 'edit';
    document.getElementById('form-id').value = z.id;
    document.getElementById('form-name').value = z.name;
    document.getElementById('form-cost').value = z.cost;
    document.getElementById('form-active-wrap').style.display = 'flex';
    document.getElementById('form-active').checked = z.is_active == 1;
    document.getElementById('btn-cancel').style.display = 'inline-flex';
}
function resetForm() {
    document.getElementById('form-title').innerText = 'Add New Zone';
    document.getElementById('form-action').value = 'add';
    document.getElementById('form-id').value = '';
    document.getElementById('form-name').value = '';
    document.getElementById('form-cost').value = '0';
    document.getElementById('form-active-wrap').style.display = 'none';
    document.getElementById('form-active').checked = true;
    document.getElementById('btn-cancel').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
