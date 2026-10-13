<?php
/**
 * LUMEEGY — Admin Products & Variants AJAX API
 * All AJAX calls from admin/products.php go here.
 */
require_once __DIR__ . '/../includes/functions.php';
lume_session_start();

header('Content-Type: application/json; charset=utf-8');

// Auth check — admin only
$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
admin_enforce_csrf();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── GET: fetch variants for a product ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_variants') {
    $productId = (int)($_GET['product_id'] ?? 0);
    if (!$productId) { echo json_encode(['success' => false]); exit; }

    $stmt = db()->prepare('SELECT * FROM product_variants WHERE product_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$productId]);
    $variants = $stmt->fetchAll();

    foreach ($variants as &$v) {
        $v['image_url'] = !empty($v['image']) ? SITE_URL . '/' . $v['image'] : null;
    }
    echo json_encode(['success' => true, 'variants' => $variants]);
    exit;
}

// ── GET: fetch single variant for editing ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_variant') {
    $id = (int)($_GET['id'] ?? 0);
    $productId = (int)($_GET['product_id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM product_variants WHERE id = ? AND product_id = ?');
    $stmt->execute([$id, $productId]);
    $v = $stmt->fetch();
    if ($v) {
        $v['image_url'] = !empty($v['image']) ? SITE_URL . '/' . $v['image'] : null;
    }
    echo json_encode(['success' => (bool)$v, 'variant' => $v ?: null]);
    exit;
}

// ── GET: fetch product images (main + gallery) ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_product_images') {
    $productId = (int)($_GET['product_id'] ?? 0);
    if (!$productId) { echo json_encode(['success' => false, 'images' => []]); exit; }

    $stmt = db()->prepare('SELECT image, gallery FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    $p = $stmt->fetch();

    $images = [];
    if ($p) {
        if (!empty($p['image'])) {
            $images[] = ['path' => $p['image'], 'url' => SITE_URL . '/' . $p['image']];
        }
        $gallery = json_decode($p['gallery'] ?? 'null', true) ?: [];
        foreach ($gallery as $g) {
            if ($g) $images[] = ['path' => $g, 'url' => SITE_URL . '/' . $g];
        }
    }
    echo json_encode(['success' => true, 'images' => $images]);
    exit;
}

