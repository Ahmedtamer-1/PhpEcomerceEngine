<?php
/**
 * Admin — Categories Management
 */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Categories';
$adminPage = 'categories';

$action  = $_GET['action'] ?? 'list';
$success = '';
$error   = '';

// ── DELETE ──
if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    // Set products in this category to NULL instead of deleting them
    db()->prepare('UPDATE products SET category_id = NULL WHERE category_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
    log_activity('delete_category', 'category', $id);
    header('Location: ' . SITE_URL . '/admin/categories.php?msg=deleted');
    exit;
}

// ── SAVE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id          = (int) ($_POST['id'] ?? 0);
    $name        = trim($_POST['name'] ?? '');
    $slug        = trim($_POST['slug'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $sortOrder   = (int) ($_POST['sort_order'] ?? 0);

    if (!$slug && $name) {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $slug = trim($slug, '-');
    }

    if (!$name) {
        $error = 'Category name is required.';
    } else {
        if ($id > 0) {
            db()->prepare('UPDATE categories SET name=?, slug=?, description=?, sort_order=? WHERE id=?')
                ->execute([$name, $slug, $description, $sortOrder, $id]);
            log_activity('update_category', 'category', $id);
            header('Location: ' . SITE_URL . '/admin/categories.php?msg=updated');
            exit;
        } else {
            db()->prepare('INSERT INTO categories (name, slug, description, sort_order) VALUES (?,?,?,?)')
                ->execute([$name, $slug, $description, $sortOrder]);
            log_activity('create_category', 'category', (int)db()->lastInsertId());
            header('Location: ' . SITE_URL . '/admin/categories.php?msg=added');
            exit;
        }
    }
}

if (isset($_GET['msg'])) {
    $msgs = ['added' => 'Category added!', 'updated' => 'Category updated!', 'deleted' => 'Category deleted.'];
    $success = $msgs[$_GET['msg']] ?? '';
}

// ── FORM ──
if ($action === 'add' || $action === 'edit') {
    $cat = null;
    if ($action === 'edit' && isset($_GET['id'])) {
        $stmt = db()->prepare('SELECT * FROM categories WHERE id = ?');
        $stmt->execute([(int) $_GET['id']]);
        $cat = $stmt->fetch();
        if (!$cat) { header('Location: ' . SITE_URL . '/admin/categories.php'); exit; }
        $pageTitle = 'Edit Category';
    } else {
        $pageTitle = 'Add Category';
    }

    require_once __DIR__ . '/includes/header.php';
    ?>
    <div style="max-width:500px">
        <?php if ($error): ?><div class="admin-alert admin-alert--error"><?= h($error) ?></div><?php endif; ?>
        <form method="post" class="admin-form">
            <input type="hidden" name="id" value="<?= $cat['id'] ?? 0 ?>">
            <div class="admin-form__group">
                <label>Category Name *</label>
                <input type="text" name="name" value="<?= h($cat['name'] ?? '') ?>" required>
            </div>
            <div class="admin-form__group">
                <label>Slug</label>
                <input type="text" name="slug" value="<?= h($cat['slug'] ?? '') ?>">
                <span class="admin-form__hint">Leave empty to auto-generate</span>
            </div>
            <div class="admin-form__group">
                <label>Description</label>
                <textarea name="description" rows="3"><?= h($cat['description'] ?? '') ?></textarea>
            </div>
            <div class="admin-form__group">
                <label>Sort Order</label>
                <input type="number" name="sort_order" value="<?= $cat['sort_order'] ?? 0 ?>" min="0">
            </div>
            <div style="display:flex;gap:12px">
                <button type="submit" class="admin-btn admin-btn--primary"><?= $cat ? 'Update' : 'Add' ?> Category</button>
                <a href="<?= SITE_URL ?>/admin/categories.php" class="admin-btn">Cancel</a>
            </div>
        </form>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ── LIST ──
$categories = db()->query(
    'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
     FROM categories c ORDER BY c.sort_order ASC, c.name ASC'
)->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($success): ?><div class="admin-alert admin-alert--success"><?= h($success) ?></div><?php endif; ?>

<div class="admin-toolbar">
    <div class="admin-toolbar__left">
        <span style="font-size:.85rem;color:var(--a-muted)"><?= count($categories) ?> categories</span>
    </div>
    <div class="admin-toolbar__right">
        <a href="<?= SITE_URL ?>/admin/categories.php?action=add" class="admin-btn admin-btn--primary">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Category
        </a>
    </div>
</div>

<?php if (empty($categories)): ?>
<div class="admin-empty">
    <div class="admin-empty__icon">📂</div>
    <p class="admin-empty__text">No categories yet.</p>
    <a href="<?= SITE_URL ?>/admin/categories.php?action=add" class="admin-btn admin-btn--primary">Add Category</a>
</div>
<?php else: ?>
<div class="admin-table-wrap">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Slug</th>
                <th>Products</th>
                <th>Sort</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($categories as $c): ?>
            <tr>
                <td><strong><?= h($c['name']) ?></strong></td>
                <td style="color:var(--a-muted);font-size:.8rem"><?= h($c['slug']) ?></td>
                <td><?= (int) $c['product_count'] ?></td>
                <td><?= (int) $c['sort_order'] ?></td>
                <td>
                    <div class="admin-table__actions">
                        <a href="<?= SITE_URL ?>/admin/categories.php?action=edit&id=<?= $c['id'] ?>" class="admin-btn admin-btn--sm">Edit</a>
                        <a href="<?= SITE_URL ?>/admin/categories.php?action=delete&id=<?= $c['id'] ?>" class="admin-btn admin-btn--sm admin-btn--danger"
                           onclick="return confirm('Delete this category? Products will be uncategorized.')">Delete</a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
