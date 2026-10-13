<?php
/**
 * Shared page-section renderer.
 *
 * Renders whatever is in $sections (rows from `homepage_sections`). Extracted
 * verbatim out of index.php so ANY page can be built from the same section
 * types — the homepage is just the page whose `page` column is 'home'.
 *
 * Expects: $sections  array of section rows
 * Callers set it with get_homepage_sections(true, 'about') etc.
 */
if (!isset($sections) || !is_array($sections)) { $sections = []; }

foreach ($sections as $section):
    $sett = json_decode($section['settings'] ?? '{}', true) ?: [];
    
    $padStyle = '';
    if (isset($sett['padding_top']) && $sett['padding_top'] !== '') $padStyle .= 'padding-top:' . (int)$sett['padding_top'] . 'px;';
    if (isset($sett['padding_bottom']) && $sett['padding_bottom'] !== '') $padStyle .= 'padding-bottom:' . (int)$sett['padding_bottom'] . 'px;';

    switch ($section['section_type']):

        // ═══════════════════════════════════════
        // HERO
        // ═══════════════════════════════════════
        case 'hero':
            $bgImage = !empty($section['image']) ? $section['image'] : ($sett['bg_image'] ?? 'assets/images/hero-bg.png');
            $showParticles = isset($sett['show_particles']) ? filter_var($sett['show_particles'], FILTER_VALIDATE_BOOLEAN) : true;
            $textColor = $sett['text_color'] ?? '#ffffff';
            $btnColor = $sett['button_color'] ?? '#ffffff';
?>
<section class="lume-hero" id="hero" style="color: <?= h($textColor) ?>">
    <div class="lume-hero__bg"<?= bld('image', 'bg') ?> style="background-image:url('<?= h(thumb_url($bgImage, 1920)) ?>')"></div>
    <div class="lume-hero__overlay" style="opacity: <?= h($sett['overlay'] ?? '1') ?>;"></div>
    <?php if ($showParticles): ?>
    <div class="lume-hero__particles">
        <div class="lume-particle"></div><div class="lume-particle"></div><div class="lume-particle"></div>
        <div class="lume-particle"></div><div class="lume-particle"></div><div class="lume-particle"></div>
        <div class="lume-particle"></div><div class="lume-particle"></div>
    </div>
    <?php endif; ?>
    <div class="lume-hero__content">
        <h1 class="lume-hero__title"<?= bld('title') ?> style="color: <?= h($textColor) ?>"><?= h($section['title'] ?? SITE_NAME) ?></h1>
        <p class="lume-hero__subtitle"<?= bld('subtitle') ?> style="color: <?= h($textColor) ?>"><?= h($section['subtitle'] ?? SITE_TAGLINE) ?></p>
        <?php if (!empty($section['button_text'])): ?>
        <a href="<?= h(link_url($section['button_url'] ?? '', 'shop.php')) ?>" class="lume-hero__cta"<?= bld('button_text') ?> style="--btn-color: <?= h($btnColor) ?>; border-color: var(--btn-color); color: var(--btn-color);"><?= h($section['button_text']) ?></a>
        <?php endif; ?>
    </div>
</section>
<?php break;

        // ═══════════════════════════════════════
        // FEATURED PRODUCTS
        // ═══════════════════════════════════════
        case 'featured_products':
            $eyebrow = $sett['eyebrow'] ?? 'Curated for You';
            $count   = (int)($sett['product_count'] ?? 4);
            // "Only this category" shows that category's newest pieces
            // (the setting existed in the builder but was never applied).
            $featured = !empty($sett['category_id'])
                ? get_products(['category_id' => (int)$sett['category_id'], 'limit' => $count])
                : get_featured_products($count);
