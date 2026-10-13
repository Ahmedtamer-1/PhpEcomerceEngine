<?php
/**
 * Admin — Create Manual Order
 */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Create Order';
$adminPage = 'orders';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_order') {
    $guestEmail = trim($_POST['guest_email'] ?? '');
    $shippingName = trim($_POST['shipping_name'] ?? '');
    $shippingAddr = trim($_POST['shipping_addr'] ?? '');
    $shippingCity = trim($_POST['shipping_city'] ?? '');
    $shippingCountry = trim($_POST['shipping_country'] ?? '') ?: 'Egypt';
    $phone = trim($_POST['phone'] ?? '');

    // Only statuses the form offers; payment method is free text for manual
    // orders (e.g. "manual", "cash", "bank") but kept short.
    $allowedStatuses = ['pending', 'paid', 'processing', 'shipped', 'delivered'];
    $status = in_array($_POST['status'] ?? '', $allowedStatuses, true) ? $_POST['status'] : 'pending';
    $paymentMethod = mb_substr(trim((string)($_POST['payment_method'] ?? 'manual')), 0, 30) ?: 'manual';

    $shippingCost = max(0, (float)($_POST['shipping_cost'] ?? 0));
    $discount = max(0, (float)($_POST['discount'] ?? 0));

    $rawItems = json_decode($_POST['items_json'] ?? '[]', true);
    $items = [];
    if (is_array($rawItems)) {
        foreach ($rawItems as $it) {
            if (!is_array($it) || empty($it['product_id'])) continue;
            $qty = (int)($it['quantity'] ?? 0);
            if ($qty < 1) continue;
            $items[] = [
                'product_id'    => (int)$it['product_id'],
                'variant_id'    => !empty($it['variant_id']) ? (int)$it['variant_id'] : null,
                'variant_size'  => $it['variant_size'] ?? null,
                'variant_color' => $it['variant_color'] ?? null,
                'name'          => mb_substr((string)($it['name'] ?? 'Item'), 0, 255),
                'sku'           => $it['sku'] ?? null,
                'price'         => max(0, round((float)($it['price'] ?? 0), 2)),
                'cost_price'    => isset($it['cost_price']) && $it['cost_price'] !== '' ? (float)$it['cost_price'] : null,
                'quantity'      => $qty,
            ];
        }
    }

    if (empty($items)) {
        $error = 'You must add at least one product to the order.';
    } elseif (!$shippingName) {
        $error = 'Customer name is required.';
    } elseif ($guestEmail !== '' && !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'The customer email address is not valid.';
    } else {
        // Subtotal is computed here from the items, not trusted from the page.
        $subtotal = 0.0;
        foreach ($items as $it) $subtotal += $it['price'] * $it['quantity'];
        $total = max(0, $subtotal + $shippingCost - $discount);

        try {
            // Same function checkout uses: same order-number format, and the
            // same stock deduction (variant AND product totals, with the
            // variant roll-up recomputed), so cancelling
            // or deleting this order later puts back exactly what it took.
            $orderId = create_order([
                'user_id'          => null,
                'guest_email'      => $guestEmail ?: null,
                'subtotal'         => $subtotal,
                'shipping_cost'    => $shippingCost,
                'discount'         => $discount,
                'total'            => $total,
                'payment_method'   => $paymentMethod,
                'shipping_name'    => $shippingName,
                'shipping_addr'    => $shippingAddr,
                'shipping_city'    => $shippingCity,
                'shipping_country' => $shippingCountry,
                'phone'            => $phone,
                'items'            => $items,
                // The admin may record a sale the shelf count doesn't show yet.
                'allow_oversell'   => true,
            ]);

            if ($status !== 'pending') {
                db()->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $orderId]);
            }
            $num = db()->prepare('SELECT order_number FROM orders WHERE id = ?');
            $num->execute([$orderId]);
            log_activity('create_manual_order', 'order', $orderId, 'Created manual order ' . $num->fetchColumn());

            header('Location: ' . SITE_URL . '/admin/orders.php?id=' . $orderId . '&msg=created');
            exit;
        } catch (Exception $e) {
            error_log('Manual order failed: ' . $e->getMessage());
            $error = 'Failed to create the order. Please check the items and try again.';
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div style="margin-bottom:24px">
    <a href="<?= SITE_URL ?>/admin/orders.php" class="admin-btn admin-btn--sm" style="opacity:.7">← Back to Orders</a>
</div>
<h1 style="font-size:1.5rem;font-weight:700;margin-bottom:24px">Create Manual Order</h1>

<?php if ($error): ?><div class="admin-alert admin-alert--error"><?= h($error) ?></div><?php endif; ?>

<form method="POST" id="manual-order-form">
    <input type="hidden" name="action" value="create_order">
    <input type="hidden" name="items_json" id="items_json" value="[]">
    
    <div style="display:grid;grid-template-columns:1fr 350px;gap:24px">
        <!-- LEFT COLUMN: PRODUCTS -->
        <div>
            <div style="background:var(--a-surface);border:1px solid var(--a-border);border-radius:var(--a-radius);padding:24px;margin-bottom:24px">
                <h3 style="font-size:1.1rem;font-weight:600;margin-bottom:16px">Order Items</h3>
                
                <div style="display:flex;gap:12px;margin-bottom:24px;align-items:flex-end">
                    <div class="admin-form__group" style="margin:0;flex:1">
                        <label>Select Product</label>
                        <select id="product-select" style="width:100%;padding:8px;background:var(--a-bg);color:var(--a-text);border:1px solid var(--a-border);border-radius:4px" onchange="loadVariants()">
                            <option value="">-- Choose Product --</option>
                        </select>
                    </div>
                    <div class="admin-form__group" style="margin:0;flex:1;display:none" id="variant-wrapper">
                        <label>Select Variant</label>
                        <select id="variant-select" style="width:100%;padding:8px;background:var(--a-bg);color:var(--a-text);border:1px solid var(--a-border);border-radius:4px">
                        </select>
                    </div>
                    <div class="admin-form__group" style="margin:0;width:80px">
                        <label>Qty</label>
                        <input type="number" id="item-qty" min="1" value="1" style="width:100%">
                    </div>
                    <button type="button" class="admin-btn admin-btn--primary" onclick="addItem()">Add to Order</button>
                </div>
                
                <table class="admin-table" id="items-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Price</th>
                            <th>Qty</th>
                            <th>Total</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="items-tbody">
                        <tr id="empty-row"><td colspan="5" style="text-align:center;color:var(--a-muted)">No items added yet.</td></tr>
                    </tbody>
                </table>
            </div>
            
            <!-- TOTALS -->
            <div style="background:var(--a-surface);border:1px solid var(--a-border);border-radius:var(--a-radius);padding:24px">
                <h3 style="font-size:1.1rem;font-weight:600;margin-bottom:16px">Financials</h3>
                
                <div style="display:flex;justify-content:space-between;margin-bottom:12px">
                    <span>Subtotal</span>
                    <span id="display-subtotal" style="font-weight:600"><?= CURRENCY_SYMBOL ?>0.00</span>
                    <input type="hidden" name="subtotal" id="input-subtotal" value="0">
                </div>
                
                <div style="display:flex;justify-content:space-between;margin-bottom:12px;align-items:center">
                    <span>Shipping Cost</span>
                    <div style="position:relative;width:120px">
                        <span style="position:absolute;left:8px;top:8px;color:var(--a-muted)"><?= CURRENCY_SYMBOL ?></span>
                        <input type="number" step="0.01" name="shipping_cost" id="input-shipping" value="0" style="width:100%;padding-left:24px;text-align:right" oninput="calculateTotal()">
                    </div>
                </div>
                
                <div style="display:flex;justify-content:space-between;margin-bottom:16px;align-items:center">
                    <span>Discount</span>
                    <div style="position:relative;width:120px">
                        <span style="position:absolute;left:8px;top:8px;color:var(--a-muted)"><?= CURRENCY_SYMBOL ?></span>
                        <input type="number" step="0.01" name="discount" id="input-discount" value="0" style="width:100%;padding-left:24px;text-align:right;color:var(--a-danger)" oninput="calculateTotal()">
                    </div>
                </div>
                
                <div style="border-top:1px solid var(--a-border);padding-top:16px;display:flex;justify-content:space-between;font-size:1.2rem;font-weight:700">
                    <span>Total</span>
                    <span id="display-total" style="color:var(--a-gold)"><?= CURRENCY_SYMBOL ?>0.00</span>
                </div>
            </div>
        </div>
        
        <!-- RIGHT COLUMN: CUSTOMER -->
        <div>
            <div style="background:var(--a-surface);border:1px solid var(--a-border);border-radius:var(--a-radius);padding:24px;margin-bottom:24px">
                <h3 style="font-size:1.1rem;font-weight:600;margin-bottom:16px">Customer Info</h3>
                
                <div class="admin-form__group">
                    <label>Full Name *</label>
                    <input type="text" name="shipping_name" required>
                </div>
                <div class="admin-form__group">
                    <label>Email Address</label>
                    <input type="email" name="guest_email">
                </div>
                <div class="admin-form__group">
                    <label>Phone Number</label>
                    <input type="text" name="phone">
                </div>
                <div class="admin-form__group">
                    <label>Address</label>
                    <input type="text" name="shipping_addr">
                </div>
                <div style="display:flex;gap:12px">
                    <div class="admin-form__group" style="flex:1">
                        <label>City</label>
                        <input type="text" name="shipping_city">
                    </div>
                    <div class="admin-form__group" style="flex:1">
                        <label>Country</label>
                        <input type="text" name="shipping_country" value="Egypt">
                    </div>
                </div>
            </div>
            
            <div style="background:var(--a-surface);border:1px solid var(--a-border);border-radius:var(--a-radius);padding:24px">
                <h3 style="font-size:1.1rem;font-weight:600;margin-bottom:16px">Order Settings</h3>
                <div class="admin-form__group">
                    <label>Order Status</label>
                    <select name="status">
                        <option value="paid">Paid</option>
                        <option value="pending" selected>Pending</option>
                        <option value="processing">Processing</option>
                        <option value="shipped">Shipped</option>
                        <option value="delivered">Delivered</option>
                    </select>
                </div>
                <div class="admin-form__group">
                    <label>Payment Method</label>
                    <input type="text" name="payment_method" value="Manual (Admin)">
                </div>
                
                <button type="button" onclick="submitOrder()" class="admin-btn admin-btn--primary admin-btn--full" style="margin-top:24px;font-size:1.1rem;padding:12px">Create Order</button>
            </div>
        </div>
    </div>
</form>

<script>
function escHtml(s){const d=document.createElement('div');d.textContent=s==null?'':String(s);return d.innerHTML;}
let productsData = [];
let selectedItems = [];

// Format money helper
function formatMoney(amount) {
    return '<?= CURRENCY_SYMBOL ?>' + parseFloat(amount).toFixed(2);
}

// Load products on start
fetch('<?= SITE_URL ?>/api/admin-products.php?action=list_products')
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            productsData = data.products;
            const sel = document.getElementById('product-select');
            productsData.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.name + ' - ' + formatMoney(p.price);
                sel.appendChild(opt);
            });
        }
    });

