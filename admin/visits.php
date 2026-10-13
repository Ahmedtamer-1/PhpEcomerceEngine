<?php
/**
 * Admin — Visit log
 *
 * Every visit one at a time: where it came from, the pages in order with the
 * time spent on each, what went in the bag, and whether it ended in an order.
 * ?visitor=<id> shows one visitor's whole history. Data comes from the
 * page-view beacon (api/pv.php) and cart_events; admins and bots aren't counted.
 */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Visit log';
$adminPage = 'visits';
analytics_ensure_schema();

$visitor = (string)($_GET['visitor'] ?? '');
if (!preg_match('/^[a-f0-9]{16}$/', $visitor)) $visitor = '';
$R = report_range($visitor ? 'all_time' : ($_GET['range'] ?? '7days'));
$from = $R['start'] . ' 00:00:00';
$to   = $R['end'] . ' 23:59:59';
$source = mb_substr(trim((string)($_GET['source'] ?? '')), 0, 40);
$device = in_array($_GET['device'] ?? '', ['mobile', 'tablet', 'desktop'], true) ? $_GET['device'] : '';
$shows = ['all' => 'All visits', 'ordered' => 'Ordered', 'bagged' => 'Added to bag', 'browsed' => 'Browsed 3+ pages',
          'returning' => 'Returning', 'signed' => 'Signed in', 'bounced' => 'Left after 1 page'];
$show = isset($shows[$_GET['show'] ?? '']) ? $_GET['show'] : 'all';
// Insights (the summary) is the default; "Each visit" is the raw log.
$view = ($visitor || ($_GET['view'] ?? '') === 'log' || isset($_GET['show'])) ? 'log' : 'insights';
$perPage = 30;
$pageNo = max(1, (int)($_GET['p'] ?? 1));

