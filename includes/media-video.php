<?php
/**
 * Media library: video support, chunked uploads and optimisation.
 *
 * Videos are compressed in the admin's browser before upload (see
 * admin/assets/js/media-kit.js). The server then:
 *   - re-encodes with ffmpeg when the host has it (most shared hosts don't),
 *   - otherwise makes sure an MP4's index ("moov") sits at the start of the
 *     file ("fast start"), so it begins playing at once instead of after the
 *     whole file has downloaded,
 *   - stores a poster image so pages can show a still without loading the video.
 *
 * Loaded by functions.php.
 */

const MEDIA_VIDEO_DIR      = 'assets/videos';
const MEDIA_MAX_UPLOAD     = 1024 * 1024 * 1024;   // 1 GB per file, before compression
const MEDIA_VIDEO_MIMES    = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov', 'video/x-m4v' => 'm4v'];
const MEDIA_IMAGE_MIMES    = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/svg+xml' => 'svg'];

/** Add the columns videos need (poster, duration, size before optimising). Runs once. */
function media_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (setting('schema_media_v2') === '1') return;
    try {
        $have = array_column(db()->query('SHOW COLUMNS FROM media_library')->fetchAll(), 'Field');
        foreach ([
            'poster'        => 'VARCHAR(500) NULL DEFAULT NULL',
            'duration'      => 'DECIMAL(8,2) NULL DEFAULT NULL',
            'original_size' => 'BIGINT UNSIGNED NULL DEFAULT NULL',
        ] as $col => $def) {
            if (!in_array($col, $have, true)) db()->exec("ALTER TABLE media_library ADD COLUMN `$col` $def");
        }
        db()->exec('ALTER TABLE media_library MODIFY filesize BIGINT UNSIGNED NOT NULL DEFAULT 0');
        db()->prepare('INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
            ->execute(['schema_media_v2', '1']);
    } catch (Exception $e) {
        error_log('media schema: ' . $e->getMessage());
    }
}

/** Bytes PHP will accept in one request (the smaller of the two ini limits). */
function media_request_limit(): int
{
    $toBytes = function (string $v): int {
        $v = trim($v);
        if ($v === '' || $v === '-1' || $v === '0') return PHP_INT_MAX;
        $n = (float)$v;
        return (int) match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    };
    return min($toBytes((string)ini_get('upload_max_filesize')), $toBytes((string)ini_get('post_max_size')));
}

/** Chunk size for uploads: comfortably under the server limit, at most 8 MB. */
function media_chunk_size(): int
{
    return max(256 * 1024, min(8 * 1024 * 1024, (int)(media_request_limit() * 0.8)));
}

// ════════════════════════════════════════════════════════
// CHUNKED UPLOADS
// ════════════════════════════════════════════════════════

function media_chunk_dir(string $uploadId): string
{
    return rtrim(sys_get_temp_dir(), '/\\') . '/adorna-upload-' . $uploadId;
}