function loadVariants() {
    const pid = document.getElementById('product-select').value;
    const vWrap = document.getElementById('variant-wrapper');
    const vSel = document.getElementById('variant-select');
    vSel.innerHTML = '';
    
    if (!pid) {
        vWrap.style.display = 'none';
        return;
    }
    
    const product = productsData.find(p => p.id == pid);
    if (product.has_variants == 1) {
        vWrap.style.display = 'block';
        vSel.innerHTML = '<option value="">Loading variants...</option>';
        fetch('<?= SITE_URL ?>/api/admin-products.php?action=get_variants&product_id=' + pid)
            .then(r => r.json())
            .then(data => {
                vSel.innerHTML = '';
                if (data.success && data.variants.length > 0) {
                    data.variants.forEach(v => {
                        const opt = document.createElement('option');
                        opt.value = v.id;
                        let label = [];
                        if (v.color_name) label.push(v.color_name);
                        if (v.size) label.push(v.size);
                        let vPrice = v.price_override ? v.price_override : product.price;
                        opt.textContent = label.join(' / ') + ' - ' + formatMoney(vPrice) + ' (' + v.stock + ' in stock)';
                        opt.dataset.color = v.color_name || '';
                        opt.dataset.size = v.size || '';
                        opt.dataset.price = vPrice;
                        opt.dataset.sku = v.sku || product.sku;
                        opt.dataset.cost = v.cost_price || product.cost_price || 0;
                        vSel.appendChild(opt);
                    });
                } else {
                    vWrap.style.display = 'none';
                }
            });
    } else {
        vWrap.style.display = 'none';
    }
}