$all = function (string $sql, array $p = []) {
    try { $s = db()->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (Exception $e) { error_log('visit log: ' . $e->getMessage()); return []; }
};
$qs = function (array $over = []) use ($R, $source, $device, $show, $visitor, $view): string {
    $q = array_filter(array_merge(['view' => $view === 'log' && !$visitor ? 'log' : null, 'range' => $visitor ? null : $R['key'], 'source' => $source, 'device' => $device,
        'show' => $show === 'all' ? null : $show, 'visitor' => $visitor], $over), fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($q);
};

// One row per visit (browser-tab session). Bag and order activity is matched to
// the visit by visitor and time: anything up to 30 minutes after its last page.
$SESSIONS = "SELECT session_id, visitor_id, MIN(created_at) started, MAX(created_at) last_at, COUNT(*) views,
                    SUM(COALESCE(duration_s, 0)) dur, MAX(user_id) user_id, MAX(is_new_visitor) is_new,
                    SUBSTRING_INDEX(GROUP_CONCAT(source ORDER BY id SEPARATOR '\x1f'), '\x1f', 1) source,
                    MAX(is_paid) paid, MAX(device) device, MAX(browser) browser, MAX(utm_campaign) campaign
             FROM page_views WHERE created_at BETWEEN ? AND ?" . ($visitor ? ' AND visitor_id = ?' : '') . "
             GROUP BY session_id, visitor_id";
$HAS = fn(string $event) => "EXISTS (SELECT 1 FROM cart_events ce WHERE ce.visitor_id = s.visitor_id AND ce.event = '$event'
                              AND ce.created_at BETWEEN s.started AND DATE_ADD(s.last_at, INTERVAL 30 MINUTE))";
$params = $visitor ? [$from, $to, $visitor] : [$from, $to];
$where = ['1'];
if ($source !== '') { $where[] = 's.source = ?'; $params[] = $source; }
if ($device !== '') { $where[] = 's.device = ?'; $params[] = $device; }
$baseParams = $params;
$baseWhere = implode(' AND ', $where);

$counts = $visits = $pages = $events = $visitNo = $visitorInfo = $users = $orders = $prodNames = [];
$hasMore = false;
if ($view === 'log') {
    // Counts for the filter tabs (with the source/device filters applied).
    $hasAdd = $HAS('add');
    $hasBuy = $HAS('purchase');
    $counts = $all("SELECT COUNT(*) total, SUM(s.views = 1) bounced, SUM(s.views >= 3) browsed, SUM(s.is_new = 0) returning,
                           SUM(s.user_id IS NOT NULL) signed, SUM($hasAdd) bagged, SUM($hasBuy) ordered
                    FROM ($SESSIONS) s WHERE $baseWhere", $baseParams)[0] ?? [];
    $counts['all'] = $counts['total'] ?? 0;

    $showSql = [
        'ordered' => $hasBuy, 'bagged' => $hasAdd, 'browsed' => 's.views >= 3',
        'returning' => 's.is_new = 0', 'signed' => 's.user_id IS NOT NULL', 'bounced' => 's.views = 1',
    ][$show] ?? null;
    if ($showSql) $where[] = $showSql;
    $visits = $all("SELECT s.* FROM ($SESSIONS) s WHERE " . implode(' AND ', $where) . "
                    ORDER BY s.started DESC LIMIT " . ($perPage + 1) . " OFFSET " . (($pageNo - 1) * $perPage), $params);
    $hasMore = count($visits) > $perPage;
    $visits = array_slice($visits, 0, $perPage);

    // ── Details for the visits on this page ──
    if ($visits) {
        $sids = array_column($visits, 'session_id');
        $vids = array_values(array_unique(array_column($visits, 'visitor_id')));
        $ph = fn(array $a) => implode(',', array_fill(0, count($a), '?'));

        foreach ($all('SELECT session_id, created_at, page_type, path, product_id, duration_s FROM page_views
                       WHERE session_id IN (' . $ph($sids) . ') ORDER BY id', $sids) as $pv) {
            $pages[$pv['session_id']][] = $pv;
        }

        // Visit number and lifetime totals for each visitor.
        foreach ($all('SELECT visitor_id, session_id, MIN(created_at) st, MAX(user_id) uid FROM page_views
                       WHERE visitor_id IN (' . $ph($vids) . ') GROUP BY visitor_id, session_id ORDER BY st', $vids) as $r) {
            $vi = &$visitorInfo[$r['visitor_id']];
            $vi['n'] = ($vi['n'] ?? 0) + 1;
            $vi['first'] ??= $r['st'];
            if ($r['uid']) $vi['uid'] = (int)$r['uid'];
            $visitNo[$r['session_id']] = $vi['n'];
            unset($vi);
        }

        // Bag and order events, each assigned to the visit it happened in.
        $starts = array_column($visits, 'started');
        $ev = $all("SELECT ce.event, ce.product_id, ce.quantity, ce.unit_price, ce.visitor_id, ce.order_id, ce.created_at, p.name
                    FROM cart_events ce LEFT JOIN products p ON p.id = ce.product_id
                    WHERE ce.visitor_id IN (" . $ph($vids) . ") AND ce.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 30 MINUTE)
                    ORDER BY ce.created_at, ce.id", array_merge($vids, [min($starts), max(array_column($visits, 'last_at'))]));
        foreach ($ev as $e) {
            $best = null;
            foreach ($visits as $v) {
                if ($v['visitor_id'] !== $e['visitor_id'] || $e['created_at'] < $v['started']) continue;
                if ($e['created_at'] > date('Y-m-d H:i:s', strtotime($v['last_at']) + 1800)) continue;
                if (!$best || $v['started'] > $best['started']) $best = $v;
            }
            if ($best) $events[$best['session_id']][] = $e;
        }

        $uids = array_values(array_unique(array_filter(array_merge(array_column($visits, 'user_id'), array_column($visitorInfo, 'uid')))));
        if ($uids) {
            foreach ($all('SELECT id, first_name, last_name, email FROM users WHERE id IN (' . $ph($uids) . ')', $uids) as $u) $users[(int)$u['id']] = $u;
        }
        $oids = array_values(array_unique(array_filter(array_column($ev, 'order_id'))));
        if ($oids) {
            foreach ($all('SELECT id, order_number, total, status FROM orders WHERE id IN (' . $ph($oids) . ')', $oids) as $o) $orders[(int)$o['id']] = $o;
        }
    }
    $prodNames = analytics_product_names(array_merge(...array_map(fn($ps) => array_column($ps, 'product_id'), array_values($pages ?: [[]]))));
}

// ── Insights: the period analysed, plus the one before for comparison ──
$A = $K = $P = null;
$findings = [];
if ($view === 'insights') {
    require_once __DIR__ . '/includes/visit-insights.php';
    $sessions = vi_sessions($from, $to, $source, $device);
    $A = vi_analyse($sessions);
    $K = vi_kpis($sessions);
    $P = $R['key'] !== 'all_time' ? vi_kpis(vi_sessions($R['prevStart'] . ' 00:00:00', $R['prevEnd'] . ' 23:59:59', $source, $device)) : null;
    $prodNames = analytics_product_names(array_merge(array_keys($A['products']), array_map(fn($k) => (int)substr($k, 1), array_filter(array_keys($A['bounce_landing']), fn($k) => preg_match('/^p\d+$/', $k)))));
    $findings = vi_findings($A, $K, $P, $prodNames);
    unset($sessions);
}

// ── One visitor's summary (visitor mode) ──
$profile = null;
if ($visitor) {
    $profile = $all("SELECT COUNT(DISTINCT session_id) visits, COUNT(*) views, SUM(COALESCE(duration_s,0)) dur,
                            MIN(created_at) first_seen, MAX(created_at) last_seen, MAX(user_id) uid,
                            GROUP_CONCAT(DISTINCT source ORDER BY source SEPARATOR ', ') sources,
                            GROUP_CONCAT(DISTINCT CONCAT(device, ' · ', browser) SEPARATOR ', ') devices,
                            COUNT(DISTINCT CASE WHEN page_type = 'product' THEN product_id END) products_seen
                     FROM page_views WHERE visitor_id = ?", [$visitor])[0] ?? null;
    $profileOrders = $all("SELECT DISTINCT o.id, o.order_number, o.total, o.status, o.created_at FROM cart_events ce
                           JOIN orders o ON o.id = ce.order_id WHERE ce.visitor_id = ? AND ce.event = 'purchase' ORDER BY o.created_at", [$visitor]);
    $profileTop = $all("SELECT product_id, COUNT(*) n, SUM(COALESCE(duration_s,0)) dur FROM page_views
                        WHERE visitor_id = ? AND page_type = 'product' GROUP BY product_id ORDER BY n DESC, dur DESC LIMIT 5", [$visitor]);
    $prodNames += analytics_product_names(array_column($profileTop, 'product_id'));
    if (!empty($profile['uid']) && !isset($users[(int)$profile['uid']])) {
        foreach ($all('SELECT id, first_name, last_name, email FROM users WHERE id = ?', [(int)$profile['uid']]) as $u) $users[(int)$u['id']] = $u;
    }
}

$sourceList = $all("SELECT source, COUNT(DISTINCT session_id) n FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY source ORDER BY n DESC", [$from, $to]);

$when = function (string $ts): string {
    $t = strtotime($ts);
    $diff = time() - $t;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if (date('Y-m-d', $t) === date('Y-m-d')) return 'Today ' . date('H:i', $t);
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday ' . date('H:i', $t);
    return date(date('Y', $t) === date('Y') ? 'D j M · H:i' : 'j M Y · H:i', $t);
};
$offset = function (string $ts, string $start): string {
    $s = max(0, strtotime($ts) - strtotime($start));
    return '+' . intdiv($s, 60) . ':' . str_pad((string)($s % 60), 2, '0', STR_PAD_LEFT);
};
$userName = fn(array $u) => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['email'] ?? 'Customer');
$ordinal = fn(int $n) => $n . (in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th'));

require_once __DIR__ . '/includes/header.php';
?>

<div class="g-dash-head">
    <div class="g-dash-greet">
        <?php if ($visitor): ?>
            <h2><?= isset($users[(int)($profile['uid'] ?? 0)]) ? h($userName($users[(int)$profile['uid']])) : 'Anonymous visitor' ?></h2>
            <p><a href="<?= SITE_URL ?>/admin/visits.php">&larr; All visits</a> &middot; every visit from this browser</p>
        <?php else: ?>
            <h2>Visit log</h2>
            <p><?= $view === 'insights' ? 'What visitors do in the shop, where they lose interest, and what to fix &mdash; worked out from every visit.' : 'Each visit on its own &mdash; where it came from, what they looked at, and how it ended. Click a visit to see the full path.' ?></p>
        <?php endif; ?>
    </div>
    <?php if (!$visitor): ?>
    <nav class="g-range" aria-label="Date range">
        <?php foreach (array_diff_key(report_ranges(), ['all_time' => 1]) as $rk => $rl): ?>
            <a href="<?= h($qs(['range' => $rk, 'p' => null])) ?>" class="<?= $R['key'] === $rk ? 'is-active' : '' ?>"><?= h($rl) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
</div>

<?php if ($profile && (int)$profile['visits']): ?>
<div class="admin-stats">
    <div class="admin-stat-card admin-stat-card--accent">
        <div class="admin-stat-card__label">Visits</div>
        <div class="admin-stat-card__value"><?= number_format((int)$profile['visits']) ?></div>
        <div class="g-hint">first <?= h($when($profile['first_seen'])) ?> · last <?= h($when($profile['last_seen'])) ?></div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__label">Pages viewed</div>
        <div class="admin-stat-card__value"><?= number_format((int)$profile['views']) ?></div>
        <div class="g-hint"><?= (int)$profile['products_seen'] ?> different product<?= (int)$profile['products_seen'] === 1 ? '' : 's' ?></div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__label">Time in the shop</div>
        <div class="admin-stat-card__value"><?= analytics_fmt_duration($profile['dur']) ?></div>
        <div class="g-hint"><?= h($profile['sources']) ?></div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__label">Orders</div>
        <div class="admin-stat-card__value"><?= count($profileOrders) ?></div>
        <div class="g-hint"><?= $profileOrders ? money(array_sum(array_column($profileOrders, 'total'))) . ' in total' : 'hasn’t ordered yet' ?></div>
    </div>
</div>
<div class="admin-dashboard-grid--3 g-grid-2">
    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">Pieces they keep looking at</div>
        <?php if (!$profileTop): ?><div class="admin-empty__text" style="padding:12px 0">No product views</div><?php else: ?>
        <table class="admin-table" style="font-size:12.5px">
            <thead><tr><th>Product</th><th class="num">Views</th><th class="num">Time</th></tr></thead>
            <tbody>
            <?php foreach ($profileTop as $t): ?>
            <tr><td><a href="<?= SITE_URL ?>/admin/products.php?edit=<?= (int)$t['product_id'] ?>"><?= h($prodNames[(int)$t['product_id']] ?? 'Product') ?></a></td>
                <td class="num"><?= (int)$t['n'] ?></td><td class="num"><?= analytics_fmt_duration($t['dur']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">About this visitor</div>
        <div class="g-kv">
            <?php if (isset($users[(int)($profile['uid'] ?? 0)])): $u = $users[(int)$profile['uid']]; ?>
                <span>Account</span><b><a href="<?= SITE_URL ?>/admin/users.php?id=<?= (int)$u['id'] ?>"><?= h($userName($u)) ?></a> <span class="g-dim"><?= h($u['email']) ?></span></b>
            <?php else: ?>
                <span>Account</span><b class="g-dim">Not signed in on this browser</b>
            <?php endif; ?>
            <span>Device</span><b><?= h(ucwords($profile['devices'] ?? '')) ?></b>
            <span>Came from</span><b><?= h($profile['sources']) ?></b>
            <span>Orders</span><b><?php if (!$profileOrders): ?>&mdash;<?php else: foreach ($profileOrders as $i => $o): ?><?= $i ? ', ' : '' ?><a href="<?= SITE_URL ?>/admin/orders.php?id=<?= (int)$o['id'] ?>">#<?= h($o['order_number']) ?></a><?php endforeach; endif; ?></b>
        </div>
        <p class="g-hint">A visitor is one browser on one device. The same person on their phone and laptop shows up as two visitors unless they sign in.</p>
    </div>
</div>
<?php endif; ?>

<?php if (!$visitor): ?>
<nav class="g-range g-viewtabs" aria-label="View">
    <a href="<?= h($qs(['view' => null, 'show' => null, 'p' => null])) ?>" class="<?= $view === 'insights' ? 'is-active' : '' ?>">Insights</a>
    <a href="<?= h($qs(['view' => 'log', 'p' => null])) ?>" class="<?= $view === 'log' ? 'is-active' : '' ?>">Each visit</a>
</nav>
<?php endif; ?>

<?php if ($view === 'insights'):
    $logLink = fn(array $over) => h($qs(array_merge(['view' => 'log', 'show' => null, 'p' => null], $over)));
    $pts = function (float $now, ?float $before, bool $lowerIsBetter = false): string {
        if ($before === null) return '';
        $d = round($now - $before, 1);
        if (abs($d) < 0.5) return '';
        $good = $lowerIsBetter ? $d < 0 : $d > 0;
        return '<span class="admin-stat-card__trend admin-stat-card__trend--' . ($good ? 'up' : 'down') . '">' . ($d > 0 ? '+' : '') . number_format($d, 1) . ' pts</span>';
    };
    $hasPrev = $P && $P['visits'] > 0;
    $pr = fn(string $k) => $hasPrev ? $P[$k] : null;
?>
<div class="admin-toolbar">
    <div class="admin-toolbar__left"><span class="g-hint" style="margin:0"><?= number_format($K['visits']) ?> visits analysed &middot; <?= h($R['label']) ?><?= $hasPrev ? ', compared with the ' . number_format($P['visits']) . ' visits in the period before' : '' ?></span></div>
    <form class="admin-toolbar__right g-visit-filters" method="get">
        <input type="hidden" name="range" value="<?= h($R['key']) ?>">
        <select name="source" onchange="this.form.submit()" aria-label="Source">
            <option value="">All sources</option>
            <?php foreach ($sourceList as $s): ?><option value="<?= h($s['source']) ?>" <?= $source === $s['source'] ? 'selected' : '' ?>><?= h($s['source']) ?> (<?= (int)$s['n'] ?>)</option><?php endforeach; ?>
        </select>
        <select name="device" onchange="this.form.submit()" aria-label="Device">
            <option value="">All devices</option>
            <?php foreach (['mobile' => 'Mobile', 'desktop' => 'Desktop', 'tablet' => 'Tablet'] as $dk => $dl): ?><option value="<?= $dk ?>" <?= $device === $dk ? 'selected' : '' ?>><?= $dl ?></option><?php endforeach; ?>
        </select>
        <noscript><button class="admin-btn admin-btn--sm">Filter</button></noscript>
    </form>
</div>

<?php if (!$K['visits']): ?>
<div class="admin-dashboard-panel"><div class="admin-empty" style="padding:40px 10px"><div class="admin-empty__text">No visits in this period yet. Your own visits while signed in as admin aren&rsquo;t recorded.</div></div></div>
<?php else: ?>

<div class="admin-stats">
    <div class="admin-stat-card admin-stat-card--accent">
        <div class="admin-stat-card__label">Visits</div>
        <div class="admin-stat-card__value"><?= number_format($K['visits']) ?></div>
        <?= $hasPrev ? trend_chip(trend_pct($K['visits'], $P['visits'])) : '' ?>
        <div class="g-hint"><?= number_format($K['pages'], 1) ?> pages each &middot; typical visit <?= analytics_fmt_duration($K['time']) ?></div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__label">Left after one page</div>
        <div class="admin-stat-card__value"><?= round($K['bounce']) ?>%</div>
        <?= $pts($K['bounce'], $pr('bounce'), true) ?>
        <div class="g-hint">lower is better</div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__label">Opened a product</div>
        <div class="admin-stat-card__value"><?= round($K['product']) ?>%</div>
        <?= $pts($K['product'], $pr('product')) ?>
        <div class="g-hint">of visits</div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__label">Added to bag</div>
        <div class="admin-stat-card__value"><?= number_format($K['bag'], 1) ?>%</div>
        <?= $pts($K['bag'], $pr('bag')) ?>
        <div class="g-hint">of visits</div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__label">Ordered</div>
        <div class="admin-stat-card__value"><?= number_format($K['order'], 1) ?>%</div>
        <?= $pts($K['order'], $pr('order')) ?>
        <div class="g-hint"><?= number_format($A['stages']['ordered']) ?> visits ended in an order</div>
    </div>
</div>

<div class="admin-dashboard-panel" style="margin-bottom:22px">
    <div class="admin-dashboard-panel__title">What the data says <span class="g-hint" style="margin:0">most important first</span></div>
    <div class="g-finds">
    <?php foreach ($findings as $fi): ?>
        <div class="g-find g-find--<?= h($fi['tone']) ?>">
            <b><?= $fi['title'] ?></b>
            <p><?= $fi['text'] ?></p>
            <?php if (!empty($fi['link'])): $lk = $fi['link']; $lkLabel = $lk['label']; unset($lk['label']); ?><a href="<?= $logLink($lk) ?>"><?= h($lkLabel) ?> &rarr;</a><?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
    <?php if (!$findings): ?><p class="g-hint">Nothing stands out in this period. The numbers below have the detail.</p><?php endif; ?>
</div>

<div class="admin-dashboard-grid--3 g-grid-2">
    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">How far visits get <span class="g-hint" style="margin:0">each visit counted once, at its furthest step</span></div>
        <div class="g-funnel">
        <?php
        $stageInfo = [
            'bounced'  => ['Left on the first page', 'bounced'],
            'browsed'  => ['Looked around, never opened a product', null],
            'product'  => ['Opened products, didn’t add to bag', null],
            'bagged'   => ['Added to bag, didn’t go to checkout', 'bagged'],
            'checkout' => ['Reached checkout, didn’t order', null],
            'ordered'  => ['Ordered', 'ordered'],
        ];
        $stMax = max(1, max($A['stages']));
        foreach ($stageInfo as $sk => [$stLabel, $stShow]): $c = $A['stages'][$sk]; ?>
        <div class="g-funnel__step">
            <div class="g-funnel__row"><span><?= $stShow ? '<a href="' . $logLink(['show' => $stShow]) . '">' . h($stLabel) . '</a>' : h($stLabel) ?></span><b><?= number_format($c) ?> <span class="g-dim"><?= round(vi_pct($c, $K['visits'])) ?>%</span></b></div>
            <div class="g-bar__track"><span style="width:<?= $c ? max(1.5, round($c / $stMax * 100, 1)) : 0 ?>%"></span></div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">When they leave <span class="g-hint" style="margin:0">visits that didn’t order, by last page</span></div>
        <table class="admin-table" style="font-size:12.5px">
            <thead><tr><th>Last page</th><th class="num">Visits ended</th><th class="num" title="Of all views of this kind of page, how many were the last page of a visit">Exit rate</th><th class="num" title="Median time on this kind of page">Time there</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($A['exit_kinds'], 0, 8) as $r): if (!$r['exits']) continue; ?>
            <tr><td><?= h($r['k']) ?></td><td class="num"><?= number_format($r['exits']) ?></td>
                <td class="num"><span class="g-meter" style="--v:<?= round($r['rate']) ?>%"></span><?= round($r['rate']) ?>%</td>
                <td class="num"><?= $r['time'] !== null ? analytics_fmt_duration($r['time']) : '&mdash;' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="g-hint">Exit rate is how often a view of that page was the last thing in a visit. A high rate on <b>Bag</b> or <b>Checkout</b> means people change their mind right before paying.</p>
    </div>
</div>

<div class="admin-dashboard-panel" style="margin:22px 0">
    <div class="admin-dashboard-panel__title">Products in visits <a href="<?= SITE_URL ?>/admin/demand.php?range=<?= h($R['key']) ?>">Product demand</a></div>
    <?php $prodRows = array_slice($A['products'], 0, 15, true); if (!$prodRows): ?>
        <div class="admin-empty__text" style="padding:12px 0">No product pages opened in this period</div>
    <?php else: ?>
    <div class="admin-responsive-table">
    <table class="admin-table g-demand-table">
        <thead><tr><th>Product</th><th class="num" title="Visits that opened it">Visits</th><th class="num" title="Median time on the page">Time on page</th>
            <th class="num" title="Visits that started on this product, usually from an ad or link">Landed here</th><th class="num" title="Visits that left the shop from this page without ordering">Left from here</th>
            <th class="num">Added to bag</th><th class="num">Ordered</th></tr></thead>
        <tbody>
        <?php foreach ($prodRows as $p):
            $tag = null;
            if ($p['visits'] >= 4 && !$p['bagged']) $tag = ['look', 'looked, didn’t add'];
            elseif ($p['visits'] >= 4 && $p['bag_rate'] >= $A['star_cut']) $tag = ['hot', 'converts well'];
            elseif ($p['visits'] >= 4 && $p['exit_rate'] >= 50) $tag = ['stall', 'visits end here'];
        ?>
        <tr>
            <td><a href="<?= SITE_URL ?>/admin/products.php?edit=<?= (int)$p['pid'] ?>"><strong><?= h($prodNames[$p['pid']] ?? 'Product #' . $p['pid']) ?></strong></a><?php if ($tag): ?> <span class="g-tag g-tag--<?= $tag[0] ?>"><?= h($tag[1]) ?></span><?php endif; ?></td>
            <td class="num"><?= number_format($p['visits']) ?></td>
            <td class="num"><?= $p['time'] !== null ? analytics_fmt_duration($p['time']) : '&mdash;' ?></td>
            <td class="num"><?= $p['landed'] ?: '<span class="g-dim">0</span>' ?></td>
            <td class="num"><?= $p['exits'] ?> <span class="g-dim"><?= round($p['exit_rate']) ?>%</span></td>
            <td class="num"><?= $p['bagged'] ?> <span class="g-dim"><?= round($p['bag_rate']) ?>%</span></td>
            <td class="num"><?= $p['ordered'] ?: '<span class="g-dim">0</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<div class="admin-dashboard-panel" style="margin-bottom:22px">
    <div class="admin-dashboard-panel__title">Usual paths through the shop <span class="g-hint" style="margin:0">repeats folded together &middot; “+ Bag” means something was added</span></div>
    <div class="g-paths">
    <?php foreach ($A['paths'] as $path): ?>
        <div class="g-path">
            <span class="g-path__steps"><?php foreach ($path['steps'] as $i => $st): ?><?= $i ? '<i>→</i>' : '' ?><span class="g-path__step<?= $st === '+ Bag' ? ' is-bag' : ($st === 'Order placed' ? ' is-order' : '') ?>"><?= h($st) ?></span><?php endforeach; ?></span>
            <span class="g-path__n"><b><?= number_format($path['n']) ?></b> <span class="g-dim"><?= round(vi_pct($path['n'], $K['visits'])) ?>%</span></span>
        </div>
    <?php endforeach; ?>
    </div>
</div>

<?php $segTable = function (array $rows, string $head, ?string $filter = null) use ($logLink): string {
    $o = '<table class="admin-table" style="font-size:12.5px"><thead><tr><th>' . h($head) . '</th><th class="num">Visits</th><th class="num" title="Left after one page">1 page</th><th class="num">Bagged</th><th class="num">Ordered</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        if (!$r['visits']) continue;
        $name = $filter ? '<a href="' . $logLink([$filter => $r['k']]) . '">' . h($r['k']) . '</a>' : h($r['k']);
        $o .= '<tr><td>' . $name . '</td><td class="num">' . number_format($r['visits']) . '</td><td class="num">' . round($r['bounce']) . '%</td>'
            . '<td class="num">' . number_format($r['bag'], 1) . '%</td><td class="num"><strong>' . number_format($r['order'], 1) . '%</strong></td></tr>';
    }
    return $o . '</tbody></table>';
}; ?>
<div class="admin-dashboard-grid--3 g-grid-2">
    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">By source and visitor</div>
        <?= $segTable(array_slice($A['by_source'], 0, 8), 'Source', 'source') ?>
        <div style="height:14px"></div>
        <?= $segTable(array_merge(array_values($A['by_device']), array_values($A['by_new'])), 'Device / visitor') ?>
    </div>
    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">By time</div>
        <?= $segTable(array_values($A['by_daypart']), 'Time of day') ?>
        <div style="height:14px"></div>
        <?php $wdRows = [];
              foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $d) if (isset($A['by_weekday'][$d])) $wdRows[] = ['k' => $d] + $A['by_weekday'][$d]; ?>
        <?= $segTable($wdRows, 'Weekday') ?>
    </div>
</div>
<?php endif; ?>

<?php else: ?>

<div class="admin-toolbar">
    <div class="admin-toolbar__left">
        <div class="admin-filter-tabs">
            <?php foreach ($shows as $sk => $sl): ?>
                <a href="<?= h($qs(['show' => $sk === 'all' ? null : $sk, 'p' => null])) ?>" class="admin-filter-tab <?= $show === $sk ? 'active' : '' ?>"><?= h($sl) ?><?php if (!empty($counts[$sk])): ?> <span class="admin-filter-tab__count"><?= number_format((int)$counts[$sk]) ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if (!$visitor): ?>
    <form class="admin-toolbar__right g-visit-filters" method="get">
        <input type="hidden" name="range" value="<?= h($R['key']) ?>">
        <input type="hidden" name="view" value="log">
        <?php if ($show !== 'all'): ?><input type="hidden" name="show" value="<?= h($show) ?>"><?php endif; ?>
        <select name="source" onchange="this.form.submit()" aria-label="Source">
            <option value="">All sources</option>
            <?php foreach ($sourceList as $s): ?><option value="<?= h($s['source']) ?>" <?= $source === $s['source'] ? 'selected' : '' ?>><?= h($s['source']) ?> (<?= (int)$s['n'] ?>)</option><?php endforeach; ?>
        </select>
        <select name="device" onchange="this.form.submit()" aria-label="Device">
            <option value="">All devices</option>
            <?php foreach (['mobile' => 'Mobile', 'desktop' => 'Desktop', 'tablet' => 'Tablet'] as $dk => $dl): ?><option value="<?= $dk ?>" <?= $device === $dk ? 'selected' : '' ?>><?= $dl ?></option><?php endforeach; ?>
        </select>
        <noscript><button class="admin-btn admin-btn--sm">Filter</button></noscript>
    </form>
    <?php endif; ?>
</div>

<?php if (!$visits): ?>
<div class="admin-dashboard-panel"><div class="admin-empty" style="padding:40px 10px"><div class="admin-empty__text">No visits match. Your own visits while signed in as admin aren&rsquo;t recorded.</div></div></div>
<?php else: ?>
<div class="g-visits">
<?php foreach ($visits as $v):
    $sid = $v['session_id'];
    $vp = $pages[$sid] ?? [];
    $ve = $events[$sid] ?? [];
    $n = $visitNo[$sid] ?? 1;
    $info = $visitorInfo[$v['visitor_id']] ?? ['n' => 1];
    $uid = (int)($v['user_id'] ?: ($info['uid'] ?? 0));
    $bagged = array_filter($ve, fn($e) => $e['event'] === 'add');
    $orderIds = array_unique(array_filter(array_column(array_filter($ve, fn($e) => $e['event'] === 'purchase'), 'order_id')));
    $land = $vp[0] ?? null;
    $exit = $vp ? end($vp) : null;
    // Merge pages and bag events into one timeline.
    $timeline = [];
    foreach ($vp as $p) $timeline[] = ['t' => $p['created_at'], 'k' => 0, 'page' => $p];
    foreach ($ve as $e) $timeline[] = ['t' => $e['created_at'], 'k' => 1, 'ev' => $e];
    usort($timeline, fn($a, $b) => [$a['t'], $a['k']] <=> [$b['t'], $b['k']]);
?>
<details class="g-visit<?= $orderIds ? ' is-ordered' : ($bagged ? ' is-bagged' : '') ?>">
    <summary>
        <span class="g-visit__when"><?= h($when($v['started'])) ?></span>
        <span class="g-visit__who">
            <b><?= h($v['source']) ?></b><?php if ((int)$v['paid']): ?> <span class="g-tag g-tag--paid">ad</span><?php endif; ?>
            <?php if ($v['campaign']): ?><span class="g-dim"> · <?= h($v['campaign']) ?></span><?php endif; ?>
            <small><?= h(ucfirst($v['device'])) ?> · <?= h($v['browser'] ?: 'Other') ?> · <?= $n === 1 ? 'first visit' : $ordinal($n) . ' visit' ?><?php if ($uid && isset($users[$uid])): ?> · <?= h($userName($users[$uid])) ?><?php endif; ?></small>
        </span>
        <span class="g-visit__path">
            <?= $land ? h(analytics_page_label($land, $prodNames)) : '' ?>
            <?php if ((int)$v['views'] > 1 && $exit): ?><span class="g-dim"> → </span><?= h(analytics_page_label($exit, $prodNames)) ?><?php endif; ?>
            <small><?= (int)$v['views'] ?> page<?= (int)$v['views'] === 1 ? '' : 's' ?> · <?= analytics_fmt_duration($v['dur']) ?></small>
        </span>
        <span class="g-visit__out">
            <?php foreach ($orderIds as $oid): $o = $orders[(int)$oid] ?? null; ?>
                <span class="g-tag g-tag--ordered">Ordered<?= $o ? ' · ' . strip_tags(money((float)$o['total'])) : '' ?></span>
            <?php endforeach; ?>
            <?php if ($bagged && !$orderIds): ?><span class="g-tag g-tag--restock"><?= count($bagged) ?> in bag, no order</span><?php endif; ?>
            <?php if ((int)$v['views'] === 1 && !$ve): ?><span class="g-tag g-tag--stall">left at once</span><?php endif; ?>
        </span>
    </summary>
    <ol class="g-journey">
        <?php foreach ($timeline as $step): ?>
            <?php if ($step['k'] === 0): $p = $step['page']; ?>
            <li class="g-journey__page">
                <span class="g-journey__t"><?= $offset($p['created_at'], $v['started']) ?></span>
                <span class="g-journey__label">
                    <?php if ($p['page_type'] === 'product' && $p['product_id']): ?>
                        <a href="<?= SITE_URL ?>/admin/products.php?edit=<?= (int)$p['product_id'] ?>"><?= h(analytics_page_label($p, $prodNames)) ?></a>
                    <?php else: ?>
                        <?= h(analytics_page_label($p, $prodNames)) ?>
                    <?php endif; ?>
                </span>
                <span class="g-journey__dur"><?= $p['duration_s'] !== null ? analytics_fmt_duration($p['duration_s']) : '<span class="g-dim">&mdash;</span>' ?></span>
            </li>
            <?php else: $e = $step['ev'];
                $txt = ['add' => 'Added to bag', 'remove' => 'Removed from bag', 'purchase' => 'Ordered'][$e['event']] ?? $e['event']; ?>
            <li class="g-journey__ev g-journey__ev--<?= h($e['event']) ?>">
                <span class="g-journey__t"><?= $offset($e['created_at'], $v['started']) ?></span>
                <span class="g-journey__label"><?= h($txt) ?>: <?= h($e['name'] ?? 'a product') ?><?= (int)$e['quantity'] > 1 ? ' × ' . (int)$e['quantity'] : '' ?>
                    <?php if ($e['event'] === 'purchase' && $e['order_id'] && isset($orders[(int)$e['order_id']])): ?>
                        &middot; <a href="<?= SITE_URL ?>/admin/orders.php?id=<?= (int)$e['order_id'] ?>">order #<?= h($orders[(int)$e['order_id']]['order_number']) ?></a>
                    <?php endif; ?></span>
                <span class="g-journey__dur"><?= (float)$e['unit_price'] > 0 ? money((float)$e['unit_price']) : '' ?></span>
            </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ol>
    <div class="g-visit__foot">
        <?php if (!$visitor): ?>
            <a href="<?= SITE_URL ?>/admin/visits.php?visitor=<?= h($v['visitor_id']) ?>">All <?= (int)$info['n'] ?> visit<?= (int)$info['n'] === 1 ? '' : 's' ?> from this visitor &rarr;</a>
        <?php endif; ?>
        <?php if ($uid && isset($users[$uid])): ?><a href="<?= SITE_URL ?>/admin/users.php?id=<?= $uid ?>">Customer account &rarr;</a><?php endif; ?>
        <span class="g-dim">Time per page is recorded when the visitor leaves it; &ldquo;&mdash;&rdquo; means the browser closed before it could be sent.</span>
    </div>
</details>
<?php endforeach; ?>
</div>

<div class="g-pager">
    <?php if ($pageNo > 1): ?><a class="admin-btn admin-btn--sm" href="<?= h($qs(['p' => $pageNo - 1 > 1 ? $pageNo - 1 : null])) ?>">&larr; Newer</a><?php endif; ?>
    <span class="g-dim">Page <?= $pageNo ?></span>
    <?php if ($hasMore): ?><a class="admin-btn admin-btn--sm" href="<?= h($qs(['p' => $pageNo + 1])) ?>">Older &rarr;</a><?php endif; ?>
</div>
<?php endif; ?>

<?php endif; /* view */ ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