/** Remove abandoned partial uploads older than a day. */
function media_chunk_gc(): void
{
    foreach (glob(rtrim(sys_get_temp_dir(), '/\\') . '/adorna-upload-*') ?: [] as $dir) {
        if (is_dir($dir) && filemtime($dir) < time() - 86400) {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }
}

/** Append one uploaded chunk. Chunks arrive in order; returns bytes received so far. */
function media_chunk_append(string $uploadId, int $index, array $file): int
{
    if (!preg_match('/^[a-f0-9]{16,40}$/', $uploadId)) throw new RuntimeException('Bad upload id.');
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Chunk failed to upload (code ' . ($file['error'] ?? '?') . ').');
    $dir = media_chunk_dir($uploadId);
    if ($index === 0) {
        if (is_dir($dir)) array_map('unlink', glob($dir . '/*') ?: []);
        elseif (!mkdir($dir, 0700, true)) throw new RuntimeException('Cannot create temp folder.');
        if (random_int(1, 20) === 1) media_chunk_gc();
    } elseif (!is_dir($dir)) {
        throw new RuntimeException('Upload expired, please try again.');
    }
    $part = $dir . '/data';
    $have = is_file($dir . '/next') ? (int)file_get_contents($dir . '/next') : 0;
    if ($index !== $have) throw new RuntimeException('Chunks arrived out of order.');
    $size = (is_file($part) ? filesize($part) : 0) + (int)$file['size'];
    if ($size > MEDIA_MAX_UPLOAD) throw new RuntimeException('File is larger than 1 GB.');
    $in = fopen($file['tmp_name'], 'rb');
    $out = fopen($part, 'ab');
    stream_copy_to_stream($in, $out);
    fclose($in); fclose($out);
    file_put_contents($dir . '/next', (string)($index + 1));
    clearstatcache();
    return filesize($part);
}

/**
 * Turn a finished chunked upload into a media-library item.
 * $poster is an optional uploaded still (from the browser) for videos.
 */
function media_chunk_finish(string $uploadId, string $origName, ?array $poster, ?int $userId, array $meta = []): array
{
    if (!preg_match('/^[a-f0-9]{16,40}$/', $uploadId)) throw new RuntimeException('Bad upload id.');
    $dir  = media_chunk_dir($uploadId);
    $part = $dir . '/data';
    if (!is_file($part)) throw new RuntimeException('Upload not found or expired.');
    try {
        return media_store_file($part, $origName, $poster, $userId, $meta);
    } finally {
        @unlink($part); @unlink($dir . '/next'); @rmdir($dir);
    }
}

// ════════════════════════════════════════════════════════
// STORE + OPTIMISE
// ════════════════════════════════════════════════════════

/**
 * Validate a file by its real contents, move it into the library and
 * optimise it. Shared by chunked uploads (images and videos).
 */
function media_store_file(string $tmpPath, string $origName, ?array $poster, ?int $userId, array $meta = []): array
{
    media_ensure_schema();
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
    // Some MP4s are reported as application/octet-stream; check the header.
    if ($mime === 'application/octet-stream' && media_is_mp4($tmpPath)) $mime = 'video/mp4';
    $origName = mb_substr(basename(str_replace('\\', '/', $origName)), 0, 200) ?: 'upload';

    if (isset(MEDIA_IMAGE_MIMES[$mime])) {
        $ext  = MEDIA_IMAGE_MIMES[$mime];
        $name = 'media_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!is_dir(UPLOADS_PATH)) mkdir(UPLOADS_PATH, 0755, true);
        $dest = UPLOADS_PATH . '/' . $name;
        $origSize = filesize($tmpPath);
        if (!rename($tmpPath, $dest) && !copy($tmpPath, $dest)) throw new RuntimeException('Could not save the file.');
        optimize_image_in_place($dest);
        clearstatcache();
        $info = @getimagesize($dest);
        return media_insert([
            'filename' => $origName, 'filepath' => 'assets/images/products/' . $name, 'filetype' => 'image',
            'filesize' => filesize($dest), 'original_size' => $origSize,
            'width' => $info[0] ?? null, 'height' => $info[1] ?? null,
        ], $userId);
    }

    if (!isset(MEDIA_VIDEO_MIMES[$mime])) {
        throw new RuntimeException('Unsupported file type (' . $mime . '). Use JPG, PNG, WebP, GIF, SVG, MP4, MOV or WebM.');
    }

    $absDir = ROOT_PATH . '/' . MEDIA_VIDEO_DIR;
    if (!is_dir($absDir)) mkdir($absDir, 0755, true);
    $base = 'video_' . time() . '_' . bin2hex(random_bytes(4));
    $ext  = MEDIA_VIDEO_MIMES[$mime];
    $dest = $absDir . '/' . $base . '.' . $ext;
    if (!rename($tmpPath, $dest) && !copy($tmpPath, $dest)) throw new RuntimeException('Could not save the file.');
    $origSize = (int)($meta['original_size'] ?? 0) ?: filesize($dest);

    $posterRel = null;
    // 1) Full re-encode when the server has ffmpeg (also makes a poster).
    $ff = media_ffmpeg_transcode($dest, $absDir . '/' . $base);
    if ($ff) {
        $dest = $ff['video'];
        $posterRel = $ff['poster'] ? MEDIA_VIDEO_DIR . '/' . basename($ff['poster']) : null;
    } elseif (in_array($ext, ['mp4', 'm4v', 'mov'], true)) {
        // 2) Otherwise at least move the index to the front.
        try { media_mp4_faststart($dest); } catch (Throwable $e) { error_log('faststart: ' . $e->getMessage()); }
    }

    // 3) Poster sent by the browser (used when ffmpeg didn't make one).
    if (!$posterRel && $poster && ($poster['error'] ?? 1) === UPLOAD_ERR_OK) {
        $pm = (new finfo(FILEINFO_MIME_TYPE))->file($poster['tmp_name']);
        $pext = ['image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/png' => 'png'][$pm] ?? null;
        if ($pext && $poster['size'] < 5 * 1024 * 1024) {
            $pdest = $absDir . '/' . $base . '_poster.' . $pext;
            if (move_uploaded_file($poster['tmp_name'], $pdest)) {
                optimize_image_in_place($pdest, 1920, 82);
                $posterRel = MEDIA_VIDEO_DIR . '/' . basename($pdest);
            }
        }
    }
    clearstatcache();
    $w = isset($meta['width']) ? (int)$meta['width'] : null;
    $h = isset($meta['height']) ? (int)$meta['height'] : null;
    if ($ff) { $w = $ff['width'] ?: $w; $h = $ff['height'] ?: $h; }
    return media_insert([
        'filename' => $origName, 'filepath' => MEDIA_VIDEO_DIR . '/' . basename($dest), 'filetype' => 'video',
        'filesize' => filesize($dest), 'original_size' => $origSize,
        'width' => $w ?: null, 'height' => $h ?: null,
        'duration' => isset($meta['duration']) ? round((float)$meta['duration'], 2) : ($ff['duration'] ?? null),
        'poster' => $posterRel,
    ], $userId);
}

function media_insert(array $m, ?int $userId): array
{
    media_ensure_schema();
    db()->prepare(
        'INSERT INTO media_library (filename, filepath, filetype, filesize, original_size, width, height, duration, poster, uploaded_by)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    )->execute([$m['filename'], $m['filepath'], $m['filetype'], $m['filesize'], $m['original_size'] ?? null,
                $m['width'] ?? null, $m['height'] ?? null, $m['duration'] ?? null, $m['poster'] ?? null, $userId]);
    $m['id'] = (int) db()->lastInsertId();
    return media_item_out($m);
}