// ── POST: save product (add/edit) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_product') {
    $id          = (int)($_POST['id'] ?? 0);
    $name        = trim($_POST['name'] ?? '');
    $slug        = trim($_POST['slug'] ?? '');
    $categoryId  = (int)($_POST['category_id'] ?? 0) ?: null;
    $description = trim($_POST['description'] ?? '');
    $metaTitle   = trim($_POST['meta_title'] ?? '') ?: null;
    $metaDesc    = trim($_POST['meta_desc'] ?? '') ?: null;
    $price       = (float)($_POST['price'] ?? 0);
    $salePrice   = trim($_POST['sale_price'] ?? '') !== '' ? (float)$_POST['sale_price'] : null;
    $costPrice   = trim($_POST['cost_price'] ?? '') !== '' ? (float)$_POST['cost_price'] : null;
    $sku         = trim($_POST['sku'] ?? '');
    $stock       = (int)($_POST['stock'] ?? 0);
    $isFeatured  = isset($_POST['is_featured']) ? 1 : 0;
    $isActive    = isset($_POST['is_active']) ? 1 : 0;
    $hasVariants = isset($_POST['has_variants']) ? 1 : 0;
    $preorder    = isset($_POST['preorder_enabled']) ? 1 : 0;
    $sizeChart   = trim($_POST['size_chart'] ?? '') ?: null;
    $material    = trim($_POST['material'] ?? '') ?: null;
    $gem         = trim($_POST['gem'] ?? '') ?: null;
    ensure_material_gem_columns();

    if (!$slug && $name) {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $slug = trim($slug, '-');
    }

    if (!$name) {
        echo json_encode(['success' => false, 'message' => 'Product name is required.']);
        exit;
    }
    if ($price <= 0) {
        echo json_encode(['success' => false, 'message' => 'Price must be greater than zero.']);
        exit;
    }

    // Main image — from media library
    $imagePath = $_POST['existing_image'] ?? '';
    if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $result = media_upload($_FILES['image'], (int)$user['id']);
        if ($result) {
            $imagePath = $result['filepath'];
        }
    }

    // Gallery — media paths sent as JSON array
    $galleryPaths = [];
    $galleryJson  = $_POST['gallery_paths'] ?? '[]';
    $galleryPaths = json_decode($galleryJson, true) ?: [];

    // Also handle new gallery file uploads
    if (!empty($_FILES['gallery_images']['name'][0])) {
        foreach ($_FILES['gallery_images']['tmp_name'] as $gi => $gtmp) {
            if (empty($gtmp) || $_FILES['gallery_images']['error'][$gi] !== UPLOAD_ERR_OK) continue;
            $gfile = [
                'name'     => $_FILES['gallery_images']['name'][$gi],
                'type'     => $_FILES['gallery_images']['type'][$gi],
                'tmp_name' => $gtmp,
                'error'    => $_FILES['gallery_images']['error'][$gi],
                'size'     => $_FILES['gallery_images']['size'][$gi],
            ];
            $gresult = media_upload($gfile, (int)$user['id']);
            if ($gresult) {
                $galleryPaths[] = $gresult['filepath'];
            }
        }
    }
    $galleryEncoded = json_encode(array_values(array_unique($galleryPaths))) ?: '[]';

    try {
        if ($id > 0) {
            if ($hasVariants) {
                $st = db()->prepare('SELECT COALESCE(SUM(stock),0) FROM product_variants WHERE product_id = ?');
                $st->execute([$id]);
                $stock = (int)$st->fetchColumn();
            }
            db()->prepare(
                'UPDATE products SET category_id=?, name=?, slug=?, description=?, meta_title=?, meta_desc=?, price=?, sale_price=?, cost_price=?,
                 sku=?, stock=?, is_featured=?, is_active=?, has_variants=?, preorder_enabled=?, image=?, gallery=?, size_chart=?, material=?, gem=? WHERE id=?'
            )->execute([$categoryId, $name, $slug, $description, $metaTitle, $metaDesc, $price, $salePrice, $costPrice,
                        $sku, $stock, $isFeatured, $isActive, $hasVariants, $preorder, $imagePath, $galleryEncoded, $sizeChart, $material, $gem, $id]);
            log_activity('update_product', 'product', $id);
            echo json_encode(['success' => true, 'message' => 'Product updated!', 'product_id' => $id]);
        } else {
            db()->prepare(
                'INSERT INTO products (category_id, name, slug, description, meta_title, meta_desc, price, sale_price, cost_price, sku, stock, is_featured, is_active, has_variants, preorder_enabled, image, gallery, size_chart, material, gem)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$categoryId, $name, $slug, $description, $metaTitle, $metaDesc, $price, $salePrice, $costPrice,
                        $sku, $stock, $isFeatured, $isActive, $hasVariants, $preorder, $imagePath, $galleryEncoded, $sizeChart, $material, $gem]);
            $newId = (int)db()->lastInsertId();
            log_activity('create_product', 'product', $newId);
            echo json_encode(['success' => true, 'message' => 'Product added!', 'product_id' => $newId]);
        }
    } catch (PDOException $e) {
        $msg = str_contains($e->getMessage(), 'Duplicate') ? 'A product with this slug already exists.' : 'Database error.';
        echo json_encode(['success' => false, 'message' => $msg]);
    }
    exit;
}

// ── POST: delete product ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_product') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['success' => false]); exit; }
    db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    log_activity('delete_product', 'product', $id);
    echo json_encode(['success' => true]);
    exit;
}

