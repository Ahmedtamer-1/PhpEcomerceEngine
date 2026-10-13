<?php
/**
 * Visit insights — the analysis behind the Insights tab of the Visit log.
 *
 * Rebuilds every visit in a period from page_views + cart_events (same rules as
 * the log: bag/order activity belongs to the visitor's visit it happened in, up
 * to 30 minutes after its last page), then summarises how far visits get, where
 * they stop, how each product performs, the usual paths through the shop, and
 * a short list of plain-language findings.
 */

/** Visits whose first page falls in the period, oldest first. */
function vi_sessions(string $from, string $to, string $source = '', string $device = ''): array
{
    $sessions = [];
    try {
        // Whole visits: also take pages after $to for visits that started inside the period.
        $s = db()->prepare("SELECT pv.session_id, pv.visitor_id, pv.created_at, pv.page_type, pv.path, pv.product_id, pv.duration_s,
                                   pv.source, pv.is_paid, pv.device, pv.is_new_visitor, pv.user_id
                            FROM page_views pv
                            JOIN (SELECT session_id FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY session_id) t
                              ON t.session_id = pv.session_id
                            ORDER BY pv.id LIMIT 250000");
        $s->execute([$from, $to]);
        while ($r = $s->fetch()) {
            $sid = $r['session_id'];
            if (!isset($sessions[$sid])) {
                $sessions[$sid] = ['sid' => $sid, 'vid' => $r['visitor_id'], 'start' => $r['created_at'], 'source' => $r['source'],
                    'paid' => (int)$r['is_paid'], 'device' => $r['device'], 'new' => 0, 'uid' => null, 'pages' => [], 'events' => []];
            }
            $v = &$sessions[$sid];
            $v['end'] = $r['created_at'];
            $v['new'] = max($v['new'], (int)$r['is_new_visitor']);
            if ($r['user_id']) $v['uid'] = (int)$r['user_id'];
            $v['pages'][] = ['t' => $r['created_at'], 'type' => $r['page_type'], 'path' => $r['path'],
                             'pid' => $r['product_id'] ? (int)$r['product_id'] : null, 'dur' => $r['duration_s'] === null ? null : (int)$r['duration_s']];
            unset($v);
        }
    } catch (Exception $e) { error_log('visit insights: ' . $e->getMessage()); return []; }

    $sessions = array_filter($sessions, fn($v) => $v['start'] >= $from && $v['start'] <= $to
        && ($source === '' || $v['source'] === $source) && ($device === '' || $v['device'] === $device));
    if (!$sessions) return [];
    uasort($sessions, fn($a, $b) => $a['start'] <=> $b['start']);

    // Attach bag/order events to the visitor's visit they happened in.
    $byVisitor = [];
    foreach ($sessions as $sid => $v) $byVisitor[$v['vid']][] = $sid;
    try {
        $s = db()->prepare("SELECT event, product_id, quantity, visitor_id, order_id, created_at FROM cart_events
                            WHERE visitor_id IS NOT NULL AND created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY) ORDER BY created_at, id");
        $s->execute([$from, $to]);
        while ($e = $s->fetch()) {
            $best = null;
            foreach ($byVisitor[$e['visitor_id']] ?? [] as $sid) {
                $v = $sessions[$sid];
                if ($e['created_at'] < $v['start']) break;
                if (strtotime($e['created_at']) <= strtotime($v['end']) + 1800) $best = $sid;
            }
            if ($best) $sessions[$best]['events'][] = ['event' => $e['event'], 'pid' => (int)$e['product_id'], 't' => $e['created_at'], 'order' => $e['order_id']];
        }
    } catch (Exception $e) { error_log('visit insights: ' . $e->getMessage()); }

    foreach ($sessions as &$v) {
        $types = array_column($v['pages'], 'type');
        $evs = array_column($v['events'], 'event');
        $v['views'] = count($v['pages']);
        $v['dur'] = array_sum(array_map(fn($p) => (int)$p['dur'], $v['pages']));
        $v['ordered'] = in_array('purchase', $evs, true) || in_array('order', $types, true);
        $v['bagged'] = in_array('add', $evs, true);
        // How far the visit got.
        $v['stage'] = match (true) {
            $v['ordered'] => 'ordered',
            in_array('checkout', $types, true) => 'checkout',
            $v['bagged'] => 'bagged',
            $v['views'] === 1 => 'bounced',
            in_array('product', $types, true) => 'product',
            default => 'browsed',
        };
    }
    unset($v);
    return $sessions;
}

function vi_median(array $xs): ?float
{
    $xs = array_values(array_filter($xs, fn($x) => $x !== null));
    if (!$xs) return null;
    sort($xs);
    $m = intdiv(count($xs), 2);
    return count($xs) % 2 ? (float)$xs[$m] : ($xs[$m - 1] + $xs[$m]) / 2;
}

function vi_pct(float $n, float $d): float { return $d > 0 ? $n / $d * 100 : 0.0; }

/** Headline rates for a set of visits. */
function vi_kpis(array $sessions): array
{
    $n = count($sessions);
    $c = fn(callable $f) => count(array_filter($sessions, $f));
    return [
        'visits'  => $n,
        'bounce'  => vi_pct($c(fn($v) => $v['stage'] === 'bounced'), $n),
        'pages'   => $n ? array_sum(array_column($sessions, 'views')) / $n : 0,
        'time'    => vi_median(array_column($sessions, 'dur')) ?? 0,
        'product' => vi_pct($c(fn($v) => in_array('product', array_column($v['pages'], 'type'), true)), $n),
        'bag'     => vi_pct($c(fn($v) => $v['bagged']), $n),
        'order'   => vi_pct($c(fn($v) => $v['ordered']), $n),
    ];
}

const VI_PAGE_NAMES = ['home' => 'Home', 'shop' => 'Shop', 'product' => 'Product', 'cart' => 'Bag', 'checkout' => 'Checkout',
                       'order' => 'Order placed', 'account' => 'Account', 'track' => 'Track order', 'page' => 'Info page'];

function vi_analyse(array $sessions): array
{
    $n = count($sessions);
    $out = ['n' => $n];

    // ── How far visits get (each visit counted once, at its furthest step) ──
    $stages = ['bounced' => 0, 'browsed' => 0, 'product' => 0, 'bagged' => 0, 'checkout' => 0, 'ordered' => 0];
    foreach ($sessions as $v) $stages[$v['stage']]++;
    $out['stages'] = $stages;

    // ── Products ──
    $prod = [];
    $exitPages = [];
    foreach ($sessions as $v) {
        $seen = [];
        foreach ($v['pages'] as $p) {
            if ($p['type'] !== 'product' || !$p['pid']) continue;
            $pr = &$prod[$p['pid']];
            $pr ??= ['visits' => 0, 'views' => 0, 'durs' => [], 'exits' => 0, 'bagged' => 0, 'ordered' => 0, 'landed' => 0];
            $pr['views']++;
            if ($p['dur'] !== null) $pr['durs'][] = $p['dur'];
            if (!isset($seen[$p['pid']])) { $pr['visits']++; $seen[$p['pid']] = true; }
            unset($pr);
        }
        $first = $v['pages'][0];
        if ($first['type'] === 'product' && $first['pid'] && isset($prod[$first['pid']])) $prod[$first['pid']]['landed']++;
        $last = end($v['pages']);
        if (!$v['ordered'] && $last['type'] === 'product' && $last['pid'] && isset($prod[$last['pid']])) $prod[$last['pid']]['exits']++;
        $bagPids = array_unique(array_column(array_filter($v['events'], fn($e) => $e['event'] === 'add'), 'pid'));
        $buyPids = array_unique(array_column(array_filter($v['events'], fn($e) => $e['event'] === 'purchase'), 'pid'));
        foreach ($bagPids as $pid) if (isset($prod[$pid])) $prod[$pid]['bagged']++;
        foreach ($buyPids as $pid) if (isset($prod[$pid])) $prod[$pid]['ordered']++;

        // Where visits that didn't order stopped, by kind of page.
        if (!$v['ordered']) {
            $k = VI_PAGE_NAMES[$last['type']] ?? 'Other';
            $exitPages[$k] = ($exitPages[$k] ?? 0) + 1;
        }
    }
    foreach ($prod as $pid => &$pr) {
        $pr['pid'] = $pid;
        $pr['time'] = vi_median($pr['durs']);
        $pr['bag_rate'] = vi_pct($pr['bagged'], $pr['visits']);
        $pr['exit_rate'] = vi_pct($pr['exits'], $pr['visits']);
        unset($pr['durs']);
    }
    unset($pr);
    uasort($prod, fn($a, $b) => [$b['visits'], $b['bagged']] <=> [$a['visits'], $a['bagged']]);
    $out['products'] = $prod;
    // The shop's own average, so "converts well" means better than usual here.
    $out['avg_bag_rate'] = vi_pct(array_sum(array_column($prod, 'bagged')), array_sum(array_column($prod, 'visits')));
    $out['star_cut'] = max(15, $out['avg_bag_rate'] * 1.5);

    // Per page kind: how often viewing it is the last thing a visit does.
    $kind = [];
    foreach ($sessions as $v) {
        $last = count($v['pages']) - 1;
        foreach ($v['pages'] as $i => $p) {
            $k = VI_PAGE_NAMES[$p['type']] ?? 'Other';
            $kind[$k]['views'] = ($kind[$k]['views'] ?? 0) + 1;
            $kind[$k]['exits'] = ($kind[$k]['exits'] ?? 0) + (int)($i === $last && !$v['ordered']);
            if ($p['dur'] !== null) $kind[$k]['durs'][] = $p['dur'];
        }
    }
    foreach ($kind as $k => &$r) { $r['k'] = $k; $r['rate'] = vi_pct($r['exits'], $r['views']); $r['time'] = vi_median($r['durs'] ?? []); unset($r['durs']); }
    unset($r);
    uasort($kind, fn($a, $b) => $b['exits'] <=> $a['exits']);
    $out['exit_kinds'] = $kind;
    arsort($exitPages);
    $out['exit_pages'] = $exitPages;

    // ── Usual paths: page kinds in order, repeats folded, bag adds marked ──
    $paths = [];
    foreach ($sessions as $v) {
        $steps = [];
        $tl = array_map(fn($p) => [$p['t'], 0, $p['type'] === 'product' ? 'Product' : (VI_PAGE_NAMES[$p['type']] ?? 'Other')], $v['pages']);
        foreach ($v['events'] as $e) if ($e['event'] === 'add') $tl[] = [$e['t'], 1, '+ Bag'];
        usort($tl, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        foreach ($tl as [, , $label]) {
            $lastI = count($steps) - 1;
            if ($lastI >= 0 && ($steps[$lastI] === $label || ($label === 'Product' && $steps[$lastI] === 'Products'))) {
                if ($label === 'Product') $steps[$lastI] = 'Products';
                continue;
            }
            $steps[] = $label;
        }
        if ($v['ordered'] && end($steps) !== 'Order placed') $steps[] = 'Order placed';
        if (count($steps) > 6) $steps = array_merge(array_slice($steps, 0, 3), ['…'], array_slice($steps, -2));
        $key = implode(' → ', $steps);
        $paths[$key]['n'] = ($paths[$key]['n'] ?? 0) + 1;
        $paths[$key]['ordered'] = ($paths[$key]['ordered'] ?? 0) + (int)$v['ordered'];
        $paths[$key]['steps'] = $steps;
    }
    uasort($paths, fn($a, $b) => $b['n'] <=> $a['n']);
    $out['paths'] = array_slice($paths, 0, 10, true);

    // ── Groups: source, device, new/returning, time of day ──
    $group = function (callable $key) use ($sessions): array {
        $g = [];
        foreach ($sessions as $v) $g[$key($v)][] = $v;
        $rows = [];
        foreach ($g as $k => $vs) $rows[$k] = ['k' => $k] + vi_kpis($vs);
        uasort($rows, fn($a, $b) => $b['visits'] <=> $a['visits']);
        return $rows;
    };
    $out['by_source'] = $group(fn($v) => $v['source']);
    $out['by_device'] = $group(fn($v) => ucfirst($v['device']));
    $out['by_new']    = $group(fn($v) => $v['new'] ? 'New visitors' : 'Returning visitors');
    $parts = ['Night (0–6)', 'Morning (6–12)', 'Afternoon (12–17)', 'Evening (17–21)', 'Late evening (21–24)'];
    $out['by_daypart'] = $group(function ($v) use ($parts) {
        $h = (int)date('G', strtotime($v['start']));
        return $parts[$h < 6 ? 0 : ($h < 12 ? 1 : ($h < 17 ? 2 : ($h < 21 ? 3 : 4)))];
    });
    $order = array_flip($parts);
    uksort($out['by_daypart'], fn($a, $b) => $order[$a] <=> $order[$b]);
    $wd = [];
    foreach ($sessions as $v) $wd[date('l', strtotime($v['start']))][] = $v;
    $out['by_weekday'] = array_map(fn($vs) => vi_kpis($vs), $wd);

    // Where one-page visits land
    $bLand = [];
    foreach ($sessions as $v) if ($v['stage'] === 'bounced') {
        $p = $v['pages'][0];
        $k = $p['type'] === 'product' ? 'p' . $p['pid'] : (VI_PAGE_NAMES[$p['type']] ?? 'Other');
        $bLand[$k] = ($bLand[$k] ?? 0) + 1;
    }
    arsort($bLand);
    $out['bounce_landing'] = $bLand;
    return $out;
}

/**
 * Plain-language findings, most important first.
 * @return list<array{tone:string,title:string,text:string,link?:array}>
 */
function vi_findings(array $A, array $K, ?array $P, array $names): array
{
    $f = [];
    $n = $A['n'];
    if ($n < 5) return [['tone' => 'info', 'title' => 'Not enough visits yet', 'text' => 'There are only ' . $n . ' visit' . ($n === 1 ? '' : 's') . ' in this period. Pick a longer range for findings you can rely on.']];
    $pc = fn($x) => round($x) . '%';
    $pname = fn($pid) => $names[$pid] ?? 'a product';
    $land = function (string $k) use ($names): string { return str_starts_with($k, 'p') && ctype_digit(substr($k, 1)) ? ($names[(int)substr($k, 1)] ?? 'a product page') : $k; };

    // 1. Trend vs previous period
    if ($P && $P['visits'] >= 5) {
        $dv = vi_pct($K['visits'] - $P['visits'], $P['visits']);
        $do = $K['order'] - $P['order'];
        if (abs($dv) >= 15 || abs($do) >= 1) {
            $parts = [];
            if (abs($dv) >= 15) $parts[] = 'visits are ' . ($dv > 0 ? 'up' : 'down') . ' ' . round(abs($dv)) . '% (' . number_format($K['visits']) . ' vs ' . number_format($P['visits']) . ')';
            if (abs($do) >= 1) $parts[] = 'the share of visits that order went ' . ($do > 0 ? 'up' : 'down') . ' from ' . number_format($P['order'], 1) . '% to ' . number_format($K['order'], 1) . '%';
            $f[] = ['tone' => ($dv >= 0 && $do >= 0) ? 'good' : (($dv < 0 && $do < 0) ? 'bad' : 'info'), 'w' => 60,
                    'title' => 'Compared with the period before', 'text' => ucfirst(implode(', and ', $parts)) . '.'];
        }
    }

    // 2. Biggest leak in the journey
    $s = $A['stages'];
    $bounceN = $s['bounced'];
    if ($bounceN / $n >= .3) {
        $topLand = array_key_first($A['bounce_landing']);
        $share = $topLand ? round($A['bounce_landing'][$topLand] / $bounceN * 100) : 0;
        $f[] = ['tone' => 'bad', 'w' => 90 + $bounceN / $n * 10, 'title' => $pc($bounceN / $n * 100) . ' of visits leave after one page',
                'text' => 'That\'s ' . number_format($bounceN) . ' visits.' . ($topLand ? ' ' . $share . '% of them landed on ' . h($land($topLand)) . '. That page has to grab attention fast: a clear photo, the price, and an obvious next step.' : ''),
                'link' => ['show' => 'bounced', 'label' => 'See these visits']];
    }
    $sawProduct = $s['product'] + $s['bagged'] + $s['checkout'] + $s['ordered'];
    $noProduct = $s['browsed'];
    if ($noProduct / $n >= .15) {
        $f[] = ['tone' => 'warn', 'w' => 70, 'title' => $pc($noProduct / $n * 100) . ' browse but never open a product',
                'text' => 'They looked around the home or shop pages and left without opening a single piece. Bigger product photos on Home, or a "new in" row at the top, could help.'];
    }
    $lookNoAdd = $s['product'];
    if ($sawProduct >= 5 && $lookNoAdd / max(1, $sawProduct) >= .6) {
        $f[] = ['tone' => 'warn', 'w' => 75, 'title' => $pc($lookNoAdd / $sawProduct * 100) . ' of people who open a product don\'t add anything',
                'text' => number_format($lookNoAdd) . ' visits viewed products and left without using the bag. The product page is where most interest is lost. Check prices, sizes, extra photos and delivery info there.'];
    }
    $bagNoOrder = $s['bagged'] + $s['checkout'];
    $bagAll = $bagNoOrder + $s['ordered'];
    if ($bagAll >= 3 && $bagNoOrder / $bagAll >= .4) {
        $atCheckout = $s['checkout'];
        $f[] = ['tone' => 'bad', 'w' => 95, 'title' => number_format($bagNoOrder) . ' visits added to bag but didn\'t order (' . $pc($bagNoOrder / $bagAll * 100) . ')',
                'text' => ($atCheckout ? number_format($atCheckout) . ' of them reached checkout and stopped there, so check shipping cost, payment options and the form length. ' : 'Most never opened checkout. ')
                          . 'These people were close to buying. A reminder message or the welcome discount could bring them back.',
                'link' => ['show' => 'bagged', 'label' => 'See these visits']];
    }

    // 3. Products
    $prods = array_filter($A['products'], fn($p) => $p['visits'] >= 4);
    $lookers = array_filter($prods, fn($p) => $p['bagged'] === 0);
    if ($lookers) {
        uasort($lookers, fn($a, $b) => [$b['visits'], $b['time'] ?? 0] <=> [$a['visits'], $a['time'] ?? 0]);
        $p = reset($lookers);
        $f[] = ['tone' => 'warn', 'w' => 80, 'title' => h($pname($p['pid'])) . ': lots of looks, no adds',
                'text' => 'Opened in ' . $p['visits'] . ' visits' . ($p['time'] !== null ? ', about ' . analytics_fmt_duration($p['time']) . ' on the page each time,' : '') . ' but nobody added it to the bag. '
                          . ($p['time'] !== null && $p['time'] >= 30 ? 'People are reading it, so something stops them: price, size, or a question the page doesn\'t answer.' : 'People leave it quickly, so the photos may not match what they expected.')];
    }
    $stars = array_filter($prods, fn($p) => $p['bag_rate'] >= $A['star_cut']);
    if ($stars) {
        uasort($stars, fn($a, $b) => $b['bag_rate'] <=> $a['bag_rate']);
        $p = reset($stars);
        $f[] = ['tone' => 'good', 'w' => 65, 'title' => h($pname($p['pid'])) . ' sells itself',
                'text' => $pc($p['bag_rate']) . ' of the visits that open it add it to the bag (' . $p['bagged'] . ' of ' . $p['visits'] . '), against ' . $pc($A['avg_bag_rate']) . ' for products overall. Feature it on Home and in ads.'];
    }
    $exiters = array_filter($prods, fn($p) => $p['exit_rate'] >= 50 && $p['bagged'] === 0);
    if ($exiters) {
        uasort($exiters, fn($a, $b) => $b['exits'] <=> $a['exits']);
        $p = reset($exiters);
        if (!$lookers || $p['pid'] !== reset($lookers)['pid']) {
            $f[] = ['tone' => 'warn', 'w' => 60, 'title' => 'Visits often end on ' . h($pname($p['pid'])),
                    'text' => $pc($p['exit_rate']) . ' of visits that open it leave the shop right there. Add "you may also like" pieces or a clearer way back to the shop.'];
        }
    }

    // 4. Who converts
    $src = array_filter($A['by_source'], fn($r) => $r['visits'] >= 8);
    if (count($src) >= 2) {
        uasort($src, fn($a, $b) => $b['bag'] <=> $a['bag']);
        $best = reset($src); $worst = end($src);
        if ($best['bag'] - $worst['bag'] >= 5) {
            $f[] = ['tone' => 'info', 'w' => 55, 'title' => h($best['k']) . ' brings the most interested visitors',
                    'text' => $pc($best['bag']) . ' of ' . h($best['k']) . ' visits add to bag, against ' . $pc($worst['bag']) . ' from ' . h($worst['k'])
                              . ' (' . $worst['visits'] . ' visits, ' . $pc($worst['bounce']) . ' leave after one page).',
                    'link' => ['source' => $worst['k'], 'label' => 'See ' . $worst['k'] . ' visits']];
        }
    }
    $dev = array_filter($A['by_device'], fn($r) => $r['visits'] >= 8);
    if (isset($dev['Mobile'], $dev['Desktop']) && abs($dev['Mobile']['bounce'] - $dev['Desktop']['bounce']) >= 12) {
        $worse = $dev['Mobile']['bounce'] > $dev['Desktop']['bounce'] ? 'Mobile' : 'Desktop';
        $better = $worse === 'Mobile' ? 'Desktop' : 'Mobile';
        $f[] = ['tone' => 'warn', 'w' => 50, 'title' => $worse . ' visitors leave much faster',
                'text' => $pc($dev[$worse]['bounce']) . ' of ' . strtolower($worse) . ' visits leave after one page, against ' . $pc($dev[$better]['bounce']) . ' on ' . strtolower($better) . '. Open the shop on a ' . strtolower($worse === 'Mobile' ? 'phone' : 'computer') . ' and check how the first screen looks.',
                'link' => ['device' => strtolower($worse), 'label' => 'See ' . strtolower($worse) . ' visits']];
    }
    $nr = $A['by_new'];
    if (isset($nr['New visitors'], $nr['Returning visitors']) && $nr['Returning visitors']['visits'] >= 5 && $nr['New visitors']['order'] > 0) {
        $ratio = $nr['Returning visitors']['order'] / $nr['New visitors']['order'];
        if ($ratio >= 1.5) {
            $f[] = ['tone' => 'info', 'w' => 45, 'title' => 'Returning visitors order ' . number_format($ratio, 1) . '× as often',
                    'text' => number_format($nr['Returning visitors']['order'], 1) . '% of return visits end in an order, against ' . number_format($nr['New visitors']['order'], 1) . '% for first visits. Getting people to come back (Instagram, retargeting ads, sign-ups) pays off.',
                    'link' => ['show' => 'returning', 'label' => 'See returning visits']];
        }
    }

    // 5. When
    $dp = array_filter($A['by_daypart'], fn($r) => $r['visits'] >= 5);
    if (count($dp) >= 2) {
        $busiest = array_reduce($dp, fn($c, $r) => !$c || $r['visits'] > $c['visits'] ? $r : $c);
        $bestBag = array_reduce($dp, fn($c, $r) => !$c || $r['bag'] > $c['bag'] ? $r : $c);
        $text = 'The most visits come in the ' . strtolower(preg_replace('/ \(.*/', '', $busiest['k'])) . ' (' . $pc($busiest['visits'] / $n * 100) . ').';
        if ($bestBag['k'] !== $busiest['k'] && $bestBag['bag'] - $busiest['bag'] >= 3) {
            $text .= ' But ' . strtolower(preg_replace('/ \(.*/', '', $bestBag['k'])) . ' visitors are the most likely to add to bag (' . $pc($bestBag['bag']) . ' vs ' . $pc($busiest['bag']) . '), so that\'s a good time to post.';
        } else {
            $text .= ' That\'s the best time to post and run stories.';
        }
        $f[] = ['tone' => 'info', 'w' => 40, 'title' => 'Best time of day', 'text' => $text];
    }

    usort($f, fn($a, $b) => $b['w'] <=> $a['w']);
    return array_slice($f, 0, 8);
}