/** Shape a media_library row for the admin JS. */
function media_item_out(array $m): array
{
    return [
        'id'            => (int)($m['id'] ?? 0),
        'filename'      => $m['filename'],
        'filepath'      => $m['filepath'],
        'url'           => SITE_URL . '/' . $m['filepath'],
        'filetype'      => $m['filetype'] ?? 'image',
        'filesize'      => (int)($m['filesize'] ?? 0),
        'original_size' => isset($m['original_size']) ? (int)$m['original_size'] : null,
        'width'         => !empty($m['width']) ? (int)$m['width'] : null,
        'height'        => !empty($m['height']) ? (int)$m['height'] : null,
        'duration'      => isset($m['duration']) && $m['duration'] !== null ? (float)$m['duration'] : null,
        'poster'        => $m['poster'] ?? null,
        'thumb'         => ($m['filetype'] ?? 'image') === 'video'
                             ? (!empty($m['poster']) ? thumb_url($m['poster'], 400) : null)
                             : thumb_url($m['filepath'], 400),
        'alt_text'      => $m['alt_text'] ?? '',
        'created_at'    => $m['created_at'] ?? date('Y-m-d H:i:s'),
    ];
}

/** Poster image recorded for an uploaded video path, if any. */
function media_poster_for(string $videoPath): ?string
{
    static $cache = [];
    $videoPath = ltrim(preg_replace('#^' . preg_quote(rtrim(SITE_URL, '/'), '#') . '/#', '', $videoPath), '/');
    if (array_key_exists($videoPath, $cache)) return $cache[$videoPath];
    try {
        media_ensure_schema();
        $s = db()->prepare("SELECT poster FROM media_library WHERE filepath = ? AND filetype = 'video' LIMIT 1");
        $s->execute([$videoPath]);
        return $cache[$videoPath] = ($s->fetchColumn() ?: null);
    } catch (Exception $e) {
        return $cache[$videoPath] = null;
    }
}