// ── POST: save variant ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_variant') {
    $vid        = (int)($_POST['variant_id'] ?? 0);
    $productId  = (int)($_POST['product_id'] ?? 0);
    $size       = trim($_POST['size'] ?? '') ?: null;
    $colorName  = trim($_POST['color_name'] ?? '') ?: null;
    $colorHex   = trim($_POST['color_hex'] ?? '') ?: null;
    $sku        = trim($_POST['sku'] ?? '') ?: null;
    $priceOver  = trim($_POST['price_override'] ?? '');
    $priceOverV = $priceOver !== '' ? (float)$priceOver : null;
    $costPrice  = trim($_POST['cost_price'] ?? '');
    $costPriceV = $costPrice !== '' ? (float)$costPrice : null;
    $stock      = (int)($_POST['stock'] ?? 0);
    $sortOrder  = (int)($_POST['sort_order'] ?? 0);
    $isActive   = isset($_POST['is_active']) ? 1 : 0;

    if (!$productId) { echo json_encode(['success' => false, 'message' => 'No product ID.']); exit; }
    if (!$size && !$colorName) {
        echo json_encode(['success' => false, 'message' => 'Size or color is required.']);
        exit;
    }

    // Image handling: media library path OR file upload
    $imagePath = null;
    if ($vid > 0) {
        $st = db()->prepare('SELECT image FROM product_variants WHERE id=? AND product_id=?');
        $st->execute([$vid, $productId]);
        $imagePath = $st->fetchColumn() ?: null;
    }

    // If a media library path was sent
    if (isset($_POST['media_image_path'])) {
        $imagePath = $_POST['media_image_path'] !== '' ? $_POST['media_image_path'] : null;
    }

    // If a new file was uploaded
    if (!empty($_FILES['image_file']['tmp_name']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
        $result = media_upload($_FILES['image_file'], (int)$user['id']);
        if ($result) $imagePath = $result['filepath'];
    }

    try {
        if ($vid > 0) {
            db()->prepare(
                'UPDATE product_variants SET size=?, color_name=?, color_hex=?, sku=?, price_override=?, cost_price=?, stock=?, sort_order=?, is_active=?, image=? WHERE id=? AND product_id=?'
            )->execute([$size, $colorName, $colorHex, $sku, $priceOverV, $costPriceV, $stock, $sortOrder, $isActive, $imagePath, $vid, $productId]);
            
            // Sync total stock back to product
            db()->prepare('UPDATE products SET stock = (SELECT COALESCE(SUM(stock),0) FROM product_variants WHERE product_id = ?) WHERE id = ? AND has_variants = 1')->execute([$productId, $productId]);
            
            log_activity('update_variant', 'variant', $vid);
            echo json_encode(['success' => true, 'message' => 'Variant updated.', 'variant_id' => $vid]);
        } else {
            /* Guard against silent duplicates.
               There IS a UNIQUE KEY (product_id, size, color_name), but MySQL
               treats NULLs as DISTINCT — so for a size-only product (no
               colour) the same size can be inserted over and over without
               ever tripping the constraint. That is how products ended up
               with two complete sets of size variants. <=> is the NULL-safe
               comparison the unique key cannot do for us. */
            $dupe = db()->prepare(
                'SELECT id FROM product_variants
                 WHERE product_id = ? AND size <=> ? AND color_name <=> ? LIMIT 1'
            );
            $dupe->execute([$productId, $size ?: null, $colorName ?: null]);
            if ($existingId = $dupe->fetchColumn()) {
                $label = trim(($size ?: '') . ' ' . ($colorName ?: '')) ?: 'that combination';
                echo json_encode([
                    'success' => false,
                    'message' => 'A variant for "' . $label . '" already exists — edit that one instead of adding a second.',
                    'existing_variant_id' => (int)$existingId,
                ]);
                exit;
            }

            db()->prepare(
                'INSERT INTO product_variants (product_id, size, color_name, color_hex, sku, price_override, cost_price, stock, sort_order, is_active, image) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$productId, $size, $colorName, $colorHex, $sku, $priceOverV, $costPriceV, $stock, $sortOrder, $isActive, $imagePath]);
            $newVid = (int)db()->lastInsertId();
            
            // Sync total stock back to product
            db()->prepare('UPDATE products SET stock = (SELECT COALESCE(SUM(stock),0) FROM product_variants WHERE product_id = ?) WHERE id = ? AND has_variants = 1')->execute([$productId, $productId]);
            
            log_activity('create_variant', 'variant', $newVid);
            echo json_encode(['success' => true, 'message' => 'Variant added.', 'variant_id' => $newVid]);
        }
    } catch (PDOException $e) {
        $msg = str_contains($e->getMessage(), 'Duplicate') ? 'This size/color combination already exists.' : 'Database error.';
        echo json_encode(['success' => false, 'message' => $msg]);
    }
    exit;
}

// ── POST: delete variant ──
// ── POST: bulk-generate the size × colour matrix ──
// Replaces adding variants one form submission at a time: a 6-size × 3-colour
// product needed 18 round trips. Existing combinations are skipped, never
// overwritten, so this is safe to re-run after adding a size or colour.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'generate_variants') {
    $productId = (int)($_POST['product_id'] ?? 0);
    if (!$productId) { echo json_encode(['success' => false, 'message' => 'No product ID.']); exit; }

    // Sizes arrive comma/newline separated; colours as JSON [{name,hex},…]
    $rawSizes = (string)($_POST['sizes'] ?? '');
    $sizes = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,\n]/', $rawSizes)), 'strlen')));

    $colors = json_decode((string)($_POST['colors'] ?? '[]'), true);
    if (!is_array($colors)) $colors = [];
    $colors = array_values(array_filter($colors, static fn($c) => !empty($c['name'])));

    if (!$sizes && !$colors) {
        echo json_encode(['success' => false, 'message' => 'Enter at least one size or colour.']);
        exit;
    }

    // Cartesian product; a missing axis becomes a single NULL so a size-only
    // or colour-only product still generates one row per value.
    $sizeAxis  = $sizes  ?: [null];
    $colorAxis = $colors ?: [null];

    $total = count($sizeAxis) * count($colorAxis);
    if ($total > 200) {
        echo json_encode(['success' => false, 'message' => "That would create $total variants. Narrow it down to 200 or fewer."]);
        exit;
    }

    $baseSku = (string)(db()->query('SELECT sku FROM products WHERE id = ' . $productId)->fetchColumn() ?: '');

    $exists = db()->prepare('SELECT id FROM product_variants WHERE product_id = ? AND size <=> ? AND color_name <=> ? LIMIT 1');
    $insert = db()->prepare(
        'INSERT INTO product_variants (product_id, size, color_name, color_hex, sku, stock, sort_order, is_active)
         VALUES (?,?,?,?,?,0,?,1)'
    );

    $created = 0; $skipped = 0;
    try {
        db()->beginTransaction();
        foreach ($colorAxis as $color) {
            foreach ($sizeAxis as $size) {
                $cName = $color['name'] ?? null;
                $cHex  = $color['hex']  ?? null;

                $exists->execute([$productId, $size, $cName]);
                if ($exists->fetchColumn()) { $skipped++; continue; }

                // Sort numerically where sizes are numbers (6,7,…,11) so they
                // do not list alphabetically with 10 and 11 before 6.
                $sort = is_numeric($size) ? (int)$size : 0;

                $sku = $baseSku
                    ? trim($baseSku . ($cName ? '-' . preg_replace('/[^A-Za-z0-9]/', '', $cName) : '') . ($size ? '-' . preg_replace('/[^A-Za-z0-9]/', '', $size) : ''), '-')
                    : null;

                $insert->execute([$productId, $size ?: null, $cName, $cHex, $sku, $sort]);
                $created++;
            }
        }
        // Keep has_variants and the rolled-up stock consistent.
        db()->prepare('UPDATE products SET has_variants = 1 WHERE id = ?')->execute([$productId]);
        db()->prepare('UPDATE products SET stock = (SELECT COALESCE(SUM(stock),0) FROM product_variants WHERE product_id = ?) WHERE id = ?')->execute([$productId, $productId]);
        db()->commit();
    } catch (Exception $e) {
        if (db()->inTransaction()) db()->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }

    log_activity('generate_variants', 'product', $productId, "$created created, $skipped skipped");
    echo json_encode([
        'success' => true,
        'created' => $created,
        'skipped' => $skipped,
        'message' => $created
            ? "$created variant" . ($created === 1 ? '' : 's') . " created" . ($skipped ? ", $skipped already existed." : '.')
            : "Nothing to create — all $skipped combinations already exist.",
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_variant') {
    $id        = (int)($_POST['id'] ?? 0);
    $productId = (int)($_POST['product_id'] ?? 0);
    db()->prepare('DELETE FROM product_variants WHERE id = ? AND product_id = ?')->execute([$id, $productId]);
    
    // Sync total stock back to product if it still has variants
    db()->prepare('UPDATE products SET stock = (SELECT COALESCE(SUM(stock),0) FROM product_variants WHERE product_id = ?) WHERE id = ? AND has_variants = 1')->execute([$productId, $productId]);

    log_activity('delete_variant', 'variant', $id);
    echo json_encode(['success' => true]);
    exit;
}

// ── Helper: ensure color_galleries column exists ──
function ensure_color_galleries_column() {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $cols = array_column(db()->query('SHOW COLUMNS FROM products')->fetchAll(), 'Field');
        if (!in_array('color_galleries', $cols)) {
            db()->exec('ALTER TABLE products ADD COLUMN color_galleries TEXT DEFAULT NULL');
        }
        if (!in_array('size_chart', $cols)) {
            db()->exec('ALTER TABLE products ADD COLUMN size_chart VARCHAR(500) DEFAULT NULL');
        }
    } catch (Exception $e) { /* silently skip */ }
}

