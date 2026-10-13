<?php
/**
 * LUMEEGY — Central Configuration
 * ─────────────────────────────────────────────────────────────
 * Edit this file to set your local / Hostinger credentials.
 * NEVER commit real API keys to version control.
 */

// ── Environment ──────────────────────────────────────────────
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2) + [NULL, NULL];
        if ($name !== null && $value !== null) {
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (!defined($name)) define($name, $value);
        }
    }
}

if(!defined('ENV')) define('ENV', 'production'); // 'development' | 'production'

// Detect dynamic SITE_URL if not explicitly set in .env
if(!defined('SITE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
    $domainName = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Auto-detect if it's in a subfolder (e.g., localhost/PHPLUME)
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $dirName = dirname($scriptName);
    
    // Strip trailing slashes and common known subdirs like /admin or /api if we are deep in the app
    if (strpos($dirName, '/admin') !== false) {
        $dirName = substr($dirName, 0, strpos($dirName, '/admin'));
    } elseif (strpos($dirName, '/api') !== false) {
        $dirName = substr($dirName, 0, strpos($dirName, '/api'));
    }
    
    $dirName = str_replace('\\', '/', $dirName);
    if ($dirName === '/' || $dirName === '.') {
        $dirName = '';
    }
    
    // For simpler setups, if HTTP_HOST is localhost, we might just want to let the user define it in .env,
    // but this fallback is usually robust.
    define('SITE_URL', rtrim($protocol . $domainName . $dirName, '/'));
}
if(!defined('SITE_NAME')) define('SITE_NAME', 'LUMEEGY');
if(!defined('SITE_TAGLINE')) define('SITE_TAGLINE', 'Illuminate Your Ritual');

// ── Database ─────────────────────────────────────────────────
// Hostinger: use credentials from hPanel → Databases
if(!defined('DB_HOST')) define('DB_HOST', 'localhost');
if(!defined('DB_NAME')) define('DB_NAME', 'database_name');
if(!defined('DB_USER')) define('DB_USER', 'database_user');
if(!defined('DB_PASS')) define('DB_PASS', '');
if(!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// ── Meta Pixel / Conversions API ────────────────────────────────
// Paste your Pixel ID from Meta Events Manager
if(!defined('META_PIXEL_ID')) define('META_PIXEL_ID', '');        // e.g. '1234567890123456'
// Conversions API access token (Events Manager → Settings → Conversions API).
// Server-side only — never sent to the browser. Keep this in .env, not here.
if(!defined('META_CONVERSION_API_TOKEN')) define('META_CONVERSION_API_TOKEN', '');

// ── Google OAuth ─────────────────────────────────────────────
if(!defined('GOOGLE_CLIENT_ID')) define('GOOGLE_CLIENT_ID', '');
if(!defined('GOOGLE_CLIENT_SECRET')) define('GOOGLE_CLIENT_SECRET', '');

// ── Payment Gateway ──────────────────────────────────────────
// Supports: paymob | fawry | stripe (set below)
if(!defined('GATEWAY_PROVIDER')) define('GATEWAY_PROVIDER', 'paymob');
if(!defined('GATEWAY_API_KEY')) define('GATEWAY_API_KEY', '');      // Your gateway secret key
if(!defined('GATEWAY_IFRAME_ID')) define('GATEWAY_IFRAME_ID', '');    // Paymob iframe integration ID (if Paymob)
if(!defined('GATEWAY_HMAC_SECRET')) define('GATEWAY_HMAC_SECRET', ''); // Paymob HMAC secret

// ── Currency ─────────────────────────────────────────────────
if(!defined('CURRENCY_CODE')) define('CURRENCY_CODE',   'EGP');
if(!defined('CURRENCY_SYMBOL')) define('CURRENCY_SYMBOL', 'EGP ');
if(!defined('FREE_SHIPPING_OVER')) define('FREE_SHIPPING_OVER', 2000);  // Order total for free shipping
if(!defined('FLAT_SHIPPING_RATE')) define('FLAT_SHIPPING_RATE',  100);

// ── Security ─────────────────────────────────────────────────
if(!defined('SESSION_NAME')) define('SESSION_NAME', 'lumeegy_session');
if(!defined('CSRF_TOKEN_NAME')) define('CSRF_TOKEN_NAME', '_lume_csrf');

// ── Paths ─────────────────────────────────────────────────────
if(!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
if(!defined('UPLOADS_PATH')) define('UPLOADS_PATH', ROOT_PATH . '/assets/images/products');
if(!defined('UPLOADS_URL')) define('UPLOADS_URL',  SITE_URL . '/assets/images/products');

// ── Display errors (dev only) ─────────────────────────────────
if (ENV === 'development') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// ── Timezone ──────────────────────────────────────────────────
date_default_timezone_set('Africa/Cairo');
