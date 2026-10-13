<?php
// ─── Input sanitisation ───
require_once __DIR__ . '/includes/functions.php';
// Sanitise all query params at the top of the file before any DB calls
$categorySlug = trim(preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['category'] ?? ''))) ?: null;
$search       = mb_substr(trim(strip_tags($_GET['q'] ?? '')), 0, 120) ?: null;
$sort         = in_array($_GET['sort'] ?? '', ['featured','newest','price_asc','price_desc','name_asc']) ? $_GET['sort'] : 'featured';
$sizeFilter   = trim(strip_tags($_GET['size'] ?? '')) ?: null;
$stockStatus  = in_array($_GET['stock'] ?? '', ['in_stock','out_of_stock']) ? $_GET['stock'] : null;
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 24; // products per page
$offset       = ($page - 1) * $perPage;
$siteName = setting('site_name', SITE_NAME);
/** Does products.preorder_enabled exist yet? (older installs lack it) */
function shop_has_preorder(): bool
{
    static $has = null;
    if ($has === null) {
        try { $has = (bool) db()->query("SHOW COLUMNS FROM products LIKE 'preorder_enabled'")->fetch(); }
        catch (Exception $e) { $has = false; }
    }
    return $has;
}
/**
 * One page of the mixed "Featured" order.
 *
 * Each category (in the order set in Admin -> Categories) is a queue of its
 * products, newest first. The page is built from full rows taken in turn:
 * 4 from the first category, 4 from the next, ... then round again. A
 * category with fewer than a row left (e.g. a single necklace) waits until
 * the end, so it never leaves a half-filled row in the middle of the page.
 * 4 per row matches the desktop grid; on 2-column screens a turn is 2 rows.
 * The order is fixed (not random), so pages never repeat or skip a product.
 */
function shop_mixed_page(string $whereClause, array $params, int $limit, int $offset): array
{
    $row = 4;
    $stmt = db()->prepare(
        "SELECT p.id, p.category_id FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE $whereClause
         ORDER BY COALESCE(c.sort_order, 9999) ASC, c.name ASC, p.created_at DESC, p.id DESC"
    );
    $stmt->execute($params);
    $queues = [];
    foreach ($stmt->fetchAll() as $r) {
        $queues[(int)($r['category_id'] ?? 0)][] = (int)$r['id'];
    }
    $ordered = [];
    $leftovers = [];
    while ($queues) {
        foreach (array_keys($queues) as $cat) {
            if (count($queues[$cat]) >= $row) {
                array_push($ordered, ...array_splice($queues[$cat], 0, $row));
            } else {
                array_push($leftovers, ...$queues[$cat]);
                unset($queues[$cat]);
            }
        }
    }
    $ids = array_slice(array_merge($ordered, $leftovers), $offset, $limit);
    if (!$ids) return [];

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT p.*, c.name AS category_name, c.slug AS category_slug
         FROM products p LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.id IN ($ph)"
    );
    $stmt->execute($ids);
    $byId = [];
    foreach ($stmt->fetchAll() as $p) $byId[(int)$p['id']] = $p;
    $out = [];
    foreach ($ids as $id) if (isset($byId[$id])) $out[] = $byId[$id];
    return $out;
}
// ─── Data fetching ───
$where = ['p.is_active = 1'];
$params = [];
if ($categorySlug) {
    $where[] = 'c.slug = ?';
    $params[] = $categorySlug;
}
if ($search) {
    $where[] = '(p.name LIKE ? OR p.description LIKE ?)';
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
}
if ($stockStatus === 'in_stock') {
    $where[] = 'p.stock > 0';
} elseif ($stockStatus === 'out_of_stock') {
    $where[] = 'p.stock <= 0';
}
if ($sizeFilter) {
    // Only pieces that can actually be bought in that size: the size is in
    // stock, or the piece takes pre-orders. It used to match sold-out sizes
    // too, so "size 10" listed 29 rings none of which came in size 10.
    $where[] = 'EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.id AND pv.size = ? AND pv.is_active = 1'
             . ' AND (pv.stock > 0' . (shop_has_preorder() ? ' OR p.preorder_enabled = 1' : '') . '))';
    $params[] = $sizeFilter;
}
$whereClause = implode(' AND ', $where);
// Count total products for pagination
$countSql = "SELECT COUNT(p.id) FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE $whereClause";
$stmt = db()->prepare($countSql);
$stmt->execute($params);
$totalProducts = (int) $stmt->fetchColumn();
$totalPages = max(1, ceil($totalProducts / $perPage));
// Guard pagination
if ($page > $totalPages && $totalProducts > 0) {
    $q = $_GET;
    $q['page'] = 1;
    redirect('/shop.php?' . http_build_query($q));
}
// "Featured" (the default) mixes the categories on the plain shop page — a
// row of bracelets, a row of rings, a row of earrings, and so on — instead of
// whatever was added last (often a whole page of one category). As soon as a
// filter or search is applied it falls back to newest-first, so filtering
// behaves exactly as before.
$isFiltered = $categorySlug || $search || $sizeFilter || $stockStatus;
$mixedOrder = ($sort === 'featured' && !$isFiltered);
// Order map
$orderMap = [
    'featured'   => 'p.created_at DESC',
    'newest'     => 'p.created_at DESC',
    'price_asc'  => 'COALESCE(NULLIF(p.sale_price, 0), p.price) ASC',
    'price_desc' => 'COALESCE(NULLIF(p.sale_price, 0), p.price) DESC',
    'name_asc'   => 'p.name ASC',
];
$orderBy = $orderMap[$sort];
// Fetch products
$sql = "SELECT p.*, c.name AS category_name, c.slug AS category_slug 
        FROM products p 
        LEFT JOIN categories c ON c.id = p.category_id 
        WHERE $whereClause 
        ORDER BY $orderBy 
        LIMIT $perPage OFFSET $offset";
