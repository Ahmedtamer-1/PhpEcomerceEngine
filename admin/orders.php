<?php
/**
 * Admin — Orders Management
 */
require_once __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
$pageTitle = 'Orders';
$adminPage = 'orders';

// ── DELETE ORDER ──
if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    $delId = (int) $_GET['id'];
    // Puts the items back into stock (unless already cancelled/refunded)
    // and removes the rows, in one transaction.
    delete_order($delId);
    log_activity('delete_order', 'order', $delId);
    header('Location: ' . SITE_URL . '/admin/orders.php?msg=deleted');
    exit;
}

// ── BULK ACTIONS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && !empty($_POST['bulk_ids'])) {
    $action = $_POST['bulk_action'];
    $ids = $_POST['bulk_ids']; // array of IDs
    
    // Sanitize IDs
    $cleanIds = array_map('intval', $ids);
    
    if ($action === 'delete') {
        // One at a time so each order's stock is restored with its delete.
        foreach ($cleanIds as $delId) {
            delete_order($delId);
        }
        log_activity('bulk_delete_orders', 'order', 0, count($cleanIds) . " orders deleted");
        header('Location: ' . SITE_URL . '/admin/orders.php?msg=bulk_deleted');
        exit;
    } elseif (strpos($action, 'status_') === 0) {
        $status = substr($action, 7);
        $validStatuses = ['pending', 'pending_payment', 'payment_uploaded', 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];
        if (in_array($status, $validStatuses)) {
            // One at a time via set_order_status() rather than a single bulk
            // UPDATE: it is what returns items to stock when an order becomes
            // Cancelled/Refunded (and takes them again when un-cancelled).
            // The old bulk UPDATE changed the column and nothing else.
            foreach ($cleanIds as $oid) {
                set_order_status((int)$oid, $status);
            }
            log_activity('bulk_status_update', 'order', 0, count($cleanIds) . " orders marked as $status");
            header('Location: ' . SITE_URL . '/admin/orders.php?msg=bulk_updated');
            exit;
        }
    }
}

// ── UPDATE STATUS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['status'])) {
    $validStatuses = ['pending', 'pending_payment', 'payment_uploaded', 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];
    $newStatus = $_POST['status'];
    if (in_array($newStatus, $validStatuses)) {
        $prevStmt = db()->prepare('SELECT status FROM orders WHERE id = ?');
        $prevStmt->execute([(int) $_POST['order_id']]);
        $prevStatus = (string) $prevStmt->fetchColumn();

        // set_order_status() also restores stock on Cancelled/Refunded, and
        // re-deducts it if the order is moved back to an active status.
        $changed = set_order_status((int) $_POST['order_id'], $newStatus) && $prevStatus !== $newStatus;
        log_activity('update_order_status', 'order', (int) $_POST['order_id'], "Status: $newStatus");

        // Customer emails only when the status really changed — re-saving an
        // order that was already Shipped used to send the email again.
        if ($changed) {
            $emailFor = ['shipped' => 'shipped', 'paid' => 'paid', 'cancelled' => 'cancelled'];
            if (isset($emailFor[$newStatus])) send_order_email($emailFor[$newStatus], (int) $_POST['order_id']);
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        header('Location: ' . SITE_URL . '/admin/orders.php?msg=updated');
        exit;
    }
}

$success = '';
$msgs = [
    'updated' => 'Order status updated.',
    'created' => 'Order created.',
    'deleted' => 'Order deleted.',
    'bulk_deleted' => 'Selected orders were deleted.',
    'bulk_updated' => 'Selected orders were updated.'
];
if (isset($_GET['msg'], $msgs[$_GET['msg']])) {
    $success = $msgs[$_GET['msg']];
}

