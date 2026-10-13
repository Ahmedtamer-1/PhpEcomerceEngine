<?php
/**
 * Section registry — the single source of truth for what each section type
 * is made of.
 *
 * Consumed by:
 *   admin/builder.php            — builds the settings panel from `fields`
 *   api/section-preview.php      — validates an incoming draft
 *   includes/render-sections.php — renders using the same keys
 *
 * SECTION SHAPE
 *   label, icon   shown in the section list
 *   desc          one line shown in "Add section"
 *   fields        list of FIELD
 *
 * FIELD SHAPE
 *   key     where the value lives. A bare name ("title") is a column on
 *           homepage_sections; "settings.foo" goes in the JSON.
 *   label   shown above the control
 *   type    text | textarea | image | video | color | number | select |
 *           toggle | range | list | category | link
 *   group   content | media | style | layout   (panel sections)
 *   hint    optional helper line
 *   default value for a new section
 *   options select: value => label
 *   on/off  toggle: the stored values (defaults '1' / '')
 *   min/max/step/unit   range
 *   columns list: each line is one item, columns joined with " | " — the
 *           exact format render-sections.php parses. Column: key, label, type
 *           (text | image | video | number), optional placeholder/min/max.
 *   item    list: what one entry is called ("Slide")
 */

function section_registry(): array
{
    $heading = [
        ['key' => 'title',    'label' => 'Heading', 'type' => 'text', 'group' => 'content'],
        ['key' => 'subtitle', 'label' => 'Subtext', 'type' => 'text', 'group' => 'content'],
    ];
    $button = [
        ['key' => 'button_text', 'label' => 'Button label', 'type' => 'text', 'group' => 'content',
         'hint' => 'Leave empty to hide the button'],
        ['key' => 'button_url',  'label' => 'Button link',  'type' => 'link', 'group' => 'content'],
    ];
    $padding = [
        ['key' => 'settings.padding_top',    'label' => 'Space above',  'type' => 'range', 'group' => 'layout',
         'min' => 0, 'max' => 200, 'step' => 4, 'unit' => 'px', 'hint' => 'Leave at the start to use the theme default'],
        ['key' => 'settings.padding_bottom', 'label' => 'Space below',  'type' => 'range', 'group' => 'layout',
         'min' => 0, 'max' => 200, 'step' => 4, 'unit' => 'px'],
    ];
    $overlay = fn(string $def) => ['key' => 'settings.overlay', 'label' => 'Darken background', 'type' => 'range', 'group' => 'style',
         'min' => 0, 'max' => 1, 'step' => 0.05, 'default' => $def, 'hint' => 'Makes text easier to read over the picture'];
    $colors = [
        ['key' => 'settings.text_color',   'label' => 'Text colour',   'type' => 'color', 'group' => 'style', 'default' => '#ffffff'],
        ['key' => 'settings.button_color', 'label' => 'Button colour', 'type' => 'color', 'group' => 'style', 'default' => '#ffffff'],
    ];
    $reviews = ['key' => 'settings.reviews', 'label' => 'Reviews', 'type' => 'list', 'group' => 'content', 'item' => 'Review',
        'columns' => [
            ['key' => 'text',   'label' => 'Review',   'type' => 'text', 'placeholder' => 'What the customer said'],
            ['key' => 'author', 'label' => 'Name',     'type' => 'text', 'placeholder' => 'Customer name'],
            ['key' => 'stars',  'label' => 'Stars (1–5)', 'type' => 'number', 'min' => 1, 'max' => 5, 'placeholder' => '5'],
        ]];

    return [
        'hero' => [
            'label' => 'Hero Banner', 'icon' => '🌟',
            'desc'  => 'Big full-screen picture with a heading and button',
            'fields' => array_merge($heading, [
                ['key' => 'image', 'label' => 'Background image', 'type' => 'image', 'group' => 'media'],
            ], $colors, [
                $overlay('0.5'),
                ['key' => 'settings.show_particles', 'label' => 'Floating particles', 'type' => 'toggle', 'group' => 'style', 'on' => 'true', 'off' => ''],
            ], $button, $padding),
        ],

        'featured_products' => [
            'label' => 'Featured Products', 'icon' => '📦',
            'desc'  => 'A row of products marked as featured, or from one category',
            'fields' => array_merge($heading, [
                ['key' => 'settings.eyebrow', 'label' => 'Small label above heading', 'type' => 'text', 'group' => 'content'],
                ['key' => 'settings.product_count', 'label' => 'How many products', 'type' => 'range', 'group' => 'content',
                 'min' => 2, 'max' => 12, 'step' => 1, 'default' => '4'],
                ['key' => 'settings.category_id', 'label' => 'Products to show', 'type' => 'category', 'group' => 'content',
                 'hint' => 'Featured products, or the newest pieces from one category'],
            ], $button, $padding),
        ],

        'brand_story' => [
            'label' => 'Brand Story', 'icon' => '📖',
            'desc'  => 'Text beside an image — tell people who you are',
            'fields' => array_merge($heading, [
                ['key' => 'settings.eyebrow', 'label' => 'Small label above heading', 'type' => 'text', 'group' => 'content'],
                ['key' => 'content', 'label' => 'Body text', 'type' => 'textarea', 'group' => 'content',
                 'hint' => 'Leave a blank line between paragraphs'],
                ['key' => 'image', 'label' => 'Image', 'type' => 'image', 'group' => 'media'],
                ['key' => 'settings.bg_color', 'label' => 'Background colour', 'type' => 'color', 'group' => 'style'],
            ], $button, $padding),
        ],

        'image_banner' => [
            'label' => 'Image Banner', 'icon' => '🖼️',
            'desc'  => 'A wide picture with optional heading and button',
            'fields' => array_merge([
                ['key' => 'image', 'label' => 'Banner image', 'type' => 'image', 'group' => 'media'],
            ], $heading, $button, $padding),
        ],

        'video' => [
            'label' => 'Video', 'icon' => '🎬',
            'desc'  => 'An uploaded video, or a YouTube / Vimeo link',
            'fields' => array_merge([
                ['key' => 'image', 'label' => 'Video', 'type' => 'video', 'group' => 'media',
                 'hint' => 'Upload or pick from Media — compressed automatically'],
                ['key' => 'settings.video_url', 'label' => 'Or a YouTube / Vimeo link', 'type' => 'text', 'group' => 'media',
                 'hint' => 'Used instead of the video above when filled in'],
                ['key' => 'settings.poster', 'label' => 'Cover image', 'type' => 'image', 'group' => 'media',
                 'hint' => 'Optional — uploaded videos already have one'],
                ['key' => 'settings.autoplay', 'label' => 'Play automatically (muted, looping)', 'type' => 'toggle', 'group' => 'style',
                 'hint' => 'Off shows play controls instead'],
            ], $heading, $button, $padding),
        ],

        'video_hero' => [
            'label' => 'Video Hero', 'icon' => '🎥',
            'desc'  => 'Full-screen looping background video with a heading',
            'fields' => array_merge($heading, [
                ['key' => 'image', 'label' => 'Background video', 'type' => 'video', 'group' => 'media',
                 'hint' => 'Always muted and looping'],
                ['key' => 'settings.video_url', 'label' => 'Or a YouTube / Vimeo link', 'type' => 'text', 'group' => 'media'],
                ['key' => 'settings.poster', 'label' => 'Cover image', 'type' => 'image', 'group' => 'media',
                 'hint' => 'Shown while the video loads, or if there is no video'],
                $overlay('0.45'),
            ], $colors, $button, $padding),
        ],

        'video_gallery' => [
            'label' => 'Video Gallery', 'icon' => '🎞️',
            'desc'  => 'A grid of video thumbnails that open in a player',
            'fields' => array_merge($heading, [
                ['key' => 'settings.videos', 'label' => 'Videos', 'type' => 'list', 'group' => 'content', 'item' => 'Video',
                 'columns' => [
                     ['key' => 'video',  'label' => 'Video', 'type' => 'video'],
                     ['key' => 'poster', 'label' => 'Cover (optional)', 'type' => 'image'],
                     ['key' => 'label',  'label' => 'Caption', 'type' => 'text', 'placeholder' => 'Optional'],
                 ]],
                ['key' => 'settings.columns', 'label' => 'Columns', 'type' => 'select', 'group' => 'layout',
                 'options' => ['2' => '2 columns', '3' => '3 columns', '4' => '4 columns'], 'default' => '3'],
            ], $padding),
        ],

        'video_reels' => [
            'label' => 'Video Reels', 'icon' => '📱',
            'desc'  => 'Swipeable row of vertical (9:16) videos, like Reels or TikTok',
            'fields' => array_merge($heading, [
                ['key' => 'settings.eyebrow', 'label' => 'Small label above heading', 'type' => 'text', 'group' => 'content'],
                ['key' => 'settings.reels', 'label' => 'Videos', 'type' => 'list', 'group' => 'content', 'item' => 'Video',
                 'columns' => [
                     ['key' => 'video',   'label' => 'Video', 'type' => 'video'],
                     ['key' => 'poster',  'label' => 'Cover (optional)', 'type' => 'image'],
                     ['key' => 'caption', 'label' => 'Caption', 'type' => 'text', 'placeholder' => 'Optional'],
                     ['key' => 'link',    'label' => 'Link', 'type' => 'text', 'placeholder' => 'Optional, e.g. /product.php?slug=…'],
                 ]],
                ['key' => 'settings.link_text', 'label' => 'Link label', 'type' => 'text', 'group' => 'content',
                 'default' => 'Shop the look', 'hint' => 'Shown on videos that have a link'],
                ['key' => 'settings.autoplay', 'label' => 'Play the centred video automatically', 'type' => 'toggle', 'group' => 'style',
                 'default' => '1', 'hint' => 'Always starts muted; visitors tap the speaker for sound'],
            ], $padding),
        ],

        'text_block' => [
            'label' => 'Text Block', 'icon' => '📝',
            'desc'  => 'A heading and paragraphs of text',
            'fields' => array_merge($heading, [
                ['key' => 'content', 'label' => 'Body text', 'type' => 'textarea', 'group' => 'content'],
                ['key' => 'settings.bg_color', 'label' => 'Background colour', 'type' => 'color', 'group' => 'style'],
            ], $button, $padding),
        ],

        'category_grid' => [
            'label' => 'Category Grid', 'icon' => '🗂️',
            'desc'  => 'Tiles linking to each product category',
            'fields' => array_merge($heading, [
                ['key' => 'settings.columns', 'label' => 'Columns', 'type' => 'select', 'group' => 'layout',
                 'options' => ['3' => '3 columns', '4' => '4 columns']],
            ], $padding),
        ],

        'social_carousel' => [
            'label' => 'Social Carousel', 'icon' => '📸',
            'desc'  => 'A scrolling strip of photos, e.g. from Instagram',
            'fields' => array_merge($heading, [
                ['key' => 'settings.images', 'label' => 'Photos', 'type' => 'list', 'group' => 'content', 'item' => 'Photo',
                 'columns' => [['key' => 'image', 'label' => 'Photo', 'type' => 'image']]],
            ], $padding),
        ],

        'logo_carousel' => [
            'label' => 'Logo Carousel', 'icon' => '🏷️',
            'desc'  => 'Continuously scrolling logos (press, partners)',
            'fields' => array_merge($heading, [
                ['key' => 'settings.logos', 'label' => 'Logos', 'type' => 'list', 'group' => 'content', 'item' => 'Logo',
                 'columns' => [['key' => 'image', 'label' => 'Logo', 'type' => 'image']]],
                ['key' => 'settings.speed', 'label' => 'Scroll time', 'type' => 'range', 'group' => 'style',
                 'min' => 10, 'max' => 90, 'step' => 5, 'unit' => 's', 'default' => '30', 'hint' => 'Lower is faster'],
                ['key' => 'settings.grayscale', 'label' => 'Grey until hovered', 'type' => 'toggle', 'group' => 'style', 'default' => '1'],
            ], $padding),
        ],

        'image_carousel' => [
            'label' => 'Image Carousel', 'icon' => '🎠',
            'desc'  => 'Slides of pictures with optional captions',
            'fields' => array_merge($heading, [
                ['key' => 'settings.slides', 'label' => 'Slides', 'type' => 'list', 'group' => 'content', 'item' => 'Slide',
                 'columns' => [
                     ['key' => 'image',   'label' => 'Picture', 'type' => 'image'],
                     ['key' => 'caption', 'label' => 'Caption', 'type' => 'text', 'placeholder' => 'Optional'],
                 ]],
            ], $padding),
        ],

        'testimonials' => [
            'label' => 'Testimonials', 'icon' => '💬',
            'desc'  => 'Customer reviews shown as cards',
            'fields' => array_merge($heading, [$reviews], $padding),
        ],

        'testimonial_carousel' => [
            'label' => 'Testimonial Spotlight', 'icon' => '🗣️',
            'desc'  => 'One review at a time, rotating',
            'fields' => array_merge($heading, [
                $reviews,
                ['key' => 'settings.interval', 'label' => 'Change every', 'type' => 'range', 'group' => 'style',
                 'min' => 2000, 'max' => 15000, 'step' => 500, 'unit' => 'ms', 'default' => '6000'],
            ], $padding),
        ],
    ];
}

/** Is this a section type we know how to render? */
function section_type_exists(string $type): bool
{
    return array_key_exists($type, section_registry());
}

