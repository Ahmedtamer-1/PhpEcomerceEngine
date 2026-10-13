<?php
/**
 * Product Feed for Meta Catalog
 *
 * Formats:
 * - ?format=csv    — Tab-separated (default, what Meta Catalog Manager wants)
 * - ?format=xml    — RSS 2.0 with Google namespace
 * - ?debug=1       — Plain-text report of every image URL and whether the file
 *                    actually exists on disk. Use this to verify the feed before
 *                    re-uploading to Meta.
 *
 * Usage:
 * - Meta Catalog Manager: Data Sources → Add → Scheduled feed
 *   https://yoursite.com/api/product-feed.php?format=csv
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Cache control — feed should be fresh but not hammered every second
header('Cache-Control: public, max-age=3600');

/**
 * Product image paths are stored relative to the web root and already contain
 * their own directory (e.g. "assets/images/products/media_x.png"), so anything
 * that prepends a directory produces a 404. That is exactly why Meta ingested
 * every product but could not fetch a single image. Same contract as
 * asset_url(): take the stored path as-is and only make it absolute.
 */
function feed_image_url(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') return '';
    if (preg_match('~^https?://~i', $path)) return $path;

    // Encode per segment so spaces/unicode in filenames survive without
    // turning the directory separators into %2F.
    $segments = array_map('rawurlencode', explode('/', ltrim($path, '/')));
    return SITE_URL . '/' . implode('/', $segments);
}

/**
 * Collects every usable image for a product: main image first (Meta uses that
 * as the primary), then gallery and per-colour gallery images as extras.
 * Both gallery columns are JSON and their shape differs (flat list vs
 * colour => [paths]), so walk them recursively rather than assuming either.
 */
function feed_product_images(array $p): array
{
    $paths = [];

    if (!empty($p['image'])) {
        $paths[] = $p['image'];
    }

    foreach (['gallery', 'color_galleries'] as $column) {
        if (empty($p[$column])) continue;
        $decoded = json_decode((string) $p[$column], true);
        if (!is_array($decoded)) continue;
        array_walk_recursive($decoded, function ($value) use (&$paths) {
            if (is_string($value) && trim($value) !== '') $paths[] = $value;
        });
    }

    $urls = [];
    foreach ($paths as $path) {
        // The placeholder is a "no image" marker, not a product photo — sending
        // it to Meta would show the same grey square on every item.
        if (stripos($path, 'placeholder') !== false) continue;

        $url = feed_image_url($path);
        if ($url !== '' && !in_array($url, $urls, true)) $urls[] = $url;
    }

    return $urls;
}

/**
 * Tabs and newlines are the field and record separators — one stray character
 * inside a description shifts every column after it, which Meta reports as a
 * malformed row rather than as bad data.
 */
/** in stock / preorder (out of stock but pre-order enabled) / out of stock */
function feed_availability(array $p): string
{
    if ((int)$p['stock'] > 0) return 'in stock';
    return !empty($p['preorder_enabled']) ? 'preorder' : 'out of stock';
}

function feed_tsv(?string $value): string
{
    return trim(preg_replace('/[\t\r\n]+/', ' ', (string) $value));
}

function feed_description(array $p): string
{
    $raw = $p['description'] ?: ($p['meta_desc'] ?: $p['name']);
    $text = trim(html_entity_decode(strip_tags((string) $raw), ENT_QUOTES, 'UTF-8'));
    if ($text === '') $text = (string) $p['name'];
    // Meta caps descriptions at 9,999 characters.
    return mb_substr($text, 0, 9999);
}