?>
<section class="lume-section lume-section--center container" id="featured" style="<?= $padStyle ?>">
    <p class="lume-section__eyebrow lume-reveal"<?= bld('settings.eyebrow') ?>><?= h($eyebrow) ?></p>
    <h2 class="lume-section__title lume-reveal"<?= bld('title') ?>><?= h($section['title'] ?? 'Featured Products') ?></h2>
    <div class="lume-divider lume-reveal" style="margin-bottom:16px"></div>
    <p class="lume-section__subtitle lume-reveal"<?= bld('subtitle') ?>><?= h($section['subtitle'] ?? '') ?></p>
    <div class="lume-products">
        <?php foreach ($featured as $p): ?>
        <div class="lume-product-card lume-reveal">
            <a href="<?= SITE_URL ?>/product.php?slug=<?= urlencode($p['slug']) ?>" class="lume-product-card__img-wrap">
                <img src="<?= h(product_image($p)) ?>" alt="<?= h($p['name']) ?>" class="lume-product-card__img" loading="lazy">
                <?php if (product_sale_price($p) !== null): ?>
                <span class="lume-product-card__badge">Sale</span>
                <?php endif; ?>
            </a>
            <div class="lume-product-card__body">
                <p class="lume-product-card__cat"><?= h($p['category_name'] ?? '') ?></p>
                <h3 class="lume-product-card__name"><a href="<?= SITE_URL ?>/product.php?slug=<?= urlencode($p['slug']) ?>"><?= h($p['name']) ?></a></h3>
                <div class="lume-product-card__price"><?= product_price($p) ?></div>
                <div class="lume-product-card__actions">
                    <button class="btn-add-cart" onclick="addToCart(<?= (int)$p['id'] ?>)">Add to Bag</button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if (!empty($section['button_text'])): ?>
    <div style="text-align:center;margin-top:48px" class="lume-reveal">
        <a href="<?= h(link_url($section['button_url'] ?? '', 'shop.php')) ?>" class="lume-btn"<?= bld('button_text') ?>><?= h($section['button_text']) ?></a>
    </div>
    <?php endif; ?>
</section>
<?php break;

        // ═══════════════════════════════════════
        // BRAND STORY
        // ═══════════════════════════════════════
        case 'brand_story':
            $eyebrow  = $sett['eyebrow'] ?? 'Our Story';
            $storyImg = !empty($section['image']) ? $section['image'] : ($sett['image'] ?? 'assets/images/hero-bg.png');
?>
<section class="lume-section container" id="story" style="<?= $padStyle ?>">
    <div class="lume-about-intro">
        <div class="lume-about-intro__img lume-reveal-left">
            <img src="<?= h(thumb_url($storyImg, 1200)) ?>" alt="<?= h($section['title'] ?? 'Brand story') ?>" loading="lazy"<?= bld('image', 'image') ?>>
        </div>
        <div class="lume-about-intro__text lume-reveal-right">
            <p class="lume-section__eyebrow"<?= bld('settings.eyebrow') ?>><?= h($eyebrow) ?></p>
            <h2<?= bld('title') ?>><?= h($section['title'] ?? 'Our Story') ?></h2>
            <?php /* Text pasted on Windows arrives with \r\n line endings; without
                     normalising, the blank-line split never matched and the whole
                     story rendered as one paragraph. */
                  foreach (explode("\n\n", str_replace("\r\n", "\n", $section['content'] ?? '')) as $pn => $para): ?>
            <p<?= bld('content', 'para', $pn) ?>><?= h(trim($para)) ?></p>
            <?php endforeach; ?>
            <?php if (!empty($section['button_text'])): ?>
            <a href="<?= h(link_url($section['button_url'] ?? '', 'about.php')) ?>" class="lume-btn" style="margin-top:24px"<?= bld('button_text') ?>><?= h($section['button_text']) ?></a>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php break;

        // ═══════════════════════════════════════
        // IMAGE BANNER
        // ═══════════════════════════════════════
        case 'image_banner':
            $bannerImg = !empty($section['image']) ? $section['image'] : ($sett['image'] ?? 'assets/images/hero-bg.png');