// ── VIEW SINGLE ORDER ──
if (isset($_GET['id'])) {
    $orderId = (int) $_GET['id'];
    $stmt = db()->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) { header('Location: ' . SITE_URL . '/admin/orders.php'); exit; }

    $items = db()->prepare('
        SELECT oi.*, p.image AS product_image, pv.image AS variant_image 
        FROM order_items oi 
        LEFT JOIN products p ON oi.product_id = p.id 
        LEFT JOIN product_variants pv ON oi.variant_id = pv.id 
        WHERE oi.order_id = ?
    ');
    $items->execute([$orderId]);
    $orderItems = $items->fetchAll();

    $pageTitle = 'Order ' . $order['order_number'];
    require_once __DIR__ . '/includes/header.php';
    ?>

    <?php if ($success): ?><div class="admin-alert admin-alert--success"><?= h($success) ?></div><?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 320px;gap:24px">
        <!-- Order Items -->
        <div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr><th>Product</th><th>SKU</th><th>Price</th><th>Qty</th><th>Subtotal</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orderItems as $i): 
                        $itemImg = !empty($i['variant_image']) ? $i['variant_image'] : (!empty($i['product_image']) ? $i['product_image'] : '');
                    ?>
                        <tr>
                            <td>
                                <div style="display:flex;align-items:center;gap:12px">
                                    <?php if (!empty($itemImg)): ?>
                                        <img src="<?= SITE_URL ?>/<?= h($itemImg) ?>" alt="<?= h($i['name']) ?>" style="width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid var(--a-border, rgba(255,255,255,0.1));flex-shrink:0;">
                                    <?php else: ?>
                                        <div style="width:48px;height:48px;border-radius:6px;border:1px solid var(--a-border, rgba(255,255,255,0.1));background:var(--a-surface2, rgba(255,255,255,0.05));display:flex;align-items:center;justify-content:center;color:var(--a-muted, #888);flex-shrink:0;">
                                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <strong><?= h($i['name']) ?></strong>
                                        <?php if (!empty($i['variant_size']) || !empty($i['variant_color'])): ?>
                                            <div style="font-size:0.8rem;color:var(--text-muted);margin-top:2px;">
                                                <?php if (!empty($i['variant_size'])) echo 'Size: ' . h($i['variant_size']) . '&nbsp;&nbsp;'; ?>
                                                <?php if (!empty($i['variant_color'])) echo 'Color: ' . h($i['variant_color']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td style="color:var(--a-muted)"><?= h($i['sku'] ?? '—') ?></td>
                            <td><?= money((float)$i['price']) ?></td>
                            <td><?= (int)$i['quantity'] ?></td>
                            <td><?= money((float)$i['subtotal']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Shipping Info -->
            <div class="admin-dashboard-panel">
                <h3 class="admin-dashboard-panel__title">Shipping Details</h3>
                <p style="margin-bottom:6px"><strong><?= h($order['shipping_name'] ?? '—') ?></strong></p>
                <p style="color:var(--text-muted);font-size:.85rem;margin-bottom:4px;"><?= h($order['shipping_addr'] ?? '') ?></p>
                <p style="color:var(--text-muted);font-size:.85rem;margin-bottom:8px;"><?= h($order['shipping_city'] ?? '') ?>, <?= h($order['shipping_country'] ?? '') ?></p>
                <?php if (!empty($order['phone'])): 
                    $cleanPhone = preg_replace('/[^0-9]/', '', $order['phone']);
                    if (preg_match('/^01[0125][0-9]{8}$/', $cleanPhone)) {
                        $cleanPhone = '2' . $cleanPhone;
                    }
                ?>
                    <p style="color:var(--text-muted);font-size:.85rem;margin-bottom:6px;display:flex;align-items:center;gap:8px;">
                        <span><strong>Phone:</strong> <?= h($order['phone']) ?></span>
                        <a href="https://wa.me/<?= h($cleanPhone) ?>" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:4px;color:#25D366;font-weight:600;font-size:0.8rem;text-decoration:none;background:rgba(37,211,102,0.12);padding:3px 8px;border-radius:4px;border:1px solid rgba(37,211,102,0.3);" title="Chat on WhatsApp">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor"><path d="M17.472 14.382c-.301-.15-1.78-.878-2.056-.978-.276-.1-.477-.15-.678.15-.2.3-.776.978-.952 1.179-.176.2-.351.225-.652.075-.301-.15-1.272-.469-2.423-1.496-.896-.798-1.502-1.784-1.678-2.085-.176-.301-.019-.464.131-.614.135-.135.301-.351.451-.526.15-.176.2-.301.3-.501.1-.2.05-.376-.025-.526-.075-.15-.678-1.631-.93-2.235-.244-.588-.493-.509-.678-.518l-.577-.01c-.2 0-.526.075-.802.376s-1.053 1.028-1.053 2.507c0 1.479 1.078 2.906 1.228 3.107.15.2 2.12 3.237 5.136 4.54.717.31 1.277.495 1.713.634.721.23 1.377.197 1.896.12.578-.086 1.78-.727 2.03-1.429.25-.702.25-1.303.176-1.429-.075-.126-.276-.201-.577-.352z"/><path d="M12 2C6.477 2 2 6.477 2 12c0 2.019.537 3.913 1.473 5.55L2 22l4.633-1.424A9.957 9.957 0 0012 22c5.523 0 10-4.477 10-10S17.523 2 12 2zm0 18c-1.792 0-3.473-.474-4.931-1.304l-.353-.201-2.753.846.858-2.684-.222-.361A7.954 7.954 0 014 12c0-4.411 3.589-8 8-8s8 3.589 8 8-3.589 8-8 8z"/></svg>
                            WhatsApp
                        </a>
                    </p>
                <?php endif; ?>
                <?php if (!empty($order['guest_email'])): ?>
                    <p style="color:var(--accent);font-size:.85rem;margin-top:4px"><?= h($order['guest_email']) ?></p>
                <?php endif; ?>
                <?php if (!empty($order['notes'])): ?>
                    <p style="color:var(--text-muted);font-size:.82rem;margin-top:12px;font-style:italic">"<?= h($order['notes']) ?>"</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Order Summary Sidebar -->
        <div>
            <div class="admin-dashboard-panel">
                <h3 class="admin-dashboard-panel__title">Summary</h3>
                <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:.85rem">
                    <span style="color:var(--text-muted)">Date</span><span><?= date('M j, Y', strtotime($order['created_at'])) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:.85rem">
                    <span style="color:var(--text-muted)">Payment</span><span style="text-transform:uppercase"><?= h($order['payment_method'] ?? 'COD') ?></span>
                </div>
                <hr style="border:none;border-top:0.5px solid var(--border);margin:12px 0">
                <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:.85rem">
                    <span style="color:var(--text-muted)">Subtotal</span><span><?= money((float)$order['subtotal']) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:.85rem">
                    <span style="color:var(--text-muted)">Shipping</span><span><?= money((float)$order['shipping_cost']) ?></span>
                </div>
                <?php if ((float)$order['discount'] > 0): ?>
                <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:.85rem">
                    <span style="color:var(--text-muted)">Discount</span><span style="color:var(--green)">-<?= money((float)$order['discount']) ?></span>
                </div>
                <?php endif; ?>
                <div style="display:flex;justify-content:space-between;padding-top:12px;border-top:0.5px solid var(--border);font-weight:600;font-size:1rem;margin-top:8px">
                    <span>Total</span><span><?= money((float)$order['total']) ?></span>
                </div>
                <?php if (!empty($order['instapay_receipt'])): ?>
                <div style="margin-top:16px;padding-top:12px;border-top:0.5px solid var(--border);">
                    <h4 style="font-size:0.85rem;color:var(--gold);margin-bottom:8px;">InstaPay Receipt</h4>
                    <a href="<?= SITE_URL ?>/<?= h($order['instapay_receipt']) ?>" target="_blank">
                        <img src="<?= SITE_URL ?>/<?= h($order['instapay_receipt']) ?>" alt="Receipt" style="max-width:100%; border:1px solid var(--border); border-radius:4px;">
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Status Update -->
            <div class="admin-dashboard-panel" style="margin-top:16px">
                <h3 class="admin-dashboard-panel__title">Update Status</h3>
                <form method="post" style="display:flex;gap:8px">
                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                    <select name="status" class="admin-form__group" style="flex:1;padding:8px 12px;background:var(--bg-input);border:0.5px solid var(--border);border-radius:var(--radius-sm);color:var(--text-primary);font-size:.82rem">
                        <?php foreach (['pending', 'pending_payment', 'payment_uploaded', 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'] as $s): ?>
                        <option value="<?= $s ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm">Update</button>
                </form>
            </div>

            <div style="margin-top:16px;display:flex;gap:8px">
                <a href="<?= SITE_URL ?>/admin/orders.php" class="admin-btn admin-btn--full">← Back to Orders</a>
                <button type="button" class="admin-btn" onclick="window.print()" title="Print Invoice"><svg viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg></button>
            </div>
            <div style="margin-top:12px">
                <a href="<?= SITE_URL ?>/admin/orders.php?action=delete&id=<?= $order['id'] ?>" class="admin-btn admin-btn--danger admin-btn--full"
                   onclick="return confirm('Delete this order permanently? This cannot be undone.')">Delete Order</a>
            </div>
        </div>
    </div>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ── LIST ──
$search = trim($_GET['search'] ?? '');
$filterStatus = $_GET['status_filter'] ?? '';

$query = 'SELECT * FROM orders WHERE 1=1';
$params = [];

if ($search) {
    $query .= ' AND (order_number LIKE ? OR shipping_name LIKE ? OR phone LIKE ? OR guest_email LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}

// Grouped filters used by the dashboard "Needs attention" shortcuts.
$statusGroups = [
    'open'     => ['pending', 'paid', 'processing'],
    'awaiting' => ['pending_payment', 'payment_uploaded'],
];
if (isset($statusGroups[$filterStatus])) {
    $query .= ' AND status IN (' . implode(',', array_fill(0, count($statusGroups[$filterStatus]), '?')) . ')';
    array_push($params, ...$statusGroups[$filterStatus]);
} elseif ($filterStatus && in_array($filterStatus, ['pending', 'pending_payment', 'payment_uploaded', 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'])) {
    $query .= ' AND status = ?';
    $params[] = $filterStatus;
}

$statusCounts = [];
try {
    $statusCounts = db()->query('SELECT status, COUNT(*) FROM orders GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {}

$sort = $_GET['sort'] ?? 'created_at';
$dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$validSorts = ['order_number', 'shipping_name', 'status', 'total', 'created_at'];
if (!in_array($sort, $validSorts)) $sort = 'created_at';

$query .= " ORDER BY $sort $dir";
$stmt = db()->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll();
require_once __DIR__ . '/includes/header.php';
?>

<?php if ($success): ?><div class="admin-alert admin-alert--success"><?= h($success) ?></div><?php endif; ?>

<div class="admin-toolbar">
    <div class="admin-toolbar__left">
        <span style="font-size:.85rem;color:var(--text-muted)"><?= count($orders) ?> orders</span>
        <div class="admin-filter-tabs">
            <a href="?status_filter=&search=<?= urlencode($search) ?>" class="admin-filter-tab <?= empty($filterStatus) ? 'active' : '' ?>">All</a>
            <?php foreach(['open' => 'To fulfil', 'awaiting' => 'Awaiting payment'] as $gk => $gl):
                $gc = 0; foreach ($statusGroups[$gk] as $gs) $gc += (int)($statusCounts[$gs] ?? 0); ?>
            <a href="?status_filter=<?= $gk ?>&search=<?= urlencode($search) ?>" class="admin-filter-tab <?= $filterStatus === $gk ? 'active' : '' ?>"><?= $gl ?><?php if ($gc): ?> <span class="admin-filter-tab__count"><?= $gc ?></span><?php endif; ?></a>
            <?php endforeach; ?>
            <?php foreach(['pending', 'pending_payment', 'payment_uploaded', 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'] as $st): ?>
            <a href="?status_filter=<?= $st ?>&search=<?= urlencode($search) ?>" class="admin-filter-tab <?= $filterStatus === $st ? 'active' : '' ?>"><?= ucwords(str_replace('_', ' ', $st)) ?><?php if (!empty($statusCounts[$st])): ?> <span class="admin-filter-tab__count"><?= (int)$statusCounts[$st] ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="admin-toolbar__right" style="display:flex; gap:12px; align-items:center">
        <form method="get" style="display:flex;gap:8px">
            <input type="hidden" name="status_filter" value="<?= h($filterStatus) ?>">
            <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search orders…" class="admin-search-input">
            <button type="submit" class="admin-btn admin-btn--sm">Search</button>
        </form>
        <a href="<?= SITE_URL ?>/admin/order-new.php" class="admin-btn admin-btn--primary">
            <svg viewBox="0 0 24 24" style="width:16px;height:16px;margin-right:6px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Order
        </a>
    </div>
</div>

<?php if (empty($orders)): ?>
<div class="admin-empty">
    <div class="admin-empty__icon">📋</div>
    <p class="admin-empty__text">No orders yet</p>
</div>
<?php else: ?>
<form id="bulk-orders-form" method="POST" action="?">
    <input type="hidden" name="bulk_action" id="bulk_action_input">
    <div class="admin-table-wrap" style="position:relative; padding-bottom:60px;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width:40px"><input type="checkbox" id="selectAll" onclick="toggleAllCheckboxes(this)"></th>
                    <th onclick="window.location='?sort=order_number&dir=<?= $sort === 'order_number' && $dir === 'ASC' ? 'desc' : 'asc' ?>'" class="<?= $sort === 'order_number' ? 'sort-' . strtolower($dir) : '' ?>">Order</th>
                    <th onclick="window.location='?sort=shipping_name&dir=<?= $sort === 'shipping_name' && $dir === 'ASC' ? 'desc' : 'asc' ?>'" class="<?= $sort === 'shipping_name' ? 'sort-' . strtolower($dir) : '' ?>">Customer</th>
                    <th onclick="window.location='?sort=status&dir=<?= $sort === 'status' && $dir === 'ASC' ? 'desc' : 'asc' ?>'" class="<?= $sort === 'status' ? 'sort-' . strtolower($dir) : '' ?>">Status</th>
                    <th onclick="window.location='?sort=total&dir=<?= $sort === 'total' && $dir === 'ASC' ? 'desc' : 'asc' ?>'" class="<?= $sort === 'total' ? 'sort-' . strtolower($dir) : '' ?>">Total</th>
                    <th>Payment</th>
                    <th onclick="window.location='?sort=created_at&dir=<?= $sort === 'created_at' && $dir === 'ASC' ? 'desc' : 'asc' ?>'" class="<?= $sort === 'created_at' ? 'sort-' . strtolower($dir) : '' ?>">Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td><input type="checkbox" name="bulk_ids[]" value="<?= $o['id'] ?>" class="row-checkbox" onclick="updateBulkActionBar()"></td>
                <td><a href="<?= SITE_URL ?>/admin/orders.php?id=<?= $o['id'] ?>" style="color:var(--accent)"><strong><?= h($o['order_number']) ?></strong></a></td>
                <td><?= h($o['shipping_name'] ?? $o['guest_email'] ?? '—') ?></td>
                <td>
                    <select class="admin-badge admin-badge--<?= h($o['status']) ?>" 
                            style="border:none; cursor:pointer; outline:none; padding-right:20px; font-family:inherit; font-weight:600; text-transform:uppercase" 
                            onchange="quickUpdateStatus(this, <?= $o['id'] ?>)">
                        <?php foreach(['pending','paid','processing','shipped','delivered','cancelled','refunded'] as $s): ?>
                            <option value="<?= $s ?>" <?= $o['status'] === $s ? 'selected' : '' ?> style="background:var(--bg-primary); color:var(--text-primary); text-transform:uppercase"><?= h($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><?= money((float)$o['total']) ?></td>
                <td style="color:var(--text-muted);font-size:.8rem"><?= h($o['payment_method'] ?? '—') ?></td>
                <td style="color:var(--text-muted)"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
                <td style="white-space:nowrap">
                    <a href="<?= SITE_URL ?>/admin/orders.php?id=<?= $o['id'] ?>" class="admin-btn admin-btn--sm">View</a>
                    <a href="<?= SITE_URL ?>/admin/orders.php?action=delete&id=<?= $o['id'] ?>" class="admin-btn admin-btn--sm admin-btn--danger"
                       onclick="return confirm('Delete order <?= h($o['order_number']) ?>? This cannot be undone.')">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</form>

<!-- Floating Bulk Action Bar -->
<div id="bulk-action-bar" style="display:none; position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:var(--bg-surface); border:0.5px solid var(--border); padding:12px 24px; border-radius:30px; box-shadow:0 10px 30px rgba(0,0,0,0.2); z-index:100; align-items:center; gap:16px;">
    <span id="bulk-count" style="font-weight:600; font-size:.9rem; color:var(--text-primary)">0 selected</span>
    <div style="width:1px; height:20px; background:var(--border)"></div>
    <select id="bulk-action-select" style="padding:6px 12px; background:var(--bg-input); border:0.5px solid var(--border); border-radius:4px; color:var(--text-primary)">
        <option value="">Choose action...</option>
        <option value="status_pending">Mark Pending</option>
        <option value="status_paid">Mark Paid</option>
        <option value="status_processing">Mark Processing</option>
        <option value="status_shipped">Mark Shipped</option>
        <option value="status_delivered">Mark Delivered</option>
        <option value="status_cancelled">Mark Cancelled</option>
        <option value="status_refunded">Mark Refunded</option>
        <option value="delete">Delete</option>
    </select>
    <button type="button" class="admin-btn admin-btn--primary admin-btn--sm" onclick="applyBulkAction()">Apply</button>
</div>

<?php endif; ?>

<script>
function toggleAllCheckboxes(masterCheckbox) {
    const checkboxes = document.querySelectorAll('.row-checkbox');
    checkboxes.forEach(cb => cb.checked = masterCheckbox.checked);
    updateBulkActionBar();
}

function updateBulkActionBar() {
    const checkboxes = document.querySelectorAll('.row-checkbox:checked');
    const bar = document.getElementById('bulk-action-bar');
    const count = document.getElementById('bulk-count');
    
    if (checkboxes.length > 0) {
        bar.style.display = 'flex';
        count.innerText = checkboxes.length + ' selected';
    } else {
        bar.style.display = 'none';
        document.getElementById('selectAll').checked = false;
    }
}

function applyBulkAction() {
    const select = document.getElementById('bulk-action-select');
    const action = select.value;
    if (!action) {
        alert('Please select an action.');
        return;
    }
    
    if (action === 'delete') {
        if (!confirm('Are you sure you want to delete the selected orders? This cannot be undone.')) {
            return;
        }
    }
    
    document.getElementById('bulk_action_input').value = action;
    document.getElementById('bulk-orders-form').submit();
}

function quickUpdateStatus(select, orderId) {
    select.style.opacity = '0.5';
    
    const formData = new FormData();
    formData.append('order_id', orderId);
    formData.append('status', select.value);
    
    fetch('<?= SITE_URL ?>/admin/orders.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        select.style.opacity = '1';
        if (data.success) {
            AdminUI.toast('Order status updated', 'success');
            select.className = 'admin-badge admin-badge--' + select.value;
        } else {
            AdminUI.toast('Failed to update status', 'error');
        }
    })
    .catch(() => {
        select.style.opacity = '1';
        AdminUI.toast('Error updating status', 'error');
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
