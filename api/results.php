<?php
/**
 * Results (成果) API — CRUD + archive/restore/delete + tag association
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';

$method = get_method();
$db = get_db();

// Helper: attach tags to a result
function attach_tags(PDO $db, int $result_id, array $tag_ids): void {
    $db->prepare('DELETE FROM result_tags WHERE result_id = ?')->execute([$result_id]);
    $stmt = $db->prepare('INSERT INTO result_tags (result_id, tag_id) VALUES (?, ?)');
    foreach ($tag_ids as $tid) {
        $stmt->execute([$result_id, (int)$tid]);
    }
}

// Helper: fetch result with tags
function get_result_with_tags(PDO $db, int $id): ?array {
    $stmt = $db->prepare('SELECT * FROM results WHERE id = ?');
    $stmt->execute([$id]);
    $result = $stmt->fetch();
    if (!$result) return null;

    $stmt = $db->prepare('SELECT t.* FROM tags t JOIN result_tags rt ON t.id = rt.tag_id WHERE rt.result_id = ?');
    $stmt->execute([$id]);
    $result['tags'] = $stmt->fetchAll();
    return $result;
}

// GET /api/results[/{id}]
if ($method === 'GET') {
    $parts = get_path_parts();
    $id = isset($parts[2]) ? (int)$parts[2] : null;

    if ($id) {
        $result = get_result_with_tags($db, $id);
        if (!$result) json_error('Not found', 404);
        json_success($result);
    } else {
        $archived = isset($_GET['archived']) ? (int)$_GET['archived'] : 0;
        $stmt = $db->prepare('SELECT * FROM results WHERE archived = ? ORDER BY created_at DESC');
        $stmt->execute([$archived]);
        $results = $stmt->fetchAll();
        // Attach tags
        foreach ($results as &$r) {
            $stmt2 = $db->prepare('SELECT t.* FROM tags t JOIN result_tags rt ON t.id = rt.tag_id WHERE rt.result_id = ?');
            $stmt2->execute([$r['id']]);
            $r['tags'] = $stmt2->fetchAll();
        }
        json_success($results);
    }
}

// POST /api/results — create
if ($method === 'POST') {
    $data = get_json_input();
    $name = optional_string($data, 'name');
    if (!$name) json_error('Name is required');

    $stmt = $db->prepare('INSERT INTO results (name, quantity, level) VALUES (?, ?, ?)');
    $stmt->execute([
        $name,
        optional_int($data, 'quantity', 1),
        optional_string($data, 'level'),
    ]);
    $id = $db->lastInsertId();

    $tag_ids = optional_array($data, 'tag_ids');
    if ($tag_ids) attach_tags($db, $id, $tag_ids);

    json_success(get_result_with_tags($db, $id), 'Result created');
}

// PUT /api/results/{id} — update (partial updates supported)
if ($method === 'PUT') {
    $parts = get_path_parts();
    $id = isset($parts[2]) ? (int)$parts[2] : 0;
    if (!$id) json_error('ID required');

    $data = get_json_input();
    $fields = [];
    $params = [];
    foreach (['name', 'quantity', 'level', 'archived'] as $f) {
        if (array_key_exists($f, $data)) {
            $fields[] = "$f = ?";
            if ($f === 'quantity') $params[] = optional_int($data, $f, 1);
            elseif ($f === 'archived') $params[] = (int)$data[$f];
            else $params[] = optional_string($data, $f);
        }
    }
    if ($fields) {
        $params[] = $id;
        $db->prepare('UPDATE results SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    }

    if (isset($data['tag_ids'])) {
        attach_tags($db, $id, optional_array($data, 'tag_ids'));
    }

    json_success(get_result_with_tags($db, $id), 'Result updated');
}

// DELETE /api/results/{id} — hard delete
if ($method === 'DELETE') {
    $parts = get_path_parts();
    $id = isset($parts[2]) ? (int)$parts[2] : 0;
    if (!$id) json_error('ID required');

    $db->prepare('DELETE FROM results WHERE id = ?')->execute([$id]);
    json_success(null, 'Result deleted permanently');
}

json_error('Method not allowed', 405);
