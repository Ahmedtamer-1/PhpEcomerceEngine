<?php
/**
 * Central error handling. Loaded first from config/config.php so it is in place
 * before anything can fail.
 *
 * Visitors never see SQL, file paths or stack traces. Each failure gets a short
 * reference ID: the full detail goes to the server log under that ID, and the
 * visitor only sees the ID (so support can find the log line).
 * With ENV=development the detail is also shown on screen, for developers.
 */

function lume_is_dev(): bool
{
    return defined('ENV') && ENV === 'development';
}

/** One reference ID per request, so the log line and the page agree. */
function lume_error_ref(): string
{
    static $ref = null;
    return $ref ??= bin2hex(random_bytes(4));
}

/** API / AJAX callers get JSON, everyone else gets a page. */
function lume_wants_json(): bool
{
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    return str_contains($path, '/api/')
        || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

/**
 * A small standalone HTML page (inline CSS, no database, no includes) so it
 * still works when the database or the rest of the site is what failed.
 */
function lume_error_page(int $status, string $title, string $message, string $ref = ''): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $site = $e(defined('SITE_NAME') ? (string)SITE_NAME : 'LUMEEGY');
    $home = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') . '/' : '/';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex"><title>' . $e($title) . ' — ' . $site . '</title>'
       . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0e0e0e;color:#eee;font-family:Georgia,serif;text-align:center;padding:24px}'
       . 'main{max-width:480px}h1{font-size:2rem;letter-spacing:.12em;text-transform:uppercase;margin:0 0 6px}'
       . '.code{color:#c4764f;font-size:.9rem;letter-spacing:.3em;margin-bottom:18px}p{color:#aaa;line-height:1.6}'
       . 'a{display:inline-block;margin-top:18px;padding:12px 28px;background:#c4764f;color:#fff;text-decoration:none;letter-spacing:.1em;text-transform:uppercase;font-size:.8rem}'
       . '.ref{margin-top:22px;font:.8rem monospace;color:#777}</style></head><body><main>'
       . '<div class="code">' . $status . '</div><h1>' . $e($title) . '</h1><p>' . $e($message) . '</p>'
       . '<a href="' . $e($home) . '">Back to ' . $site . '</a>'
       . ($ref !== '' ? '<div class="ref">Reference: ' . $e($ref) . '</div>' : '')
       . '</main></body></html>';
}

/** Log the detail under the reference ID, then answer with the generic 500. */
function lume_fail(string $detail, string $label = 'error'): void
{
    $ref = lume_error_ref();
    error_log("[ref $ref] $label: $detail");

    // Throw away any half-written page.
    while (ob_get_level() > 0) @ob_end_clean();

    if (lume_wants_json()) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        $out = ['success' => false, 'message' => 'Something went wrong', 'ref' => $ref];
        if (lume_is_dev()) $out['detail'] = $detail;
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        return;
    }

    lume_error_page(500, 'Something went wrong', 'We could not complete your request. Please try again in a moment.', $ref);
    if (lume_is_dev()) echo '<pre style="color:#f66;white-space:pre-wrap;padding:16px;max-width:1000px;margin:auto">' . htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
}

/**
 * For code that catches an exception itself (admin pages): log it with a
 * reference ID and return the text to show the user instead of getMessage().
 */
function lume_error_message(Throwable $e, string $what = 'The action could not be completed'): string
{
    $ref = bin2hex(random_bytes(4));
    error_log("[ref $ref] " . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    return $what . '. Reference: ' . $ref . '.';
}

set_exception_handler(function (Throwable $e): void {
    lume_fail(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString(), 'uncaught exception');
});

// Warnings and notices: logged, never printed in production.
set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) return true;   // silenced with @
    error_log("[php $no] $str in $file:$line");
    return !lume_is_dev();                          // dev: let PHP show it too
});

// Fatal errors skip the exception handler; catch them at shutdown.
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        lume_fail($err['message'] . ' in ' . $err['file'] . ':' . $err['line'], 'fatal error');
    }
});