function addItem() {
    const pid = document.getElementById('product-select').value;
    const qty = parseInt(document.getElementById('item-qty').value) || 1;
    
    if (!pid) return;
    
    const product = productsData.find(p => p.id == pid);
    let item = {
        product_id: product.id,
        name: product.name,
        quantity: qty
    };
    
    if (product.has_variants == 1) {
        const vSel = document.getElementById('variant-select');
        const vOpt = vSel.options[vSel.selectedIndex];
        if (!vOpt) return alert('Please select a variant.');
        item.variant_id = vOpt.value;
        item.variant_color = vOpt.dataset.color;
        item.variant_size = vOpt.dataset.size;
        item.price = parseFloat(vOpt.dataset.price);
        item.sku = vOpt.dataset.sku;
        item.cost_price = parseFloat(vOpt.dataset.cost);
    } else {
        item.price = parseFloat(product.sale_price > 0 ? product.sale_price : product.price);
        item.sku = product.sku;
        item.cost_price = parseFloat(product.cost_price || 0);
    }
    
    // Check if already in cart
    const existingIndex = selectedItems.findIndex(i => i.product_id == item.product_id && i.variant_id == item.variant_id);
    if (existingIndex > -1) {
        selectedItems[existingIndex].quantity += qty;
    } else {
        selectedItems.push(item);
    }
    
    renderItems();
}

