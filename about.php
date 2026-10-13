<?php
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'About — ' . setting('site_name', SITE_NAME);
$pageDescription = 'The story of ' . setting('site_name', SITE_NAME) . ' — a luxury fashion brand born in Egypt, crafted to illuminate your everyday style.';
require_once __DIR__ . '/includes/header.php';
?>
<?php
/* Section-built content.
   If sections exist for this page in Admin -> Builder, they render here and
   replace the static layout below. Until then the original page is shown
   unchanged, so adding the builder cannot break a live page. */
$sections = get_homepage_sections(true, 'about');
if ($sections):
    require __DIR__ . '/includes/render-sections.php';
else:
?>


<section class="lume-page-header">
    <div class="container">
        <h1 class="lume-page-header__title"><?= h(setting('page_about_header_title', 'Our Story')) ?></h1>
        <p class="lume-page-header__breadcrumb"><a href="<?= SITE_URL ?>/">Home</a> / About</p>
    </div>
</section>

<section class="container">
    <div class="lume-about-intro">
        <div class="lume-about-intro__img lume-reveal-left">
            <?php $aboutHero = setting('page_about_hero_image', 'assets/images/hero-bg.png'); ?>
            <img src="<?= h(thumb_url($aboutHero, 1200)) ?>" alt="LUMEEGY story" loading="lazy">
        </div>
        <div class="lume-about-intro__text lume-reveal-right">
            <p class="lume-section__eyebrow"><?= h(setting('page_about_eyebrow', 'The Beginning')) ?></p>
            <h2><?= h(setting('page_about_title', 'Born from Light')) ?></h2>
            <div class="lume-legal__body" style="color:var(--muted);font-size:.9rem;line-height:1.8;margin-bottom:16px">
                <?= nl2br(h(setting('page_about_text', "LUMEEGY was born from a simple belief — that style is a ritual, not a routine. Rooted in the spirit of Egyptian elegance, each piece is crafted to bring a moment of luxury into your everyday.\n\nWe source the finest fabrics and craft each piece with meticulous attention to detail. The result is a collection that honours heritage while delivering timeless, modern style.\n\nEvery texture, every scent, every detail is intentional. Because we believe the way you care for yourself says everything about who you are."))) ?>
            </div>
        </div>
    </div>
</section>

<section class="lume-section lume-section--center container lume-reveal">
    <p class="lume-section__eyebrow"><?= h(setting('page_about_values_eyebrow', 'Our Values')) ?></p>
    <h2 class="lume-section__title"><?= h(setting('page_about_values_title', 'What We Stand For')) ?></h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:40px;margin-top:48px;text-align:center">
        <div>
            <div style="font-size:2rem;margin-bottom:12px">✦</div>
            <h3 style="font-family:var(--font-serif);font-size:1.1rem;text-transform:uppercase;margin-bottom:8px"><?= h(setting('page_about_value_1_title', 'Heritage')) ?></h3>
            <p style="font-size:.85rem;color:var(--muted);line-height:1.7"><?= h(setting('page_about_value_1_text', 'Inspired by 5,000 years of Egyptian elegance, reimagined for the modern world.')) ?></p>
        </div>
        <div>
            <div style="font-size:2rem;margin-bottom:12px">✦</div>
            <h3 style="font-family:var(--font-serif);font-size:1.1rem;text-transform:uppercase;margin-bottom:8px"><?= h(setting('page_about_value_2_title', 'Luxury')) ?></h3>
            <p style="font-size:.85rem;color:var(--muted);line-height:1.7"><?= h(setting('page_about_value_2_text', 'Premium fabrics and tailoring that feel as extraordinary as they look.')) ?></p>
        </div>
        <div>
            <div style="font-size:2rem;margin-bottom:12px">✦</div>
            <h3 style="font-family:var(--font-serif);font-size:1.1rem;text-transform:uppercase;margin-bottom:8px"><?= h(setting('page_about_value_3_title', 'Intention')) ?></h3>
            <p style="font-size:.85rem;color:var(--muted);line-height:1.7"><?= h(setting('page_about_value_3_text', 'Every product is designed to transform your routine into a mindful, sensory ritual.')) ?></p>
        </div>
    </div>
</section>

<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
