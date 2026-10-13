<?php
/**
 * Admin — Export & Import Data
 */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Export / Import';
$adminPage = 'export';
$success = '';
$error   = '';

/**
 * fputcsv() that neutralises spreadsheet formulas. Customer-typed text such as
 * "=HYPERLINK(...)" would otherwise run as a formula when the export is
 * opened in Excel/Sheets. Numbers (including negatives) are left alone.
 */
function csv_safe_row($out, array $row): void
{
    foreach ($row as &$v) {
        if (is_string($v) && $v !== '' && !is_numeric($v) && strpbrk($v[0], "=+-@	") !== false) {
            $v = "'" . $v;
        }
    }
    unset($v);
    fputcsv($out, $row);
}

// ── EXPORT ──
if (isset($_GET['export'])) {
    $type = in_array($_GET['export'], ['products', 'orders', 'customers', 'newsletter'], true) ? $_GET['export'] : 'unknown';
    $filename = 'lumeegy-' . $type . '-' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel shows Arabic names correctly instead of garbled text.
    fwrite($out, "﻿");

    switch ($type) {
        case 'products':
            csv_safe_row($out, ['ID', 'Name', 'Slug', 'Category', 'Price', 'Sale Price', 'SKU', 'Stock', 'Featured', 'Active', 'Image', 'Description', 'Created']);
            $rows = db()->query(
                'SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id ORDER BY p.id'
            )->fetchAll();
            foreach ($rows as $r) {
                csv_safe_row($out, [
                    $r['id'], $r['name'], $r['slug'], $r['category_name'] ?? '',
                    $r['price'], $r['sale_price'] ?? '', $r['sku'] ?? '', $r['stock'],
                    $r['is_featured'], $r['is_active'], $r['image'] ?? '',
                    $r['description'] ?? '', $r['created_at']
                ]);
            }
            break;

        case 'orders':
            csv_safe_row($out, ['Order #', 'Customer', 'Email', 'Status', 'Subtotal', 'Shipping', 'Discount', 'Total', 'Payment', 'City', 'Country', 'Date']);
            $rows = db()->query('SELECT o.*, COALESCE(NULLIF(o.guest_email, ""), u.email) AS guest_email FROM orders o LEFT JOIN users u ON u.id = o.user_id ORDER BY o.created_at DESC')->fetchAll();
            foreach ($rows as $r) {
                csv_safe_row($out, [
                    $r['order_number'], $r['shipping_name'] ?? '',
                    $r['guest_email'] ?? '', $r['status'],
                    $r['subtotal'], $r['shipping_cost'], $r['discount'], $r['total'],
                    $r['payment_method'] ?? '', $r['shipping_city'] ?? '',
                    $r['shipping_country'] ?? '', $r['created_at']
                ]);
            }
            break;

        case 'customers':
            csv_safe_row($out, ['ID', 'First Name', 'Last Name', 'Email', 'Role', 'Verified', 'Orders', 'Total Spent', 'Joined']);
            $rows = db()->query(
                'SELECT u.*,
                 (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS order_count,
                 (SELECT COALESCE(SUM(o.total),0) FROM orders o WHERE o.user_id = u.id AND o.status NOT IN ("cancelled","refunded")) AS total_spent
                 FROM users u ORDER BY u.created_at DESC'
            )->fetchAll();
            foreach ($rows as $r) {
                csv_safe_row($out, [
                    $r['id'], $r['first_name'], $r['last_name'], $r['email'],
                    $r['role'], $r['email_verified'], $r['order_count'],
                    $r['total_spent'], $r['created_at']
                ]);
            }
            break;

        case 'newsletter':
            csv_safe_row($out, ['Email', 'Status', 'Source', 'Subscribed At']);
            $rows = db()->query('SELECT * FROM newsletter_subscribers ORDER BY subscribed_at DESC')->fetchAll();
            foreach ($rows as $r) {
                csv_safe_row($out, [$r['email'], $r['status'], $r['source'] ?? '', $r['subscribed_at']]);
            }
            break;

        default:
            csv_safe_row($out, ['Error: Unknown export type']);
    }

    fclose($out);
    exit;
}

// ── IMPORT PRODUCTS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'File upload failed.';
    } else {
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $error = 'Please upload a CSV file.';
        } else {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            $header = fgetcsv($handle); // Skip header row
            $imported = 0;
            $skipped  = 0;
            $rowNumber = 1;
            $_SESSION['skip_log'] = [];
            $map = array_flip($header);
            $isShopify = isset($map['Handle']) && isset($map['Title']);
            $currentOptions = []; // Store Option Names for the current Handle

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if ($isShopify) {
                    $get = function($col) use ($row, $map) {
                        return isset($map[$col], $row[$map[$col]]) ? trim($row[$map[$col]]) : '';
                    };

                    $handleVal = $get('Handle');
                    $title = $get('Title');
                    
                    if (!$handleVal) {
                        $skipped++;
                        continue;
                    }

                    // Update option names if this is a main row
                    if ($title) {
                        $currentOptions = [];
                        for ($i=1; $i<=3; $i++) {
                            $optName = $get("Option$i Name");
                            if ($optName) $currentOptions[$i] = strtolower($optName);
                        }
                    }

                    $name = $title;
                    $slug = $handleVal;
                    $category = $get('Product Category');
                    $price = $get('Variant Compare At Price');
                    $salePrice = $get('Variant Price');
                    
                    if (!$price && $salePrice) {
                        $price = $salePrice;
                        $salePrice = null;
                    }

                    $price = (float)$price;
                    $salePrice = (float)$salePrice > 0 ? (float)$salePrice : null;
                    
                    $sku = $get('Variant SKU');
                    if (strpos($sku, "'") === 0) $sku = substr($sku, 1); // remove leading quote from excel

                    $stock = (int)$get('Variant Inventory Qty');
                    $isFeatured = 0;
                    $description = $get('Body (HTML)');
                    
                    $vSize = '';
                    $vColor = '';
                    for ($i=1; $i<=3; $i++) {
                        $optName = $currentOptions[$i] ?? '';
                        $optVal = $get("Option$i Value");
                        if (strpos($optName, 'size') !== false) $vSize = $optVal;
                        elseif (strpos($optName, 'color') !== false) $vColor = $optVal;
                    }
                    
                    $vColorHex = '';
                    $vPriceOver = $salePrice ? $salePrice : ($price ? $price : null);
                    // Image: if main row, 'Image Src' is product image. Variant image is 'Variant Image'.
                    $vImage = $get('Variant Image') ?: ($title ? '' : $get('Image Src'));
                    $productImage = $title ? $get('Image Src') : '';

                } else {
                    // Default Format
                    if (count($row) < 5) { 
                        $skipped++; 
                        $_SESSION['skip_log'][] = "Row $rowNumber skipped: Less than 5 columns.";
                        continue; 
                    }

                    $name       = trim($row[0] ?? '');
                    $slug       = trim($row[1] ?? '');
                    $category   = trim($row[2] ?? '');
                    $price      = (float)($row[3] ?? 0);
                    // Empty or 0 means "no sale" — storing 0.00 used to price items at 0.
                    $salePrice  = (float)trim($row[4] ?? '') > 0 ? (float)$row[4] : null;
                    $sku        = trim($row[5] ?? '');
                    $stock      = (int)($row[6] ?? 0);
                    $isFeatured = (int)($row[7] ?? 0);
                    $description= trim($row[8] ?? '');
                    $vSize      = trim($row[9] ?? '');
                    $vColor     = trim($row[10] ?? '');
                    $vColorHex  = trim($row[11] ?? '');
                    $vPriceOver = (float)trim($row[12] ?? '') > 0 ? (float)trim($row[12]) : null;
                    $vImage     = trim($row[13] ?? '');
                    $productImage = trim($row[13] ?? ''); // In old format it was the same column? Wait no, let's just let it be empty if it's a variant row.
                }

                if (!$name && !$slug) { 
                    $skipped++; 
                    $_SESSION['skip_log'][] = "Row $rowNumber skipped: Name and slug are both empty.";
                    continue; 
                }

                // Auto-slug
                if ($name && !$slug) {
                    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
                    $slug = trim($slug, '-');
                }

                $productId = null;

                if ($name) {
                    // Check duplicate slug
                    $exists = db()->prepare('SELECT id FROM products WHERE slug = ?');
                    $exists->execute([$slug]);
                    $existing = $exists->fetch();

                    if ($existing) {
                        $productId = $existing['id'];
                    } else {
                        // Find or skip category
                        $catId = null;
                        if ($category) {
                            // Extract just the last part of category if it's breadcrumbs (Shopify)
                            $catParts = explode('>', $category);
                            $cleanCat = trim(end($catParts));
                            
                            $catStmt = db()->prepare('SELECT id FROM categories WHERE name = ? OR slug = ?');
                            $catStmt->execute([$cleanCat, $cleanCat]);
                            $catRow = $catStmt->fetch();
                            $catId = $catRow ? $catRow['id'] : null;
                            
                            // Optionally create category if missing
                            if (!$catId && $cleanCat) {
                                $catSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $cleanCat));
                                db()->prepare('INSERT INTO categories (name, slug) VALUES (?,?)')->execute([$cleanCat, $catSlug]);
                                $catId = db()->lastInsertId();
                            }
                        }

                        db()->prepare(
                            'INSERT INTO products (category_id, name, slug, description, price, sale_price, sku, stock, is_featured, is_active, image)
                             VALUES (?,?,?,?,?,?,?,?,?,1,?)'
                        )->execute([$catId, $name, $slug, $description, $price, $salePrice, $sku, $stock, $isFeatured, $productImage]);
                        $productId = db()->lastInsertId();
                        $imported++;
                    }
                } else {
                    // Name is empty, find product by slug
                    $stmt = db()->prepare('SELECT id FROM products WHERE slug = ?');
                    $stmt->execute([$slug]);
                    $prod = $stmt->fetch();
                    if ($prod) {
                        $productId = $prod['id'];
                    } else {
                        $skipped++; 
                        $_SESSION['skip_log'][] = "Row $rowNumber skipped: Variant row for slug '{$slug}' but parent product not found in database.";
                        continue; // Product not found
                    }
                }

                // If variant info is present, insert variant
                if ($productId && ($vSize || $vColor || ($sku && !$name))) {
                    try {
                        $vSizeVal = $vSize !== '' ? $vSize : null;
                        $vColorVal = $vColor !== '' ? $vColor : null;
                        
                        $vExists = db()->prepare('SELECT id FROM product_variants WHERE product_id = ? AND size <=> ? AND color_name <=> ?');
                        $vExists->execute([$productId, $vSizeVal, $vColorVal]);
                        if (!$vExists->fetch()) {
                            db()->prepare(
                                'INSERT INTO product_variants (product_id, size, color_name, color_hex, sku, price_override, stock, sort_order, is_active, image) VALUES (?,?,?,?,?,?,?,0,1,?)'
                            )->execute([$productId, $vSizeVal, $vColorVal, $vColorHex !== '' ? $vColorHex : null, $sku !== '' ? $sku : null, $vPriceOver, $stock, $vImage !== '' ? $vImage : null]);
                        }
                        
                        // Mark product as having variants
                        db()->prepare('UPDATE products SET has_variants = 1 WHERE id = ?')->execute([$productId]);
                    } catch (Exception $e) {
                        error_log("Variant insertion error for product $productId: " . $e->getMessage());
                    }
                }
            }
            fclose($handle);

            // Variant products keep products.stock as the sum of their variants.
            db()->exec('UPDATE products p SET stock = (SELECT COALESCE(SUM(v.stock),0) FROM product_variants v WHERE v.product_id = p.id) WHERE p.has_variants = 1');
            
            $skipMsg = '';
            if (!empty($_SESSION['skip_log'])) {
                // Show first 10 skip reasons
                $skipMsg = "<br><small>Skip Details (first 10):<br>- " . implode("<br>- ", array_map('h', array_slice($_SESSION['skip_log'], 0, 10))) . "</small>";
            }
            $success = "Imported {$imported} products" . ($skipped ? ", skipped {$skipped} rows" : '') . '.' . $skipMsg;
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($success): ?><div class="admin-alert admin-alert--success"><?= $success ?></div><?php endif; ?>
<?php if ($error): ?><div class="admin-alert admin-alert--error"><?= h($error) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
    <!-- EXPORT -->
    <div style="background:var(--a-surface);border:1px solid var(--a-border);border-radius:var(--a-radius);padding:32px">
        <h2 style="font-size:1rem;font-weight:600;margin-bottom:4px">Export Data</h2>
        <p style="font-size:.8rem;color:var(--a-muted);margin-bottom:24px">Download your data as CSV files.</p>

        <div style="display:flex;flex-direction:column;gap:12px">
            <a href="<?= SITE_URL ?>/admin/export.php?export=products" class="admin-btn">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Export Products
            </a>
            <a href="<?= SITE_URL ?>/admin/export.php?export=orders" class="admin-btn">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Export Orders
            </a>
            <a href="<?= SITE_URL ?>/admin/export.php?export=customers" class="admin-btn">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Export Customers
            </a>
            <a href="<?= SITE_URL ?>/admin/export.php?export=newsletter" class="admin-btn">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Export Newsletter Subscribers
            </a>
        </div>
    </div>

    <!-- IMPORT -->
    <div style="background:var(--a-surface);border:1px solid var(--a-border);border-radius:var(--a-radius);padding:32px">
        <h2 style="font-size:1rem;font-weight:600;margin-bottom:4px">Import Products</h2>
        <p style="font-size:.8rem;color:var(--a-muted);margin-bottom:24px">Upload a CSV file to bulk-add products.</p>

        <form method="post" enctype="multipart/form-data" class="admin-form">
            <div class="admin-form__group">
                <label>CSV File</label>
                <input type="file" name="csv_file" accept=".csv" required>
            </div>
            <button type="submit" class="admin-btn admin-btn--primary">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Import Products
            </button>
        </form>

        <div style="margin-top:24px;padding-top:20px;border-top:1px solid var(--a-border)">
            <p style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--a-gold);margin-bottom:8px">CSV Format</p>
            <p style="font-size:.78rem;color:var(--a-muted);line-height:1.6">
                Columns: <code style="background:var(--a-bg);padding:2px 6px;border-radius:3px;font-size:.72rem">Name, Slug, Category, Price, Sale Price, SKU, Stock, Featured (0/1), Description, Variant Size, Variant Color, Variant Color Hex, Variant Price Override, Variant Image</code>
            </p>
            <p style="font-size:.72rem;color:var(--a-muted);margin-top:8px">First row should be headers (it's skipped). Category should match an existing category name or slug.</p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
