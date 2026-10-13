<?php
/**
 * Admin — Visitors
 *
 * Who comes to the shop, from where, on what, when — and which sources turn
 * into bags and orders. Data comes from the page-view beacon (api/pv.php);
 * admins and bots are not counted.
 */
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Visitors';
$adminPage = 'analytics';
analytics_ensure_schema();

$R = report_range($_GET['range'] ?? '30days');
$from  = $R['start'] . ' 00:00:00';
$to    = $R['end'] . ' 23:59:59';
$pFrom = $R['prevStart'] . ' 00:00:00';
$pTo   = $R['prevEnd'] . ' 23:59:59';
$trackingSince = setting('analytics_started_at');

$one = function (string $sql, array $p) {
    try { $s = db()->prepare($sql); $s->execute($p); return $s->fetch() ?: []; } catch (Exception $e) { error_log('report query: ' . $e->getMessage()); return []; }
};
$all = function (string $sql, array $p) {
    try { $s = db()->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (Exception $e) { error_log('report query: ' . $e->getMessage()); return []; }
};

// Product pages group by product, shop and other pages by address, and the
// rest (account, checkout, …) by type, so ?redirect=… variants don't split.
$PAGE_KEY = "CASE WHEN page_type IN ('shop','page') THEN path WHEN page_type = 'product' THEN CONCAT('p', product_id) ELSE page_type END";

// ── Headline numbers ──
$kpiSql = "SELECT COUNT(DISTINCT visitor_id) visitors,
                  COUNT(DISTINCT CASE WHEN is_new_visitor = 1 THEN visitor_id END) new_visitors,
                  COUNT(DISTINCT session_id) sessions,
                  COUNT(*) views
           FROM page_views WHERE created_at BETWEEN ? AND ?";
$k  = $one($kpiSql, [$from, $to]) + ['visitors' => 0, 'new_visitors' => 0, 'sessions' => 0, 'views' => 0];
$kp = $one($kpiSql, [$pFrom, $pTo]) + ['visitors' => 0, 'sessions' => 0, 'views' => 0];
$sess = $one("SELECT AVG(n) pages, AVG(n = 1) bounce, AVG(dur) dur FROM (
                SELECT session_id, COUNT(*) n, SUM(COALESCE(duration_s, 0)) dur
                FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY session_id) t", [$from, $to]);
$onlineNow = $one("SELECT COUNT(DISTINCT visitor_id) n FROM page_views WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)", [])['n'] ?? 0;
$livePages = $all("SELECT page_type, MIN(path) path, MAX(product_id) product_id, COUNT(DISTINCT visitor_id) n FROM page_views
                   WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) GROUP BY page_type, $PAGE_KEY ORDER BY n DESC LIMIT 6", []);
$buyers = (int)($one("SELECT COUNT(DISTINCT visitor_id) n FROM cart_events WHERE event='purchase' AND visitor_id IS NOT NULL AND created_at BETWEEN ? AND ?", [$from, $to])['n'] ?? 0);
$returning = max(0, (int)$k['visitors'] - (int)$k['new_visitors']);
$fmtDur = 'analytics_fmt_duration';

// ── Daily chart ──
$byMonth = $R['days'] > 120;
$fmt = $byMonth ? '%Y-%m' : '%Y-%m-%d';
$daily = [];
foreach ($all("SELECT DATE_FORMAT(created_at, '$fmt') d, COUNT(DISTINCT visitor_id) v, COUNT(DISTINCT session_id) s, COUNT(*) pv
               FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY d", [$from, $to]) as $r) $daily[$r['d']] = $r;
foreach ($all("SELECT DATE_FORMAT(created_at, '$fmt') d, COUNT(*) o FROM orders
               WHERE status NOT IN ('cancelled','refunded') AND created_at BETWEEN ? AND ? GROUP BY d", [$from, $to]) as $r) $daily[$r['d']]['o'] = $r['o'];
// Days before the detailed tracker existed come from the old daily counter.
if (!$byMonth) {
    foreach (analytics_daily_visitors($R['start'], $R['end']) as $d => $v) if (empty($daily[$d]['v'])) $daily[$d]['v'] = $v;
}
$labels = $sV = $sS = $sO = [];
$firstKey = $daily ? min(array_keys($daily)) : null;
$cursor = strtotime($byMonth ? date('Y-m-01', strtotime($R['start'])) : $R['start']);
while ($cursor <= strtotime($R['end'])) {
    $key = date($byMonth ? 'Y-m' : 'Y-m-d', $cursor);
    if ($R['key'] !== 'all_time' || ($firstKey && $key >= $firstKey)) {
        $labels[] = date($byMonth ? 'M Y' : 'M j', $cursor);
        $sV[] = (int)($daily[$key]['v'] ?? 0);
        $sS[] = (int)($daily[$key]['s'] ?? 0);
        $sO[] = (int)($daily[$key]['o'] ?? 0);
    }
    $cursor = strtotime($byMonth ? '+1 month' : '+1 day', $cursor);
}

// ── Sources, with what each one leads to ──
// Each visitor is credited to the source of their first visit in the period.
$sources = $all(
    "SELECT s.source, COUNT(*) visitors, SUM(s.paid) paid,
            SUM(EXISTS(SELECT 1 FROM cart_events ce WHERE ce.visitor_id = s.visitor_id AND ce.event = 'add' AND ce.created_at BETWEEN ? AND ?)) bagged,
            SUM(EXISTS(SELECT 1 FROM cart_events ce WHERE ce.visitor_id = s.visitor_id AND ce.event = 'purchase' AND ce.created_at BETWEEN ? AND ?)) buyers
     FROM (SELECT visitor_id,
                  SUBSTRING_INDEX(GROUP_CONCAT(source ORDER BY created_at SEPARATOR '\x1f'), '\x1f', 1) source,
                  MAX(is_paid) paid
           FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY visitor_id) s
     GROUP BY s.source ORDER BY visitors DESC", [$from, $to, $from, $to, $from, $to]);
$campaigns = $all(
    "SELECT utm_campaign c, COUNT(DISTINCT visitor_id) visitors
     FROM page_views WHERE utm_campaign IS NOT NULL AND created_at BETWEEN ? AND ?
     GROUP BY utm_campaign ORDER BY visitors DESC LIMIT 10", [$from, $to]);

// ── Pages ──
$topPages = $all("SELECT page_type, MIN(path) path, MAX(product_id) product_id, COUNT(*) views, COUNT(DISTINCT visitor_id) visitors
                  FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY page_type, $PAGE_KEY ORDER BY visitors DESC, views DESC LIMIT 12", [$from, $to]);
$landing = $all("SELECT f.page_type, MIN(f.path) path, MAX(f.product_id) product_id, COUNT(*) sessions,
                        SUM(t.n = 1) bounces
                 FROM page_views f
                 JOIN (SELECT session_id, MIN(id) first_id, COUNT(*) n FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY session_id) t
                   ON t.first_id = f.id
                 GROUP BY f.page_type, " . str_replace(['page_type', 'path', 'product_id'], ['f.page_type', 'f.path', 'f.product_id'], $PAGE_KEY) . " ORDER BY sessions DESC LIMIT 8", [$from, $to]);
// ── Where visits end ──
$exits = $all("SELECT l.page_type, MIN(l.path) path, MAX(l.product_id) product_id, COUNT(*) sessions
               FROM page_views l
               JOIN (SELECT session_id, MAX(id) last_id, COUNT(*) n FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY session_id HAVING n > 1) t
                 ON t.last_id = l.id
               GROUP BY l.page_type, " . str_replace(['page_type', 'path', 'product_id'], ['l.page_type', 'l.path', 'l.product_id'], $PAGE_KEY) . " ORDER BY sessions DESC LIMIT 8", [$from, $to]);

// ── What people search for in the shop ──
$searches = [];
foreach ($all("SELECT path, COUNT(DISTINCT session_id) n FROM page_views
               WHERE page_type = 'shop' AND path LIKE '%q=%' AND created_at BETWEEN ? AND ? GROUP BY path", [$from, $to]) as $r) {
    parse_str((string)parse_url($r['path'], PHP_URL_QUERY), $q);
    $term = mb_strtolower(trim((string)($q['q'] ?? '')));
    if ($term !== '') $searches[$term] = ($searches[$term] ?? 0) + (int)$r['n'];
}
arsort($searches);
$searches = array_map(fn($t, $n) => ['k' => $t, 'n' => $n], array_keys(array_slice($searches, 0, 8, true)), array_slice($searches, 0, 8, true));

// ── Who orders: order rate by new/returning and by device ──
$BOUGHT = "EXISTS (SELECT 1 FROM cart_events ce WHERE ce.visitor_id = v.visitor_id AND ce.event = 'purchase' AND ce.created_at BETWEEN ? AND ?)";
$BAGGED = "EXISTS (SELECT 1 FROM cart_events ce WHERE ce.visitor_id = v.visitor_id AND ce.event = 'add' AND ce.created_at BETWEEN ? AND ?)";
$segments = $all("SELECT IF(v.is_new, 'New visitors', 'Returning visitors') k, COUNT(*) visitors, SUM($BAGGED) bagged, SUM($BOUGHT) buyers
               FROM (SELECT visitor_id, MAX(is_new_visitor) is_new FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY visitor_id) v
               GROUP BY k ORDER BY k", [$from, $to, $from, $to, $from, $to]);
$deviceSegments = $all("SELECT CONCAT(UPPER(LEFT(v.device, 1)), SUBSTRING(v.device, 2)) k, COUNT(*) visitors, SUM($BAGGED) bagged, SUM($BOUGHT) buyers
               FROM (SELECT visitor_id, SUBSTRING_INDEX(GROUP_CONCAT(device ORDER BY id), ',', 1) device FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY visitor_id) v
               GROUP BY k ORDER BY visitors DESC", [$from, $to, $from, $to, $from, $to]);

// ── Path to purchase: how many visits and how long before people order ──
$paths = $all("SELECT o.order_id,
                      (SELECT COUNT(DISTINCT pv.session_id) FROM page_views pv WHERE pv.visitor_id = o.visitor_id AND pv.created_at <= o.t) visits,
                      TIMESTAMPDIFF(MINUTE, (SELECT MIN(pv.created_at) FROM page_views pv WHERE pv.visitor_id = o.visitor_id), o.t) mins
               FROM (SELECT order_id, MIN(visitor_id) visitor_id, MIN(created_at) t FROM cart_events
                     WHERE event = 'purchase' AND visitor_id IS NOT NULL AND order_id IS NOT NULL AND created_at BETWEEN ? AND ?
                     GROUP BY order_id) o", [$from, $to]);
$median = function (array $xs) {
    if (!$xs) return null;
    sort($xs);
    $m = intdiv(count($xs), 2);
    return count($xs) % 2 ? $xs[$m] : ($xs[$m - 1] + $xs[$m]) / 2;
};
$pathVisits = array_map(fn($r) => max(1, (int)$r['visits']), $paths);
$pathMins   = array_map(fn($r) => max(0, (int)$r['mins']), $paths);
$firstVisitBuys = count(array_filter($pathVisits, fn($n) => $n === 1));
$fmtWait = function ($m): string {
    if ($m === null) return '—';
    $m = (int) round($m);
    if ($m < 60) return $m . ' min';
    if ($m < 48 * 60) return round($m / 60) . ' h';
    return round($m / 1440) . ' days';
};

// ── How deep visits go ──
$depth = $all("SELECT CASE WHEN n = 1 THEN '1 page' WHEN n <= 3 THEN '2–3 pages' WHEN n <= 9 THEN '4–9 pages' ELSE '10+ pages' END k,
                      MIN(n) o, COUNT(*) n
               FROM (SELECT session_id, COUNT(*) n FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY session_id) t
               GROUP BY k ORDER BY o", [$from, $to]);
$timeSpent = $all("SELECT CASE WHEN d < 10 THEN 'Under 10s' WHEN d < 60 THEN '10s – 1 min' WHEN d < 180 THEN '1 – 3 min'
                               WHEN d < 600 THEN '3 – 10 min' ELSE '10 min +' END k, MIN(d) o, COUNT(*) n
                   FROM (SELECT session_id, SUM(COALESCE(duration_s, 0)) d FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY session_id) t
                   GROUP BY k ORDER BY o", [$from, $to]);

$prodNames = analytics_product_names(array_merge(array_column($topPages, 'product_id'), array_column($landing, 'product_id'),
                                                 array_column($livePages, 'product_id'), array_column($exits, 'product_id')));
$pageLabel = fn(array $r): string => analytics_page_label($r, $prodNames);

// ── Devices / browsers / time of day ──
$devices  = $all("SELECT device k, COUNT(DISTINCT visitor_id) n FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY device ORDER BY n DESC", [$from, $to]);
$browsers = $all("SELECT browser k, COUNT(DISTINCT visitor_id) n FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY browser ORDER BY n DESC LIMIT 7", [$from, $to]);
$heat = array_fill(0, 7, array_fill(0, 24, 0));
foreach ($all("SELECT WEEKDAY(created_at) wd, HOUR(created_at) hr, COUNT(DISTINCT session_id) n
               FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY wd, hr", [$from, $to]) as $h) {
    $heat[(int)$h['wd']][(int)$h['hr']] = (int)$h['n'];
}
$heatMax = max(1, max(array_map('max', $heat)));
$byHour = array_fill(0, 24, 0);
foreach ($heat as $row) foreach ($row as $hr => $n) $byHour[$hr] += $n;
$peakHour = array_search(max($byHour), $byHour);

$bar = function (array $rows, string $labelKey, string $valKey, ?callable $label = null): string {
    $max = max(1, ...array_map(fn($r) => (int)$r[$valKey], $rows ?: [[$valKey => 1]]));
    $out = '';
    foreach ($rows as $r) {
        $w = round((int)$r[$valKey] / $max * 100, 1);
        $out .= '<div class="g-bar"><span class="g-bar__label">' . h($label ? $label($r) : (string)$r[$labelKey]) . '</span>'
              . '<span class="g-bar__track"><span style="width:' . $w . '%"></span></span>'
              . '<span class="g-bar__val">' . number_format((int)$r[$valKey]) . '</span></div>';
    }
    return $out ?: '<div class="admin-empty__text" style="padding:12px 0">No data yet</div>';
};

$hasData = (int)$k['views'] > 0;
require_once __DIR__ . '/includes/header.php';
?>

<div class="g-dash-head">
    <div class="g-dash-greet">
        <h2>Visitors <?php if ($onlineNow): ?><span class="g-live"><i></i><?= (int)$onlineNow ?> online now</span><?php endif; ?></h2>
        <p>Who&rsquo;s visiting, where they come from, and what turns into orders &mdash; <?= h($R['label']) ?>. <a href="<?= SITE_URL ?>/admin/visits.php?range=<?= h($R['key']) ?>">Visit insights</a> · <a href="<?= SITE_URL ?>/admin/visits.php?view=log&amp;range=<?= h($R['key']) ?>">each visit &rarr;</a></p>
    </div>
    <nav class="g-range" aria-label="Date range">
        <?php foreach (report_ranges() as $rk => $rl): ?>
            <a href="?range=<?= $rk ?>" class="<?= $R['key'] === $rk ? 'is-active' : '' ?>"><?= h($rl) ?></a>
        <?php endforeach; ?>
    </nav>
</div>

<?php if (!$hasData): ?>
<p class="g-note">No visits recorded in this period yet. Detailed tracking started <?= $trackingSince ? 'on ' . h(date('j M Y, H:i', strtotime($trackingSince))) : 'with this update' ?>; numbers appear as soon as people browse the shop. Your own visits while signed in as admin aren&rsquo;t counted.</p>
<?php elseif ($trackingSince && $R['start'] < substr($trackingSince, 0, 10)): ?>
<p class="g-note">Detailed tracking started <?= h(date('j M Y', strtotime($trackingSince))) ?>. Earlier days in the chart use the old visitor counter, which can&rsquo;t show sources or devices.</p>
<?php endif; ?>

<div class="admin-stats">
    <div class="admin-stat-card admin-stat-card--accent">
        <div class="admin-stat-card__label">Visitors</div>
        <div class="admin-stat-card__value"><?= number_format((int)$k['visitors']) ?></div>
        <?= $R['key'] !== 'all_time' ? trend_chip(trend_pct((float)$k['visitors'], (float)$kp['visitors'])) : '' ?>
        <div class="g-hint"><?= number_format((int)$k['new_visitors']) ?> new · <?= number_format($returning) ?> returning</div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__label">Visits</div>
        <div class="admin-stat-card__value"><?= number_format((int)$k['sessions']) ?></div>
        <?= $R['key'] !== 'all_time' ? trend_chip(trend_pct((float)$k['sessions'], (float)$kp['sessions'])) : '' ?>
        <div class="g-hint"><?= number_format((int)$k['views']) ?> page views · <?= number_format((float)($sess['pages'] ?? 0), 1) ?> per visit</div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__label">Time per visit</div>
        <div class="admin-stat-card__value"><?= $fmtDur($sess['dur'] ?? 0) ?></div>
        <div class="g-hint"><?= round((float)($sess['bounce'] ?? 0) * 100) ?>% left after one page</div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-card__label">Visitors who ordered</div>
        <div class="admin-stat-card__value"><?= (int)$k['visitors'] ? number_format($buyers / (int)$k['visitors'] * 100, 1) . '%' : '&mdash;' ?></div>
        <div class="g-hint"><?= number_format($buyers) ?> of <?= number_format((int)$k['visitors']) ?> visitors</div>
    </div>
</div>

<div class="admin-dashboard-panel" style="margin-bottom:22px">
    <div class="admin-dashboard-panel__title">Visitors and orders per <?= $byMonth ? 'month' : 'day' ?></div>
    <?php if (!array_sum($sV) && !array_sum($sO)): ?>
        <div class="admin-empty" style="padding:30px 10px"><div class="admin-empty__text">Nothing to chart yet.</div></div>
    <?php else: ?>
        <div class="chart-container"><canvas id="visitorsChart"></canvas></div>
    <?php endif; ?>
</div>

<div class="admin-dashboard-panel" style="margin-bottom:22px">
    <div class="admin-dashboard-panel__title">Where visitors come from <span class="g-hint" style="margin:0">credited to each visitor&rsquo;s first visit in the period</span></div>
    <?php if (!$sources): ?>
        <div class="admin-empty" style="padding:24px 10px"><div class="admin-empty__text">No data yet</div></div>
    <?php else: $srcMax = max(array_map(fn($r) => (int)$r['visitors'], $sources)); ?>
    <div class="admin-responsive-table">
    <table class="admin-table g-demand-table">
        <thead><tr><th>Source</th><th class="num">Visitors</th><th class="num" title="Visitors who added something to their bag">Bagged</th><th class="num" title="Visitors who placed an order">Ordered</th><th class="num">Order rate</th></tr></thead>
        <tbody>
        <?php foreach ($sources as $r): $v = (int)$r['visitors']; ?>
        <tr>
            <td><strong><?= h($r['source']) ?></strong><?php if ((int)$r['paid'] > 0): ?> <span class="g-tag g-tag--paid" title="Visitors who arrived from a tagged ad link"><?= (int)$r['paid'] === $v ? 'ads' : (int)$r['paid'] . ' from ads' ?></span><?php endif; ?>
                <div class="g-bar__track" style="margin-top:6px;max-width:260px"><span style="width:<?= round($v / max(1, $srcMax) * 100, 1) ?>%"></span></div></td>
            <td class="num"><?= number_format($v) ?></td>
            <td class="num"><?= number_format((int)$r['bagged']) ?> <span class="g-dim"><?= $v ? round($r['bagged'] / $v * 100) : 0 ?>%</span></td>
            <td class="num"><?= number_format((int)$r['buyers']) ?></td>
            <td class="num"><strong><?= $v ? number_format($r['buyers'] / $v * 100, 1) : '0.0' ?>%</strong></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="g-hint">Instagram and Facebook often hide the link a visitor came from; their in-app browser still identifies them. Add <code>utm_source</code>/<code>utm_campaign</code> to ad and bio links to see campaigns below.</p>
    <?php endif; ?>
</div>

<?php $segRow = function (array $r): string {
    $v = (int)$r['visitors'];
    return '<tr><td>' . h($r['k']) . '</td><td class="num">' . number_format($v) . '</td>'
         . '<td class="num">' . ($v ? round($r['bagged'] / $v * 100) : 0) . '%</td>'
         . '<td class="num"><strong>' . ($v ? number_format($r['buyers'] / $v * 100, 1) : '0.0') . '%</strong> <span class="g-dim">' . (int)$r['buyers'] . '</span></td></tr>';
}; ?>
<div class="admin-dashboard-grid--3 g-grid-2">
    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">Who orders <span class="g-hint" style="margin:0">share of visitors who bagged / ordered</span></div>
        <?php if (!$segments): ?><div class="admin-empty__text" style="padding:12px 0">No data yet</div><?php else: ?>
        <table class="admin-table" style="font-size:12.5px">
            <thead><tr><th>Visitors</th><th class="num">Count</th><th class="num">Bagged</th><th class="num">Ordered</th></tr></thead>
            <tbody>
                <?php foreach ($segments as $r) echo $segRow($r); ?>
                <tr><td colspan="4" style="padding:4px"></td></tr>
                <?php foreach ($deviceSegments as $r) echo $segRow($r); ?>
            </tbody>
        </table>
        <p class="g-hint">&ldquo;Returning&rdquo; means they had been to the shop before this visit, on the same browser.</p>
        <?php endif; ?>
    </div>
    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">Path to purchase <span class="g-hint" style="margin:0"><?= count($paths) ?> tracked order<?= count($paths) === 1 ? '' : 's' ?></span></div>
        <div class="g-mini-stats">
            <div><b><?= $paths ? round($firstVisitBuys / count($paths) * 100) . '%' : '&mdash;' ?></b><span>ordered on their first visit</span></div>
            <div><b><?= $paths ? rtrim(rtrim(number_format((float)$median($pathVisits), 1), '0'), '.') : '&mdash;' ?></b><span>visits it takes to order (median)</span></div>
            <div><b><?= h($fmtWait($median($pathMins))) ?></b><span>from first visit to order (median)</span></div>
        </div>
        <?php if ($paths): ?>
        <p class="g-hint" style="margin-top:12px"><?= $firstVisitBuys / count($paths) >= .5
            ? 'Most buyers decide on the spot &mdash; your first impression (landing page, photos, price) does the selling.'
            : 'Most buyers come back before ordering &mdash; retargeting ads, the welcome offer and Instagram follow-ups help close the sale.' ?></p>
        <?php else: ?>
        <p class="g-hint" style="margin-top:12px">Shows once orders are placed by tracked visitors (from the day tracking started).</p>
        <?php endif; ?>
        <div class="admin-dashboard-panel__title" style="margin-top:16px">How deep visits go</div>
        <div class="g-bars"><?= $bar($depth, 'k', 'n') ?></div>
    </div>
</div>

<div class="admin-dashboard-grid--3">
    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">Time per visit</div>
        <div class="g-bars"><?= $bar($timeSpent, 'k', 'n') ?></div>
    </div>
    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">Where visits end <span class="g-hint" style="margin:0">last page, visits of 2+ pages</span></div>
        <div class="g-bars"><?= $bar($exits, 'path', 'sessions', $pageLabel) ?></div>
    </div>
    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">Searched in the shop</div>
        <?php if (!$searches): ?>
            <div class="admin-empty__text" style="padding:12px 0">No searches in this period</div>
        <?php else: ?>
            <div class="g-bars"><?= $bar($searches, 'k', 'n') ?></div>
            <p class="g-hint">Searches for things you don&rsquo;t sell are ideas for new pieces.</p>
        <?php endif; ?>
    </div>
</div>

<div class="admin-dashboard-grid--3 g-grid-2">
    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">Most visited pages</div>
        <div class="g-bars"><?= $bar($topPages, 'path', 'visitors', $pageLabel) ?></div>
    </div>
    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">Where visits start <span class="g-hint" style="margin:0">and how many leave straight away</span></div>
        <?php if (!$landing): ?><div class="admin-empty__text" style="padding:12px 0">No data yet</div><?php else: ?>
        <table class="admin-table" style="font-size:12.5px">
            <thead><tr><th>Landing page</th><th class="num">Visits</th><th class="num">Left at once</th></tr></thead>
            <tbody>
            <?php foreach ($landing as $l): ?>
            <tr><td><?= h($pageLabel($l)) ?></td><td class="num"><?= number_format((int)$l['sessions']) ?></td><td class="num"><?= $l['sessions'] ? round($l['bounces'] / $l['sessions'] * 100) : 0 ?>%</td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="admin-dashboard-grid--3">
    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">Devices</div>
        <div class="g-bars"><?= $bar($devices, 'k', 'n', fn($r) => ucfirst($r['k'])) ?></div>
    </div>
    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">Browser / app</div>
        <div class="g-bars"><?= $bar($browsers, 'k', 'n') ?></div>
    </div>
    <div class="admin-dashboard-panel">
        <div class="admin-dashboard-panel__title">Campaigns <span class="g-hint" style="margin:0">utm_campaign</span></div>
        <?php if (!$campaigns): ?>
            <p class="g-hint">No tagged links yet. Example ad link:<br><code style="word-break:break-all">adornaeg.com/?utm_source=instagram&amp;utm_campaign=eid</code></p>
        <?php else: ?>
            <div class="g-bars"><?= $bar($campaigns, 'c', 'visitors') ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="admin-dashboard-panel" style="margin-top:22px">
    <div class="admin-dashboard-panel__title">Busiest times <span class="g-hint" style="margin:0">visits by weekday and hour<?= $hasData ? ' · peak around ' . str_pad((string)$peakHour, 2, '0', STR_PAD_LEFT) . ':00' : '' ?> — a good time to post</span></div>
    <div class="g-heat" role="img" aria-label="Visits by weekday and hour">
        <span></span>
        <?php for ($hr = 0; $hr < 24; $hr++): ?><span class="g-heat__h"><?= $hr % 3 === 0 ? $hr : '' ?></span><?php endfor; ?>
        <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $wd => $dn): ?>
            <span class="g-heat__d"><?= $dn ?></span>
            <?php for ($hr = 0; $hr < 24; $hr++): $n = $heat[$wd][$hr]; ?>
                <span class="g-heat__c" style="--a:<?= $n ? round(0.12 + 0.88 * $n / $heatMax, 2) : 0 ?>" title="<?= $dn ?> <?= $hr ?>:00 — <?= $n ?> visit<?= $n === 1 ? '' : 's' ?>"></span>
            <?php endfor; ?>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($livePages): ?>
<div class="admin-dashboard-panel" style="margin-top:22px">
    <div class="admin-dashboard-panel__title">Right now <span class="g-hint" style="margin:0">last 5 minutes</span> <a href="<?= SITE_URL ?>/admin/visits.php?view=log&amp;range=today">Watch them</a></div>
    <div class="g-bars"><?= $bar($livePages, 'path', 'n', $pageLabel) ?></div>
</div>
<?php endif; ?>

<script>
window.gCharts = window.gCharts || [];
window.gCharts.push({
    el: 'visitorsChart',
    labels: <?= json_encode($labels) ?>,
    series: [
        {label: 'Visitors', data: <?= json_encode($sV) ?>, kind: 'line', tone: 1, fill: true},
        {label: 'Visits', data: <?= json_encode($sS) ?>, kind: 'line', tone: 3},
        {label: 'Orders', data: <?= json_encode($sO) ?>, kind: 'bar', axis: 'y2', tone: 2}
    ]
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