// ════════════════════════════════════════════════════════
// FFMPEG (only if the host provides it)
// ════════════════════════════════════════════════════════

function media_ffmpeg_path(): ?string
{
    static $path = false;
    if ($path !== false) return $path;
    $path = null;
    if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) return null;
    $candidates = array_filter([getenv('FFMPEG_PATH') ?: null, '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/bin/ffmpeg']);
    foreach ($candidates as $c) if (@is_executable($c)) return $path = $c;
    $out = [];
    @exec((DIRECTORY_SEPARATOR === '\\' ? 'where ffmpeg' : 'command -v ffmpeg') . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'), $out, $code);
    if ($code === 0 && !empty($out[0])) $path = trim($out[0]);
    return $path;
}

/**
 * Re-encode to H.264/AAC MP4, at most 1920px wide, fast start, plus a poster.
 * Returns null when ffmpeg isn't available or the encode fails (the original
 * file is then kept as it was).
 */
function media_ffmpeg_transcode(string $src, string $outBase): ?array
{
    $ff = media_ffmpeg_path();
    if (!$ff) return null;
    $out = $outBase . '_web.mp4';
    $cmd = escapeshellarg($ff) . ' -y -hide_banner -loglevel error -i ' . escapeshellarg($src)
         . ' -map 0:v:0 -map 0:a:0? -c:v libx264 -preset medium -crf 26 -pix_fmt yuv420p'
         . ' -vf ' . escapeshellarg("scale='min(1920,iw)':-2") . ' -c:a aac -b:a 128k -movflags +faststart '
         . escapeshellarg($out) . ' 2>&1';
    @set_time_limit(600);
    exec($cmd, $log, $code);
    if ($code !== 0 || !is_file($out) || filesize($out) < 1024) {
        error_log('ffmpeg transcode failed: ' . implode(' ', array_slice($log, -3)));
        @unlink($out);
        return null;
    }
    // Keep whichever is smaller (a tiny, already-efficient upload can grow).
    if (filesize($out) >= filesize($src) && str_ends_with(strtolower($src), '.mp4')) {
        @unlink($out);
        try { media_mp4_faststart($src); } catch (Throwable $e) {}
        $out = $src;
    } else {
        @unlink($src);
    }
    $poster = $outBase . '_poster.jpg';
    exec(escapeshellarg($ff) . ' -y -hide_banner -loglevel error -ss 0.5 -i ' . escapeshellarg($out)
        . ' -frames:v 1 -vf ' . escapeshellarg("scale='min(1920,iw)':-2") . ' -q:v 4 ' . escapeshellarg($poster) . ' 2>&1', $l2, $c2);
    $probe = [];
    exec(escapeshellarg($ff) . ' -hide_banner -i ' . escapeshellarg($out) . ' 2>&1', $probe);
    $txt = implode("\n", $probe);
    $w = $h = null; $dur = null;
    if (preg_match('/Video:.*?(\d{2,5})x(\d{2,5})/', $txt, $m)) { $w = (int)$m[1]; $h = (int)$m[2]; }
    if (preg_match('/Duration: (\d+):(\d+):([\d.]+)/', $txt, $m)) $dur = $m[1] * 3600 + $m[2] * 60 + (float)$m[3];
    return ['video' => $out, 'poster' => ($c2 === 0 && is_file($poster)) ? $poster : null, 'width' => $w, 'height' => $h, 'duration' => $dur];
}

// ════════════════════════════════════════════════════════
// MP4 FAST START (pure PHP, no ffmpeg needed)
// ════════════════════════════════════════════════════════

function media_is_mp4(string $path): bool
{
    $f = @fopen($path, 'rb');
    if (!$f) return false;
    $head = fread($f, 12);
    fclose($f);
    return strlen($head) === 12 && substr($head, 4, 4) === 'ftyp';
}

/**
 * Move the "moov" box in front of "mdat" so playback can start immediately.
 * Phone recordings usually write it at the end, which makes the browser fetch
 * the whole video before showing the first frame. Rewrites the chunk-offset
 * tables (stco/co64) by the size of moov. No-op when it's already in front.
 */
