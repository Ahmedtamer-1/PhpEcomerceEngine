<?php
/**
 * LUMEEGY — Homepage Sections API
 * Handles AJAX operations for the homepage section builder.
 */
require_once __DIR__ . '/../includes/functions.php';
lume_session_start();

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    json_response(['success' => false, 'message' => 'Unauthorized'], 403);
}
admin_enforce_csrf();

// Which page's sections are we operating on? Defaults to 'home' so every
// existing caller keeps its current behaviour.
$targets = page_section_targets();
$pageKey = $_POST['page'] ?? $_GET['page'] ?? 'home';
if (!isset($targets[$pageKey])) $pageKey = 'home';

// ── GET: List sections ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sections = get_homepage_sections(false, $pageKey);
    $out = [];
    foreach ($sections as $s) {
        $out[] = [
            'id'           => (int)$s['id'],
            'section_type' => $s['section_type'],
            'title'        => $s['title'],
            'subtitle'     => $s['subtitle'],
            'content'      => $s['content'],
            'image'        => $s['image'],
            'button_text'  => $s['button_text'],
            'button_url'   => $s['button_url'],
            'settings'     => json_decode($s['settings'] ?? '{}', true),
            'is_active'    => (int)$s['is_active'],
            'sort_order'   => (int)$s['sort_order'],
        ];
    }
    json_response(['success' => true, 'sections' => $out]);
}