function removeItem(index) {
    selectedItems.splice(index, 1);
    renderItems();
}

function renderItems() {
    const tbody = document.getElementById('items-tbody');
    tbody.innerHTML = '';
    
    if (selectedItems.length === 0) {
        tbody.innerHTML = '<tr id="empty-row"><td colspan="5" style="text-align:center;color:var(--a-muted)">No items added yet.</td></tr>';
        document.getElementById('input-subtotal').value = 0;
        calculateTotal();
        return;
    }
    
    let subtotal = 0;
    
    selectedItems.forEach((item, index) => {
        const total = item.price * item.quantity;
        subtotal += total;
        
        const tr = document.createElement('tr');
        
        let metaHtml = '';
        if (item.variant_size || item.variant_color) {
            let meta = [];
            if (item.variant_color) meta.push('Color: ' + item.variant_color);
            if (item.variant_size) meta.push('Size: ' + item.variant_size);
            metaHtml = `<div style="font-size:.75rem;color:var(--a-muted);margin-top:4px">${escHtml(meta.join(' | '))}</div>`;
        }
        
        tr.innerHTML = `
            <td>
                <strong>${escHtml(item.name)}</strong>
                ${metaHtml}
            </td>
            <td>${formatMoney(item.price)}</td>
            <td>${parseInt(item.quantity, 10)}</td>
            <td>${formatMoney(total)}</td>
            <td><button type="button" class="admin-btn admin-btn--sm admin-btn--danger" onclick="removeItem(${index})">✕</button></td>
        `;
        tbody.appendChild(tr);
    });
    
    document.getElementById('input-subtotal').value = subtotal;
    calculateTotal();
}

function calculateTotal() {
    const subtotal = parseFloat(document.getElementById('input-subtotal').value) || 0;
    const shipping = parseFloat(document.getElementById('input-shipping').value) || 0;
    const discount = parseFloat(document.getElementById('input-discount').value) || 0;
    
    const total = Math.max(0, subtotal + shipping - discount);
    
    document.getElementById('display-subtotal').innerText = formatMoney(subtotal);
    document.getElementById('display-total').innerText = formatMoney(total);
}

function submitOrder() {
    if (selectedItems.length === 0) {
        alert('Please add at least one product to the order.');
        return;
    }
    document.getElementById('items_json').value = JSON.stringify(selectedItems);
    document.getElementById('manual-order-form').submit();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
