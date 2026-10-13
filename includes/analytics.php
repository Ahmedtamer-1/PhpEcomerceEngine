<?php
/**
 * Visitor tracking, cart-demand events and the one-time welcome discount.
 *
 * Loaded by functions.php. Everything here fails soft: tracking must never be
 * the reason a page, an add-to-cart or a checkout breaks.
 */

// ════════════════════════════════════════════════════════
// SCHEMA (self-installing — see database/migration-analytics-welcome.sql)
// ════════════════════════════════════════════════════════

/**
 * Create the tracking tables and welcome-discount columns once. Guarded by a
 * settings flag so the DDL runs on one request after the upload, not on every
 * page view.
 */
function analytics_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (setting('schema_analytics_v1') === '1') return;

    $pdo = db();
    $stmts = [
        "CREATE TABLE IF NOT EXISTS page_views (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            visitor_id CHAR(16) NOT NULL,
            session_id CHAR(16) NOT NULL,
            user_id INT UNSIGNED NULL,
            path VARCHAR(255) NOT NULL,
            page_type VARCHAR(20) NOT NULL DEFAULT 'other',
            product_id INT UNSIGNED NULL,
            source VARCHAR(40) NOT NULL DEFAULT 'Direct',
            is_paid TINYINT(1) NOT NULL DEFAULT 0,
            referrer_host VARCHAR(120) NULL,
            utm_campaign VARCHAR(120) NULL,
            device VARCHAR(10) NOT NULL DEFAULT 'desktop',
            browser VARCHAR(20) NULL,
            is_new_visitor TINYINT(1) NOT NULL DEFAULT 0,
            duration_s SMALLINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_created (created_at),
            KEY idx_visitor (visitor_id, created_at),
            KEY idx_session (session_id),
            KEY idx_product (product_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS cart_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event VARCHAR(10) NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            variant_id INT UNSIGNED NULL,
            quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            visitor_id CHAR(16) NULL,
            session_key VARCHAR(64) NULL,
            user_id INT UNSIGNED NULL,
            order_id INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_product (product_id, event, created_at),
            KEY idx_created (event, created_at),
            KEY idx_visitor (visitor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($stmts as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) { error_log('analytics schema: ' . $e->getMessage()); return; }
    }

    // Welcome-discount columns on users (skip any that already exist).
    $have = [];
    try {
        $have = array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field');
    } catch (Exception $e) { return; }
    $cols = [
        'welcome_discount_pct'      => 'DECIMAL(5,2) NULL DEFAULT NULL',
        'welcome_discount_order_id' => 'INT UNSIGNED NULL DEFAULT NULL',
        'welcome_discount_used_at'  => 'DATETIME NULL DEFAULT NULL',
    ];
    foreach ($cols as $col => $def) {
        if (in_array($col, $have, true)) continue;
        try { $pdo->exec("ALTER TABLE users ADD COLUMN `$col` $def"); }
        catch (Exception $e) { error_log('analytics schema: ' . $e->getMessage()); return; }
    }

    try {
        $pdo->prepare('INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
            ->execute(['schema_analytics_v1', '1']);
        // Remember when tracking began, so reports can say "since …".
        $pdo->prepare('INSERT IGNORE INTO settings (key_name, value) VALUES (?, ?)')
            ->execute(['analytics_started_at', date('Y-m-d H:i:s')]);
    } catch (Exception $e) {}
}

// ════════════════════════════════════════════════════════
// VISITOR IDENTITY
// ════════════════════════════════════════════════════════

const ANALYTICS_COOKIE = 'adn_vid';
const STAFF_COOKIE     = 'adn_staff';

/**
 * Is this the shop's own browser? Set once someone signs in as admin and kept
 * for a year, so the owner's browsing is never counted — even when signed out.
 */
function analytics_is_staff(): bool
{
    if (!empty($_COOKIE[STAFF_COOKIE])) return true;
    $u = current_user();
    return ($u['role'] ?? '') === 'admin';
}

function analytics_mark_staff(): void
{
    if (headers_sent()) return;
    setcookie(STAFF_COOKIE, '1', ['expires' => time() + 365 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https']);
    $_COOKIE[STAFF_COOKIE] = '1';
}

/** The anonymous first-party visitor id from the cookie, or '' if none yet. */
function analytics_visitor_id(): string
{
    $v = $_COOKIE[ANALYTICS_COOKIE] ?? '';
    return (is_string($v) && preg_match('/^[a-f0-9]{16}$/', $v)) ? $v : '';
}

function analytics_is_bot(string $ua): bool
{
    if ($ua === '') return true;
    return (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|facebookcatalog|headless|lighthouse|pagespeed|preview|python|curl|wget|httpclient|monitor|scan/i', $ua);
}

function analytics_device(string $ua): string
{
    if (preg_match('/iPad|Tablet|(Android(?!.*Mobile))/i', $ua)) return 'tablet';
    if (preg_match('/Mobi|iPhone|Android/i', $ua)) return 'mobile';
    return 'desktop';
}

function analytics_browser(string $ua): string
{
    // In-app browsers first: most of this store's traffic arrives from them.
    if (stripos($ua, 'Instagram') !== false) return 'Instagram app';
    if (preg_match('/FBAN|FBAV|FB_IAB/i', $ua)) return 'Facebook app';
    if (stripos($ua, 'TikTok') !== false || stripos($ua, 'musical_ly') !== false) return 'TikTok app';
    if (stripos($ua, 'SamsungBrowser') !== false) return 'Samsung';
    if (stripos($ua, 'Edg/') !== false) return 'Edge';
    if (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) return 'Opera';
    if (stripos($ua, 'Firefox') !== false) return 'Firefox';
    if (stripos($ua, 'CriOS') !== false || stripos($ua, 'Chrome') !== false) return 'Chrome';
    if (stripos($ua, 'Safari') !== false) return 'Safari';
    return 'Other';
}

/**
 * Where a visit came from, from its landing referrer, UTM tags, ad click ids
 * and — when the referrer is blank, as it usually is inside Instagram and
 * Facebook — the in-app browser itself.
 *
 * @return array{0:string,1:bool} [source label, paid?]
 */
function analytics_classify_source(string $refHost, string $utmSource, string $utmMedium, string $clickId, string $ua): array
{
    $paid = (bool) preg_match('/^(cpc|ppc|paid|paid_?social|ads?|display|cpm)$/i', $utmMedium)
         || in_array($clickId, ['gclid', 'ttclid'], true);
    $s = strtolower($utmSource);
    $map = [
        'Instagram' => ['ig', 'instagram'],
        'Facebook'  => ['fb', 'facebook', 'meta'],
        'TikTok'    => ['tiktok', 'tt'],
        'Google'    => ['google', 'gads', 'adwords'],
        'WhatsApp'  => ['whatsapp', 'wa'],
        'Email'     => ['email', 'newsletter', 'mail'],
    ];
    if ($s !== '') {
        foreach ($map as $label => $keys) if (in_array($s, $keys, true)) return [$label, $paid];
        return [mb_substr(ucfirst($utmSource), 0, 40), $paid];
    }
    $h = strtolower($refHost);
    if ($h !== '') {
        if (str_contains($h, 'instagram')) return ['Instagram', $paid];
        if (str_contains($h, 'facebook') || $h === 'fb.me' || str_contains($h, 'messenger')) return ['Facebook', $paid];
        if (str_contains($h, 'tiktok')) return ['TikTok', $paid];
        if (str_contains($h, 'whatsapp') || $h === 'wa.me') return ['WhatsApp', $paid];
        if (preg_match('/(^|\.)google\./', $h)) return ['Google', $paid];
        if (preg_match('/bing\.|yahoo\.|duckduckgo|yandex|ecosia/', $h)) return ['Other search', $paid];
        if (preg_match('/mail\.|outlook|gmail/', $h)) return ['Email', $paid];
        return ['Other sites', $paid];
    }
    if ($clickId === 'fbclid') return [stripos($ua, 'Instagram') !== false ? 'Instagram' : 'Facebook', $paid];
    if ($clickId === 'gclid') return ['Google', true];
    if ($clickId === 'ttclid') return ['TikTok', true];
    if (stripos($ua, 'Instagram') !== false) return ['Instagram', $paid];
    if (preg_match('/FBAN|FBAV|FB_IAB/i', $ua)) return ['Facebook', $paid];
    if (stripos($ua, 'TikTok') !== false || stripos($ua, 'musical_ly') !== false) return ['TikTok', $paid];
    return ['Direct', $paid];
}

/**
 * Small inline script for storefront pages: sends one page-view beacon and,
 * when the tab is hidden, how long the page was open. Done in the browser
 * rather than in PHP so bots that don't run JavaScript aren't counted and
 * cached pages are still measured.
 */
function analytics_beacon_script(string $pageType = 'other', ?int $productId = null): string
{
    $cfg = json_encode([
        // Neutral name: ad blockers often block URLs containing "track".
        'u'  => SITE_URL . '/api/pv.php',
        't'  => $pageType,
        'p'  => $productId,
    ]);
    return <<<HTML
<script>
(function(){try{
var C=$cfg,S=window.sessionStorage,sid=S.getItem('adn_sid'),land=null;
if(!sid){sid=Array.from(crypto.getRandomValues(new Uint8Array(8)),function(b){return('0'+b.toString(16)).slice(-2)}).join('');S.setItem('adn_sid',sid);
 var q=new URLSearchParams(location.search),ck=['fbclid','gclid','ttclid'].filter(function(k){return q.has(k)})[0]||'';
 var r='';try{r=document.referrer?new URL(document.referrer).hostname:''}catch(e){}
 if(r===location.hostname)r='';
 land={r:r,us:q.get('utm_source')||'',um:q.get('utm_medium')||'',uc:q.get('utm_campaign')||'',ck:ck};S.setItem('adn_land',JSON.stringify(land));}
else{try{land=JSON.parse(S.getItem('adn_land')||'null')}catch(e){}}
var b=Object.assign({sid:sid,t:C.t,p:C.p,path:location.pathname+location.search},land||{}),id=0,t0=Date.now(),sent=false;
fetch(C.u,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b),credentials:'same-origin',keepalive:true})
 .then(function(r){return r.json()}).then(function(d){id=d&&d.id||0}).catch(function(){});
function end(){if(!id||sent)return;sent=true;navigator.sendBeacon&&navigator.sendBeacon(C.u,new Blob([JSON.stringify({id:id,dur:Math.round((Date.now()-t0)/1000)})],{type:'application/json'}));}
document.addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden')end()});
addEventListener('pagehide',end);
}catch(e){}})();
</script>
HTML;
}

// ════════════════════════════════════════════════════════
// CART EVENTS (demand history — cart_sessions rows vanish at checkout)
// ════════════════════════════════════════════════════════

function cart_event_log(string $event, int $productId, ?int $variantId, int $qty, float $unitPrice = 0, ?int $orderId = null): void
{
    if (analytics_is_staff() && $event !== 'purchase') return; // owner testing the shop
    try {
        analytics_ensure_schema();
        lume_session_start();
        db()->prepare(
            'INSERT INTO cart_events (event, product_id, variant_id, quantity, unit_price, visitor_id, session_key, user_id, order_id, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,NOW())'
        )->execute([
            $event, $productId, $variantId, max(1, $qty), $unitPrice,
            analytics_visitor_id() ?: null,
            $_SESSION['cart_session_key'] ?? null,
            !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            $orderId,
        ]);
    } catch (Exception $e) {
        error_log('cart_event_log: ' . $e->getMessage());
    }
}

// ════════════════════════════════════════════════════════
// WELCOME DISCOUNT (one per account, applied automatically)
// ════════════════════════════════════════════════════════

function welcome_offer_enabled(): bool
{
    return setting('welcome_offer_enabled', '1') === '1' && welcome_offer_pct() > 0;
}

function welcome_offer_pct(): float
{
    return max(0, min(50, (float) setting('welcome_offer_pct', '5')));
}

/**
 * Give a newly created account its welcome discount — unless this email has
 * ordered before as a guest (the offer is for new customers).
 */
function welcome_discount_grant(int $userId, string $email): void
{
    if (!welcome_offer_enabled()) return;
    try {
        analytics_ensure_schema();
        $prior = db()->prepare(
            "SELECT COUNT(*) FROM orders WHERE (guest_email = ? OR user_id = ?) AND status NOT IN ('cancelled','refunded')"
        );
        $prior->execute([$email, $userId]);
        if ((int) $prior->fetchColumn() > 0) return;
        db()->prepare('UPDATE users SET welcome_discount_pct = ? WHERE id = ? AND welcome_discount_pct IS NULL')
            ->execute([welcome_offer_pct(), $userId]);
        lume_session_start();
        $_SESSION['welcome_flash'] = 1;
    } catch (Exception $e) {
        error_log('welcome_discount_grant: ' . $e->getMessage());
    }
}

/** The logged-in customer's unused welcome-discount percentage, or 0. */
function welcome_discount_available(): float
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = 0.0;
    if (!is_logged_in()) return $cache;
    try {
        analytics_ensure_schema();
        $s = db()->prepare('SELECT welcome_discount_pct FROM users WHERE id = ? AND welcome_discount_used_at IS NULL');
        $s->execute([(int)$_SESSION['user_id']]);
        $cache = (float) ($s->fetchColumn() ?: 0);
    } catch (Exception $e) {}
    return $cache;
}

/**
 * The discount the current cart gets, or null. Applied to the products only,
 * never to shipping or the COD fee.
 *
 * @return array{pct:float,amount:float,label:string}|null
 */
function cart_discount(?float $subtotal = null): ?array
{
    $pct = welcome_discount_available();
    if ($pct <= 0) return null;
    $subtotal ??= cart_total();
    if ($subtotal <= 0) return null;
    return [
        'pct'    => $pct,
        'amount' => round($subtotal * $pct / 100, 2),
        'label'  => 'Welcome discount (' . rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%)',
    ];
}

/**
 * Atomically mark the discount as used before the order is written, so two
 * checkouts submitted at once can't both get it. Returns the percentage
 * claimed, or 0 if there was nothing to claim.
 */
function welcome_discount_claim(int $userId): float
{
    try {
        $s = db()->prepare('SELECT welcome_discount_pct FROM users WHERE id = ? AND welcome_discount_used_at IS NULL');
        $s->execute([$userId]);
        $pct = (float) ($s->fetchColumn() ?: 0);
        if ($pct <= 0) return 0;
        $u = db()->prepare('UPDATE users SET welcome_discount_used_at = NOW() WHERE id = ? AND welcome_discount_used_at IS NULL');
        $u->execute([$userId]);
        return $u->rowCount() === 1 ? $pct : 0;
    } catch (Exception $e) {
        return 0;
    }
}

function welcome_discount_attach_order(int $userId, int $orderId): void
{
    try {
        db()->prepare('UPDATE users SET welcome_discount_order_id = ? WHERE id = ?')->execute([$orderId, $userId]);
    } catch (Exception $e) {}
}

/** Give the discount back when the checkout never produced an order. */
function welcome_discount_unclaim(int $userId): void
{
    try {
        db()->prepare('UPDATE users SET welcome_discount_used_at = NULL, welcome_discount_order_id = NULL WHERE id = ? AND welcome_discount_order_id IS NULL')
            ->execute([$userId]);
    } catch (Exception $e) {}
}

/**
 * A card payment that failed or was abandoned cancels its order automatically
 * (cancel_order()). The customer never got the discount in that case, so it
 * goes back to them. Orders cancelled by the shop in the admin keep it used.
 */
function welcome_discount_release_for_order(int $orderId): void
{
    try {
        db()->prepare('UPDATE users SET welcome_discount_used_at = NULL, welcome_discount_order_id = NULL WHERE welcome_discount_order_id = ?')
            ->execute([$orderId]);
    } catch (Exception $e) {}
}

// ════════════════════════════════════════════════════════
// REPORT HELPERS (admin)
// ════════════════════════════════════════════════════════

/**
 * Standard date ranges shared by the dashboard and the analytics pages.
 * @return array{key:string,label:string,start:string,end:string,prevStart:string,prevEnd:string,days:int}
 */
function report_range(string $key): array
{
    $today = date('Y-m-d');
    $r = match ($key) {
        'today'      => [$today, $today, 'Today'],
        'yesterday'  => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day')), 'Yesterday'],
        '7days'      => [date('Y-m-d', strtotime('-6 days')), $today, 'Last 7 days'],
        '30days'     => [date('Y-m-d', strtotime('-29 days')), $today, 'Last 30 days'],
        '90days'     => [date('Y-m-d', strtotime('-89 days')), $today, 'Last 90 days'],
        'last_month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month')), 'Last month'],
        'all_time'   => ['2020-01-01', $today, 'All time'],
        default      => [date('Y-m-01'), $today, 'This month'],
    };
    if (!in_array($key, ['today', 'yesterday', '7days', '30days', '90days', 'last_month', 'all_time'], true)) $key = 'this_month';
    [$start, $end, $label] = $r;
    $days = (int) round((strtotime($end) - strtotime($start)) / 86400) + 1;
    // The previous period is the same length immediately before.
    $prevEnd   = date('Y-m-d', strtotime($start . ' -1 day'));
    $prevStart = date('Y-m-d', strtotime($prevEnd . ' -' . ($days - 1) . ' days'));
    if ($key === 'this_month') { // compare month-to-date with the same days last month
        $prevStart = date('Y-m-01', strtotime('first day of last month'));
        $prevEnd   = date('Y-m-d', min(strtotime(date('Y-m-t', strtotime($prevStart))), strtotime($prevStart . ' +' . ($days - 1) . ' days')));
    }
    if ($key === 'last_month') {
        $prevStart = date('Y-m-01', strtotime($start . ' -1 month'));
        $prevEnd   = date('Y-m-t', strtotime($prevStart));
    }
    if ($key === 'all_time') { $prevStart = $prevEnd = '1970-01-01'; }
    return compact('key', 'label', 'start', 'end', 'prevStart', 'prevEnd', 'days');
}