// ── Helper: ensure material and gem columns exist ──
function ensure_material_gem_columns() {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $cols = array_column(db()->query('SHOW COLUMNS FROM products')->fetchAll(), 'Field');
        if (!in_array('material', $cols)) {
            db()->exec('ALTER TABLE products ADD COLUMN material VARCHAR(255) DEFAULT NULL');
        }
        if (!in_array('gem', $cols)) {
            db()->exec('ALTER TABLE products ADD COLUMN gem VARCHAR(255) DEFAULT NULL');
        }
        // Pre-order flag. Also shipped as database/migration-preorder.sql for
        // anyone who prefers to run it by hand; this makes that optional.
        if (!in_array('preorder_enabled', $cols)) {
            db()->exec('ALTER TABLE products ADD COLUMN preorder_enabled TINYINT(1) NOT NULL DEFAULT 0');
        }
    } catch (Exception $e) { /* silently skip */ }
}

// ── POST: save color galleries ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_color_galleries') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $galleries = $_POST['color_galleries'] ?? '{}';
    if (!$productId) { echo json_encode(['success' => false]); exit; }
    try {
        ensure_color_galleries_column();
        db()->prepare('UPDATE products SET color_galleries = ? WHERE id = ?')->execute([$galleries, $productId]);
        echo json_encode(['success' => true, 'message' => 'Color galleries saved.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}

// ── GET: get color galleries ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_color_galleries') {
    $productId = (int)($_GET['product_id'] ?? 0);
    if (!$productId) { echo json_encode(['success' => false]); exit; }
    try {
        ensure_color_galleries_column();
        $stmt = db()->prepare('SELECT color_galleries FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $val = $stmt->fetchColumn();
        $galleries = json_decode($val ?: '{}', true) ?: [];
        echo json_encode(['success' => true, 'color_galleries' => $galleries]);
    } catch (Exception $e) {
        echo json_encode(['success' => true, 'color_galleries' => []]);
    }
    exit;
}

// ── GET: get variants for a product ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_variants') {
    $productId = (int)($_GET['product_id'] ?? 0);
    if (!$productId) { echo json_encode(['success' => false]); exit; }
    $stmt = db()->prepare('SELECT * FROM product_variants WHERE product_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$productId]);
    $variants = $stmt->fetchAll();
    foreach ($variants as &$v) {
        $v['image_url'] = !empty($v['image']) ? SITE_URL . '/' . $v['image'] : null;
    }
    echo json_encode(['success' => true, 'variants' => $variants]);
    exit;
}

// ── GET: get single variant ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_variant') {
    $id = (int)($_GET['id'] ?? 0);
    $productId = (int)($_GET['product_id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM product_variants WHERE id = ? AND product_id = ?');
    $stmt->execute([$id, $productId]);
    $v = $stmt->fetch();
    if ($v) {
        $v['image_url'] = !empty($v['image']) ? SITE_URL . '/' . $v['image'] : null;
    }
    echo json_encode(['success' => (bool)$v, 'variant' => $v ?: null]);
    exit;
}

// ── GET: list all products ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list_products') {
    $products = db()->query(
        'SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id ORDER BY p.created_at DESC'
    )->fetchAll();
    foreach ($products as &$p) {
        $p['image_url'] = !empty($p['image']) ? SITE_URL . '/' . $p['image'] : null;
        // Small cached thumbnail for the list — full images made it slow.
        $p['thumb_url'] = !empty($p['image']) ? thumb_url($p['image'], 100) : null;
        unset($p['description']); // not shown in the list; the form fetches the full product
    }
    unset($p);
    echo json_encode(['success' => true, 'products' => $products]);
    exit;
}

// ── GET: fetch single product ──
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_product') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $p = $stmt->fetch();
    if ($p) {
        $p['gallery_arr'] = json_decode($p['gallery'] ?? 'null', true) ?: [];
        $p['image_url']   = !empty($p['image']) ? SITE_URL . '/' . $p['image'] : null;
        $p['size_chart_url'] = !empty($p['size_chart']) ? SITE_URL . '/' . $p['size_chart'] : null;
    }
    echo json_encode(['success' => (bool)$p, 'product' => $p ?: null]);
    exit;
}

// ── POST: bulk action ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'bulk_action') {
    $bulkAction = $_POST['bulk_action_type'] ?? '';
    $ids = json_decode($_POST['bulk_ids'] ?? '[]', true);
    if (!is_array($ids) || empty($ids)) {
        echo json_encode(['success' => false, 'message' => 'No products selected']);
        exit;
    }
    $cleanIds = array_values(array_map('intval', $ids));
    $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
    
    if ($bulkAction === 'delete') {
        db()->prepare("DELETE FROM product_variants WHERE product_id IN ($placeholders)")->execute($cleanIds);
        db()->prepare("DELETE FROM products WHERE id IN ($placeholders)")->execute($cleanIds);
        echo json_encode(['success' => true]);
        exit;
    } elseif ($bulkAction === 'mark_active') {
        db()->prepare("UPDATE products SET is_active = 1 WHERE id IN ($placeholders)")->execute($cleanIds);
        echo json_encode(['success' => true]);
        exit;
    } elseif ($bulkAction === 'mark_inactive') {
        db()->prepare("UPDATE products SET is_active = 0 WHERE id IN ($placeholders)")->execute($cleanIds);
        echo json_encode(['success' => true]);
        exit;
    } elseif ($bulkAction === 'mark_featured' || $bulkAction === 'unmark_featured') {
        $flag = $bulkAction === 'mark_featured' ? 1 : 0;
        db()->prepare("UPDATE products SET is_featured = ? WHERE id IN ($placeholders)")->execute(array_merge([$flag], $cleanIds));
        log_activity('bulk_' . $bulkAction, 'product', 0, count($cleanIds) . ' products');
        echo json_encode(['success' => true]);
        exit;
    } elseif ($bulkAction === 'set_category') {
        $catId = (int)($_POST['category_id'] ?? 0) ?: null;
        db()->prepare("UPDATE products SET category_id = ? WHERE id IN ($placeholders)")->execute(array_merge([$catId], $cleanIds));
        log_activity('bulk_set_category', 'product', 0, count($cleanIds) . ' products → category ' . ($catId ?? 'none'));
        echo json_encode(['success' => true]);
        exit;
    }
}

