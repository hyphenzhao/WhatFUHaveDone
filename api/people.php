<?php
/**
 * People API — CRUD + archive/restore/delete
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';

$method = get_method();
$db = get_db();

// GET /api/people[/{id}] — list all (non-archived) or single
if ($method === 'GET') {
    $parts = get_path_parts();
    $id = isset($parts[2]) ? (int)$parts[2] : null;

    if ($id) {
        $stmt = $db->prepare('SELECT * FROM people WHERE id = ?');
        $stmt->execute([$id]);
        $person = $stmt->fetch();
        if (!$person) json_error('Not found', 404);
        json_success($person);
    } else {
        $archived = isset($_GET['archived']) ? (int)$_GET['archived'] : 0;
        $stmt = $db->prepare('SELECT * FROM people WHERE archived = ? ORDER BY importance DESC, name ASC');
        $stmt->execute([$archived]);
        json_success($stmt->fetchAll());
    }
}

// POST /api/people — create
if ($method === 'POST') {
    $data = get_json_input();
    $name = optional_string($data, 'name');
    if (!$name) json_error('Name is required');

    $stmt = $db->prepare('INSERT INTO people (name, relationship, importance, usefulness, closeness) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $name,
        optional_string($data, 'relationship'),
        optional_int($data, 'importance', 0, 0, 5),
        optional_int($data, 'usefulness', 0, 0, 5),
        optional_int($data, 'closeness', 0, 0, 5),
    ]);
    $id = $db->lastInsertId();
    $stmt = $db->prepare('SELECT * FROM people WHERE id = ?');
    $stmt->execute([$id]);
    json_success($stmt->fetch(), 'Person created');
}

// PUT /api/people/{id} — update (partial updates supported)
if ($method === 'PUT') {
    $parts = get_path_parts();
    $id = isset($parts[2]) ? (int)$parts[2] : 0;
    if (!$id) json_error('ID required');

    $data = get_json_input();
    $fields = [];
    $params = [];
    // Prevent archiving the "me" record
    $check = $db->prepare('SELECT is_me FROM people WHERE id = ?');
    $check->execute([$id]);
    $existing = $check->fetch();
    if ($existing && $existing['is_me'] && isset($data['archived']) && $data['archived']) {
        json_error('Cannot archive the "Me" record', 403);
    }

    foreach (['name', 'relationship', 'importance', 'usefulness', 'closeness', 'archived'] as $f) {
        if (array_key_exists($f, $data)) {
            $fields[] = "$f = ?";
            if (in_array($f, ['importance', 'usefulness', 'closeness'])) $params[] = optional_int($data, $f, 0, 0, 5);
            elseif ($f === 'archived') $params[] = (int)$data[$f];
            else $params[] = optional_string($data, $f);
        }
    }
    if ($fields) {
        $params[] = $id;
        $db->prepare('UPDATE people SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    }

    $stmt = $db->prepare('SELECT * FROM people WHERE id = ?');
    $stmt->execute([$id]);
    json_success($stmt->fetch(), 'Person updated');
}

// DELETE /api/people/{id} — hard delete (only if archived)
if ($method === 'DELETE') {
    $parts = get_path_parts();
    $id = isset($parts[2]) ? (int)$parts[2] : 0;
    if (!$id) json_error('ID required');

    // Check if archived
    $stmt = $db->prepare('SELECT archived FROM people WHERE id = ?');
    $stmt->execute([$id]);
    $person = $stmt->fetch();
    if (!$person) json_error('Not found', 404);

    $db->prepare('DELETE FROM people WHERE id = ?')->execute([$id]);
    json_success(null, 'Person deleted permanently');
}

json_error('Method not allowed', 405);
