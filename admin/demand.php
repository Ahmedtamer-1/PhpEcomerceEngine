<?php
/**
 * Admin — Product demand & abandoned carts
 *
 * Which pieces people look at, put in their bag, and actually buy, plus the
 * carts that are sitting there right now. Views and add-to-cart history come
 * from page_views / cart_events (recorded from the day this was installed);
 * sales come from orders, so they go back to the beginning.
 */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Product Demand';
$adminPage = 'demand';
analytics_ensure_schema();

$R = report_range($_GET['range'] ?? '30days');
$from = $R['start'] . ' 00:00:00';
$to   = $R['end'] . ' 23:59:59';
$trackingSince = setting('analytics_started_at');
$sort = $_GET['sort'] ?? 'adds';
$q    = trim((string)($_GET['q'] ?? ''));

$fetchPairs = function (string $sql, array $params): array {
    try { $s = db()->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_UNIQUE); }
    catch (Exception $e) { error_log('report query: ' . $e->getMessage()); return []; }
};

// ── Per-product numbers ──
$views = $fetchPairs(
    "SELECT product_id, COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS viewers
     FROM page_views WHERE page_type = 'product' AND product_id IS NOT NULL AND created_at BETWEEN ? AND ?
     GROUP BY product_id", [$from, $to]);
$adds = $fetchPairs(
    "SELECT product_id,
            SUM(CASE WHEN event = 'add' THEN quantity ELSE 0 END) AS adds,
            COUNT(DISTINCT CASE WHEN event = 'add' THEN COALESCE(visitor_id, session_key) END) AS adders,
            SUM(CASE WHEN event = 'remove' THEN quantity ELSE 0 END) AS removes,
            SUM(CASE WHEN event = 'purchase' THEN quantity ELSE 0 END) AS bought_tracked
     FROM cart_events WHERE created_at BETWEEN ? AND ? GROUP BY product_id", [$from, $to]);
$sold = $fetchPairs(
    "SELECT oi.product_id, SUM(oi.quantity) AS sold, SUM(oi.subtotal) AS revenue, COUNT(DISTINCT oi.order_id) AS orders
     FROM order_items oi JOIN orders o ON o.id = oi.order_id
     WHERE oi.product_id IS NOT NULL AND o.status NOT IN ('cancelled','refunded') AND o.created_at BETWEEN ? AND ?
     GROUP BY oi.product_id", [$from, $to]);
$inCarts = $fetchPairs(
    "SELECT product_id, SUM(quantity) AS in_carts, COUNT(DISTINCT COALESCE(CONCAT('u', user_id), session_key)) AS carts
     FROM cart_sessions WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY product_id", []);

$ids = array_unique(array_merge(array_keys($views), array_keys($adds), array_keys($sold), array_keys($inCarts)));
$rows = [];
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $s = db()->prepare("SELECT p.id, p.name, p.slug, p.image, p.stock, p.price, p.sale_price, p.is_active, c.name AS category
                        FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id IN ($ph)");
    $s->execute(array_values($ids));
    foreach ($s->fetchAll() as $p) {
        $id = (int)$p['id'];
        $r = $p + [
            'views'   => (int)($views[$id]['views'] ?? 0),
            'viewers' => (int)($views[$id]['viewers'] ?? 0),
            'adds'    => (int)($adds[$id]['adds'] ?? 0),
            'adders'  => (int)($adds[$id]['adders'] ?? 0),
            'removes' => (int)($adds[$id]['removes'] ?? 0),
            'bought_tracked' => (int)($adds[$id]['bought_tracked'] ?? 0),
            'sold'    => (int)($sold[$id]['sold'] ?? 0),
            'revenue' => (float)($sold[$id]['revenue'] ?? 0),
            'in_carts'=> (int)($inCarts[$id]['in_carts'] ?? 0),
            'carts'   => (int)($inCarts[$id]['carts'] ?? 0),
        ];
        if ($q !== '' && mb_stripos($r['name'], $q) === false) continue;
        $r['add_rate']  = $r['viewers'] > 0 ? min(100, round($r['adders'] / $r['viewers'] * 100)) : null;
        $r['buy_rate']  = $r['adds'] > 0 ? min(100, round($r['bought_tracked'] / $r['adds'] * 100)) : null;
        $r['not_bought']= max(0, $r['adds'] - $r['bought_tracked']);
        $rows[$id] = $r;
    }
}

// ── Signals: what to act on ──
$addersSorted = array_column($rows, 'adders');
rsort($addersSorted);
$hotCut = max(2, $addersSorted[(int)floor(count($addersSorted) * 0.1)] ?? 2);
foreach ($rows as &$r) {
    $sig = [];
    $soldOut = (int)$r['stock'] <= 0;
    if ($soldOut && ($r['adders'] >= 2 || $r['carts'] >= 1 || $r['viewers'] >= 15)) $sig[] = ['restock', 'Wanted · sold out', 'People still want it: consider remaking or restocking.'];
    if ($r['adders'] >= $hotCut) $sig[] = ['hot', 'Hot', 'Among the most-added pieces in this period.'];
    if ($r['viewers'] >= 20 && ($r['add_rate'] ?? 0) < 3) $sig[] = ['look', 'Looked, didn’t add', 'Many views, few adds: check the price, photos or description.'];
    if ($r['adds'] >= 3 && ($r['buy_rate'] ?? 0) < 25) $sig[] = ['stall', 'Added, not bought', 'Often added to bags but rarely ordered.'];
    $r['signals'] = $sig;
}
unset($r);

$sorts = [
    'adds' => fn($a, $b) => [$b['adders'], $b['adds'], $b['viewers']] <=> [$a['adders'], $a['adds'], $a['viewers']],
    'views' => fn($a, $b) => $b['viewers'] <=> $a['viewers'],
    'sold' => fn($a, $b) => [$b['sold'], $b['revenue']] <=> [$a['sold'], $a['revenue']],
    'not_bought' => fn($a, $b) => $b['not_bought'] <=> $a['not_bought'],
    'in_carts' => fn($a, $b) => $b['in_carts'] <=> $a['in_carts'],
    'add_rate' => fn($a, $b) => [$b['add_rate'] ?? -1, $b['viewers']] <=> [$a['add_rate'] ?? -1, $a['viewers']],
];
if (!isset($sorts[$sort])) $sort = 'adds';
uasort($rows, $sorts[$sort]);
$wanted = array_filter($rows, fn($r) => in_array('restock', array_column($r['signals'], 0), true));

// ── Cart totals and abandonment for the period ──
$cartKpi = ['started' => 0, 'converted' => 0, 'add_units' => 0];
try {
    $s = db()->prepare("SELECT COUNT(DISTINCT CASE WHEN event='add' THEN session_key END) AS started,
                               COUNT(DISTINCT CASE WHEN event='purchase' THEN session_key END) AS converted,
                               COALESCE(SUM(CASE WHEN event='add' THEN quantity END),0) AS add_units
                        FROM cart_events WHERE created_at BETWEEN ? AND ?");
    $s->execute([$from, $to]);
    $cartKpi = $s->fetch() ?: $cartKpi;
} catch (Exception $e) { error_log("report query: " . $e->getMessage()); }
$abandonRate = $cartKpi['started'] > 0 ? max(0, round(100 - $cartKpi['converted'] / $cartKpi['started'] * 100)) : null;

// ── Daily trend: views, adds, purchases ──
$byMonth = $R['days'] > 120;
$fmt = $byMonth ? '%Y-%m' : '%Y-%m-%d';
$trend = [];
try {
    $s = db()->prepare("SELECT DATE_FORMAT(created_at, '$fmt') d,
                               SUM(CASE WHEN event='add' THEN quantity ELSE 0 END) adds,
                               SUM(CASE WHEN event='purchase' THEN quantity ELSE 0 END) bought,
                               COUNT(DISTINCT CASE WHEN event='add' THEN session_key END) carts
                        FROM cart_events WHERE created_at BETWEEN ? AND ? GROUP BY d");
    $s->execute([$from, $to]);
    foreach ($s->fetchAll() as $t) $trend[$t['d']] = $t;
    $s = db()->prepare("SELECT DATE_FORMAT(created_at, '$fmt') d, COUNT(*) v FROM page_views
                        WHERE page_type='product' AND created_at BETWEEN ? AND ? GROUP BY d");
    $s->execute([$from, $to]);
    foreach ($s->fetchAll(PDO::FETCH_KEY_PAIR) as $d => $v) $trend[$d]['views'] = (int)$v;
} catch (Exception $e) { error_log("report query: " . $e->getMessage()); }
$labels = $sAdds = $sBought = $sViews = [];
$cursor = strtotime($byMonth ? date('Y-m-01', strtotime($R['start'])) : $R['start']);
$firstData = $trend ? min(array_keys($trend)) : null;
while ($cursor <= strtotime($R['end'])) {
    $k = date($byMonth ? 'Y-m' : 'Y-m-d', $cursor);
    if ($R['key'] !== 'all_time' || ($firstData && $k >= $firstData)) {
        $labels[] = date($byMonth ? 'M Y' : 'M j', $cursor);
        $sAdds[]   = (int)($trend[$k]['adds'] ?? 0);
        $sBought[] = (int)($trend[$k]['bought'] ?? 0);
        $sViews[]  = (int)($trend[$k]['views'] ?? 0);
    }
    $cursor = strtotime($byMonth ? '+1 month' : '+1 day', $cursor);
}

// ── Abandoned carts right now ──
$carts = [];
try {
    $rowsC = db()->query(
        "SELECT cs.id, cs.session_key, cs.user_id, cs.product_id, cs.quantity, cs.created_at, cs.updated_at,
                p.name, p.slug, p.image, p.price, p.sale_price, p.stock, pv.price_override AS variant_price,
                pv.size AS variant_size, pv.color_name AS variant_color,
                u.first_name, u.last_name, u.email
         FROM cart_sessions cs
         JOIN products p ON p.id = cs.product_id
         LEFT JOIN product_variants pv ON pv.id = cs.variant_id
         LEFT JOIN users u ON u.id = cs.user_id
         WHERE cs.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         ORDER BY cs.updated_at DESC"
    )->fetchAll();
    foreach ($rowsC as $c) {
        $key = $c['user_id'] ? 'u' . $c['user_id'] : 's' . $c['session_key'];
        $carts[$key] ??= ['user' => $c['user_id'] ? trim($c['first_name'] . ' ' . $c['last_name']) : null,
                          'email' => $c['email'], 'items' => [], 'value' => 0.0, 'last' => $c['updated_at'], 'first' => $c['created_at']];
        $carts[$key]['items'][] = $c;
        $carts[$key]['value'] += item_effective_price($c) * (int)$c['quantity'];
        if ($c['updated_at'] > $carts[$key]['last']) $carts[$key]['last'] = $c['updated_at'];
        if ($c['created_at'] < $carts[$key]['first']) $carts[$key]['first'] = $c['created_at'];
    }
} catch (Exception $e) { error_log("report query: " . $e->getMessage()); }
$dbNow = (int) strtotime((string) db()->query('SELECT NOW()')->fetchColumn());
$idle = fn(string $t) => max(0, $dbNow - strtotime($t));
$abandoned = array_filter($carts, fn($c) => $idle($c['last']) >= 3600);
$activeNow = count($carts) - count($abandoned);
$abandonedValue = array_sum(array_column($abandoned, 'value'));
$ago = function (int $sec): string {
    if ($sec < 3600) return max(1, intdiv($sec, 60)) . ' min';
    if ($sec < 86400) return intdiv($sec, 3600) . ' h';
    return intdiv($sec, 86400) . ' d';
};

$qs = fn(array $over) => '?' . http_build_query(array_merge(['range' => $R['key'], 'sort' => $sort, 'q' => $q], $over));

require_once __DIR__ . '/includes/header.php';
?>

<div class="g-dash-head">
    <div class="g-dash-greet">
        <h2>Product demand</h2>
        <p>What people look at, put in their bag and actually buy &mdash; <?= h($R['label']) ?>.</p>
    </div>
    <nav class="g-range" aria-label="Date range">
        <?php foreach (report_ranges() as $rk => $rl): ?>
            <a href="<?= h($qs(['range' => $rk])) ?>" class="<?= $R['key'] === $rk ? 'is-active' : '' ?>"><?= h($rl) ?></a>
        <?php endforeach; ?>
    </nav>
</div>

<?php if ($trackingSince && $R['start'] < substr($trackingSince, 0, 10)): ?>
<p class="g-note">Views and add-to-cart history are recorded from <?= h(date('j M Y', strtotime($trackingSince))) ?>. &ldquo;Sold&rdquo; uses all orders, so it goes back further.</p>
<?php endif; ?>

<div class="admin-stats">
    <div class="admin-stat-card admin-stat-card--accent">
        <div class="admin-stat-card__label">Added to bag</div>
        <div class="admin-stat-card__value"><?= number_format((int)$cartKpi['add_units']) ?></div>
        <div class="g-hint"><?= number_format((int)$cartKpi['started']) ?> bags started</div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__label">Cart abandonment</div>
        <div class="admin-stat-card__value"><?= $abandonRate === null ? '&mdash;' : $abandonRate . '%' ?></div>
        <div class="g-hint"><?= number_format((int)$cartKpi['converted']) ?> of <?= number_format((int)$cartKpi['started']) ?> bags became an order</div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__label">Sitting in bags now</div>
        <div class="admin-stat-card__value"><?= money($abandonedValue) ?></div>
        <div class="g-hint"><?= count($abandoned) ?> idle bag<?= count($abandoned) === 1 ? '' : 's' ?> (1h+)<?= $activeNow ? ' · ' . $activeNow . ' shopping now' : '' ?></div>
    </div>
    <div class="admin-stat-card <?= $wanted ? 'admin-stat-card--amber' : '' ?>">
        <div class="admin-stat-card__label">Wanted but sold out</div>
        <div class="admin-stat-card__value"><?= count($wanted) ?></div>
        <div class="g-hint">pieces to consider remaking</div>
    </div>
</div>

<div class="admin-dashboard-panel" style="margin-bottom:22px">
    <div class="admin-dashboard-panel__title">Demand over time</div>
    <?php if (array_sum($sAdds) + array_sum($sViews) + array_sum($sBought) === 0): ?>
        <div class="admin-empty" style="padding:30px 10px"><div class="admin-empty__text">No product views or add-to-carts recorded in this period yet.</div></div>
    <?php else: ?>
        <div class="chart-container"><canvas id="demandChart"></canvas></div>
    <?php endif; ?>
</div>

<?php if ($wanted): ?>
<div class="admin-dashboard-panel" style="margin-bottom:22px">
    <div class="admin-dashboard-panel__title">Wanted but sold out <span class="g-hint" style="margin:0">people viewed or bagged these after they sold out</span></div>
    <div class="g-chips">
        <?php foreach (array_slice($wanted, 0, 12, true) as $id => $w): ?>
        <a class="g-chip" href="<?= SITE_URL ?>/admin/products.php?edit=<?= (int)$id ?>">
            <img src="<?= h(product_image($w, 120)) ?>" alt="" loading="lazy">
            <span><b><?= h($w['name']) ?></b><small><?= $w['adders'] ?> bagged · <?= $w['viewers'] ?> viewers<?= $w['carts'] ? ' · in ' . $w['carts'] . ' bag' . ($w['carts'] > 1 ? 's' : '') : '' ?></small></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="admin-dashboard-panel" style="margin-bottom:22px" id="products">
    <div class="admin-dashboard-panel__title" style="flex-wrap:wrap;gap:10px">
        <span>Every product</span>
        <form method="get" class="g-toolbar-row" style="margin-left:auto">
            <input type="hidden" name="range" value="<?= h($R['key']) ?>">
            <input type="hidden" name="sort" value="<?= h($sort) ?>">
            <input type="search" name="q" value="<?= h($q) ?>" placeholder="Filter by name" class="g-select-sm" style="min-width:180px">
        </form>
    </div>
    <?php if (!$rows): ?>
        <div class="admin-empty" style="padding:30px 10px"><div class="admin-empty__text">No product activity in this period<?= $q !== '' ? ' matching “' . h($q) . '”' : '' ?>.</div></div>
    <?php else: ?>
    <div class="admin-responsive-table">
    <table class="admin-table g-demand-table">
        <thead><tr>
            <th>Product</th>
            <?php
            $cols = ['views' => ['Viewers', 'Different people who opened the product page'],
                     'adds' => ['Bagged', 'People who added it to their bag (units in brackets)'],
                     'add_rate' => ['View → bag', 'Share of viewers who added it'],
                     'not_bought' => ['Not bought', 'Units added to a bag but not ordered'],
                     'sold' => ['Sold', 'Units in orders that were not cancelled'],
                     'in_carts' => ['In bags now', 'Units sitting in bags (last 30 days)']];
            foreach ($cols as $ck => [$cl, $tip]): ?>
            <th class="num" title="<?= h($tip) ?>"><a href="<?= h($qs(['sort' => $ck])) ?>#products" class="<?= $sort === $ck ? 'is-sorted' : '' ?>"><?= h($cl) ?><?= $sort === $ck ? ' ↓' : '' ?></a></th>
            <?php endforeach; ?>
            <th>Stock</th>
        </tr></thead>
        <tbody>
        <?php foreach (array_slice($rows, 0, 150, true) as $id => $r): ?>
        <tr>
            <td>
                <div class="g-prod-cell">
                    <img src="<?= h(product_image($r, 120)) ?>" alt="" loading="lazy">
                    <div style="min-width:0">
                        <a class="g-prod-name" href="<?= SITE_URL ?>/admin/products.php?edit=<?= (int)$id ?>"><?= h($r['name']) ?></a>
                        <div class="g-hint" style="margin-top:2px"><?= h($r['category'] ?? '') ?><?= !$r['is_active'] ? ' · hidden' : '' ?></div>
                        <?php if ($r['signals']): ?><div class="g-tags"><?php foreach ($r['signals'] as [$k, $label, $tip]): ?><span class="g-tag g-tag--<?= $k ?>" title="<?= h($tip) ?>"><?= h($label) ?></span><?php endforeach; ?></div><?php endif; ?>
                    </div>
                </div>
            </td>
            <td class="num"><?= $r['viewers'] ?: '<span class="g-dim">0</span>' ?></td>
            <td class="num"><?= $r['adders'] ?: '<span class="g-dim">0</span>' ?><?= $r['adds'] > $r['adders'] ? ' <span class="g-dim">(' . $r['adds'] . ')</span>' : '' ?></td>
            <td class="num"><?php if ($r['add_rate'] === null): ?><span class="g-dim">&mdash;</span><?php else: ?><span class="g-meter" style="--v:<?= $r['add_rate'] ?>%"></span><?= $r['add_rate'] ?>%<?php endif; ?></td>
            <td class="num"><?= $r['not_bought'] ?: '<span class="g-dim">0</span>' ?></td>
            <td class="num"><?= $r['sold'] ?: '<span class="g-dim">0</span>' ?><?php if ($r['revenue'] > 0): ?><div class="g-hint" style="margin:0"><?= money($r['revenue']) ?></div><?php endif; ?></td>
            <td class="num"><?= $r['in_carts'] ?: '<span class="g-dim">0</span>' ?></td>
            <td><?php if ((int)$r['stock'] <= 0): ?><span class="admin-badge admin-badge--cancelled">Sold out</span><?php else: ?><?= (int)$r['stock'] ?><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<div class="admin-dashboard-panel" id="carts">
    <div class="admin-dashboard-panel__title">Abandoned bags <span class="g-hint" style="margin:0">idle for over an hour, last 30 days &middot; <?= count($abandoned) ?> bags &middot; <?= money($abandonedValue) ?></span></div>
    <?php if (!$abandoned): ?>
        <div class="admin-empty" style="padding:30px 10px"><div class="admin-empty__text">No abandoned bags right now.</div></div>
    <?php else: ?>
    <div class="admin-responsive-table">
    <table class="admin-table">
        <thead><tr><th>Shopper</th><th>In the bag</th><th class="num">Value</th><th class="num">Idle for</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($abandoned, 0, 100) as $c): ?>
        <tr>
            <td style="white-space:nowrap">
                <?php if ($c['user']): ?>
                    <strong><?= h($c['user']) ?></strong><br>
                    <a class="g-hint" href="mailto:<?= h($c['email']) ?>?subject=<?= rawurlencode('Still thinking it over?') ?>"><?= h($c['email']) ?></a>
                <?php else: ?>
                    <span class="g-dim">Guest</span><div class="g-hint" style="margin:0">not signed in</div>
                <?php endif; ?>
            </td>
            <td>
                <div class="g-bag">
                <?php foreach ($c['items'] as $it): ?>
                    <span class="g-bag__item" title="<?= h($it['name']) ?>">
                        <img src="<?= h(product_image($it, 80)) ?>" alt="" loading="lazy">
                        <span><?= h($it['name']) ?><?= $it['quantity'] > 1 ? ' ×' . (int)$it['quantity'] : '' ?><?= (int)$it['stock'] <= 0 ? ' <em>sold out</em>' : '' ?></span>
                    </span>
                <?php endforeach; ?>
                </div>
            </td>
            <td class="num"><?= money($c['value']) ?></td>
            <td class="num"><?= $ago($idle($c['last'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="g-hint">Guests can&rsquo;t be contacted; signed-in shoppers show their email. The welcome offer encourages more shoppers to sign in.</p>
    <?php endif; ?>
</div>

<script>
window.gCharts = window.gCharts || [];
window.gCharts.push({
    el: 'demandChart',
    labels: <?= json_encode($labels) ?>,
    series: [
        {label: 'Product views', data: <?= json_encode($sViews) ?>, kind: 'line', axis: 'y2', tone: 3},
        {label: 'Added to bag', data: <?= json_encode($sAdds) ?>, kind: 'bar', tone: 1},
        {label: 'Bought', data: <?= json_encode($sBought) ?>, kind: 'bar', tone: 2}
    ]
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