// ── POST: quick inline edit of one field from the product list ──
// Lets price / sale price / stock / featured / category be changed in place
// without opening the full form.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'quick_update') {
    $id    = (int)($_POST['id'] ?? 0);
    $field = (string)($_POST['field'] ?? '');
    $raw   = trim((string)($_POST['value'] ?? ''));

    $st = db()->prepare('SELECT id, has_variants, price FROM products WHERE id = ?');
    $st->execute([$id]);
    $prod = $st->fetch();
    if (!$prod) { echo json_encode(['success' => false, 'message' => 'Product not found']); exit; }

    switch ($field) {
        case 'price':
            $val = (float)$raw;
            if ($val <= 0) { echo json_encode(['success' => false, 'message' => 'Price must be greater than zero.']); exit; }
            break;
        case 'sale_price':
            $val = $raw === '' ? null : (float)$raw;
            if ($val !== null && ($val <= 0 || $val >= (float)$prod['price'])) {
                echo json_encode(['success' => false, 'message' => 'Sale price must be below the regular price (leave empty for no sale).']); exit;
            }
            break;
        case 'cost_price':
            $val = $raw === '' ? null : max(0, (float)$raw);
            break;
        case 'stock':
            if (!empty($prod['has_variants'])) {
                echo json_encode(['success' => false, 'message' => 'This product has variants — edit stock per variant.']); exit;
            }
            if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) { echo json_encode(['success' => false, 'message' => 'Stock must be a whole number.']); exit; }
            $val = max(0, (int)$raw);
            break;
        case 'is_featured':
        case 'is_active':
            $val = $raw === '1' ? 1 : 0;
            break;
        case 'category_id':
            $val = (int)$raw ?: null;
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Field cannot be edited here.']); exit;
    }

    // $field is from the whitelist above, so it is safe to interpolate.
    db()->prepare("UPDATE products SET `$field` = ?, updated_at = NOW() WHERE id = ?")->execute([$val, $id]);
    log_activity('quick_update_product', 'product', $id, "$field = " . ($val === null ? 'NULL' : $val));
    echo json_encode(['success' => true, 'field' => $field, 'value' => $val]);
    exit;
}

