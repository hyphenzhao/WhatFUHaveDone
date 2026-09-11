<?php
/**
 * Tags API — CRUD + archive/restore/delete
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

$method = get_method();
$db = get_db();
$uid = current_user_id();

// GET /api/tags[/{id}]
if ($method === 'GET') {
    $parts = get_path_parts();
    $id = isset($parts[2]) ? (int)$parts[2] : null;

    if ($id) {
        $stmt = $db->prepare('SELECT * FROM tags WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $uid]);
        $tag = $stmt->fetch();
        if (!$tag) json_error('Not found', 404);
        json_success($tag);
    } else {
        $archived = isset($_GET['archived']) ? (int)$_GET['archived'] : 0;
        $stmt = $db->prepare('SELECT * FROM tags WHERE archived = ? AND user_id = ? ORDER BY name ASC');
        $stmt->execute([$archived, $uid]);
        json_success($stmt->fetchAll());
    }
}

// POST /api/tags — create
if ($method === 'POST') {
    $data = get_json_input();
    $name = optional_string($data, 'name');
    if (!$name) json_error('Name is required');

    $stmt = $db->prepare('INSERT INTO tags (user_id, name, color) VALUES (?, ?, ?)');
    $stmt->execute([$uid, $name, optional_string($data, 'color', '#3B82F6')]);
    $id = $db->lastInsertId();

    $stmt = $db->prepare('SELECT * FROM tags WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $uid]);
    json_success($stmt->fetch(), 'Tag created');
}

// PUT /api/tags/{id} — update (partial updates supported)
if ($method === 'PUT') {
    $parts = get_path_parts();
    $id = isset($parts[2]) ? (int)$parts[2] : 0;
    if (!$id) json_error('ID required');

    $data = get_json_input();
    $fields = [];
    $params = [];
    foreach (['name', 'color', 'archived'] as $f) {
        if (array_key_exists($f, $data)) {
            $fields[] = "$f = ?";
            if ($f === 'archived') $params[] = (int)$data[$f];
            else $params[] = optional_string($data, $f, $f === 'color' ? '#3B82F6' : '');
        }
    }
    if ($fields) {
        $params[] = $id;
        $params[] = $uid;
        $db->prepare('UPDATE tags SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?')->execute($params);
    }

    $stmt = $db->prepare('SELECT * FROM tags WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $uid]);
    json_success($stmt->fetch(), 'Tag updated');
}

// DELETE /api/tags/{id} — hard delete (only if archived)
if ($method === 'DELETE') {
    $parts = get_path_parts();
    $id = isset($parts[2]) ? (int)$parts[2] : 0;
    if (!$id) json_error('ID required');

    $db->prepare('DELETE FROM tags WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    json_success(null, 'Tag deleted permanently');
}

json_error('Method not allowed', 405);