?>
<section class="lume-section" style="padding:0; <?= $padStyle ?>">
    <div style="position:relative;aspect-ratio:21/9;overflow:hidden;background:#111">
        <img src="<?= h(thumb_url($bannerImg, 1920)) ?>" alt="<?= h($section['title'] ?? '') ?>" loading="lazy" style="width:100%;height:100%;object-fit:cover;opacity:.7"<?= bld('image', 'image') ?>>
        <?php if (!empty($section['title'])): ?>
        <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:24px">
            <h2 style="font-family:var(--font-serif);font-size:clamp(2rem,5vw,4rem);text-transform:uppercase;letter-spacing:.04em;margin-bottom:12px" class="lume-reveal"<?= bld('title') ?>><?= h($section['title']) ?></h2>
            <?php if (!empty($section['subtitle'])): ?>
            <p style="font-size:.85rem;color:var(--gold);letter-spacing:.15em;text-transform:uppercase;margin-bottom:24px" class="lume-reveal"<?= bld('subtitle') ?>><?= h($section['subtitle']) ?></p>
            <?php endif; ?>
            <?php if (!empty($section['button_text'])): ?>
            <a href="<?= h(link_url($section['button_url'] ?? '', '')) ?>" class="lume-btn lume-reveal"<?= bld('button_text') ?>><?= h($section['button_text']) ?></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php break;

        // ═══════════════════════════════════════
        // TEXT BLOCK
        // ═══════════════════════════════════════
        case 'video':
            /* Accepts either a self-hosted file (mp4/webm, chosen from the
               media library into `image`, or typed into settings.video_url)
               or a YouTube/Vimeo link, which is turned into an embed.
               Self-hosted is preferred: no third-party cookies, and it can
               autoplay muted inline as a background-style banner. */
            $videoRaw = trim($sett['video_url'] ?? '') ?: trim($section['image'] ?? '');
            $video    = resolve_video_source($videoRaw);
            $embed    = $video['embed'];
            $videoSrc = $video['file'];
            if (!$embed && !$videoSrc) break; // nothing configured — render nothing
            // No poster chosen: use the one made when the video was uploaded.
            $poster   = trim($sett['poster'] ?? '') ?: ($videoSrc ? (string) media_poster_for($videoRaw) : '');
?>
<section class="lume-section lume-video-section" style="padding:0; <?= $padStyle ?>">
    <div class="lume-video-section__frame">
        <?php if ($embed): ?>
            <iframe src="<?= h($embed) ?>" title="<?= h($section['title'] ?? 'Video') ?>"
                    loading="lazy" allowfullscreen
                    allow="accelerometer; encrypted-media; gyroscope; picture-in-picture"
                    referrerpolicy="strict-origin-when-cross-origin"></iframe>
        <?php else: ?>
            <?php /* muted+playsinline so mobile browsers allow inline autoplay.
                     Autoplay videos start (and load) only once scrolled into
                     view — see "Lazy videos" in main.js. */ ?>
            <video<?= bld('image', 'video') ?> <?= !empty($sett['autoplay']) ? 'muted loop playsinline data-lazy-video' : 'controls playsinline' ?>
                   preload="<?= $poster ? 'none' : 'metadata' ?>"
                   <?= $poster ? 'poster="' . h(thumb_url($poster, 1600)) . '"' : '' ?>>
                <source src="<?= h($videoSrc) ?>">
                Your browser does not support embedded video.
            </video>
        <?php endif; ?>

        <?php if (!empty($section['title']) || !empty($section['subtitle']) || !empty($section['button_text'])): ?>
        <div class="lume-video-section__overlay">
            <?php if (!empty($section['title'])): ?>
                <h2 class="lume-reveal"<?= bld('title') ?>><?= h($section['title']) ?></h2>
            <?php endif; ?>
            <?php if (!empty($section['subtitle'])): ?>
                <p class="lume-reveal"<?= bld('subtitle') ?>><?= h($section['subtitle']) ?></p>
            <?php endif; ?>
            <?php if (!empty($section['button_text'])): ?>
                <a href="<?= h(link_url($section['button_url'] ?? '', '')) ?>" class="lume-btn lume-reveal"<?= bld('button_text') ?>><?= h($section['button_text']) ?></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php break;

        // ═══════════════════════════════════════
        // VIDEO HERO (fullscreen looping background)
        // ═══════════════════════════════════════
        case 'video_hero':
            $videoRaw  = trim($sett['video_url'] ?? '') ?: trim($section['image'] ?? '');
            $video     = resolve_video_source($videoRaw);
            $poster    = trim($sett['poster'] ?? '') ?: ($video['file'] ? (string) media_poster_for($videoRaw) : '');
            $posterUrl = $poster ? thumb_url($poster, 1920) : '';
            $textColor = $sett['text_color'] ?? '#ffffff';
            $btnColor  = $sett['button_color'] ?? '#ffffff';

            // Embeds have no native "muted background loop" mode, so bolt the
            // per-provider query params on that fake it.
            $embedUrl = $video['embed'];
            if ($video['provider'] === 'youtube') {
                $embedUrl .= '?autoplay=1&mute=1&loop=1&playlist=' . h($video['id']) . '&controls=0&playsinline=1&modestbranding=1';
            } elseif ($video['provider'] === 'vimeo') {
                $embedUrl .= '?autoplay=1&muted=1&loop=1&background=1';
            }
