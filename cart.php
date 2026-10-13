<?php
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Your Bag — ' . setting('site_name', SITE_NAME);
require_once __DIR__ . '/includes/header.php';
$items = cart_items();
$subtotal = cart_total();
// Same threshold as checkout (it used to read a hard-coded constant here, so
// the bag and checkout could disagree). Shipping itself depends on the city,
// which is only chosen at checkout.
$freeOver = (float) setting('free_shipping_over', (string) FREE_SHIPPING_OVER);
$freeShip = $freeOver > 0 && $subtotal >= $freeOver;
$discount = cart_discount($subtotal);
$total = $subtotal - ($discount['amount'] ?? 0);
?>

<section class="lume-page-header">
    <div class="container">
        <h1 class="lume-page-header__title">Your Bag</h1>
        <p class="lume-page-header__breadcrumb"><a href="<?= SITE_URL ?>/">Home</a> / Cart</p>
    </div>
</section>

<section class="lume-cart-page container">
    <?php if (isset($_GET['err']) && $_GET['err'] === 'stock'): ?>
    <div style="background:var(--terracotta,#c44);color:#fff;padding:12px;margin-bottom:24px;text-align:center;border-radius:4px">
        Sorry, you cannot add more of this item as we don't have enough in stock.
    </div>
    <?php endif; ?>

    <?php if (empty($items)): ?>
    <div style="text-align:center;padding:60px 0">
        <p style="color:var(--muted);font-size:1rem;margin-bottom:24px">Your bag is empty</p>
        <a href="<?= SITE_URL ?>/shop.php" class="lume-btn">Start Shopping</a>
    </div>
    <?php else: ?>
    <table class="lume-cart-table">
        <thead>
            <tr>
                <th>Product</th>
                <th class="lume-cart-table__hide-mobile">Price</th>
                <th>Qty</th>
                <th>Subtotal</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item):
            $price = item_effective_price($item);
            $lineTotal = $price * (int)$item['quantity'];
            $variantLabel = '';
            if (!empty($item['variant_size'])) $variantLabel .= $item['variant_size'];
            if (!empty($item['variant_color'])) $variantLabel .= ($variantLabel ? ' / ' : '') . $item['variant_color'];
        ?>
            <tr>
                <td>
                    <div class="lume-cart-table__product">
                        <img src="<?= asset_url(!empty($item['variant_image']) ? h($item['variant_image']) : product_image($item)) ?>" alt="<?= h($item['name']) ?>">
                        <div>
                            <a href="<?= SITE_URL ?>/product.php?slug=<?= h($item['slug']) ?>" style="font-size:.9rem"><?= h($item['name']) ?></a>
                            <?php if ($variantLabel): ?>
                            <div class="lume-cart-variant-meta"><?= h($variantLabel) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td class="lume-cart-table__hide-mobile"><?= money($price) ?></td>
                <td>
                    <form method="post" action="<?= SITE_URL ?>/api/cart.php" style="display:flex;align-items:center;gap:6px">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="cart_id" value="<?= (int)$item['id'] ?>">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <?php $max = !empty($item['has_variants']) ? (int)$item['variant_stock'] : (int)$item['stock']; ?>
                        <input type="number" name="quantity" value="<?= (int)$item['quantity'] ?>" min="1" max="<?= $max ?>"
                               class="lume-form__input" style="width:60px;padding:8px;text-align:center"
                               onchange="let v=parseInt(this.value)||1;this.value=Math.max(1,Math.min(<?= $max ?>,v));this.closest('form').submit()">
                        <noscript><button type="submit" class="lume-btn" style="padding:8px 12px;font-size:.6rem">Update</button></noscript>
                    </form>
                </td>
                <td><?= money($lineTotal) ?></td>
                <td>
                    <form method="post" action="<?= SITE_URL ?>/api/cart.php">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="cart_id" value="<?= (int)$item['id'] ?>">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <button type="submit" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:.7rem;text-transform:uppercase;letter-spacing:.1em">✕</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="lume-cart-summary">
        <div class="lume-cart-summary__row"><span>Subtotal</span><span><?= money($subtotal) ?></span></div>
        <?php if ($discount): ?>
        <div class="lume-cart-summary__row lume-cart-discount"><span><?= h($discount['label']) ?></span><span>-<?= money($discount['amount']) ?></span></div>
        <?php endif; ?>
        <div class="lume-cart-summary__row"><span>Shipping</span><span><?= $freeShip ? 'Free' : 'At checkout' ?></span></div>
        <?php if ($freeOver > 0 && !$freeShip): ?>
        <p style="font-size:.7rem;color:var(--gold);margin-bottom:12px">Add <?= money($freeOver - $subtotal) ?> more for free shipping!</p>
        <?php endif; ?>
        <div class="lume-cart-summary__row total"><span><?= $freeShip ? 'Total' : 'Total before shipping' ?></span><span><?= money($total) ?></span></div>
        <?php if (!is_logged_in() && welcome_offer_enabled()): ?>
        <p class="lume-welcome-nudge"><a href="<?= SITE_URL ?>/account.php?action=register&amp;redirect=<?= urlencode('/cart.php') ?>">Create an account</a> and get <?= h(rtrim(rtrim(number_format(welcome_offer_pct(), 2), '0'), '.')) ?>% off this order.</p>
        <?php endif; ?>
        <a href="<?= SITE_URL ?>/checkout.php" class="lume-btn lume-btn--full lume-btn--solid" style="margin-top:16px">Checkout</a>
    </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
