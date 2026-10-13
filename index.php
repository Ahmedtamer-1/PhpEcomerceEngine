<?php
require_once __DIR__ . '/includes/functions.php';
$pageTitle = setting('site_name', SITE_NAME) . ' — ' . setting('site_tagline', SITE_TAGLINE);
$pageDescription = setting('default_meta_description', 'Discover ' . setting('site_name', SITE_NAME) . ' — a luxury fashion brand.');
// Load dynamic homepage sections from DB — before the header, so the hero
// image can be preloaded in <head> (it is the largest thing above the fold).
$sections = [];
try {
    $sections = get_homepage_sections(true);
} catch (Exception $e) {
    // Fallback: table may not exist yet
    $sections = [];
}
$preloadImages = [];
foreach ($sections as $sec) {
    if (($sec['section_type'] ?? '') === 'hero') {
        $secSett = json_decode($sec['settings'] ?? '{}', true) ?: [];
        $heroImg = !empty($sec['image']) ? $sec['image'] : ($secSett['bg_image'] ?? 'assets/images/hero-bg.png');
        $preloadImages[] = thumb_url($heroImg, 1920);
    }
    break; // only the first section is above the fold
}
require_once __DIR__ . '/includes/header.php';

$featured = get_featured_products(4);

// If no sections in DB, render defaults
if (empty($sections)) {
    $sections = [
        ['section_type' => 'hero',              'title' => SITE_NAME, 'subtitle' => SITE_TAGLINE, 'button_text' => 'Explore the Collection', 'button_url' => '/shop.php', 'settings' => '{"bg_image":"assets/images/hero-bg.png","show_particles":true}', 'image' => null, 'content' => null],
        ['section_type' => 'featured_products', 'title' => 'Featured Products', 'subtitle' => 'Our most-loved pieces, hand-picked by our editors.', 'button_text' => 'View All Products', 'button_url' => '/shop.php', 'settings' => '{"eyebrow":"Curated for You","product_count":4}', 'image' => null, 'content' => null],
        ['section_type' => 'brand_story',       'title' => 'Born from Light', 'subtitle' => null, 'content' => "LUMEEGY was born from a simple belief — that style is a ritual, not a routine. Rooted in the spirit of Egyptian elegance, each piece is crafted to bring a moment of luxury into your everyday.\n\nFrom our signature silhouettes to carefully selected fabrics, every detail is designed to illuminate — your look, your confidence, your spirit.", 'button_text' => 'Read Our Story', 'button_url' => '/about.php', 'settings' => '{"eyebrow":"Our Story","image":"assets/images/hero-bg.png"}', 'image' => null],
    ];
}

require __DIR__ . '/includes/render-sections.php';
?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