?>
<section class="lume-video-hero" style="<?= $padStyle ?>">
    <?php if ($embedUrl): ?>
        <iframe class="lume-video-hero__media" src="<?= h($embedUrl) ?>" title="<?= h($section['title'] ?? 'Video') ?>"
                loading="lazy" allow="accelerometer; autoplay; encrypted-media; gyroscope"
                referrerpolicy="strict-origin-when-cross-origin"></iframe>
    <?php elseif ($video['file']): ?>
        <video class="lume-video-hero__media"<?= bld('image', 'video') ?> autoplay muted loop playsinline preload="metadata"
               <?= $posterUrl ? 'poster="' . h($posterUrl) . '"' : '' ?>>
            <source src="<?= h($video['file']) ?>">
        </video>
    <?php elseif ($posterUrl): ?>
        <img class="lume-video-hero__media" src="<?= h($posterUrl) ?>" alt="" loading="lazy"<?= bld('image', 'video') ?>>
    <?php endif; ?>
    <div class="lume-video-hero__overlay" style="opacity: <?= h($sett['overlay'] ?? '0.45') ?>;"></div>
    <div class="lume-video-hero__content">
        <?php if (!empty($section['title'])): ?>
            <h2 class="lume-reveal"<?= bld('title') ?> style="color: <?= h($textColor) ?>"><?= h($section['title']) ?></h2>
        <?php endif; ?>
        <?php if (!empty($section['subtitle'])): ?>
            <p class="lume-reveal"<?= bld('subtitle') ?> style="color: <?= h($textColor) ?>"><?= h($section['subtitle']) ?></p>
        <?php endif; ?>
        <?php if (!empty($section['button_text'])): ?>
            <a href="<?= h(link_url($section['button_url'] ?? '', '')) ?>" class="lume-hero__cta lume-reveal"<?= bld('button_text') ?>
               style="--btn-color: <?= h($btnColor) ?>; border-color: var(--btn-color); color: var(--btn-color);"><?= h($section['button_text']) ?></a>
        <?php endif; ?>
    </div>
</section>
<?php break;

        // ═══════════════════════════════════════
        // VIDEO GALLERY (thumbnail grid → lightbox)
        // ═══════════════════════════════════════
        case 'video_gallery':
            $videosText = $sett['videos'] ?? '';
            $videosList = array_filter(array_map('trim', explode("\n", $videosText)));
            if (empty($videosList)) break;
            $columns = (int)($sett['columns'] ?? 3);
            if ($columns < 2 || $columns > 4) $columns = 3;
            static $vgCounter = 0;
            $vgCounter++;
            $modalId = 'vg-modal-' . (int)($section['id'] ?? 0) . '-' . $vgCounter;
?>
<section class="lume-section container" style="<?= $padStyle ?>">
    <?php if (!empty($section['title'])): ?>
    <div class="lume-section--center">
        <h2 class="lume-section__title lume-reveal"<?= bld('title') ?>><?= h($section['title']) ?></h2>
        <?php if (!empty($section['subtitle'])): ?>
        <p class="lume-section__subtitle lume-reveal"<?= bld('subtitle') ?>><?= h($section['subtitle']) ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="lume-video-gallery lume-video-gallery--cols-<?= $columns ?> lume-reveal" style="margin-top: <?= empty($section['title']) ? '0' : '40px' ?>;">
        <?php foreach (array_values($videosList) as $vrow => $line):
            $parts  = array_map('trim', explode('|', $line));
            $raw    = $parts[0] ?? '';
            if ($raw === '') continue;
            $label  = $parts[2] ?? '';
            $video  = resolve_video_source($raw);
            $poster = ($parts[1] ?? '') ?: ($video['file'] ? (string) media_poster_for($raw) : '');
            $src    = $video['embed'] ?: $video['file'];
            if (!$src) continue;
        ?>
        <button type="button" class="lume-video-gallery__thumb"<?= bld('settings.videos', 'video', $vrow, 0) ?> data-modal="<?= h($modalId) ?>" data-src="<?= h($src) ?>" data-embed="<?= $video['embed'] ? '1' : '0' ?>">
            <?php if ($poster): ?>
            <img src="<?= h(thumb_url($poster, 800)) ?>" alt="<?= h($label ?: 'Video') ?>" loading="lazy">
            <?php else: ?>
            <div class="lume-video-gallery__thumb-fallback"></div>
            <?php endif; ?>
            <span class="lume-video-gallery__play">▶</span>
            <?php if ($label): ?><span class="lume-video-gallery__label"<?= bld('settings.videos', 'text', $vrow, 2) ?>><?= h($label) ?></span><?php endif; ?>
        </button>
        <?php endforeach; ?>
    </div>
