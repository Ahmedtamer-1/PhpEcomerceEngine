<?php
require_once __DIR__ . '/../includes/functions.php';
// Self-load the CAPI helper rather than relying on functions.php having been
// updated to require it — is_readable() first, so a missing file is a no-op
// instead of a fatal.
if (!function_exists('meta_capi_send') && is_readable(__DIR__ . '/../includes/meta-capi.php')) {
    require_once __DIR__ . '/../includes/meta-capi.php';
}
lume_session_start();

// GET — return cart contents
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'get';
    if ($action === 'get') {
        $items = cart_items();
        $out = [];
        foreach ($items as $i) {
            $p = item_effective_price($i);
            $img = !empty($i['variant_image']) ? asset_url($i['variant_image']) : product_image($i);
            $out[] = [
                'id'            => (int)$i['id'],
                'product_id'    => (int)$i['product_id'],
                'name'          => $i['name'],
                'slug'          => $i['slug'],
                'image'         => $img,
                'quantity'      => (int)$i['quantity'],
                'display_price' => money($p * (int)$i['quantity']),
                'variant_size'  => $i['variant_size'] ?? null,
                'variant_color' => $i['variant_color'] ?? null,
            ];
        }
        $subtotal = cart_total();
        $disc = cart_discount($subtotal);
        json_response([
            'items' => $out, 'count' => cart_count(),
            'subtotal_display' => money($subtotal),
            'discount' => $disc ? ['label' => $disc['label'], 'display' => '-' . money($disc['amount'])] : null,
            'total_display' => money($subtotal - ($disc['amount'] ?? 0)),
        ]);
    }
}