// p.* so the optional gallery columns come through on installs that have them
// without the feed erroring on installs that don't.
$stmt = db()->prepare('
    SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.is_active = 1
    ORDER BY p.id DESC
');
$stmt->execute();
$products = $stmt->fetchAll();

$format   = $_GET['format'] ?? 'csv';
$currency = setting('currency', CURRENCY_CODE);
$brand    = setting('site_name', SITE_NAME);

// ─────────────────────────────────────────────────────────────
// Debug report — verify image URLs resolve before uploading
// ─────────────────────────────────────────────────────────────
// The debug report lists stored file paths, so only admins may see it.
$feedUser = !empty($_GET['debug']) ? (function () { lume_session_start(); return current_user(); })() : null;
if (!empty($_GET['debug']) && ($feedUser['role'] ?? '') === 'admin') {
    header('Content-Type: text/plain; charset=utf-8');

    $missing = 0;
    $noImage = 0;

    echo "Product feed image check\n";
    echo "SITE_URL: " . SITE_URL . "\n";
    echo str_repeat('─', 60) . "\n\n";

    foreach ($products as $p) {
        $images = feed_product_images($p);
        echo "#{$p['id']}  {$p['name']}\n";
        echo "  stored: " . ($p['image'] ?: '(none)') . "\n";

        if (!$images) {
            $noImage++;
            echo "  ✗ NO IMAGE — Meta will reject this item\n\n";
            continue;
        }

        foreach ($images as $i => $url) {
            $isLocal = strpos($url, SITE_URL . '/') === 0;

            if ($isLocal) {
                $relative = urldecode(substr($url, strlen(SITE_URL) + 1));
                $onDisk   = file_exists(ROOT_PATH . '/' . $relative);
            } else {
                // Externally hosted — can't check the filesystem, assume it's live.
                $onDisk = true;
            }
            if (!$onDisk) $missing++;

            $label = $i === 0 ? 'main ' : 'extra';
            $mark  = $onDisk ? ($isLocal ? '✓' : '~') : '✗';
            echo "  {$mark} {$label} {$url}\n";
        }
        echo "\n";
    }

    echo str_repeat('─', 60) . "\n";
    echo 'Products: ' . count($products) . "\n";
    echo "Products with no image: {$noImage}\n";
    echo "Image URLs not found on disk: {$missing}\n";
    echo ($missing === 0 && $noImage === 0)
        ? "\nAll image URLs resolve. Safe to upload to Meta.\n"
        : "\nFix the ✗ rows above before uploading.\n";
    exit;
}

// ─────────────────────────────────────────────────────────────
// XML (RSS 2.0)
// ─────────────────────────────────────────────────────────────
if ($format === 'xml') {
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
    echo '  <channel>' . "\n";
    echo '    <title>' . htmlspecialchars($brand) . ' Product Catalog</title>' . "\n";
    echo '    <link>' . htmlspecialchars(SITE_URL) . '</link>' . "\n";
    echo '    <description>Product Catalog Feed</description>' . "\n";

    foreach ($products as $p) {
        $url    = SITE_URL . '/product.php?slug=' . urlencode($p['slug']);
        $images = feed_product_images($p);
        $hasSale = product_sale_price($p) !== null;

        echo '    <item>' . "\n";
        echo '      <g:id>' . htmlspecialchars($p['id']) . '</g:id>' . "\n";
        echo '      <g:title>' . htmlspecialchars($p['name']) . '</g:title>' . "\n";
        echo '      <g:description>' . htmlspecialchars(feed_description($p)) . '</g:description>' . "\n";
        echo '      <g:link>' . htmlspecialchars($url) . '</g:link>' . "\n";

        // image_link is required; additional_image_link is capped at 20 by Meta.
        if ($images) {
            echo '      <g:image_link>' . htmlspecialchars($images[0]) . '</g:image_link>' . "\n";
            foreach (array_slice($images, 1, 20) as $extra) {
                echo '      <g:additional_image_link>' . htmlspecialchars($extra) . '</g:additional_image_link>' . "\n";
            }
        }

        // RSS requires the currency inside the price value, not a separate field.
        echo '      <g:price>' . number_format((float) $p['price'], 2, '.', '') . ' ' . htmlspecialchars($currency) . '</g:price>' . "\n";
        if ($hasSale) {
            echo '      <g:sale_price>' . number_format((float) $p['sale_price'], 2, '.', '') . ' ' . htmlspecialchars($currency) . '</g:sale_price>' . "\n";
        }

        echo '      <g:availability>' . feed_availability($p) . '</g:availability>' . "\n";
        echo '      <g:condition>new</g:condition>' . "\n";
        echo '      <g:brand>' . htmlspecialchars($brand) . '</g:brand>' . "\n";

        if (!empty($p['sku'])) {
            echo '      <g:mpn>' . htmlspecialchars($p['sku']) . '</g:mpn>' . "\n";
        }
        if (!empty($p['category_name'])) {
            // product_type is the free-form store taxonomy. google_product_category
            // is deliberately omitted: it only accepts Google's own taxonomy IDs,
            // so a store category name there is silently discarded.
            echo '      <g:product_type>' . htmlspecialchars($p['category_name']) . '</g:product_type>' . "\n";
        }
        echo '    </item>' . "\n";
    }

    echo '  </channel>' . "\n";
    echo '</rss>';

// ─────────────────────────────────────────────────────────────
// Tab-separated (Meta's preferred format)
// ─────────────────────────────────────────────────────────────
} else {
    header('Content-Type: text/tab-separated-values; charset=utf-8');
    header('Content-Disposition: attachment; filename="product-feed.txt"');

    $headers = [
        'id', 'title', 'description', 'availability', 'condition',
        'price', 'sale_price', 'link', 'image_link', 'additional_image_link',
        'brand', 'mpn', 'product_type',
    ];
    echo implode("\t", $headers) . "\n";

    foreach ($products as $p) {
        $url     = SITE_URL . '/product.php?slug=' . urlencode($p['slug']);
        $images  = feed_product_images($p);
        $hasSale = product_sale_price($p) !== null;

        $row = [
            $p['id'],
            feed_tsv(mb_substr($p['name'], 0, 200)),
            feed_tsv(feed_description($p)),
            feed_availability($p),
            'new',
            // Currency belongs in the price value — relying on the catalog's
            // default currency instead is what makes prices import as 0.
            number_format((float) $p['price'], 2, '.', '') . ' ' . $currency,
            $hasSale ? number_format((float) $p['sale_price'], 2, '.', '') . ' ' . $currency : '',
            $url,
            $images[0] ?? '',
            implode(',', array_slice($images, 1, 20)),
            feed_tsv($brand),
            feed_tsv($p['sku'] ?? ''),
            feed_tsv($p['category_name'] ?? ''),
        ];

        echo implode("\t", $row) . "\n";
    }
}