</section>
<div class="lume-video-gallery__modal" id="<?= h($modalId) ?>">
    <button type="button" class="lume-video-gallery__modal-close" aria-label="Close">✕</button>
    <div class="lume-video-gallery__modal-media"></div>
</div>
<?php break;

        // ═══════════════════════════════════════
        // VIDEO REELS (vertical 9:16 swipe carousel)
        // ═══════════════════════════════════════
        case 'video_reels':
            /* Only the centred reel plays; the rest sit paused on their cover.
               One sound toggle per section — unmuting follows you as you swipe,
               so at most one video is ever audible. See "Video Reels" in main.js. */
            $reels = [];
            foreach (array_values(array_filter(array_map('trim', explode("\n", $sett['reels'] ?? '')))) as $rrow => $line) {
                $parts = array_map('trim', explode('|', $line));
                $video = resolve_video_source($parts[0] ?? '');
                if (!$video['file']) continue; // embeds can't be muted/synced; uploads only
                $reels[] = [
                    'row'     => $rrow,
                    'src'     => $video['file'],
                    'poster'  => ($parts[1] ?? '') ?: (string) media_poster_for($parts[0]),
                    'caption' => $parts[2] ?? '',
                    'link'    => $parts[3] ?? '',
                ];
            }
            if (empty($reels)) break;
            $eyebrow  = $sett['eyebrow'] ?? '';
            $linkText = trim($sett['link_text'] ?? '') ?: 'Shop the look';
            $autoplay = ($sett['autoplay'] ?? '1') !== '';
?>
<section class="lume-section lume-reels-section" style="<?= $padStyle ?>">
    <?php if (!empty($section['title']) || $eyebrow): ?>
    <div class="container lume-section--center">
        <?php if ($eyebrow): ?><p class="lume-section__eyebrow lume-reveal"<?= bld('settings.eyebrow') ?>><?= h($eyebrow) ?></p><?php endif; ?>
        <?php if (!empty($section['title'])): ?><h2 class="lume-section__title lume-reveal"<?= bld('title') ?>><?= h($section['title']) ?></h2><?php endif; ?>
        <?php if (!empty($section['subtitle'])): ?>
        <p class="lume-section__subtitle lume-reveal"<?= bld('subtitle') ?>><?= h($section['subtitle']) ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="lume-reels lume-reveal" data-autoplay="<?= $autoplay ? '1' : '0' ?>">
        <div class="lume-reels__track">
            <?php foreach ($reels as $i => $r): ?>
            <article class="lume-reels__card<?= $i === 0 ? ' is-active' : '' ?>">
                <video class="lume-reels__video"<?= bld('settings.reels', 'video', $r['row'], 0) ?> muted loop playsinline preload="<?= $r['poster'] ? 'none' : 'metadata' ?>"
                       <?= $r['poster'] ? 'poster="' . h(thumb_url($r['poster'], 720)) . '"' : '' ?>>
                    <source src="<?= h($r['src']) ?>">
                </video>
                <div class="lume-reels__shade"></div>
                <span class="lume-reels__play" aria-hidden="true"></span>
                <button type="button" class="lume-reels__sound" aria-label="Turn sound on" aria-pressed="false">
                    <svg class="lume-reels__ico-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9v6h4l5 4V5L8 9H4z"/><path d="M16 9l5 6M21 9l-5 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <svg class="lume-reels__ico-on" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9v6h4l5 4V5L8 9H4z"/><path d="M16 8.5a5 5 0 0 1 0 7M18.5 6a8.5 8.5 0 0 1 0 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </button>
                <?php if ($r['caption'] || $r['link']): ?>
                <div class="lume-reels__meta">
                    <?php if ($r['caption']): ?><p class="lume-reels__caption"<?= bld('settings.reels', 'text', $r['row'], 2) ?>><?= h($r['caption']) ?></p><?php endif; ?>
                    <?php if ($r['link']): ?><a class="lume-reels__link" href="<?= h(link_url($r['link'], '')) ?>"><?= h($linkText) ?> →</a><?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="lume-reels__progress"><span></span></div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php if (count($reels) > 1): ?>
        <button type="button" class="lume-reels__arrow lume-reels__arrow--prev" aria-label="Previous video">‹</button>
        <button type="button" class="lume-reels__arrow lume-reels__arrow--next" aria-label="Next video">›</button>
        <div class="lume-reels__dots">
            <?php foreach ($reels as $i => $r): ?>
            <button type="button" class="lume-reels__dot<?= $i === 0 ? ' active' : '' ?>" aria-label="Go to video <?= $i + 1 ?>"></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php break;

        case 'text_block':
            $eyebrow = $sett['eyebrow'] ?? '';