// POST — add / update / remove
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // Non-AJAX form submissions (from cart page)
    $isAjax = is_ajax();

    if (!csrf_verify($_POST['csrf'] ?? '')) {
        if ($isAjax) json_response(['success' => false, 'message' => 'Invalid CSRF token'], 400);
        else redirect('/cart.php');
    }

    if ($action === 'add') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $qty = max(1, (int)($_POST['quantity'] ?? 1));
        $vid = (int)($_POST['variant_id'] ?? 0) ?: null;
        if ($pid <= 0) { if ($isAjax) json_response(['success'=>false,'message'=>'Invalid product'],400); else redirect('/cart.php'); }
        
        $product = db()->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1');
        $product->execute([$pid]);
        $prodData = $product->fetch();

        if (!$prodData) {
            if ($isAjax) json_response(['success'=>false,'message'=>'Product not found'], 404); else redirect('/cart.php');
        }

        // Pre-order: when enabled for this product, the stock ceiling below is
        // skipped so an out-of-stock item can still be ordered. Defaults to 0
        // via ?? so this behaves normally if the column is not present yet.
        $preorderAllowed = !empty($prodData['preorder_enabled'] ?? 0);

        $maxStock = 0;
        if (!empty($prodData['has_variants'])) {
            if (!$vid) {
                if ($isAjax) json_response(['success' => false, 'message' => 'Please select a size/color.'], 400); else redirect('/cart.php');
            }
            // The variant must belong to THIS product: the cart prices a line
            // from the variant's price_override, so pairing a product with some
            // other product's cheaper variant would change what is charged.
            $vStmt = db()->prepare('SELECT stock FROM product_variants WHERE id = ? AND product_id = ? AND is_active = 1');
            $vStmt->execute([$vid, $pid]);
            $vStock = $vStmt->fetchColumn();
            if ($vStock === false) {
                if ($isAjax) json_response(['success' => false, 'message' => 'That option is no longer available.'], 400); else redirect('/cart.php');
            }
            $maxStock = (int)$vStock;
        } else {
            $vid = null; // a variant id on a simple product would only confuse pricing
            $maxStock = (int)$prodData['stock'];
        }

        // Get current quantity in cart
        $currentCartQty = 0;
        $items = cart_items();
        foreach ($items as $i) {
            if ($i['product_id'] == $pid && ($i['variant_id'] ?? null) == $vid) {
                $currentCartQty += (int)$i['quantity'];
            }
        }

        if (!$preorderAllowed && $currentCartQty + $qty > $maxStock) {
            if ($isAjax) json_response(['success' => false, 'message' => "Only $maxStock available in stock."], 400); else redirect('/cart.php?err=stock');
        }
        
        cart_add($pid, $qty, $vid);
        $vRow = $vid ? get_variant($vid) : null;
        cart_event_log('add', $pid, $vid, $qty, $vRow ? item_effective_price($prodData + ['variant_price' => $vRow['price_override']]) : product_effective_price($prodData));

        // ── Meta Conversions API: AddToCart ──
        // event_id is generated here and handed back to the client so its
        // fbq('track','AddToCart', ..., {eventID}) call dedupes against this
        // server-side one instead of Meta counting the add twice.
        //
        // Every call is behind function_exists(): the cart must keep working
        // even if includes/meta-capi.php is missing or wasn't loaded (e.g. a
        // partial upload where functions.php is the older copy). Tracking is
        // never worth a 500 on the one request that adds a customer's item.
        $capiEventId = '';
        if (function_exists('meta_capi_send') && function_exists('meta_capi_event_id')) {
            $capiEventId = meta_capi_event_id('atc');
            $addedItem = null;
            foreach (cart_items() as $i) {
                if ($i['product_id'] == $pid && ($i['variant_id'] ?? null) == $vid) { $addedItem = $i; break; }
            }
            $unitPrice = $addedItem ? item_effective_price($addedItem) : product_effective_price($prodData);
            meta_capi_send('AddToCart', $capiEventId, [
                'currency'     => defined('CURRENCY_CODE') ? CURRENCY_CODE : 'EGP',
                'value'        => $unitPrice * $qty,
                'content_ids'  => [(string)($vid ?: $pid)],
                'content_type' => 'product',
                'content_name' => $prodData['name'],
                'num_items'    => $qty,
            ]);
        }

        if ($isAjax) json_response(['success'=>true,'count'=>cart_count(),'meta_event_id'=>$capiEventId]);
        redirect('/cart.php');
    }

    if ($action === 'update') {
        $cid = (int)($_POST['cart_id'] ?? 0);
        $qty = max(1, (int)($_POST['quantity'] ?? 1));
        
        // Find cart item to check max stock
        $items = cart_items();
        $cartItem = null;
        foreach ($items as $i) {
            if ($i['id'] == $cid) {
                $cartItem = $i;
                break;
            }
        }
        
        if ($cartItem) {
            $maxStock = !empty($cartItem['has_variants']) ? (int)$cartItem['variant_stock'] : (int)$cartItem['stock'];
            // Pre-order products could be added beyond stock but then never
            // have their quantity changed on the cart page.
            $isPreorder = in_array((int)$cartItem['product_id'], preorder_product_ids([$cartItem['product_id']]), true);
            if (!$isPreorder && $qty > $maxStock) {
                if ($isAjax) json_response(['success' => false, 'message' => "Only $maxStock available in stock.", 'max_stock' => $maxStock], 400); 
                else redirect('/cart.php?err=stock');
            }
        }

        cart_update($cid, $qty);
        if ($cartItem && $qty !== (int)$cartItem['quantity']) {
            cart_event_log($qty > (int)$cartItem['quantity'] ? 'add' : 'remove', (int)$cartItem['product_id'],
                $cartItem['variant_id'] ? (int)$cartItem['variant_id'] : null, abs($qty - (int)$cartItem['quantity']), item_effective_price($cartItem));
        }
        if ($isAjax) json_response(['success'=>true,'count'=>cart_count()]);
        redirect('/cart.php');
    }

    if ($action === 'remove') {
        $cid = (int)($_POST['cart_id'] ?? 0);
        foreach (cart_items() as $i) {
            if ((int)$i['id'] === $cid) {
                cart_event_log('remove', (int)$i['product_id'], $i['variant_id'] ? (int)$i['variant_id'] : null, (int)$i['quantity'], item_effective_price($i));
                break;
            }
        }
        cart_update($cid, 0);
        if ($isAjax) json_response(['success'=>true,'count'=>cart_count()]);
        redirect('/cart.php');
    }
}

json_response(['error'=>'Bad request'], 400);
