<?php
/**
 * Meta Conversions API — server-side mirror of the browser Pixel events
 * fired in includes/header.php and assets/js/main.js.
 *
 * Sending the same standard events (AddToCart, InitiateCheckout, Purchase)
 * directly from the server means they still land in Meta when the browser
 * Pixel doesn't fire — ad blockers, Safari ITP, iOS App Tracking
 * Transparency. Each server call here is paired with a browser fbq() call
 * using the SAME event_id; that's how Meta knows the two are one event and
 * dedupes them instead of double-counting.
 *
 * Docs: https://developers.facebook.com/docs/marketing-api/conversions-api
 */

/**
 * Reads the CAPI token without assuming the constant exists.
 *
 * META_CONVERSION_API_TOKEN is defined in config/config.php (and via .env).
 * If this file is deployed but those are not — a partial upload — referencing
 * the constant directly is a fatal Error in PHP 8, which would take down
 * every page that touches the cart. defined() keeps a partial deploy merely
 * inert instead of fatal.
 */
function meta_capi_token(): string
{
    return defined('META_CONVERSION_API_TOKEN') ? (string)META_CONVERSION_API_TOKEN : '';
}

function meta_capi_pixel_id(): string
{
    $fromDb = function_exists('setting') ? setting('meta_pixel_id') : '';
    if ($fromDb !== '') return $fromDb;
    return defined('META_PIXEL_ID') ? (string)META_PIXEL_ID : '';
}

function meta_capi_enabled(): bool
{
    return meta_capi_pixel_id() !== '' && meta_capi_token() !== '';
}

/** SHA-256 a piece of PII the way Meta requires: lowercased and trimmed first. */
function meta_capi_hash(?string $value): ?string
{
    $value = strtolower(trim((string)$value));
    return $value === '' ? null : hash('sha256', $value);
}

/**
 * Builds the `user_data` block Meta uses to match an event to a person.
 * Fields we don't know are simply omitted — CAPI matches on whatever subset
 * it gets, so a guest AddToCart (IP + user agent + fbp only) is still useful.
 */
function meta_capi_user_data(array $overrides = []): array
{
    $data = [];

    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    if ($ip) {
        // XFF can be a comma-separated proxy chain; the client is the first hop.
        $data['client_ip_address'] = trim(explode(',', $ip)[0]);
    }
    if (!empty($_SERVER['HTTP_USER_AGENT'])) {
        $data['client_user_agent'] = $_SERVER['HTTP_USER_AGENT'];
    }
    if (!empty($_COOKIE['_fbp'])) {
        $data['fbp'] = $_COOKIE['_fbp'];
    }
    if (!empty($_COOKIE['_fbc'])) {
        $data['fbc'] = $_COOKIE['_fbc'];
    } elseif (!empty($_GET['fbclid'])) {
        // No _fbc cookie yet (first hit of an ad click) — Meta's documented
        // fallback: build it straight from the fbclid query param.
        $data['fbc'] = 'fb.1.' . time() . '.' . preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['fbclid']);
    }

    $user = current_user();
    if ($user) {
        $data['external_id'] = meta_capi_hash((string)$user['id']);
        if (!empty($user['email']))      $data['em'] = meta_capi_hash($user['email']);
        if (!empty($user['first_name'])) $data['fn'] = meta_capi_hash($user['first_name']);
        if (!empty($user['last_name']))  $data['ln'] = meta_capi_hash($user['last_name']);
    }

    // Checkout-time overrides (guest email/phone/name/address) win over
    // whatever a logged-in profile has, since they're what the order used.
    $map = ['email' => 'em', 'phone' => 'ph', 'first_name' => 'fn', 'last_name' => 'ln',
            'city' => 'ct', 'country' => 'country'];
    foreach ($map as $from => $to) {
        if (empty($overrides[$from])) continue;
        $val = $overrides[$from];
        if ($from === 'phone') {
            $val = preg_replace('/[^0-9]/', '', $val); // digits only, per spec
            // Meta matches on the full international number; local Egyptian
            // mobiles (01xxxxxxxxx) otherwise almost never match.
            if (preg_match('/^01\d{9}$/', $val)) $val = '2' . $val;
            elseif (str_starts_with($val, '00')) $val = substr($val, 2);
        }
        if ($from === 'country') $val = substr(trim($val), 0, 2);           // best-effort ISO-2
        $hashed = meta_capi_hash($val);
        if ($hashed) $data[$to] = $hashed;
    }

    return $data;
}

/**
 * Sends one event to the Conversions API. Fire-and-forget by design:
 * failures are logged, never thrown, so a Meta outage or bad token can
 * never break cart/checkout for a real customer.
 */
function meta_capi_send(string $eventName, string $eventId, array $customData = [], array $userDataOverrides = [], ?string $eventSourceUrl = null): void
{
    // Analytics must NEVER be able to break the store. Everything below runs
    // inside a Throwable guard: a missing extension, a DNS failure, a bad
    // token — all of it degrades to a log line, never a 500. (Learned the
    // hard way: an unguarded curl_init() here once took add-to-cart down.)
    try {
        // Say WHY when disabled. Silence here is indistinguishable from
        // "sent successfully" when you're staring at Events Manager
        // wondering where your server events went.
        if (!meta_capi_enabled()) {
            error_log('Meta CAPI [' . $eventName . '] skipped: '
                . (meta_capi_pixel_id() === '' ? 'no pixel ID configured' : 'no access token configured (check META_CONVERSION_API_TOKEN in .env)'));
            return;
        }

        $pixelId = meta_capi_pixel_id();
        $payload = json_encode([
            'data' => [[
                'event_name'       => $eventName,
                'event_time'       => time(),
                'event_id'         => $eventId,
                'action_source'    => 'website',
                'event_source_url' => $eventSourceUrl ?? (SITE_URL . ($_SERVER['REQUEST_URI'] ?? '/')),
                'user_data'        => meta_capi_user_data($userDataOverrides),
                'custom_data'      => $customData,
            ]],
        ]);

        $url = 'https://graph.facebook.com/v21.0/' . urlencode($pixelId) . '/events'
             . '?access_token=' . urlencode(meta_capi_token());

        $response = null;
        $error    = null;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 2, // a slow Meta must never hold up a customer
                CURLOPT_CONNECTTIMEOUT => 1,
            ]);
            $response = curl_exec($ch);
            $error    = curl_error($ch) ?: null;
            curl_close($ch);
        } elseif (ini_get('allow_url_fopen')) {
            // Fallback for hosts without the curl extension.
            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n",
                'content'       => $payload,
                'timeout'       => 2,
                'ignore_errors' => true,
            ]]);
            $response = @file_get_contents($url, false, $ctx);
            if ($response === false) { $response = null; $error = 'stream request failed'; }
        } else {
            $error = 'no HTTP transport available (curl extension and allow_url_fopen both unavailable)';
        }

        if ($error || (is_string($response) && strpos($response, '"error"') !== false)) {
            error_log('Meta CAPI [' . $eventName . ']: ' . ($error ?: $response));
        }
    } catch (\Throwable $e) {
        error_log('Meta CAPI [' . $eventName . '] exception: ' . $e->getMessage());
    }
}

/** A fresh, unique event_id for events with no natural stable key (AddToCart, InitiateCheckout). */
function meta_capi_event_id(string $prefix): string
{
    return $prefix . '_' . bin2hex(random_bytes(8));
}
