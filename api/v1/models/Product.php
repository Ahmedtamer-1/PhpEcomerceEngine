<?php
$slug = $_GET['slug'] ?? '';
require_once __DIR__ . '/includes/functions.php';
$product = get_product_by_slug($slug);
if (!$product) {
    http_response_code(404);
    $pageTitle = 'Product Not Found';
    require_once __DIR__ . '/includes/header.php';
    echo '<section class="lume-section container" style="text-align:center;padding:200px 0"><h1>Product Not Found</h1><p style="color:var(--muted);margin-top:16px">The product you\'re looking for doesn\'t exist.</p><a href="'.SITE_URL.'/shop.php" class="lume-btn" style="margin-top:32px">Back to Shop</a></section>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ── SEO meta ──────────────────────────────────────────────────────
$siteName        = setting('site_name', SITE_NAME);
$pageTitle       = !empty($product['meta_title'])
    ? h($product['meta_title'])
    : h($product['name']) . ' — ' . $siteName;
$pageDescription = !empty($product['meta_desc'])
    ? h($product['meta_desc'])
    : mb_substr(strip_tags($product['description'] ?? ''), 0, 160);
$pageKeywords    = h($product['name'])
    . (!empty($product['category_name']) ? ', ' . h($product['category_name']) : '')
    . ', ' . $siteName;
$ogType          = 'product';
$ogImage         = product_image($product); // product's own image
$canonicalUrl    = SITE_URL . '/product.php?slug=' . urlencode($product['slug']);
// ──────────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/header.php';

$hasVariants       = !empty($product['has_variants']);
$variants          = $hasVariants ? get_product_variants((int)$product['id']) : [];
$showStock         = setting('show_stock_indicator', '1') === '1';
$lowThreshold      = (int)setting('stock_low_threshold', '5');

// Unique sizes / colors
$totalVariantStock = 0;
$sizes  = [];
$colors = [];
$colorImages = [];
foreach ($variants as $v) {
    $totalVariantStock += (int)$v['stock'];
    if (!empty($v['size'])       && !in_array($v['size'], $sizes))           $sizes[] = $v['size'];
    if (!empty($v['color_name'])) {
        if (!isset($colors[$v['color_name']])) {
            $colors[$v['color_name']] = $v['color_hex'] ?? '#888';
        }
        if (!empty($v['image']) && empty($colorImages[$v['color_name']])) {
            $colorImages[$v['color_name']] = $v['image'];
        }
    }
}

$inStock      = $hasVariants ? $totalVariantStock > 0 : (int)$product['stock'] > 0;
// Pre-order flag. `?? 0` so the page still renders if the column has not been
// added yet (it is created automatically on the first admin product save).
$preorderEnabled = !empty($product['preorder_enabled'] ?? 0);
$stockDisplay = $hasVariants ? $totalVariantStock    : (int)$product['stock'];

// ── Build gallery data per color ──────────────────────────────────────────────
$colorGalleries = json_decode($product['color_galleries'] ?? 'null', true) ?: [];
$hasColorGalleries = !empty($colorGalleries);

// Per-color image arrays (this is the primary gallery source)
$colorImageArrays = [];
if ($hasColorGalleries) {
    // Use explicitly set color galleries — include ALL images per color
    foreach ($colorGalleries as $cName => $imgs) {
        if (is_array($imgs) && !empty($imgs)) {
            $colorImageArrays[$cName] = array_values($imgs);
        }
    }
}
// Also merge in variant images for colors that don't yet have gallery entries
foreach ($variants as $v) {
    if (!empty($v['image']) && !empty($v['color_name'])) {
        if (!isset($colorImageArrays[$v['color_name']])) {
            $colorImageArrays[$v['color_name']] = [];
        }
        if (!in_array($v['image'], $colorImageArrays[$v['color_name']])) {
            $colorImageArrays[$v['color_name']][] = $v['image'];
        }
    }
}

// Determine first color to auto-select
$firstColor = !empty($colors) ? array_key_first($colors) : null;

// General images: if product has color variants, use the first color's gallery
// instead of the generic product gallery (which may contain unrelated images)
$generalImages = [];
if ($firstColor && !empty($colorImageArrays[$firstColor])) {
    $generalImages = $colorImageArrays[$firstColor];
} else {
    // No colors: use product's gallery images. 
    // The cover image is strictly for thumbnails/listings, UNLESS there are no gallery images at all.
    $mainSrc = product_image($product);
    $extraImages = json_decode($product['gallery'] ?? 'null', true) ?: [];
    
    if (empty($extraImages)) {
        // Fallback to cover image only if gallery is completely empty to prevent broken UI
        $generalImages[] = $mainSrc;
    } else {
        foreach ($extraImages as $img) {
            if ($img && $img !== $mainSrc) {
                $generalImages[] = $img;
            }
        }
    }
}

// Active gallery: start with first color's images if available, else general
$activeImages = $generalImages;
if ($firstColor && !empty($colorImageArrays[$firstColor])) {
    $activeImages = $colorImageArrays[$firstColor];
}

// Size chart
$sizeChart = $product['size_chart'] ?? null;
?>

<!-- BREADCRUMB -->
<section class="lume-page-header">
    <div class="container">
        <p class="lume-page-header__breadcrumb">
            <a href="<?= SITE_URL ?>/">Home</a> /
            <a href="<?= SITE_URL ?>/shop.php">Shop</a>
            <?php if (!empty($product['category_name'])): ?>
            / <a href="<?= SITE_URL ?>/shop.php?category=<?= h($product['category_slug']) ?>"><?= h($product['category_name']) ?></a>
            <?php endif; ?>
            / <?= h($product['name']) ?>
        </p>
    </div>
</section>

<!-- PRODUCT SECTION -->
<section class="container">
    <div class="lume-product-single">

        <!-- ── GALLERY ── -->
        <div class="lume-gallery lume-reveal-left">

            <div class="lume-gallery__stage">
                <button class="lume-gallery__arrow lume-gallery__arrow--prev" id="gallery-prev" aria-label="Previous image">&#8249;</button>

                <div class="lume-gallery__main-wrap" id="gallery-main-wrap">
                    <img class="lume-gallery__main" id="gallery-main-img"
                         src="<?= asset_url(h($activeImages[0])) ?>"
                         alt="<?= h($product['name']) ?>">
                    <!-- Zoom hint -->
                    <div class="lume-gallery__zoom-hint" id="zoom-hint">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                    </div>
                </div>

                <button class="lume-gallery__arrow lume-gallery__arrow--next" id="gallery-next" aria-label="Next image">&#8250;</button>
            </div>

            <div class="lume-gallery__thumbs" id="gallery-thumbs">
                <?php foreach ($activeImages as $idx => $img): ?>
                <button class="lume-gallery__thumb <?= $idx === 0 ? 'active' : '' ?>"
                        data-index="<?= $idx ?>"
                        aria-label="View image <?= $idx + 1 ?>">
                    <img src="<?= asset_url(h($img)) ?>" alt="" loading="lazy">
                </button>
                <?php endforeach; ?>
            </div>

        </div><!-- /.lume-gallery -->

        <!-- ── INFO ── -->
        <div class="lume-product-single__info lume-reveal-right">

            <?php if (!empty($product['category_name'])): ?>
            <p class="lume-product-single__cat"><?= h($product['category_name']) ?></p>
            <?php endif; ?>

            <h1><?= h($product['name']) ?></h1>

            <div class="lume-product-single__price" id="product-price-display">
                <?= product_price($product) ?>
            </div>

            <div class="lume-product-single__desc"><?= nl2br(h($product['description'] ?? '')) ?></div>

            <?php if ($hasVariants && !empty($colors)): ?>
            <!-- Color Selector -->
            <div class="lume-variant-section">
                <label class="lume-variant-section__label">Color: <span id="selected-color-label"><?= $firstColor ? h($firstColor) : 'Select a color' ?></span></label>
                <div class="lume-color-options" id="color-options">
                    <?php foreach ($colors as $name => $hex): ?>
                    <button type="button" class="lume-color-swatch <?= $name === $firstColor ? 'is-active' : '' ?>" 
                            data-color="<?= h($name) ?>" 
                            data-hex="<?= h($hex) ?>" 
                            data-image="<?= h($colorImages[$name] ?? '') ?>"
                            aria-label="<?= h($name) ?>" title="<?= h($name) ?>">
                        <span class="lume-color-swatch__inner" style="<?= !empty($colorImages[$name]) ? 'background-image:url('.asset_url(h($colorImages[$name])).');background-size:cover;background-position:center' : 'background:'.h($hex) ?>"></span>
                        <span class="lume-color-swatch__tooltip"><?= h($name) ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($hasVariants && !empty($sizes)): ?>
            <!-- Size Swatches -->
            <div class="lume-variant-section">
                <label class="lume-variant-section__label">Size: <span id="selected-size-label">Select a size</span></label>
                <div class="lume-size-swatches" id="size-swatches">
                    <?php foreach ($sizes as $sz): ?>
                    <button type="button" class="lume-size-swatch" data-size="<?= h($sz) ?>" title="<?= h($sz) ?>">
                        <?= h($sz) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($sizeChart): ?>
            <!-- Size Chart Button -->
            <button type="button" class="lume-sizechart-btn" id="btn-size-chart">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 3H3v18h18V3z"/><path d="M7 3v4M12 3v6M17 3v4"/></svg>
                Size Chart
            </button>
            <?php endif; ?>

            <?php if ($hasVariants): ?>
            <div class="lume-variant-msg" id="variant-msg">Please select your options above</div>
            <?php endif; ?>

            <?php if ($inStock): ?>
            <div class="lume-product-single__qty">
                <button class="lume-qty-btn" data-dir="minus">&#8722;</button>
                <span class="lume-qty-val" id="qty-val" data-max="<?= $stockDisplay ?>">1</span>
                <button class="lume-qty-btn" data-dir="plus">+</button>
            </div>
            <button class="lume-btn lume-btn--full lume-btn--solid" id="btn-add-single"
                    <?= $hasVariants ? 'disabled style="opacity:.4;cursor:not-allowed"' : '' ?>
                    data-product-id="<?= (int)$product['id'] ?>"
                    data-product-name="<?= h($product['name']) ?>"
                    data-product-price="<?= (float)($product['sale_price'] ?: $product['price'] ?? 0) ?>"
                    <?php if (!$hasVariants): ?>onclick="addToCart(<?= (int)$product['id'] ?>, parseInt(document.getElementById('qty-val')?.textContent||'1'), null, {name:this.dataset.productName, price:parseFloat(this.dataset.productPrice)}); return false;"<?php endif; ?>>
                Add to Bag
            </button>
            <?php if ($showStock): ?>
            <p class="lume-product-single__stock in-stock" id="stock-info">
                <?php if (!$hasVariants): ?>
                    &#10003; In stock
                <?php endif; ?>
            </p>
            <?php else: ?>
            <p class="lume-product-single__stock" id="stock-info" style="display:none"></p>
            <?php endif; ?>
            <?php elseif ($preorderEnabled): ?>
            <?php /* Out of stock, but this product is flagged for pre-order. */ ?>
            <div class="lume-product-single__qty">
                <button class="lume-qty-btn" data-dir="minus">&#8722;</button>
                <span class="lume-qty-val" id="qty-val" data-max="99">1</span>
                <button class="lume-qty-btn" data-dir="plus">+</button>
            </div>
            <button class="lume-btn lume-btn--full lume-btn--solid" id="btn-add-single"
                    <?= $hasVariants ? 'disabled style="opacity:.4;cursor:not-allowed"' : '' ?>
                    data-product-id="<?= (int)$product['id'] ?>"
                    data-product-name="<?= h($product['name']) ?>"
                    data-product-price="<?= (float)($product['sale_price'] ?: $product['price'] ?? 0) ?>"
                    <?php if (!$hasVariants): ?>onclick="addToCart(<?= (int)$product['id'] ?>, parseInt(document.getElementById('qty-val')?.textContent||'1'), null, {name:this.dataset.productName, price:parseFloat(this.dataset.productPrice)}); return false;"<?php endif; ?>>
                Pre-order
            </button>
            <p class="lume-product-single__stock" style="color:var(--gold)">Available to pre-order &mdash; ships when back in stock.</p>
            <?php else: ?>
            <button class="lume-btn lume-btn--full" disabled style="opacity:.4;cursor:not-allowed">Sold Out</button>
            <p class="lume-product-single__stock out-of-stock">&#10005; Currently out of stock</p>
            <?php endif; ?>

            <?php if (!empty($product['sku']) && !$hasVariants): ?>
            <p class="lume-product-single__sku">SKU: <?= h($product['sku']) ?></p>
            <?php endif; ?>
            <p class="lume-product-single__sku" id="variant-sku" style="display:none"></p>

            <?php if (!empty($product['material']) || !empty($product['gem'])): ?>
            <div class="lume-product-single__details">
                <?php if (!empty($product['material'])): ?>
                <div class="lume-product-detail">
                    <span class="lume-product-detail__label">Material</span>
                    <span class="lume-product-detail__value"><?= h($product['material']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($product['gem'])): ?>
                <div class="lume-product-detail">
                    <span class="lume-product-detail__label">Gem / Stone</span>
                    <span class="lume-product-detail__value"><?= h($product['gem']) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div><!-- /.lume-product-single__info -->
    </div>
</section>

<?php
// Fetch recommendations
$recommendations = get_products([
    'category_id' => $product['category_id'],
    'exclude_id'  => $product['id'],
    'limit'       => 4
]);
?>
<?php if (!empty($recommendations)): ?>
<section class="lume-section container" style="padding-top: 0; margin-top: 64px;">
    <h2 class="lume-section__title" style="margin-bottom: 32px; font-size: clamp(1.2rem, 2vw, 1.5rem);">You Might Also Like</h2>
    <div class="lume-products">
        <?php foreach ($recommendations as $rec): ?>
        <div class="lume-product-card lume-reveal">
            <a href="<?= SITE_URL ?>/product.php?slug=<?= h($rec['slug']) ?>" class="lume-product-card__img-wrap">
                <img src="<?= product_image($rec) ?>" alt="<?= h($rec['name']) ?>" class="lume-product-card__img" loading="lazy">
                <?php if (!empty($rec['sale_price'])): ?>
                <span class="lume-product-card__badge">Sale</span>
                <?php endif; ?>
            </a>
            <div class="lume-product-card__body">
                <h3 class="lume-product-card__name"><a href="<?= SITE_URL ?>/product.php?slug=<?= h($rec['slug']) ?>"><?= h($rec['name']) ?></a></h3>
                <div class="lume-product-card__price"><?= product_price($rec) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- Lightbox -->
<div class="lume-lightbox" id="lume-lightbox">
    <button class="lume-lightbox__close" id="lightbox-close" aria-label="Close">✕</button>
    <button class="lume-lightbox__nav lume-lightbox__nav--prev" id="lightbox-prev" aria-label="Previous">&#8249;</button>
    <img class="lume-lightbox__img" id="lightbox-img" src="" alt="">
    <button class="lume-lightbox__nav lume-lightbox__nav--next" id="lightbox-next" aria-label="Next">&#8250;</button>
    <div class="lume-lightbox__counter" id="lightbox-counter"></div>
</div>

<?php if ($sizeChart): ?>
<!-- Size Chart Modal -->
<div class="lume-sizechart-modal" id="sizechart-modal">
    <div class="lume-sizechart-modal__backdrop" id="sizechart-backdrop"></div>
    <div class="lume-sizechart-modal__content">
        <div class="lume-sizechart-modal__header">
            <h3>Size Chart</h3>
            <button class="lume-sizechart-modal__close" id="sizechart-close" aria-label="Close">✕</button>
        </div>
        <div class="lume-sizechart-modal__body">
            <img src="<?= asset_url(h($sizeChart)) ?>" alt="Size Chart for <?= h($product['name']) ?>">
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Gallery data for JS -->
<script id="gallery-data" type="application/json"><?= json_encode([
    'general'   => $generalImages,
    'colors'    => $colorImageArrays,
    'firstColor' => $firstColor,
    'hasColorGalleries' => $hasColorGalleries || !empty($colorImageArrays),
], JSON_UNESCAPED_UNICODE) ?></script>

<?php if ($hasVariants && !empty($variants)): ?>
<!-- Variant data for JS -->
<script id="variant-data" type="application/json"><?= json_encode(array_map(function($v) {
    return [
        'id'         => (int)$v['id'],
        'size'       => $v['size'],
        'color_name' => $v['color_name'],
        'color_hex'  => $v['color_hex'],
        'sku'        => $v['sku'],
        'price'      => $v['price_override'] !== null ? (float)$v['price_override'] : null,
        'stock'      => (int)$v['stock'],
        'image'      => $v['image'] ?? null,
    ];
}, $variants), JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>

<!-- Product config for JS -->
<script id="product-config" type="application/json"><?= json_encode([
    'showStock'    => $showStock,
    'lowThreshold' => $lowThreshold,
    'productName'  => $product['name'],
    'productPrice' => (float)($product['sale_price'] ?: $product['price'] ?? 0),
    'productId'    => (int)$product['id'],
    'preorder'     => $preorderEnabled,
], JSON_UNESCAPED_UNICODE) ?></script>

<!-- JSON-LD Structured Data — Schema.org Product -->
<script type="application/ld+json"><?= json_encode([
    '@context'    => 'https://schema.org',
    '@type'       => 'Product',
    'name'        => $product['name'],
    'description' => strip_tags($product['description'] ?? ''),
    'image'       => product_image($product),
    'url'         => SITE_URL . '/product.php?slug=' . urlencode($product['slug']),
    'sku'         => $product['sku'] ?? '',
    'brand'       => [
        '@type' => 'Brand',
        'name'  => setting('site_name', SITE_NAME),
    ],
    'offers' => [
        '@type'         => 'Offer',
        'url'           => SITE_URL . '/product.php?slug=' . urlencode($product['slug']),
        'priceCurrency' => setting('currency', 'EGP'),
        'price'         => number_format((float)($product['sale_price'] ?: $product['price'] ?? 0), 2, '.', ''),
        'availability'  => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
        'seller'        => [
            '@type' => 'Organization',
            'name'  => setting('site_name', SITE_NAME),
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>