<?php
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Contact — ' . setting('site_name', SITE_NAME);
$pageDescription = 'Get in touch with the ' . setting('site_name', SITE_NAME) . ' team. We\'d love to hear from you.';
require_once __DIR__ . '/includes/header.php';
?>
<?php
/* Section-built content.
   If sections exist for this page in Admin -> Builder, they render here and
   replace the static layout below. Until then the original page is shown
   unchanged, so adding the builder cannot break a live page. */
$sections = get_homepage_sections(true, 'contact');
if ($sections):
    require __DIR__ . '/includes/render-sections.php';
else:
?>


<section class="lume-page-header">
    <div class="container">
        <h1 class="lume-page-header__title">Contact Us</h1>
        <p class="lume-page-header__breadcrumb"><a href="<?= SITE_URL ?>/">Home</a> / Contact</p>
    </div>
</section>

<section class="container">
    <div class="lume-contact">
        <div class="lume-contact__info lume-reveal-left">
            <p class="lume-section__eyebrow"><?= h(setting('page_contact_eyebrow', 'Get In Touch')) ?></p>
            <h2><?= h(setting('page_contact_title', 'We\'d Love to Hear From You')) ?></h2>
            <div class="lume-legal__body" style="color:var(--muted);font-size:.9rem;line-height:1.8;margin-bottom:16px">
                <?= nl2br(h(setting('page_contact_text', 'Whether you have a question about a product, need help with an order, or just want to say hello — our team is here for you.'))) ?>
            </div>
            <div style="margin-top:32px">
                <div class="lume-contact__detail">
                    <span>✉</span>
                    <span><?= h(setting('contact_email', 'hello@lumeegy.com')) ?></span>
                </div>
                <div class="lume-contact__detail">
                    <span>✦</span>
                    <span>Cairo, Egypt</span>
                </div>
            </div>
        </div>
        <div class="lume-reveal-right">
            <div id="contact-msg"></div>
            <form class="lume-form" id="contact-form" novalidate>
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <div class="lume-form__group">
                    <label class="lume-form__label" for="contact-name">Full Name</label>
                    <input class="lume-form__input" type="text" id="contact-name" name="name" required>
                </div>
                <div class="lume-form__group">
                    <label class="lume-form__label" for="contact-email">Email</label>
                    <input class="lume-form__input" type="email" id="contact-email" name="email" required>
                </div>
                <div class="lume-form__group">
                    <label class="lume-form__label" for="contact-subject">Subject</label>
                    <input class="lume-form__input" type="text" id="contact-subject" name="subject">
                </div>
                <div class="lume-form__group">
                    <label class="lume-form__label" for="contact-message">Message</label>
                    <textarea class="lume-form__textarea" id="contact-message" name="message" required></textarea>
                </div>
                <?php /* Honeypot for spam bots — hidden from people and screen readers. */ ?>
                <div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">
                    <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                </div>
                <button type="submit" class="lume-btn lume-btn--full">Send Message</button>
            </form>
        </div>
    </div>
</section>

<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