function report_ranges(): array
{
    return ['today' => 'Today', 'yesterday' => 'Yesterday', '7days' => '7 days', '30days' => '30 days',
            'this_month' => 'This month', 'last_month' => 'Last month', '90days' => '90 days', 'all_time' => 'All time'];
}

/** First day with detailed page-view data, or null before tracking starts. */
function analytics_first_day(): ?string
{
    static $d = false;
    if ($d !== false) return $d;
    try { $d = db()->query('SELECT DATE(MIN(created_at)) FROM page_views')->fetchColumn() ?: null; }
    catch (Exception $e) { $d = null; }
    return $d;
}

/**
 * Unique visitors per day, from the detailed tracker where it has data and
 * from the older daily counter before that, so history isn't lost.
 *
 * @return array<string,int> date => visitors
 */
function analytics_daily_visitors(string $start, string $end): array
{
    $out = [];
    $first = analytics_first_day();
    try {
        $s = db()->prepare('SELECT visit_date, visitors FROM daily_visitors WHERE visit_date BETWEEN ? AND ?' . ($first ? ' AND visit_date < ?' : ''));
        $s->execute($first ? [$start, $end, $first] : [$start, $end]);
        foreach ($s->fetchAll(PDO::FETCH_KEY_PAIR) as $d => $v) $out[$d] = (int)$v;
    } catch (Exception $e) {}
    if ($first) {
        try {
            $s = db()->prepare('SELECT DATE(created_at) d, COUNT(DISTINCT visitor_id) v FROM page_views WHERE created_at BETWEEN ? AND ? GROUP BY d');
            $s->execute([$start . ' 00:00:00', $end . ' 23:59:59']);
            foreach ($s->fetchAll(PDO::FETCH_KEY_PAIR) as $d => $v) $out[$d] = (int)$v;
        } catch (Exception $e) {}
    }
    return $out;
}