// ── POST: save the whole page at once (used by the visual builder) ──
// The builder edits a page as a single state, so it saves as one: rows are
// updated, created or removed to match exactly what was sent, and sort_order
// is taken from array position. Scoped to one page and wrapped in a
// transaction, so a partial failure cannot leave a half-saved page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_all') {
    require_once __DIR__ . '/../includes/section-registry.php';
    ensure_page_sections_column();

    $incoming = json_decode($_POST['sections'] ?? '[]', true);
    if (!is_array($incoming)) {
        json_response(['success' => false, 'message' => 'Malformed section data.'], 400);
    }

    // What exists for this page right now, so we know what to delete.
    $cur = db()->prepare('SELECT id FROM homepage_sections WHERE page = ?');
    $cur->execute([$pageKey]);
    $existingIds = array_map('intval', array_column($cur->fetchAll(), 'id'));
    $keptIds = [];

    try {
        db()->beginTransaction();

        $upd = db()->prepare(
            'UPDATE homepage_sections SET section_type=?, title=?, subtitle=?, content=?, image=?,
             button_text=?, button_url=?, settings=?, is_active=?, sort_order=? WHERE id=? AND page=?'
        );
        $ins = db()->prepare(
            'INSERT INTO homepage_sections (page, section_type, title, subtitle, content, image,
             button_text, button_url, settings, is_active, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );

        foreach (array_values($incoming) as $i => $row) {
            if (!is_array($row)) continue;
            $type = (string)($row['section_type'] ?? '');
            if (!section_type_exists($type)) continue; // ignore unknown types

            $settings = $row['settings'] ?? [];
            if (is_string($settings)) {
                $d = json_decode($settings, true);
                $settings = is_array($d) ? $d : [];
            }
            $vals = [
                $type,
                ($row['title']       ?? '') !== '' ? $row['title']       : null,
                ($row['subtitle']    ?? '') !== '' ? $row['subtitle']    : null,
                ($row['content']     ?? '') !== '' ? $row['content']     : null,
                ($row['image']       ?? '') !== '' ? $row['image']       : null,
                ($row['button_text'] ?? '') !== '' ? $row['button_text'] : null,
                ($row['button_url']  ?? '') !== '' ? $row['button_url']  : null,
                json_encode((object)$settings),
                empty($row['is_active']) ? 0 : 1,
                $i,
            ];

            $id = (int)($row['id'] ?? 0);
            if ($id > 0 && in_array($id, $existingIds, true)) {
                $upd->execute(array_merge($vals, [$id, $pageKey]));
                $keptIds[] = $id;
            } else {
                $ins->execute(array_merge([$pageKey], $vals));
                $keptIds[] = (int)db()->lastInsertId();
            }
        }

        // Anything the builder no longer lists was deleted by the user.
        $gone = array_diff($existingIds, $keptIds);
        if ($gone) {
            $ph = implode(',', array_fill(0, count($gone), '?'));
            $del = db()->prepare("DELETE FROM homepage_sections WHERE page = ? AND id IN ($ph)");
            $del->execute(array_merge([$pageKey], array_values($gone)));
        }

        db()->commit();
    } catch (Exception $e) {
        if (db()->inTransaction()) db()->rollBack();
        json_response(['success' => false, 'message' => 'Save failed: ' . $e->getMessage()], 500);
    }

    json_response([
        'success' => true,
        'saved'   => count($keptIds),
        'deleted' => count($gone ?? []),
        'ids'     => $keptIds,
        'message' => count($keptIds) . ' section' . (count($keptIds) === 1 ? '' : 's') . ' saved.',
    ]);
}

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add new section
    if ($action === 'add') {
        $type     = trim($_POST['section_type'] ?? 'text_block');
        $title    = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $content  = trim($_POST['content'] ?? '');
        $image    = trim($_POST['image'] ?? '');
        $btnText  = trim($_POST['button_text'] ?? '');
        $btnUrl   = trim($_POST['button_url'] ?? '');
        $settings = $_POST['settings'] ?? '{}';
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        require_once __DIR__ . '/../includes/section-registry.php';
        if (!section_type_exists($type)) {
            json_response(['success' => false, 'message' => 'Unknown section type.'], 400);
        }
        // Settings must be a JSON object; anything else is stored as {}.
        $decoded = json_decode((string)$settings, true);
        $settings = json_encode(is_array($decoded) ? (object)$decoded : new stdClass());

        ensure_page_sections_column();

        // Sort order is per page, so About's first section is 1, not "next
        // after every homepage section".
        $ms = db()->prepare('SELECT COALESCE(MAX(sort_order),0) FROM homepage_sections WHERE page = ?');
        $ms->execute([$pageKey]);
        $maxSort = (int)$ms->fetchColumn();

        db()->prepare(
            'INSERT INTO homepage_sections (page, section_type, title, subtitle, content, image, button_text, button_url, settings, is_active, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([$pageKey, $type, $title ?: null, $subtitle ?: null, $content ?: null, $image ?: null, $btnText ?: null, $btnUrl ?: null, $settings, $isActive, $maxSort + 1]);

        json_response(['success' => true, 'id' => (int)db()->lastInsertId()]);
    }

    // Update section
    if ($action === 'update') {
        $id       = (int)($_POST['section_id'] ?? 0);
        $title    = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $content  = trim($_POST['content'] ?? '');
        $image    = trim($_POST['image'] ?? '');
        $btnText  = trim($_POST['button_text'] ?? '');
        $btnUrl   = trim($_POST['button_url'] ?? '');
        $settings = $_POST['settings'] ?? '{}';
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($id <= 0) json_response(['success' => false, 'message' => 'Invalid ID'], 400);

        db()->prepare(
            'UPDATE homepage_sections SET title=?, subtitle=?, content=?, image=?, button_text=?, button_url=?, settings=?, is_active=? WHERE id=?'
        )->execute([$title ?: null, $subtitle ?: null, $content ?: null, $image ?: null, $btnText ?: null, $btnUrl ?: null, $settings, $isActive, $id]);

        json_response(['success' => true]);
    }

    // Delete section
    if ($action === 'delete') {
        $id = (int)($_POST['section_id'] ?? 0);
        if ($id <= 0) json_response(['success' => false, 'message' => 'Invalid ID'], 400);

        db()->prepare('DELETE FROM homepage_sections WHERE id = ?')->execute([$id]);
        json_response(['success' => true]);
    }

    // Toggle active
    if ($action === 'toggle') {
        $id = (int)($_POST['section_id'] ?? 0);
        if ($id <= 0) json_response(['success' => false, 'message' => 'Invalid ID'], 400);

        db()->prepare('UPDATE homepage_sections SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        json_response(['success' => true]);
    }

    // Reorder sections
    if ($action === 'reorder') {
        $orderJson = $_POST['order'] ?? '[]';
        $order     = json_decode($orderJson, true);
        if (is_array($order)) {
            $stmt = db()->prepare('UPDATE homepage_sections SET sort_order = ? WHERE id = ?');
            foreach ($order as $i => $id) {
                $stmt->execute([$i + 1, (int)$id]);
            }
        }
        json_response(['success' => true]);
    }
}

json_response(['error' => 'Bad request'], 400);