?>
<section class="lume-section lume-section--center container" style="<?= $padStyle ?>">
    <?php if ($eyebrow): ?><p class="lume-section__eyebrow lume-reveal"<?= bld('settings.eyebrow') ?>><?= h($eyebrow) ?></p><?php endif; ?>
    <?php if (!empty($section['title'])): ?><h2 class="lume-section__title lume-reveal"<?= bld('title') ?>><?= h($section['title']) ?></h2><?php endif; ?>
    <div class="lume-divider lume-reveal" style="margin-bottom:16px"></div>
    <?php if (!empty($section['content'])): ?>
    <div class="lume-reveal" style="max-width:700px;margin:0 auto;color:var(--muted);line-height:1.8"<?= bld('content', 'multi') ?>>
        <?= nl2br(h($section['content'])) ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($section['button_text'])): ?>
    <div class="lume-reveal" style="margin-top:32px">
        <a href="<?= h(link_url($section['button_url'] ?? '', '')) ?>" class="lume-btn"<?= bld('button_text') ?>><?= h($section['button_text']) ?></a>
    </div>
    <?php endif; ?>
</section>
<?php break;

        // ═══════════════════════════════════════
        // CATEGORY GRID
        // ═══════════════════════════════════════
        case 'category_grid':
            $categories = get_categories();
?>
<section class="lume-section lume-section--center container" style="<?= $padStyle ?>">
    <h2 class="lume-section__title lume-reveal"<?= bld('title') ?>><?= h($section['title'] ?? 'Shop by Category') ?></h2>
    <div class="lume-divider lume-reveal" style="margin-bottom:32px"></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:20px;margin-top:32px">
        <?php foreach ($categories as $cat): ?>
        <a href="<?= SITE_URL ?>/shop.php?category=<?= h($cat['slug']) ?>" class="lume-product-card lume-reveal" style="text-align:center;padding:40px 20px">
            <h3 style="font-family:var(--font-serif);font-size:1.2rem;text-transform:uppercase;letter-spacing:.05em"><?= h($cat['name']) ?></h3>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php break;

        // ═══════════════════════════════════════
        // SOCIAL CAROUSEL
        // ═══════════════════════════════════════
        case 'social_carousel':
            $imagesText = $sett['images'] ?? '';
            $imagesList = array_filter(array_map('trim', explode("\n", $imagesText)));
            if (empty($imagesList)) break; // Skip if no images
?>
<section class="lume-section" style="padding-bottom: 0; <?= $padStyle ?>">
    <?php if (!empty($section['title'])): ?>
    <div class="container lume-section--center">
        <h2 class="lume-section__title lume-reveal"<?= bld('title') ?>><?= h($section['title']) ?></h2>
        <?php if (!empty($section['subtitle'])): ?>
        <p class="lume-section__subtitle lume-reveal"<?= bld('subtitle') ?>><?= h($section['subtitle']) ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <div class="lume-social-carousel lume-reveal" style="margin-top: <?= empty($section['title']) ? '0' : '48px' ?>;">
        <div class="lume-social-carousel__track">
            <?php foreach (array_values($imagesList) as $irow => $imgUrl): ?>
            <div class="lume-social-carousel__item">
                <img src="<?= h(thumb_url($imgUrl, 600)) ?>" alt="Social image" loading="lazy"<?= bld('settings.images', 'image', $irow, 0) ?>>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php break;

        // ═══════════════════════════════════════
        // LOGO CAROUSEL (continuous marquee)
        // ═══════════════════════════════════════
        case 'logo_carousel':
            $logosText = $sett['logos'] ?? '';
            $logosList = array_filter(array_map('trim', explode("\n", $logosText)));
            if (empty($logosList)) break;
            $speed = (int)($sett['speed'] ?? 30);
            if ($speed < 5) $speed = 30;
            $grayscale = ($sett['grayscale'] ?? '1') !== '';
