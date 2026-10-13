<?php
/**
 * LUMEEGY — Media Library API
 * Handles AJAX upload, list, and delete for the media manager.
 */
require_once __DIR__ . '/../includes/functions.php';
lume_session_start();

// Auth check — admin only
$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    json_response(['success' => false, 'message' => 'Unauthorized'], 403);
}
admin_enforce_csrf();

// ── LIST ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    media_ensure_schema();

    // What the uploader needs to know about this server.
    if (isset($_GET['caps'])) {
        json_response([
            'success'    => true,
            'chunk_size' => media_chunk_size(),
            'max_size'   => MEDIA_MAX_UPLOAD,
            'ffmpeg'     => media_ffmpeg_path() !== null,
        ]);
    }

    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 40;
    $offset = ($page - 1) * $limit;
    $search = trim($_GET['search'] ?? '');
    $type   = in_array($_GET['type'] ?? '', ['image', 'video'], true) ? $_GET['type'] : '';

    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = '(filename LIKE ? OR alt_text LIKE ?)';
        $term = '%' . $search . '%';
        array_push($params, $term, $term);
    }
    if ($type !== '') {
        $where[] = 'filetype = ?';
        $params[] = $type;
    }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $cnt = db()->prepare("SELECT COUNT(*) FROM media_library $w");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();

    $stmt = db()->prepare("SELECT * FROM media_library $w ORDER BY created_at DESC, id DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $out = array_map('media_item_out', $stmt->fetchAll());

    $counts = db()->query("SELECT filetype, COUNT(*) FROM media_library GROUP BY filetype")->fetchAll(PDO::FETCH_KEY_PAIR);

    json_response([
        'success' => true,
        'items'   => $out,
        'total'   => $total,
        'page'    => $page,
        'pages'   => max(1, (int)ceil($total / $limit)),
        'counts'  => ['all' => array_sum($counts), 'image' => (int)($counts['image'] ?? 0), 'video' => (int)($counts['video'] ?? 0)],
    ]);
}

// ── POST: Upload / Delete / Update ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'upload';

    if ($action === 'upload') {
        $results = [];

        if (!empty($_FILES['files'])) {
            $files = $_FILES['files'];
            $count = is_array($files['name']) ? count($files['name']) : 1;

            for ($i = 0; $i < $count; $i++) {
                $file = [
                    'name'     => is_array($files['name']) ? $files['name'][$i] : $files['name'],
                    'type'     => is_array($files['type']) ? $files['type'][$i] : $files['type'],
                    'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
                    'error'    => is_array($files['error']) ? $files['error'][$i] : $files['error'],
                    'size'     => is_array($files['size']) ? $files['size'][$i] : $files['size'],
                ];

                $result = media_upload($file, (int)$user['id']);
                if ($result) {
                    $results[] = $result;
                }
            }
        }

        if (empty($results)) {
            json_response(['success' => false, 'message' => 'No files uploaded or invalid file types'], 400);
        }

        json_response(['success' => true, 'uploaded' => $results, 'count' => count($results)]);
    }

    // Chunked upload: big files (videos) go up in pieces so they get past
    // the server's per-request size limit.
    if ($action === 'chunk') {
        try {
            $got = media_chunk_append((string)($_POST['upload_id'] ?? ''), (int)($_POST['index'] ?? -1), $_FILES['chunk'] ?? []);
            json_response(['success' => true, 'received' => $got]);
        } catch (Throwable $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
    if ($action === 'finish') {
        try {
            @set_time_limit(600);
            $meta = [];
            foreach (['width', 'height', 'duration', 'original_size'] as $k) {
                if (isset($_POST[$k]) && is_numeric($_POST[$k])) $meta[$k] = $_POST[$k] + 0;
            }
            $item = media_chunk_finish((string)($_POST['upload_id'] ?? ''), (string)($_POST['filename'] ?? 'upload'),
                                       $_FILES['poster'] ?? null, (int)$user['id'], $meta);
            log_activity('upload_media', 'media', $item['id'], $item['filename']);
            json_response(['success' => true, 'item' => $item]);
        } catch (Throwable $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['media_id'] ?? 0);
        if ($id <= 0) json_response(['success' => false, 'message' => 'Invalid ID'], 400);

        // Deleting a file that products/pages still use leaves broken images
        // on the storefront, so ask first unless the admin already confirmed.
        if (empty($_POST['force'])) {
            $fp = db()->prepare('SELECT filepath FROM media_library WHERE id = ?');
            $fp->execute([$id]);
            $path = (string)$fp->fetchColumn();
            if ($path !== '') {
                $like = '%' . addcslashes($path, '%_\\') . '%';
                $uses = 0;
                $checks = [
                    'SELECT COUNT(*) FROM products WHERE image = ? OR gallery LIKE ? OR size_chart = ?' => [$path, $like, $path],
                    'SELECT COUNT(*) FROM product_variants WHERE image = ?' => [$path],
                    'SELECT COUNT(*) FROM homepage_sections WHERE image = ? OR settings LIKE ?' => [$path, $like],
                    'SELECT COUNT(*) FROM settings WHERE value = ?' => [$path],
                ];
                foreach ($checks as $sql => $params) {
                    try { $st = db()->prepare($sql); $st->execute($params); $uses += (int)$st->fetchColumn(); }
                    catch (Exception $e) { /* optional column/table missing — skip */ }
                }
                if ($uses > 0) {
                    json_response(['success' => false, 'in_use' => $uses,
                        'message' => "This file is used in $uses place(s) (products, pages or settings). Deleting it will leave broken images or videos there."]);
                }
            }
        }

        $ok = media_delete($id);
        json_response(['success' => $ok]);
    }

    if ($action === 'bulk_delete') {
        $ids = json_decode($_POST['media_ids'] ?? '[]', true);
        if (empty($ids) || !is_array($ids)) json_response(['success' => false, 'message' => 'No IDs provided'], 400);

        $deleted = 0;
        foreach ($ids as $id) {
            if (media_delete((int)$id)) {
                $deleted++;
            }
        }
        json_response(['success' => true, 'deleted' => $deleted]);
    }

    if ($action === 'update_alt') {
        $id  = (int)($_POST['media_id'] ?? 0);
        $alt = trim($_POST['alt_text'] ?? '');
        if ($id <= 0) json_response(['success' => false, 'message' => 'Invalid ID'], 400);

        db()->prepare('UPDATE media_library SET alt_text = ? WHERE id = ?')->execute([$alt, $id]);
        json_response(['success' => true]);
    }
}

json_response(['error' => 'Bad request'], 400);