/** Unique visitors over a whole period (de-duplicated across days where possible). */
function analytics_visitors_total(string $start, string $end): int
{
    $first = analytics_first_day();
    $total = 0;
    if (!$first || $start < $first) {
        try {
            $s = db()->prepare('SELECT COALESCE(SUM(visitors),0) FROM daily_visitors WHERE visit_date BETWEEN ? AND ?' . ($first ? ' AND visit_date < ?' : ''));
            $s->execute($first ? [$start, $end, $first] : [$start, $end]);
            $total += (int) $s->fetchColumn();
        } catch (Exception $e) {}
    }
    if ($first && $end >= $first) {
        try {
            $s = db()->prepare('SELECT COUNT(DISTINCT visitor_id) FROM page_views WHERE created_at BETWEEN ? AND ?');
            $s->execute([max($start, $first) . ' 00:00:00', $end . ' 23:59:59']);
            $total += (int) $s->fetchColumn();
        } catch (Exception $e) {}
    }
    return $total;
}

/** Product names for page-view rows that point at products. @return array<int,string> */
function analytics_product_names(array $productIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
    if (!$ids) return [];
    try {
        $s = db()->prepare('SELECT id, name FROM products WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $s->execute($ids);
        return $s->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) { return []; }
}

/** A readable name for a page-view row ({page_type, path, product_id}). */
function analytics_page_label(array $r, array $prodNames): string
{
    $names = ['home' => 'Home', 'shop' => 'Shop', 'cart' => 'Bag', 'checkout' => 'Checkout', 'order' => 'Order confirmed',
              'account' => 'Account', 'track' => 'Track order'];
    if ($r['page_type'] === 'product') return $prodNames[(int)$r['product_id']] ?? 'Product';
    $label = $names[$r['page_type']] ?? ucfirst(trim(preg_replace('/\.php.*$/', '', (string)$r['path']), '/') ?: 'Page');
    if ($r['page_type'] === 'shop' && str_contains((string)$r['path'], '?')) {
        parse_str((string)parse_url($r['path'], PHP_URL_QUERY), $q);
        if (!empty($q['category'])) $label .= ' · ' . $q['category'];
        elseif (!empty($q['q'])) $label .= ' · “' . $q['q'] . '”';
    }
    return $label;
}

/** 75 → "1m 15s" */
function analytics_fmt_duration($s): string
{
    $s = (int) round((float)$s);
    if ($s < 60) return $s . 's';
    if ($s < 3600) return intdiv($s, 60) . 'm ' . str_pad((string)($s % 60), 2, '0', STR_PAD_LEFT) . 's';
    return intdiv($s, 3600) . 'h ' . str_pad((string)intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT) . 'm';
}

function trend_pct(float $current, float $previous): ?int
{
    if ($previous == 0) return $current > 0 ? null : 0; // null = "new", nothing to compare
    return (int) round(($current - $previous) / $previous * 100);
}

/** Render a small up/down trend chip for a metric card. */
function trend_chip(?int $pct, bool $inverse = false): string
{
    if ($pct === null) return '<span class="admin-stat-card__trend admin-stat-card__trend--up">new</span>';
    if ($pct === 0) return '';
    $good = $inverse ? $pct < 0 : $pct > 0;
    $pts  = $pct > 0 ? '18 15 12 9 6 15' : '6 9 12 15 18 9';
    return '<span class="admin-stat-card__trend admin-stat-card__trend--' . ($good ? 'up' : 'down') . '"><svg viewBox="0 0 24 24"><polyline points="' . $pts . '"/></svg>'
        . ($pct > 0 ? '+' : '') . $pct . '%</span>';
}