?>
<section class="lume-section" style="<?= $padStyle ?>">
    <?php if (!empty($section['title'])): ?>
    <div class="container lume-section--center">
        <h2 class="lume-section__title lume-reveal"<?= bld('title') ?>><?= h($section['title']) ?></h2>
        <?php if (!empty($section['subtitle'])): ?>
        <p class="lume-section__subtitle lume-reveal"<?= bld('subtitle') ?>><?= h($section['subtitle']) ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="lume-logo-carousel<?= $grayscale ? '' : ' lume-logo-carousel--color' ?> lume-reveal" style="margin-top: <?= empty($section['title']) ? '0' : '48px' ?>;">
        <div class="lume-logo-carousel__track" style="animation-duration: <?= $speed ?>s">
            <?php $logosList = array_values($logosList); foreach (array_merge($logosList, $logosList) as $i => $logo): ?>
            <div class="lume-logo-carousel__item"<?= $i >= count($logosList) ? ' aria-hidden="true"' : '' ?>>
                <img src="<?= h(thumb_url($logo, 300)) ?>" alt="" loading="lazy"<?= $i < count($logosList) ? bld('settings.logos', 'image', $i, 0) : '' ?>>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php break;

        // ═══════════════════════════════════════
        // IMAGE CAROUSEL (single slide, arrows + dots)
        // ═══════════════════════════════════════
        case 'image_carousel':
            $slidesText = $sett['slides'] ?? '';
            $slides = [];
            foreach (array_values(array_filter(array_map('trim', explode("\n", $slidesText)))) as $srow => $line) {
                $parts = array_map('trim', explode('|', $line));
                $img = $parts[0] ?? '';
                if ($img === '') continue;
                $slides[] = ['img' => $img, 'caption' => $parts[1] ?? '', 'row' => $srow];
            }
            if (empty($slides)) break;
?>
<section class="lume-section" style="<?= $padStyle ?>">
    <?php if (!empty($section['title'])): ?>
    <div class="container lume-section--center">
        <h2 class="lume-section__title lume-reveal"<?= bld('title') ?>><?= h($section['title']) ?></h2>
        <?php if (!empty($section['subtitle'])): ?>
        <p class="lume-section__subtitle lume-reveal"<?= bld('subtitle') ?>><?= h($section['subtitle']) ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="lume-image-carousel lume-reveal" style="margin-top: <?= empty($section['title']) ? '0' : '40px' ?>;">
        <div class="lume-image-carousel__track">
            <?php foreach ($slides as $sl): ?>
            <div class="lume-image-carousel__slide">
                <img src="<?= h(thumb_url($sl['img'], 1600)) ?>" alt="<?= h($sl['caption']) ?>" loading="lazy"<?= bld('settings.slides', 'image', $sl['row'], 0) ?>>
                <?php if ($sl['caption']): ?><p class="lume-image-carousel__caption"<?= bld('settings.slides', 'text', $sl['row'], 1) ?>><?= h($sl['caption']) ?></p><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($slides) > 1): ?>
        <button type="button" class="lume-gallery__arrow lume-image-carousel__arrow lume-image-carousel__arrow--prev" aria-label="Previous">‹</button>
        <button type="button" class="lume-gallery__arrow lume-image-carousel__arrow lume-image-carousel__arrow--next" aria-label="Next">›</button>
        <div class="lume-image-carousel__dots">
            <?php foreach ($slides as $i => $sl): ?>
            <button type="button" class="lume-image-carousel__dot<?= $i === 0 ? ' active' : '' ?>" aria-label="Go to slide <?= $i + 1 ?>"></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php break;

        // ═══════════════════════════════════════
        // TESTIMONIALS CAROUSEL
        // ═══════════════════════════════════════
        case 'testimonials':
            $reviewsText = $sett['reviews'] ?? '';
            $reviewsList = array_filter(array_map('trim', explode("\n", $reviewsText)));
            if (empty($reviewsList)) break;
