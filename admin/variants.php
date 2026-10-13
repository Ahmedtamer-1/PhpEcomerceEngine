<?php
/**
 * Admin — Product Variants Management
 */
require_once __DIR__ . '/includes/auth.php';
$adminPage = 'products';

$productId = (int)($_GET['product_id'] ?? 0);
if (!$productId) { header('Location: ' . SITE_URL . '/admin/products.php'); exit; }

$stmt = db()->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([$productId]);
$product = $stmt->fetch();
if (!$product) { header('Location: ' . SITE_URL . '/admin/products.php'); exit; }

$productImages = [];
if (!empty($product['image'])) {
    $productImages[] = $product['image'];
}
$galleryImgs = json_decode($product['gallery'] ?? 'null', true) ?: [];
foreach ($galleryImgs as $img) {
    if ($img && !in_array($img, $productImages)) {
        $productImages[] = $img;
    }
}

$pageTitle = 'Variants — ' . $product['name'];
$success = '';
$error = '';

// ── DELETE VARIANT ──
if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    $vid = (int)$_GET['id'];
    db()->prepare('DELETE FROM product_variants WHERE id = ? AND product_id = ?')->execute([$vid, $productId]);
    // Keep the product total equal to the sum of its variants.
    db()->prepare('UPDATE products SET stock = (SELECT COALESCE(SUM(stock),0) FROM product_variants WHERE product_id = ?) WHERE id = ? AND has_variants = 1')->execute([$productId, $productId]);
    log_activity('delete_variant', 'variant', $vid);
    header('Location: ' . SITE_URL . '/admin/variants.php?product_id=' . $productId . '&msg=deleted');
    exit;
}