if ($mixedOrder) {
    $products = shop_mixed_page($whereClause, $params, $perPage, $offset);
} else {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
}
$productIds = array_column($products, 'id');
// Fetch variant indicators
$variantProducts = [];
if (!empty($productIds)) {
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = db()->prepare("SELECT id, has_variants FROM products WHERE id IN ($placeholders)");
    $stmt->execute($productIds);
    foreach ($stmt->fetchAll() as $row) {
        if (!empty($row['has_variants'])) {
            $variantProducts[(int)$row['id']] = true;
        }
    }
}
// Fetch colors
$productColors = get_product_color_swatches($productIds);
// Fetch categories for filter bar with count
$catStmt = db()->query("
    SELECT c.id, c.name, c.slug, c.sort_order, COUNT(p.id) as p_count 
    FROM categories c 
    LEFT JOIN products p ON p.category_id = c.id AND p.is_active = 1 
    GROUP BY c.id, c.name, c.slug, c.sort_order
    ORDER BY c.sort_order ASC, c.name ASC
");
$rawCategories = $catStmt->fetchAll();
$currentCat = null;
foreach ($rawCategories as $cat) {
    if ($categorySlug && strtolower($cat['slug']) === $categorySlug) { $currentCat = $cat; break; }
}
$categories = [];
$seenCats = [];
foreach ($rawCategories as $cat) {
    if (!isset($seenCats[$cat['name']])) {
        $categories[] = $cat;
        $seenCats[$cat['name']] = true;
    }
}
// Fetch all available sizes for filter dropdown
// Only sizes someone can buy right now, in the category being viewed (ring sizes on Rings,
// bracelet sizes on Bracelets), sorted as numbers: 6, 7 … 10, not 10, 11, 6.
$sizeSql = "SELECT DISTINCT pv.size FROM product_variants pv
            JOIN products p ON p.id = pv.product_id AND p.is_active = 1
            WHERE pv.size IS NOT NULL AND pv.size != '' AND pv.is_active = 1
              AND (pv.stock > 0" . (shop_has_preorder() ? ' OR p.preorder_enabled = 1' : '') . ")"
         . ($currentCat ? ' AND p.category_id = ' . (int)$currentCat['id'] : '');
$allSizes = db()->query($sizeSql)->fetchAll(PDO::FETCH_COLUMN);
usort($allSizes, function ($a, $b) {
    $na = is_numeric($a); $nb = is_numeric($b);
    if ($na && $nb) return (float)$a <=> (float)$b;
    return $na ? -1 : ($nb ? 1 : strnatcasecmp($a, $b)); // words like "ALL" after numbers
});
// A size picked on another category shouldn't hide everything here.
if ($sizeFilter && !in_array($sizeFilter, $allSizes, true) && $currentCat) $allSizes[] = $sizeFilter;
// ─── Image resolution helper ───
$displayImages = [];
$hoverImages = [];
foreach ($products as $p) {
    $pid = (int)$p['id'];
    $mainImg = product_image($p);
    $hasValidMain = !empty($p['image']) && $p['image'] !== 'assets/images/placeholder.jpg';
    
    $colorGals = json_decode($p['color_galleries'] ?? 'null', true) ?: [];
    $firstColorImg = null;
    $secondColorImg = null;
    
    foreach ($colorGals as $cName => $imgs) {
        if (is_array($imgs) && !empty($imgs)) {
            if (!$firstColorImg) $firstColorImg = $imgs[0];
            if (count($imgs) > 1 && !$secondColorImg) $secondColorImg = $imgs[1];
            break;
        }
    }
    
    if (!$firstColorImg && !empty($productColors[$pid])) {
        foreach ($productColors[$pid] as $cn => $cData) {
            if (!empty($cData['image'])) {
                $firstColorImg = $cData['image'];
                break;
            }
        }
    }
    
    if ($hasValidMain) {
        $displayImages[$pid] = $mainImg;
    } elseif ($firstColorImg) {
        $displayImages[$pid] = $firstColorImg;
    } else {
        $displayImages[$pid] = $mainImg;
    }
    
    $gallery = json_decode($p['gallery'] ?? 'null', true) ?: [];
    foreach ($gallery as $gImg) {
        if ($gImg && $gImg !== $displayImages[$pid]) {
            $hoverImages[$pid] = $gImg;
            break;
        }
    }
    
    if (!isset($hoverImages[$pid])) {
        if ($secondColorImg && $secondColorImg !== $displayImages[$pid]) {
            $hoverImages[$pid] = $secondColorImg;
        } elseif ($firstColorImg && $firstColorImg !== $displayImages[$pid]) {
            $hoverImages[$pid] = $firstColorImg;
        } elseif (!empty($productColors[$pid])) {
            foreach ($productColors[$pid] as $cn => $cData) {
                if (!empty($cData['image']) && $cData['image'] !== $displayImages[$pid]) {
                    $hoverImages[$pid] = $cData['image'];
                    break;
                }
            }
        }
    }
}
// ─── URL builder helper ───
$shopUrl = function(int $p) use ($categorySlug, $search, $sort, $sizeFilter, $stockStatus): string {
    $params = [];
    if ($categorySlug) $params['category'] = $categorySlug;
    if ($search) $params['q'] = $search;
    if ($sort !== 'featured') $params['sort'] = $sort;
    if ($sizeFilter) $params['size'] = $sizeFilter;
    if ($stockStatus) $params['stock'] = $stockStatus;
    if ($p > 1) $params['page'] = $p;
    
    $qs = http_build_query($params);
    return SITE_URL . '/shop.php' . ($qs ? '?' . $qs : '');
};
// ─── SEO meta ───
$pageTitle       = ($search ? 'Search: ' . h($search) . ' — ' : '') . ($categorySlug ? ucfirst(str_replace('-',' ',$categorySlug)) . ' — ' : '') . 'Shop — ' . $siteName;
$pageDescription = 'Browse our full collection of luxury clothing, accessories, and fashion essentials.';
$pageKeywords    = 'shop, ' . $siteName . ', fashion, clothing, accessories';
$canonicalUrl    = SITE_URL . '/shop.php' . ($categorySlug ? '?category=' . urlencode($categorySlug) : '');
require_once __DIR__ . '/includes/header.php';
// Inject rel prev/next directly
if ($page > 1) {
    echo '<link rel="prev" href="' . h($shopUrl($page - 1)) . '">' . "\n";
}
if ($page < $totalPages) {
    echo '<link rel="next" href="' . h($shopUrl($page + 1)) . '">' . "\n";
}
?>
<!-- Page header / breadcrumb -->
<section class="lume-page-header">
    <div class="container">
        <h1 class="lume-page-header__title">Shop</h1>
        <p class="lume-page-header__breadcrumb">
            <a href="<?= SITE_URL ?>/">Home</a> / Shop
            <?php if ($categorySlug): ?> / <?= h($currentCat['name'] ?? ucfirst(str_replace('-',' ',$categorySlug))) ?><?php endif; ?>
        </p>
    </div>
</section>
<!-- Main shop section -->
<section class="lume-section container" id="shop-grid">
    <!-- Advanced Filter Form -->
    <div class="lume-shop-toolbar" style="margin-bottom: 2rem; background: var(--bg-card); padding: 1rem; border-radius: 8px; border: 1px solid var(--border);">
        <form method="get" id="filter-form" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: center; width: 100%;">
            <?php if ($search): ?>
                <input type="hidden" name="q" value="<?= h($search) ?>">
            <?php endif; ?>
            
            <div style="display: flex; flex-direction: column; flex: 1; min-width: 150px;">
                <label style="font-size: 0.85rem; color: var(--muted); margin-bottom: 0.25rem;">Category</label>
                <select name="category" onchange="this.form.submit()" style="padding: 0.5rem; border: 1px solid var(--border); background: var(--bg); color: inherit; border-radius: 4px;">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= h($cat['slug']) ?>" <?= $currentCat && (int)$currentCat['id'] === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= h($cat['name']) ?> (<?= (int)$cat['p_count'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display: flex; flex-direction: column; flex: 1; min-width: 150px;">
                <label style="font-size: 0.85rem; color: var(--muted); margin-bottom: 0.25rem;">Size</label>
                <select name="size" onchange="this.form.submit()" style="padding: 0.5rem; border: 1px solid var(--border); background: var(--bg); color: inherit; border-radius: 4px;">
                    <option value="">All Sizes</option>
                    <?php foreach ($allSizes as $sz): ?>
                        <option value="<?= h($sz) ?>" <?= $sizeFilter === $sz ? 'selected' : '' ?>><?= h(strtoupper($sz) === 'ALL' ? 'One size' : $sz) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display: flex; flex-direction: column; flex: 1; min-width: 150px;">
                <label style="font-size: 0.85rem; color: var(--muted); margin-bottom: 0.25rem;">Availability</label>
                <select name="stock" onchange="this.form.submit()" style="padding: 0.5rem; border: 1px solid var(--border); background: var(--bg); color: inherit; border-radius: 4px;">
                    <option value="">All</option>
                    <option value="in_stock" <?= $stockStatus === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                    <option value="out_of_stock" <?= $stockStatus === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
                </select>
            </div>
            <div style="display: flex; flex-direction: column; flex: 1; min-width: 150px;">
                <label style="font-size: 0.85rem; color: var(--muted); margin-bottom: 0.25rem;">Sort By</label>
                <select name="sort" onchange="this.form.submit()" style="padding: 0.5rem; border: 1px solid var(--border); background: var(--bg); color: inherit; border-radius: 4px;">
                    <option value="featured"   <?= $sort==='featured'   ? 'selected':'' ?>>Featured</option>
                    <option value="newest"     <?= $sort==='newest'     ? 'selected':'' ?>>Newest</option>
                    <option value="price_asc"  <?= $sort==='price_asc'  ? 'selected':'' ?>>Price: Low to High</option>
                    <option value="price_desc" <?= $sort==='price_desc' ? 'selected':'' ?>>Price: High to Low</option>
                    <option value="name_asc"   <?= $sort==='name_asc'   ? 'selected':'' ?>>Name: A–Z</option>
                </select>
            </div>
            
            <div style="display: flex; align-items: flex-end; padding-bottom: 2px;">
                <a href="<?= SITE_URL ?>/shop.php" style="padding: 0.5rem 1rem; color: var(--muted); text-decoration: underline; font-size: 0.9rem;">Clear</a>
            </div>
        </form>
    </div>
    <!-- Toolbar: result count -->
    <div style="margin-bottom: 2rem;">
        <p class="lume-shop-count" style="margin: 0; color: var(--muted);">
            Showing <strong><?= $totalProducts > 0 ? $offset + 1 : 0 ?>–<?= min($offset + $perPage, $totalProducts) ?></strong> of <strong><?= $totalProducts ?></strong> products<?= $search ? ' for "<em>'.h($search).'</em>"' : '' ?>
        </p>
    </div>
    <!-- Product grid OR empty state -->
    <?php if (empty($products)): ?>
        <div class="lume-empty-state" style="text-align: center; padding: 4rem 1rem; background: var(--bg-card); border-radius: 8px; border: 1px solid var(--border);">
            <svg style="width: 64px; height: 64px; margin-bottom: 1rem; color: var(--muted);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <h2 style="margin-bottom: 0.5rem; font-family: var(--font-serif);">No products found</h2>
            <p style="color: var(--muted); margin-bottom: 1.5rem;"><?= $search ? 'Try a different search term.' : 'Check back soon for new arrivals.' ?></p>
            <?php if ($search || $categorySlug): ?>
            <a href="<?= SITE_URL ?>/shop.php" class="lume-btn" style="display: inline-block; padding: 0.75rem 1.5rem; background: var(--gold); color: var(--bg); text-decoration: none; border-radius: 4px; font-weight: 600;">Browse All Products</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="lume-products">
            <?php $cardIndex = 0; foreach ($products as $p): $cardIndex++; ?>
            <?php $pid = (int)$p['id']; ?>
            <div class="lume-product-card lume-reveal" data-pid="<?= $pid ?>" style="display: flex; flex-direction: column;">
                <a href="<?= SITE_URL ?>/product.php?slug=<?= h($p['slug'] ?? '') ?>" class="lume-product-card__img-wrap" style="overflow: hidden;">
                    <img src="<?= h(thumb_url($displayImages[$pid] ?? ($p['image'] ?? ''), 600)) ?>" 
                         alt="<?= h($p['name'] ?? '') ?>" 
                         class="lume-product-card__img" 
                         <?= $cardIndex <= 4 ? 'loading="eager" fetchpriority="high"' : 'loading="lazy" decoding="async"' /* first row shows immediately */ ?>
                         <?php if (!empty($hoverImages[$pid])): ?>
                         data-hover-src="<?= h(thumb_url($hoverImages[$pid], 600)) ?>"
                         <?php endif; ?>>
                    <?php if ((int)$p['stock'] <= 0 && !empty($p['preorder_enabled'] ?? 0)): ?>
                    <span class="lume-product-card__badge lume-product-card__badge--preorder">Pre-order</span>
                    <?php elseif ((int)$p['stock'] <= 0): ?>
                    <span class="lume-product-card__badge lume-product-card__badge--sold-out">Sold Out</span>
                    <?php elseif (product_sale_price($p) !== null): ?>
                    <span class="lume-product-card__badge">Sale</span>
                    <?php endif; ?>
                    <?php if (!empty($productColors[$pid])): ?>
                    <div class="lume-product-card__swatches">
                        <?php foreach ($productColors[$pid] as $cn => $cData): ?>
                        <span class="lume-product-card__swatch-dot" 
                              style="background:<?= h($cData['hex'] ?? '') ?>" 
                              title="<?= h($cn ?? '') ?>"
                              <?php if (!empty($cData['image'])): ?>
                              data-image="<?= h(thumb_url($cData['image'] ?? '', 600)) ?>"
                              <?php endif; ?>></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </a>
                <div class="lume-product-card__body">
                    <h3 class="lume-product-card__name"><a href="<?= SITE_URL ?>/product.php?slug=<?= h($p['slug'] ?? '') ?>"><?= h($p['name'] ?? '') ?></a></h3>
                    <div class="lume-product-card__price"><?= product_price($p) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav class="lume-pagination" aria-label="Shop pages" style="margin-top: 3rem; display: flex; justify-content: center; gap: 0.5rem;">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="<?= h($shopUrl($i)) ?>" class="lume-pagination__btn <?= $i===$page ? 'active':'' ?>" <?= $i===$page ? 'aria-current="page"' : '' ?> style="display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border: 1px solid <?= $i===$page ? 'var(--gold)' : 'var(--border)' ?>; background: <?= $i===$page ? 'var(--gold)' : 'transparent' ?>; color: <?= $i===$page ? 'var(--bg)' : 'inherit' ?>; text-decoration: none; border-radius: 4px; transition: all 0.3s ease;"><?= $i ?></a>
            <?php endfor; ?>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
<script>
// ─── Color swatch → card image swap ───
document.querySelectorAll('.lume-product-card__swatch-dot').forEach(dot => {
  dot.addEventListener('mouseenter', () => {
    const img = dot.closest('.lume-product-card').querySelector('.lume-product-card__img');
    if (img && dot.dataset.image) {
      img.dataset.originalSrc = img.dataset.originalSrc || img.src;
      img.src = dot.dataset.image;
    }
  });
  dot.addEventListener('mouseleave', () => {
    const img = dot.closest('.lume-product-card').querySelector('.lume-product-card__img');
    if (img && img.dataset.originalSrc) img.src = img.dataset.originalSrc;
  });
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