?>
<section class="lume-section container" style="<?= $padStyle ?>">
    <?php if (!empty($section['title'])): ?>
    <div class="lume-section--center">
        <h2 class="lume-section__title lume-reveal"<?= bld('title') ?>><?= h($section['title']) ?></h2>
        <?php if (!empty($section['subtitle'])): ?>
        <p class="lume-section__subtitle lume-reveal"<?= bld('subtitle') ?>><?= h($section['subtitle']) ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="lume-testimonials lume-reveal" style="margin-top: <?= empty($section['title']) ? '0' : '48px' ?>;">
        <div class="lume-testimonials__track" id="testimonial-track-<?= $section['id'] ?? '1' ?>">
            <?php foreach (array_values($reviewsList) as $rrow => $rev):
                $parts = array_map('trim', explode('|', $rev));
                if (count($parts) < 2) continue;
                $text = $parts[0];
                $author = $parts[1];
                $stars = isset($parts[2]) ? max(0, min(5, (int)$parts[2])) : 5;
                $initial = mb_substr($author, 0, 1, 'UTF-8');
            ?>
            <div class="lume-testimonial-card">
                <div class="lume-testimonial-card__stars">
                    <?= str_repeat('★', $stars) ?><?= str_repeat('☆', 5 - $stars) ?>
                </div>
                <p class="lume-testimonial-card__text"<?= bld('settings.reviews', 'text', $rrow, 0) ?>><?= h($text) ?></p>
                <div class="lume-testimonial-card__author-wrap">
                    <div class="lume-testimonial-card__avatar"><?= h(strtoupper($initial)) ?></div>
                    <div class="lume-testimonial-card__author-info">
                        <strong<?= bld('settings.reviews', 'text', $rrow, 1) ?>><?= h($author) ?></strong>
                        <span>Verified buyer</span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="lume-testimonials__nav">
            <button class="lume-nav-btn lume-nav-btn--prev" onclick="document.getElementById('testimonial-track-<?= $section['id'] ?? '1' ?>').scrollBy({left: -320, behavior: 'smooth'})">◄</button>
            <button class="lume-nav-btn lume-nav-btn--next" onclick="document.getElementById('testimonial-track-<?= $section['id'] ?? '1' ?>').scrollBy({left: 320, behavior: 'smooth'})">►</button>
        </div>
    </div>
</section>
<?php break;

        // ═══════════════════════════════════════
        // TESTIMONIAL SPOTLIGHT (single rotating quote)
        // ═══════════════════════════════════════
        case 'testimonial_carousel':
            $reviews = [];
            foreach (array_values(array_filter(array_map('trim', explode("\n", $sett['reviews'] ?? '')))) as $rrow => $rev) {
                $parts = array_map('trim', explode('|', $rev));
                if (count($parts) < 2) continue;
                $reviews[] = [
                    'row'    => $rrow,
                    'text'   => $parts[0],
                    'author' => $parts[1],
                    'stars'  => isset($parts[2]) ? max(0, min(5, (int)$parts[2])) : 5,
                ];
            }
            if (empty($reviews)) break;
            $interval = (int)($sett['interval'] ?? 6000);
            if ($interval < 1500) $interval = 6000;
?>
<section class="lume-section container" style="<?= $padStyle ?>">
    <?php if (!empty($section['title'])): ?>
    <div class="lume-section--center">
        <h2 class="lume-section__title lume-reveal"<?= bld('title') ?>><?= h($section['title']) ?></h2>
        <?php if (!empty($section['subtitle'])): ?>
        <p class="lume-section__subtitle lume-reveal"<?= bld('subtitle') ?>><?= h($section['subtitle']) ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="lume-testimonial-carousel lume-reveal" data-interval="<?= $interval ?>">
        <div class="lume-testimonial-carousel__slides">
            <?php foreach ($reviews as $i => $r): ?>
            <blockquote class="lume-testimonial-carousel__slide<?= $i === 0 ? ' active' : '' ?>">
                <div class="lume-testimonial-carousel__stars"><?= str_repeat('★', $r['stars']) ?><?= str_repeat('☆', max(0, 5 - $r['stars'])) ?></div>
                <p<?= bld('settings.reviews', 'text', $r['row'], 0) ?>><?= h($r['text']) ?></p>
                <cite<?= bld('settings.reviews', 'text', $r['row'], 1) ?>><?= h($r['author']) ?></cite>
            </blockquote>
            <?php endforeach; ?>
        </div>
        <?php if (count($reviews) > 1): ?>
        <div class="lume-testimonial-carousel__dots">
            <?php foreach ($reviews as $i => $r): ?>
            <button type="button" class="lume-testimonial-carousel__dot<?= $i === 0 ? ' active' : '' ?>" aria-label="Go to review <?= $i + 1 ?>"></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php break;

    endswitch;
endforeach;
?>