function media_mp4_faststart(string $path): bool
{
    $f = fopen($path, 'rb');
    if (!$f) return false;
    $size = filesize($path);
    $boxes = [];
    $pos = 0;
    while ($pos < $size) {
        fseek($f, $pos);
        $hdr = fread($f, 8);
        if (strlen($hdr) < 8) break;
        $len = unpack('N', substr($hdr, 0, 4))[1];
        $type = substr($hdr, 4, 4);
        $hlen = 8;
        if ($len === 1) { $len = media_u64(fread($f, 8)); $hlen = 16; }
        elseif ($len === 0) { $len = $size - $pos; }
        if ($len < $hlen) { fclose($f); return false; }
        $boxes[] = ['type' => $type, 'pos' => $pos, 'len' => $len];
        $pos += $len;
    }
    $types = array_column($boxes, 'type');
    $mi = array_search('moov', $types, true);
    $di = array_search('mdat', $types, true);
    if ($mi === false || $di === false || $mi < $di) { fclose($f); return false; } // nothing to do
    $moovBox = $boxes[$mi];
    if ($moovBox['len'] > 64 * 1024 * 1024) { fclose($f); return false; }
    fseek($f, $moovBox['pos']);
    $moov = fread($f, $moovBox['len']);

    // Shift every chunk offset by moov's size; if a 32-bit offset would
    // overflow, give up rather than write a broken file.
    $shift = $moovBox['len'];
    $ok = true;
    $moov = media_patch_offsets($moov, 8, strlen($moov), $shift, $ok);
    if (!$ok) { fclose($f); return false; }

    $tmp = $path . '.fs.tmp';
    $o = fopen($tmp, 'wb');
    foreach ($boxes as $i => $b) {
        if ($i === $mi) continue;
        if ($i === $di) fwrite($o, $moov);
        fseek($f, $b['pos']);
        $left = $b['len'];
        while ($left > 0) { $chunk = fread($f, min(4 * 1024 * 1024, $left)); if ($chunk === '' || $chunk === false) break; fwrite($o, $chunk); $left -= strlen($chunk); }
    }
    fclose($o); fclose($f);
    if (filesize($tmp) !== $size) { @unlink($tmp); return false; }
    return rename($tmp, $path);
}

function media_u64(string $b): int
{
    $p = unpack('Nhi/Nlo', $b);
    return ($p['hi'] << 32) | $p['lo'];
}

/** Walk the boxes inside moov and rewrite stco/co64 tables. */
function media_patch_offsets(string $buf, int $start, int $end, int $shift, bool &$ok): string
{
    $containers = ['moov', 'trak', 'mdia', 'minf', 'stbl', 'edts', 'udta', 'dinf'];
    $pos = $start;
    while ($pos + 8 <= $end) {
        $len = unpack('N', substr($buf, $pos, 4))[1];
        $type = substr($buf, $pos + 4, 4);
        $hlen = 8;
        if ($len === 1) { $len = media_u64(substr($buf, $pos + 8, 8)); $hlen = 16; }
        if ($len < 8 || $pos + $len > $end) break;
        if (in_array($type, $containers, true)) {
            $buf = media_patch_offsets($buf, $pos + $hlen, $pos + $len, $shift, $ok);
        } elseif ($type === 'stco' || $type === 'co64') {
            $n = unpack('N', substr($buf, $pos + 12, 4))[1];
            $p = $pos + 16;
            for ($i = 0; $i < $n; $i++) {
                if ($type === 'stco') {
                    $v = unpack('N', substr($buf, $p, 4))[1] + $shift;
                    if ($v > 0xFFFFFFFF) { $ok = false; return $buf; }
                    $buf = substr_replace($buf, pack('N', $v), $p, 4);
                    $p += 4;
                } else {
                    $v = media_u64(substr($buf, $p, 8)) + $shift;
                    $buf = substr_replace($buf, pack('NN', $v >> 32, $v & 0xFFFFFFFF), $p, 8);
                    $p += 8;
                }
            }
        }
        $pos += $len;
    }
    return $buf;
}