// ── POST: duplicate a product (with its variants) as an inactive draft ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'duplicate_product') {
    $id = (int)($_POST['id'] ?? 0);
    $st = db()->prepare('SELECT * FROM products WHERE id = ?');
    $st->execute([$id]);
    $src = $st->fetch();
    if (!$src) { echo json_encode(['success' => false, 'message' => 'Product not found']); exit; }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $copy = $src;
        unset($copy['id'], $copy['created_at'], $copy['updated_at']);
        $copy['name']      = $src['name'] . ' (Copy)';
        $copy['is_active'] = 0;            // never goes live by accident
        $copy['is_featured'] = 0;
        $baseSlug = ($src['slug'] ?: 'product') . '-copy';
        $slug = $baseSlug; $n = 2;
        $chk = $pdo->prepare('SELECT 1 FROM products WHERE slug = ?');
        while (true) { $chk->execute([$slug]); if (!$chk->fetchColumn()) break; $slug = $baseSlug . '-' . $n++; }
        $copy['slug'] = $slug;
        if (!empty($copy['sku'])) $copy['sku'] = $copy['sku'] . '-COPY';

        $cols = array_keys($copy);
        $pdo->prepare('INSERT INTO products (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($copy));
        $newId = (int)$pdo->lastInsertId();

        $vs = $pdo->prepare('SELECT * FROM product_variants WHERE product_id = ?');
        $vs->execute([$id]);
        foreach ($vs->fetchAll() as $v) {
            unset($v['id'], $v['created_at'], $v['updated_at']);
            $v['product_id'] = $newId;
            if (!empty($v['sku'])) $v['sku'] = $v['sku'] . '-COPY';
            $vc = array_keys($v);
            $pdo->prepare('INSERT INTO product_variants (`' . implode('`,`', $vc) . '`) VALUES (' . implode(',', array_fill(0, count($vc), '?')) . ')')
                ->execute(array_values($v));
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('duplicate_product failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Could not duplicate this product.']);
        exit;
    }
    log_activity('duplicate_product', 'product', $newId, 'Copied from #' . $id);
    echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Duplicated as an inactive draft.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
exit;
