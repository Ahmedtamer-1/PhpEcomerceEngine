<?php
/**
 * Admin Dashboard
 */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Dashboard';
$adminPage = 'dashboard';
analytics_ensure_schema();

// Ensure ad_spend table exists just in case
try {
    db()->exec("CREATE TABLE IF NOT EXISTS ad_spend (
        id INT AUTO_INCREMENT PRIMARY KEY,
        platform VARCHAR(50) NOT NULL,
        campaign_name VARCHAR(100) NULL,
        amount DECIMAL(10,2) NOT NULL,
        date_logged DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {}

// ── Date range ──
$R = report_range($_GET['range'] ?? 'this_month');
$range = $R['key'];
$rangeLabel = $R['label'];
$startDate = $R['start'];  $endDate = $R['end'];
$startDt = $startDate . ' 00:00:00';  $endDt = $endDate . ' 23:59:59';
$pStartDt = $R['prevStart'] . ' 00:00:00';  $pEndDt = $R['prevEnd'] . ' 23:59:59';
$compare = $range !== 'all_time';

$val = function (string $sql, array $p = []) {
    try { $s = db()->prepare($sql); $s->execute($p); return $s->fetchColumn(); } catch (Exception $e) { error_log('dashboard query: ' . $e->getMessage()); return 0; }
};
$rows = function (string $sql, array $p = []) {
    try { $s = db()->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (Exception $e) { error_log('report query: ' . $e->getMessage()); return []; }
};
$LIVE = "status NOT IN ('cancelled','refunded')"; // an order that still counts as a sale

// ── Sales (booked = every order not cancelled) and revenue (delivered only) ──
$periodSales     = (float) $val("SELECT COALESCE(SUM(total),0) FROM orders WHERE $LIVE AND created_at BETWEEN ? AND ?", [$startDt, $endDt]);
$prevSales       = (float) $val("SELECT COALESCE(SUM(total),0) FROM orders WHERE $LIVE AND created_at BETWEEN ? AND ?", [$pStartDt, $pEndDt]);
$periodOrders    = (int) $val("SELECT COUNT(*) FROM orders WHERE $LIVE AND created_at BETWEEN ? AND ?", [$startDt, $endDt]);
$prevOrders      = (int) $val("SELECT COUNT(*) FROM orders WHERE $LIVE AND created_at BETWEEN ? AND ?", [$pStartDt, $pEndDt]);
$cancelledOrders = (int) $val("SELECT COUNT(*) FROM orders WHERE status IN ('cancelled','refunded') AND created_at BETWEEN ? AND ?", [$startDt, $endDt]);
$periodRevenue   = (float) $val('SELECT COALESCE(SUM(total),0) FROM orders WHERE status = "delivered" AND created_at BETWEEN ? AND ?', [$startDt, $endDt]);
$validOrdersCount= (int) $val('SELECT COUNT(*) FROM orders WHERE status = "delivered" AND created_at BETWEEN ? AND ?', [$startDt, $endDt]);
$aov     = $periodOrders > 0 ? $periodSales / $periodOrders : 0;
$prevAov = $prevOrders > 0 ? $prevSales / $prevOrders : 0;

$periodVisitors = analytics_visitors_total($startDate, $endDate);
$prevVisitors   = $compare ? analytics_visitors_total($R['prevStart'], $R['prevEnd']) : 0;
$conversionRate = $periodVisitors > 0 ? $periodOrders / $periodVisitors * 100 : 0;
$prevConversion = $prevVisitors > 0 ? $prevOrders / $prevVisitors * 100 : 0;

$newCustomers   = (int) $val('SELECT COUNT(*) FROM users WHERE role = "customer" AND created_at BETWEEN ? AND ?', [$startDt, $endDt]);
// A buyer is "returning" if they had a live order before their first one in
// this period (matched by account, or by email for guest checkouts).
$WHO = "COALESCE(CONCAT('u', user_id), LOWER(guest_email))";
$repeatBuyers   = (int) $val(
    "SELECT COUNT(*) FROM (SELECT $WHO who, MIN(created_at) first_in FROM orders
                           WHERE $LIVE AND created_at BETWEEN ? AND ? GROUP BY who) p
     WHERE EXISTS (SELECT 1 FROM orders o2 WHERE o2.$LIVE AND o2.created_at < p.first_in
                   AND COALESCE(CONCAT('u', o2.user_id), LOWER(o2.guest_email)) = p.who)",
    [$startDt, $endDt]);
$buyersInPeriod = (int) $val("SELECT COUNT(DISTINCT $WHO) FROM orders WHERE $LIVE AND created_at BETWEEN ? AND ?", [$startDt, $endDt]);

// Welcome offer
$welcomeUsed = $rows("SELECT COUNT(*) n, COALESCE(SUM(o.discount),0) amt FROM users u JOIN orders o ON o.id = u.welcome_discount_order_id
                      WHERE o.$LIVE AND o.created_at BETWEEN ? AND ?", [$startDt, $endDt])[0] ?? ['n' => 0, 'amt' => 0];

// ── Financials (delivered orders) ──
$totalDiscounts = (float) $val('SELECT COALESCE(SUM(discount),0) FROM orders WHERE status = "delivered" AND created_at BETWEEN ? AND ?', [$startDt, $endDt]);
$totalShipping  = (float) $val('SELECT COALESCE(SUM(shipping_cost),0) FROM orders WHERE status = "delivered" AND created_at BETWEEN ? AND ?', [$startDt, $endDt]);
$netProductRevenue = $periodRevenue - $totalShipping; // total already has the discount taken off
$totalCogs = (float) $val('SELECT COALESCE(SUM(oi.cost_price * oi.quantity), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                           WHERE o.status = "delivered" AND o.created_at BETWEEN ? AND ?', [$startDt, $endDt]);
$totalAdSpend = (float) $val('SELECT COALESCE(SUM(amount), 0) FROM ad_spend WHERE date_logged BETWEEN ? AND ?', [$startDate, $endDate]);
$grossProfit = $netProductRevenue - $totalCogs;
$netProfit   = $grossProfit - $totalAdSpend;
$roas = $totalAdSpend > 0 ? $periodSales / $totalAdSpend : null;

// ── Chart: sales per hour (single day), day, or month ──
$single = $startDate === $endDate;
$byMonth = $R['days'] > 120;
$fmt = $single ? '%H' : ($byMonth ? '%Y-%m' : '%Y-%m-%d');
$salesBy = $rows("SELECT DATE_FORMAT(created_at, '$fmt') k, SUM(total) s, COUNT(*) n FROM orders WHERE $LIVE AND created_at BETWEEN ? AND ? GROUP BY k", [$startDt, $endDt]);
$salesBy = array_column($salesBy, null, 'k');
$visBy = [];
if ($single) {
    foreach ($rows("SELECT DATE_FORMAT(created_at, '%H') k, COUNT(DISTINCT visitor_id) v FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY k", [$startDt, $endDt]) as $r) $visBy[$r['k']] = (int)$r['v'];
} elseif ($byMonth) {
    foreach (analytics_daily_visitors($startDate, $endDate) as $d => $v) { $m = substr($d, 0, 7); $visBy[$m] = ($visBy[$m] ?? 0) + $v; }
} else {
    $visBy = analytics_daily_visitors($startDate, $endDate);
}
$chartLabels = $chartSales = $chartVisitors = [];
if ($single) {
    $lastHour = $endDate === date('Y-m-d') ? (int)date('G') : 23;
    for ($hr = 0; $hr <= $lastHour; $hr++) {
        $k = str_pad((string)$hr, 2, '0', STR_PAD_LEFT);
        $chartLabels[] = $k . ':00';
        $chartSales[] = round((float)($salesBy[$k]['s'] ?? 0));
        $chartVisitors[] = (int)($visBy[$k] ?? 0);
    }
} else {
    $firstKey = ($salesBy || $visBy) ? min(array_merge(array_keys($salesBy), array_keys($visBy))) : null;
    $cursor = strtotime($byMonth ? date('Y-m-01', strtotime($startDate)) : $startDate);
    while ($cursor <= strtotime($endDate)) {
        $k = date($byMonth ? 'Y-m' : 'Y-m-d', $cursor);
        if ($range !== 'all_time' || ($firstKey && $k >= $firstKey)) {
            $chartLabels[] = date($byMonth ? 'M Y' : ($R['days'] <= 7 ? 'D j' : 'M j'), $cursor);
            $chartSales[] = round((float)($salesBy[$k]['s'] ?? 0));
            $chartVisitors[] = (int)($visBy[$k] ?? 0);
        }
        $cursor = strtotime($byMonth ? '+1 month' : '+1 day', $cursor);
    }
}
$sparkSales = json_encode(array_slice($chartSales, -14));

// ── Funnel (needs the detailed tracker) ──
$funnel = $rows("SELECT
        COUNT(DISTINCT visitor_id) visitors,
        COUNT(DISTINCT CASE WHEN page_type = 'product' THEN visitor_id END) viewed,
        COUNT(DISTINCT CASE WHEN page_type = 'checkout' THEN visitor_id END) checkout
    FROM page_views WHERE created_at BETWEEN ? AND ?", [$startDt, $endDt])[0] ?? [];
$funnelBag = (int) $val("SELECT COUNT(DISTINCT visitor_id) FROM cart_events WHERE event = 'add' AND visitor_id IS NOT NULL AND created_at BETWEEN ? AND ?", [$startDt, $endDt]);
$funnelBuy = (int) $val("SELECT COUNT(DISTINCT visitor_id) FROM cart_events WHERE event = 'purchase' AND visitor_id IS NOT NULL AND created_at BETWEEN ? AND ?", [$startDt, $endDt]);
$hasFunnel = (int)($funnel['visitors'] ?? 0) > 0;
$steps = $hasFunnel ? [
    ['Visited the shop', (int)$funnel['visitors']],
    ['Viewed a product', (int)$funnel['viewed']],
    ['Added to bag', $funnelBag],
    ['Reached checkout', (int)$funnel['checkout']],
    ['Placed an order', $funnelBuy],
] : [['Visitors', $periodVisitors], ['Orders', $periodOrders]];

// ── Lists ──
$topProducts = $rows("SELECT oi.product_id, oi.name, SUM(oi.quantity) total_qty, SUM(oi.subtotal) total_revenue
                      FROM order_items oi JOIN orders o ON o.id = oi.order_id
                      WHERE o.$LIVE AND o.created_at BETWEEN ? AND ?
                      GROUP BY oi.product_id, oi.name ORDER BY total_qty DESC, total_revenue DESC LIMIT 6", [$startDt, $endDt]);
$mostWanted = $rows("SELECT ce.product_id, p.name, p.stock, p.image,
                            COUNT(DISTINCT COALESCE(ce.visitor_id, ce.session_key)) adders,
                            SUM(CASE WHEN ce.event = 'purchase' THEN ce.quantity ELSE 0 END) bought
                     FROM cart_events ce JOIN products p ON p.id = ce.product_id
                     WHERE ce.created_at BETWEEN ? AND ? AND ce.event IN ('add','purchase')
                     GROUP BY ce.product_id, p.name, p.stock, p.image
                     HAVING adders > 0 ORDER BY adders DESC, bought DESC LIMIT 6", [$startDt, $endDt]);
$sources = $rows("SELECT source, COUNT(DISTINCT visitor_id) n FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY source ORDER BY n DESC LIMIT 6", [$startDt, $endDt]);
$cities  = $rows("SELECT COALESCE(NULLIF(shipping_city, ''), 'Unknown') city, COUNT(*) n, SUM(total) s FROM orders
                  WHERE $LIVE AND created_at BETWEEN ? AND ? GROUP BY city ORDER BY n DESC, s DESC LIMIT 6", [$startDt, $endDt]);
$recentActivity = $rows('SELECT al.*, u.first_name, u.last_name FROM activity_log al LEFT JOIN users u ON u.id = al.user_id ORDER BY al.created_at DESC LIMIT 5');

// ── Needs attention (clickable shortcuts at the top) ──
$attn = [
    'pending'  => (int) $val("SELECT COUNT(*) FROM orders WHERE status IN ('pending','paid','processing')"),
    'awaiting' => (int) $val("SELECT COUNT(*) FROM orders WHERE status IN ('pending_payment','payment_uploaded')"),
    'unread'   => (int) $val('SELECT COUNT(*) FROM contact_messages WHERE is_read = 0'),
];
// Sold-out count. Most pieces are one-offs (stock 1–2), so "low stock" is
// nearly the whole catalogue and meaningless; sold-out is what needs action.
$soldOutCount = (int) $val('SELECT COUNT(*) FROM products WHERE is_active = 1 AND stock <= 0');

// Bags idle for 1h+ in the last 7 days, with their value.
$abandoned = $rows("SELECT COUNT(DISTINCT COALESCE(CONCAT('u', cs.user_id), cs.session_key)) n,
                           COALESCE(SUM(cs.quantity * COALESCE(NULLIF(pv.price_override, 0),
                               CASE WHEN p.sale_price > 0 AND p.sale_price < p.price THEN p.sale_price ELSE p.price END)), 0) v
                    FROM cart_sessions cs JOIN products p ON p.id = cs.product_id
                    LEFT JOIN product_variants pv ON pv.id = cs.variant_id
                    WHERE cs.updated_at < DATE_SUB(NOW(), INTERVAL 1 HOUR) AND cs.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")[0] ?? ['n' => 0, 'v' => 0];

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

require_once __DIR__ . '/includes/header.php';
?>

<!-- GREETING + DATE RANGE -->
<div class="g-dash-head">
    <div class="g-dash-greet">
        <h2><?= h($greeting) ?>, <?= h($adminUser['first_name'] ?? '') ?></h2>
        <p>Here&rsquo;s how the store is doing &mdash; <?= h($rangeLabel) ?><?= $compare ? ', compared with the period before' : '' ?>.</p>
    </div>
    <nav class="g-range" aria-label="Date range">
        <?php foreach (report_ranges() as $rk => $rl): ?>
            <a href="?range=<?= $rk ?>" class="<?= $range === $rk ? 'is-active' : '' ?>"><?= h($rl) ?></a>
        <?php endforeach; ?>
    </nav>
</div>

<!-- NEEDS ATTENTION — each card opens the filtered list -->
<div class="g-attention">
    <a href="<?= SITE_URL ?>/admin/orders.php?status_filter=open" class="g-attn g-attn--blue <?= $attn['pending'] ? '' : 'g-attn--muted' ?>">
        <span class="g-attn__icon"><svg viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></span>
        <span><span class="g-attn__num"><?= $attn['pending'] ?></span><br><span class="g-attn__label">Orders to fulfil</span></span>
        <span class="g-attn__go">&rarr;</span>
    </a>
    <a href="<?= SITE_URL ?>/admin/orders.php?status_filter=awaiting" class="g-attn g-attn--amber <?= $attn['awaiting'] ? '' : 'g-attn--muted' ?>">
        <span class="g-attn__icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg></span>
        <span><span class="g-attn__num"><?= $attn['awaiting'] ?></span><br><span class="g-attn__label">Awaiting payment</span></span>
        <span class="g-attn__go">&rarr;</span>
    </a>
    <a href="<?= SITE_URL ?>/admin/demand.php#carts" class="g-attn g-attn--red <?= $abandoned['n'] ? '' : 'g-attn--muted' ?>">
        <span class="g-attn__icon"><svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg></span>
        <span><span class="g-attn__num"><?= (int)$abandoned['n'] ?></span><br><span class="g-attn__label">Abandoned bags &middot; <?= money((float)$abandoned['v']) ?></span></span>
        <span class="g-attn__go">&rarr;</span>
    </a>
    <a href="<?= SITE_URL ?>/admin/messages.php?filter=unread" class="g-attn g-attn--green <?= $attn['unread'] ? '' : 'g-attn--muted' ?>">
        <span class="g-attn__icon"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span>
        <span><span class="g-attn__num"><?= $attn['unread'] ?></span><br><span class="g-attn__label">Unread messages</span></span>
        <span class="g-attn__go">&rarr;</span>
    </a>
</div>

<!-- HEADLINE NUMBERS -->
<div class="admin-stats">
    <div class="admin-stat-card admin-stat-card--accent">
        <div class="admin-stat-card__label" title="Every order placed in the period that wasn't cancelled or refunded">Sales</div>
        <div class="admin-stat-card__value accent"><?= money($periodSales) ?></div>
        <?= $compare ? trend_chip(trend_pct($periodSales, $prevSales)) : '' ?>
        <svg class="metric-sparkline metric-sparkline--green" viewBox="0 0 100 32" preserveAspectRatio="none" id="sparkline-revenue"></svg>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__label">Orders</div>
        <div class="admin-stat-card__value"><?= number_format($periodOrders) ?></div>
        <?= $compare ? trend_chip(trend_pct($periodOrders, $prevOrders)) : '' ?>
        <div class="g-hint"><?= $cancelledOrders ? $cancelledOrders . ' cancelled not counted' : 'none cancelled' ?></div>
    </div>
    <a class="admin-stat-card g-card-link" href="<?= SITE_URL ?>/admin/analytics.php?range=<?= h($range) ?>">
        <div class="admin-stat-card__label">Visitors</div>
        <div class="admin-stat-card__value"><?= number_format($periodVisitors) ?></div>
        <?= $compare ? trend_chip(trend_pct($periodVisitors, $prevVisitors)) : '' ?>
        <div class="g-hint">See sources &amp; devices &rarr;</div>
    </a>
    <div class="admin-stat-card">
        <div class="admin-stat-card__label" title="Orders ÷ visitors">Conversion rate</div>
        <div class="admin-stat-card__value"><?= number_format($conversionRate, 2) ?>%</div>
        <?php if ($compare && $prevConversion > 0): $d = round($conversionRate - $prevConversion, 2); ?>
            <?php if ($d != 0): ?><span class="admin-stat-card__trend admin-stat-card__trend--<?= $d > 0 ? 'up' : 'down' ?>"><?= $d > 0 ? '+' : '' ?><?= number_format($d, 2) ?> pts</span><?php endif; ?>
        <?php endif; ?>
        <div class="g-hint"><?= number_format($periodOrders) ?> order<?= $periodOrders === 1 ? '' : 's' ?> from <?= number_format($periodVisitors) ?> visitors</div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__label">Avg order value</div>
        <div class="admin-stat-card__value"><?= money($aov) ?></div>
        <?= $compare ? trend_chip(trend_pct($aov, $prevAov)) : '' ?>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__label" title="Total of orders marked Delivered in this period">Revenue &middot; delivered</div>
        <div class="admin-stat-card__value"><?= money($periodRevenue) ?></div>
        <div class="g-hint"><?= $validOrdersCount ?> delivered<?= $periodSales > $periodRevenue ? ' · ' . money($periodSales - $periodRevenue) . ' still on the way' : '' ?></div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__label">Customers</div>
        <div class="admin-stat-card__value"><?= number_format($buyersInPeriod) ?></div>
        <div class="g-hint"><?= $repeatBuyers ?> returning · <?= $newCustomers ?> new account<?= $newCustomers === 1 ? '' : 's' ?></div>
    </div>
    <a class="admin-stat-card g-card-link" href="<?= SITE_URL ?>/admin/settings.php#welcome">
        <div class="admin-stat-card__label">Welcome offer</div>
        <div class="admin-stat-card__value"><?= (int)$welcomeUsed['n'] ?></div>
        <div class="g-hint"><?= welcome_offer_enabled() ? 'orders used it · ' . money((float)$welcomeUsed['amt']) . ' off' : 'switched off &middot; turn on in Settings' ?></div>
    </a>
</div>

<!-- SALES vs VISITORS -->
<div class="admin-dashboard-grid">
    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">Sales and visitors <span class="g-hint" style="margin:0">per <?= $single ? 'hour' : ($byMonth ? 'month' : 'day') ?></span></div>
        <div class="chart-container">
            <?php if (!array_sum($chartSales) && !array_sum($chartVisitors)): ?>
                <div class="admin-empty" style="padding: 40px 20px;"><div class="admin-empty__text">No orders or visits in this period</div></div>
            <?php else: ?>
                <canvas id="salesChart"></canvas>
            <?php endif; ?>
        </div>
    </div>

    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">Profit <span class="g-hint" style="margin:0">delivered orders</span></div>
        <table class="deductions-table">
            <tr><td class="d-label">Delivered revenue</td><td class="d-value"><?= money($periodRevenue) ?></td></tr>
            <tr><td class="d-label">Shipping collected</td><td class="d-value d-neg">-<?= money($totalShipping) ?></td></tr>
            <tr><td class="d-label">Cost of goods</td><td class="d-value d-neg">-<?= money($totalCogs) ?></td></tr>
            <tr><td class="d-label">Ad spend</td><td class="d-value d-neg">-<?= money($totalAdSpend) ?></td></tr>
            <tr class="d-total"><td class="d-label"><strong>Net profit</strong></td><td class="d-value <?= $netProfit >= 0 ? 'd-pos' : 'd-neg' ?>"><strong><?= money($netProfit) ?></strong></td></tr>
        </table>
        <div class="g-mini-stats" style="margin-top:14px">
            <div><b><?= $netProductRevenue > 0 ? round($grossProfit / $netProductRevenue * 100) . '%' : '&mdash;' ?></b><span>gross margin</span></div>
            <div><b><?= $roas === null ? '&mdash;' : number_format($roas, 1) . '×' ?></b><span title="Sales ÷ ad spend">return on ads</span></div>
            <div><b><?= money($totalDiscounts) ?></b><span>discounts given</span></div>
        </div>
        <?php if ($totalAdSpend == 0): ?><p class="g-hint"><a href="<?= SITE_URL ?>/admin/ad-spend.php">Log ad spend</a> to see profit after ads and return on ads.</p><?php endif; ?>
    </div>
</div>

<!-- FUNNEL / WANTED / SOURCES -->
<div class="admin-dashboard-grid--3">
    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">Shopper journey</div>
        <div class="g-funnel">
            <?php $top = max(1, $steps[0][1]); foreach ($steps as $i => [$label, $n]):
                $prevN = $i ? $steps[$i - 1][1] : 0; ?>
            <div class="g-funnel__step">
                <div class="g-funnel__row"><span><?= h($label) ?></span><b><?= number_format($n) ?></b></div>
                <div class="g-bar__track"><span style="width:<?= max($n ? 1.5 : 0, round($n / $top * 100, 1)) ?>%"></span></div>
                <?php if ($i && $prevN): ?><div class="g-funnel__drop"><?= round($n / $prevN * 100) ?>% of previous step</div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$hasFunnel): ?><p class="g-hint">The full journey (product views, bags, checkout) is recorded from this update onward.</p><?php endif; ?>
    </div>

    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">Most wanted <a href="<?= SITE_URL ?>/admin/demand.php?range=<?= h($range) ?>">All demand</a></div>
        <?php if (!$mostWanted): ?>
            <div class="admin-empty" style="padding:20px 0;"><div class="admin-empty__text">No add-to-bag activity recorded yet</div></div>
        <?php else: ?>
            <div class="g-rank">
            <?php foreach ($mostWanted as $w): ?>
                <a class="g-rank__row" href="<?= SITE_URL ?>/admin/products.php?edit=<?= (int)$w['product_id'] ?>">
                    <img src="<?= h(product_image($w, 80)) ?>" alt="" loading="lazy">
                    <span class="g-rank__name"><?= h($w['name']) ?><?php if ((int)$w['stock'] <= 0): ?> <span class="g-tag g-tag--restock">sold out</span><?php endif; ?></span>
                    <span class="g-rank__num" title="People who added it / units bought"><?= (int)$w['adders'] ?> <small>bagged</small> · <?= (int)$w['bought'] ?> <small>sold</small></span>
                </a>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">Traffic sources <a href="<?= SITE_URL ?>/admin/analytics.php?range=<?= h($range) ?>">Details</a></div>
        <?php if (!$sources): ?>
            <div class="admin-empty" style="padding:20px 0;"><div class="admin-empty__text">Recorded from this update onward</div></div>
        <?php else: $sMax = max(array_column($sources, 'n')); ?>
            <div class="g-bars">
            <?php foreach ($sources as $s): ?>
                <div class="g-bar"><span class="g-bar__label"><?= h($s['source']) ?></span><span class="g-bar__track"><span style="width:<?= round($s['n'] / max(1, $sMax) * 100, 1) ?>%"></span></span><span class="g-bar__val"><?= number_format((int)$s['n']) ?></span></div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- BEST SELLERS / CITIES / ACTIVITY -->
<div class="admin-dashboard-grid--3">
    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">Best sellers</div>
        <?php if (empty($topProducts)): ?>
            <div class="admin-empty" style="padding:20px 0;"><div class="admin-empty__text">No sales in this period</div></div>
        <?php else: ?>
            <table class="admin-table" style="font-size:12px;">
                <thead><tr><th>Product</th><th class="num">Sold</th><th class="num">Sales</th></tr></thead>
                <tbody>
                <?php foreach ($topProducts as $tp): ?>
                <tr>
                    <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($tp['name']) ?></td>
                    <td class="num"><?= (int)$tp['total_qty'] ?></td>
                    <td class="num"><?= money((float)$tp['total_revenue']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">Orders by city</div>
        <?php if (!$cities): ?>
            <div class="admin-empty" style="padding:20px 0;"><div class="admin-empty__text">No orders in this period</div></div>
        <?php else: $cMax = max(array_column($cities, 'n')); ?>
            <div class="g-bars">
            <?php foreach ($cities as $c): ?>
                <div class="g-bar" title="<?= h(strip_tags(money((float)$c['s']))) ?>"><span class="g-bar__label"><?= h($c['city']) ?></span><span class="g-bar__track"><span style="width:<?= round($c['n'] / max(1, $cMax) * 100, 1) ?>%"></span></span><span class="g-bar__val"><?= (int)$c['n'] ?></span></div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">
            Activity Log
            <a href="<?= SITE_URL ?>/admin/activity.php">View All</a>
        </div>
        <?php if (empty($recentActivity)): ?>
            <div class="admin-empty" style="padding:20px 0;"><div class="admin-empty__text">No recent activity</div></div>
        <?php else: ?>
            <div class="admin-timeline">
                <?php foreach ($recentActivity as $act):
                    $who = trim(($act['first_name'] ?? '') . ' ' . ($act['last_name'] ?? ''));
                    if (!$who) $who = 'System';
                    $diff = time() - strtotime($act['created_at']);
                    if ($diff < 60) $timeAgo = 'now';
                    elseif ($diff < 3600) $timeAgo = floor($diff/60) . 'm ago';
                    elseif ($diff < 86400) $timeAgo = floor($diff/3600) . 'h ago';
                    else $timeAgo = floor($diff/86400) . 'd ago';
                ?>
                <div class="admin-timeline__item">
                    <div class="admin-timeline__dot"></div>
                    <div class="admin-timeline__content">
                        <strong><?= h($who) ?></strong> <?= h(str_replace('_', ' ', $act['action'])) ?>
                    </div>
                    <div class="admin-timeline__meta"><?= $timeAgo ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
window.sparklineData = <?= $sparkSales ?>;
window.gCharts = window.gCharts || [];
window.gCharts.push({
    el: 'salesChart',
    labels: <?= json_encode($chartLabels) ?>,
    series: [
        {label: 'Sales', data: <?= json_encode($chartSales) ?>, kind: 'bar', tone: 1, money: true},
        {label: 'Visitors', data: <?= json_encode($chartVisitors) ?>, kind: 'line', axis: 'y2', tone: 3}
    ]
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
