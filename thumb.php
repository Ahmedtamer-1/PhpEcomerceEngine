<?php
/**
 * LUMEEGY — Image Thumbnail Generator
 * Dynamically resizes images, converts to WebP, and caches them.
 */

// Basic setup
// Decoding a large product photo (some are 5MB+ PNGs) needs more than the
// default 128M; without this the resize failed and the full original was sent.
@ini_set('memory_limit', '512M');
@set_time_limit(30);
define('ROOT_PATH', __DIR__);
define('CACHE_DIR', ROOT_PATH . '/assets/images/cache');

$src = $_GET['src'] ?? '';
$w = (int)($_GET['w'] ?? 0);

if (!$src || $w <= 0) {
    header('HTTP/1.1 400 Bad Request');
    exit('Missing src or width');
}

// Snap widths up to a 50px step (max 2000): every distinct w is a new cache
// file, so an unbounded w let anyone fill the disk with near-identical copies.
$w = (int)min(2000, max(50, ceil($w / 50) * 50));

// Clean and validate source path
$src = ltrim((string)parse_url($src, PHP_URL_PATH), '/'); // Remove leading slash and query params
$srcPath = realpath(ROOT_PATH . '/' . $src);
$assetsRoot = realpath(ROOT_PATH . '/assets');

// Only image files that really live under assets/ — "../" paths used to reach
// any image-like file anywhere on the server.
if (!$srcPath || !$assetsRoot
    || strpos($srcPath, $assetsRoot . DIRECTORY_SEPARATOR) !== 0
    || !preg_match('/\.(jpe?g|png|webp|gif)$/i', $srcPath)
    || !is_file($srcPath)) {
    header('HTTP/1.1 404 Not Found');
    exit('Image not found');
}

// Create cache directory if it doesn't exist
if (!is_dir(CACHE_DIR)) {
    @mkdir(CACHE_DIR, 0755, true);
}

// Generate a unique hash for the cached file based on path, width, and file modified time
$filemtime = filemtime($srcPath);
$hash = md5($src . $w . $filemtime);
$cachePath = CACHE_DIR . '/' . $hash . '.webp';

// If cache exists and is fresh, serve it immediately
if (file_exists($cachePath)) {
    serve_image($cachePath, 'image/webp');
}

// Otherwise, generate the thumbnail
$imgInfo = @getimagesize($srcPath);
if (!$imgInfo) {
    header('HTTP/1.1 415 Unsupported Media Type');
    exit('Not a valid image');
}

$origW = $imgInfo[0];
$origH = $imgInfo[1];
$mime = $imgInfo['mime'];

// Requested width is at least the original: keep the original size but still
// re-encode to WebP (a 570KB PNG logo becomes ~20KB). GIFs are served as-is so
// animations keep working.
if ($w >= $origW) {
    if ($mime === 'image/gif') serve_image($srcPath, $mime);
    $w = $origW;
}

// Calculate new dimensions while maintaining aspect ratio
$h = (int)round(($w / $origW) * $origH);

// Load original image into GD
switch ($mime) {
    case 'image/jpeg':
        $sourceImage = @imagecreatefromjpeg($srcPath);
        break;
    case 'image/png':
        $sourceImage = @imagecreatefrompng($srcPath);
        break;
    case 'image/webp':
        $sourceImage = @imagecreatefromwebp($srcPath);
        break;
    case 'image/gif':
        $sourceImage = @imagecreatefromgif($srcPath);
        break;
    default:
        // Unsupported format for resizing, serve original
        serve_image($srcPath, $mime);
}

if (!$sourceImage) {
    serve_image($srcPath, $mime);
}

// Create new true color image
$thumb = imagecreatetruecolor($w, $h);

// Preserve transparency for PNG and WebP
if ($mime === 'image/png' || $mime === 'image/webp' || $mime === 'image/gif') {
    imagealphablending($thumb, false);
    imagesavealpha($thumb, true);
    $transparent = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
    imagefilledrectangle($thumb, 0, 0, $w, $h, $transparent);
}

// Resize
imagecopyresampled($thumb, $sourceImage, 0, 0, 0, 0, $w, $h, $origW, $origH);

// Save to cache as WebP (quality 80)
imagewebp($thumb, $cachePath, 80);

// Free up memory
imagedestroy($sourceImage);
imagedestroy($thumb);

// Serve the newly created cache
if (file_exists($cachePath)) {
    serve_image($cachePath, 'image/webp');
} else {
    // Fallback if cache write failed (permissions issue)
    serve_image($srcPath, $mime);
}


/**
 * Helper to serve the image file directly to the browser
 */
function serve_image($path, $mime) {
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=31536000, immutable'); // Cache for 1 year
    header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 31536000));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