// ── SAVE VARIANT (Add / Edit) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vid          = (int)($_POST['variant_id'] ?? 0);
    $size         = trim($_POST['size'] ?? '') ?: null;
    $colorName    = trim($_POST['color_name'] ?? '') ?: null;
    $colorHex     = trim($_POST['color_hex'] ?? '') ?: null;
    $sku          = trim($_POST['sku'] ?? '') ?: null;
    $priceOver    = trim($_POST['price_override'] ?? '');
    $priceOverVal = $priceOver !== '' ? (float)$priceOver : null;
    $stock        = (int)($_POST['stock'] ?? 0);
    $sortOrder    = (int)($_POST['sort_order'] ?? 0);
    $isActive     = isset($_POST['is_active']) ? 1 : 0;

    if (!$size && !$colorName) {
        $error = 'At least a size or color is required.';
    } else {
        try {
            // Image upload
            $imagePath = null;
            if ($vid > 0) {
                $stmt = db()->prepare('SELECT image FROM product_variants WHERE id=? AND product_id=?');
                $stmt->execute([$vid, $productId]);
                $imagePath = $stmt->fetchColumn();
            }
            
            if (isset($_POST['existing_image_path'])) {
                $imagePath = $_POST['existing_image_path'] !== '' ? $_POST['existing_image_path'] : null;
            }

            if (!empty($_FILES['image_file']['tmp_name']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                // Extension comes from the detected image type, never from the
                // uploaded filename (which could be e.g. "x.php").
                $imgTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['image_file']['tmp_name']);
                $ext = $imgTypes[$mime] ?? null;
                $newName = 'var_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                $dest = ROOT_PATH . '/assets/images/products/' . $newName;
                if ($ext && move_uploaded_file($_FILES['image_file']['tmp_name'], $dest)) {
                    optimize_image_in_place($dest);
                    $imagePath = 'assets/images/products/' . $newName;
                }
            }

            if ($vid > 0) {
                db()->prepare(
                    'UPDATE product_variants SET size=?, color_name=?, color_hex=?, sku=?, price_override=?, stock=?, sort_order=?, is_active=?, image=? WHERE id=? AND product_id=?'
                )->execute([$size, $colorName, $colorHex, $sku, $priceOverVal, $stock, $sortOrder, $isActive, $imagePath, $vid, $productId]);
                log_activity('update_variant', 'variant', $vid);
                $success = 'Variant updated.';
            } else {
                db()->prepare(
                    'INSERT INTO product_variants (product_id, size, color_name, color_hex, sku, price_override, stock, sort_order, is_active, image) VALUES (?,?,?,?,?,?,?,?,?,?)'
                )->execute([$productId, $size, $colorName, $colorHex, $sku, $priceOverVal, $stock, $sortOrder, $isActive, $imagePath]);
                log_activity('create_variant', 'variant', (int)db()->lastInsertId());
                $success = 'Variant added.';
            }
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                $error = 'This size/color combination already exists.';
            } else {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Keep the product total equal to the sum of its variants after any save.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    db()->prepare('UPDATE products SET stock = (SELECT COALESCE(SUM(stock),0) FROM product_variants WHERE product_id = ?) WHERE id = ? AND has_variants = 1')->execute([$productId, $productId]);
}

// Flash messages
if (isset($_GET['msg'])) {
    $msgs = ['deleted' => 'Variant deleted.'];
    $success = $msgs[$_GET['msg']] ?? '';
}

// Load variants
$variants = db()->prepare('SELECT * FROM product_variants WHERE product_id = ? ORDER BY sort_order ASC, id ASC');
$variants->execute([$productId]);
$variantList = $variants->fetchAll();

// Editing a specific variant?
$editVariant = null;
if (($_GET['action'] ?? '') === 'edit' && isset($_GET['id'])) {
    $evStmt = db()->prepare('SELECT * FROM product_variants WHERE id = ? AND product_id = ?');
    $evStmt->execute([(int)$_GET['id'], $productId]);
    $editVariant = $evStmt->fetch();
}

require_once __DIR__ . '/includes/header.php';
?>

<div style="margin-bottom:20px">
    <a href="<?= SITE_URL ?>/admin/products.php?action=edit&id=<?= $productId ?>" class="admin-btn admin-btn--sm" style="opacity:.7">
        ← Back to <?= h($product['name']) ?>
    </a>
</div>

<?php if ($success): ?><div class="admin-alert admin-alert--success"><?= h($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="admin-alert admin-alert--error"><?= h($error) ?></div><?php endif; ?>

<h2 style="font-size:1rem;margin-bottom:4px;color:var(--a-text,#eee)">Variants for: <?= h($product['name']) ?></h2>
<p style="font-size:.75rem;color:var(--a-muted);margin-bottom:24px">Base price: <?= money((float)$product['price']) ?> · SKU: <?= h($product['sku'] ?? '—') ?></p>

<!-- Existing Variants -->
<?php if (empty($variantList)): ?>
<div class="admin-empty" style="padding:40px 0">
    <div class="admin-empty__icon">🏷️</div>
    <p class="admin-empty__text">No variants yet. Add sizes and colors below.</p>
</div>
<?php else: ?>
<div class="admin-table-wrap" style="margin-bottom:32px">
    <table class="admin-table">
        <thead>
            <tr>
                <th>Size</th>
                <th>Color</th>
                <th>SKU</th>
                <th>Price Override</th>
                <th>Stock</th>
                <th>Order</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($variantList as $v): ?>
            <tr>
                <td><?= h($v['size'] ?? '—') ?></td>
                <td>
                    <?php if ($v['color_name']): ?>
                    <span style="display:inline-flex;align-items:center;gap:6px">
                        <?php if ($v['color_hex']): ?>
                        <span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:<?= h($v['color_hex']) ?>;border:1px solid rgba(255,255,255,.15)"></span>
                        <?php endif; ?>
                        <?= h($v['color_name']) ?>
                    </span>
                    <?php else: ?>—<?php endif; ?>
                    <?php if ($v['image']): ?>
                    <div style="margin-top:4px"><img src="<?= SITE_URL . '/' . h($v['image']) ?>" alt="" style="width:30px;height:30px;object-fit:cover;border-radius:4px"></div>
                    <?php endif; ?>
                </td>
                <td style="font-size:.75rem;color:var(--a-muted)"><?= h($v['sku'] ?? '—') ?></td>
                <td><?= $v['price_override'] !== null ? money((float)$v['price_override']) : '<span style="color:var(--a-muted)">—</span>' ?></td>
                <td><?= (int)$v['stock'] ?></td>
                <td style="color:var(--a-muted)"><?= (int)$v['sort_order'] ?></td>
                <td>
                    <span class="admin-badge admin-badge--<?= $v['is_active'] ? 'active' : 'inactive' ?>">
                        <?= $v['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </td>
                <td>
                    <div class="admin-table__actions">
                        <a href="<?= SITE_URL ?>/admin/variants.php?product_id=<?= $productId ?>&action=edit&id=<?= $v['id'] ?>" class="admin-btn admin-btn--sm">Edit</a>
                        <a href="<?= SITE_URL ?>/admin/variants.php?product_id=<?= $productId ?>&action=delete&id=<?= $v['id'] ?>" class="admin-btn admin-btn--sm admin-btn--danger"
                           onclick="return confirm('Delete this variant?')">Delete</a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Add / Edit Variant Form -->
<div class="admin-variant-form">
    <h3 class="admin-variant-form__title"><?= $editVariant ? '✎ Edit Variant' : '+ Add Variant' ?></h3>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="variant_id" value="<?= $editVariant['id'] ?? 0 ?>">
        <div class="admin-variant-form__row">
            <div class="admin-variant-form__group">
                <label>Size</label>
                <input type="text" name="size" value="<?= h($editVariant['size'] ?? '') ?>" placeholder="e.g. S, M, L, XL">
            </div>
            <div class="admin-variant-form__group">
                <label>Color Name</label>
                <input type="text" name="color_name" value="<?= h($editVariant['color_name'] ?? '') ?>" placeholder="e.g. Black, Sand">
            </div>
            <div class="admin-variant-form__group">
                <label>Color Hex</label>
                <input type="color" name="color_hex" value="<?= h($editVariant['color_hex'] ?? '#888888') ?>" style="height:34px;padding:2px">
            </div>
        </div>
        <div class="admin-variant-form__row">
            <div class="admin-variant-form__group">
                <label>SKU</label>
                <input type="text" name="sku" value="<?= h($editVariant['sku'] ?? '') ?>" placeholder="e.g. LME-TP-001-M">
            </div>
            <div class="admin-variant-form__group">
                <label>Price Override (EGP)</label>
                <input type="number" name="price_override" step="0.01" min="0" value="<?= $editVariant['price_override'] ?? '' ?>" placeholder="Leave blank = base price">
            </div>
            <div class="admin-variant-form__group">
                <label>Stock</label>
                <input type="number" name="stock" min="0" value="<?= $editVariant['stock'] ?? 0 ?>">
            </div>
            <div class="admin-variant-form__group">
                <label>Sort Order</label>
                <input type="number" name="sort_order" min="0" value="<?= $editVariant['sort_order'] ?? 0 ?>">
            </div>
        </div>
        <div class="admin-variant-form__row">
            <div class="admin-variant-form__group" style="grid-column: 1 / -1;">
                <label>Variant Image (Optional)</label>
                <?php if (!empty($productImages)): ?>
                <div style="margin-bottom:8px;font-size:.75rem;color:var(--a-muted)">Select from product gallery:</div>
                <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
                    <?php foreach ($productImages as $imgOption): 
                        $isSelected = ($editVariant['image'] ?? '') === $imgOption;
                    ?>
                    <label style="cursor:pointer;border:2px solid <?= $isSelected ? 'var(--a-gold)' : 'transparent' ?>;border-radius:4px;padding:2px;transition:border-color .2s" class="variant-img-option" onclick="document.querySelectorAll('.variant-img-option').forEach(el=>el.style.borderColor='transparent');this.style.borderColor='var(--a-gold)';document.getElementById('existing_image_input').value='<?= h($imgOption) ?>';document.getElementById('image_file_input').value='';">
                        <img src="<?= SITE_URL . '/' . h($imgOption) ?>" style="width:60px;height:60px;object-fit:cover;border-radius:2px" alt="">
                    </label>
                    <?php endforeach; ?>
                    <label style="cursor:pointer;border:2px solid <?= empty($editVariant['image']) ? 'var(--a-gold)' : 'transparent' ?>;border-radius:4px;padding:2px;display:flex;align-items:center;justify-content:center;width:64px;height:64px;background:var(--a-bg);color:var(--a-muted);font-size:.7rem" class="variant-img-option" onclick="document.querySelectorAll('.variant-img-option').forEach(el=>el.style.borderColor='transparent');this.style.borderColor='var(--a-gold)';document.getElementById('existing_image_input').value='';">
                        None
                    </label>
                </div>
                <?php else: ?>
                    <?php if (!empty($editVariant['image'])): ?>
                        <div style="margin-bottom:8px">
                            <img src="<?= SITE_URL . '/' . h($editVariant['image']) ?>" alt="Variant Image" style="max-height:60px;border-radius:4px">
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <input type="hidden" name="existing_image_path" id="existing_image_input" value="<?= h($editVariant['image'] ?? '') ?>">
                
                <div style="margin-top:8px">
                    <label style="font-size:.75rem;color:var(--a-muted)">Or upload a new image from PC:</label>
                    <input type="file" name="image_file" accept="image/*" id="image_file_input" style="margin-top:4px" onchange="document.getElementById('existing_image_input').value='';document.querySelectorAll('.variant-img-option').forEach(el=>el.style.borderColor='transparent');">
                </div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:16px;margin-top:4px">
            <div class="admin-form__check">
                <input type="checkbox" name="is_active" id="v_is_active" <?= ($editVariant['is_active'] ?? 1) ? 'checked' : '' ?>>
                <label for="v_is_active">Active</label>
            </div>
            <button type="submit" class="admin-btn admin-btn--primary">
                <?= $editVariant ? 'Update Variant' : 'Add Variant' ?>
            </button>
            <?php if ($editVariant): ?>
            <a href="<?= SITE_URL ?>/admin/variants.php?product_id=<?= $productId ?>" class="admin-btn">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
