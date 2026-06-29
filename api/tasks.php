<?php
/**
 * Tasks API — CRUD + stage changes + people/tag/result association
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';

$method = get_method();
$db = get_db();

// Helpers
function attach_people(PDO $db, int $task_id, array $people_ids): void {
    $db->prepare('DELETE FROM task_people WHERE task_id = ?')->execute([$task_id]);
    $stmt = $db->prepare('INSERT INTO task_people (task_id, people_id) VALUES (?, ?)');
    foreach ($people_ids as $pid) $stmt->execute([$task_id, (int)$pid]);
}
function attach_task_tags(PDO $db, int $task_id, array $tag_ids): void {
    $db->prepare('DELETE FROM task_tags WHERE task_id = ?')->execute([$task_id]);
    $stmt = $db->prepare('INSERT INTO task_tags (task_id, tag_id) VALUES (?, ?)');
    foreach ($tag_ids as $tid) $stmt->execute([$task_id, (int)$tid]);
}
function attach_results(PDO $db, int $task_id, array $result_ids): void {
    $db->prepare('DELETE FROM task_results WHERE task_id = ?')->execute([$task_id]);
    $stmt = $db->prepare('INSERT INTO task_results (task_id, result_id) VALUES (?, ?)');
    foreach ($result_ids as $rid) $stmt->execute([$task_id, (int)$rid]);
}
function get_task_full(PDO $db, int $id): ?array {
    $stmt = $db->prepare('SELECT * FROM tasks WHERE id = ?');
    $stmt->execute([$id]);
    $task = $stmt->fetch();
    if (!$task) return null;

    // People
    $stmt = $db->prepare('SELECT p.* FROM people p JOIN task_people tp ON p.id = tp.people_id WHERE tp.task_id = ?');
    $stmt->execute([$id]);
    $task['people'] = $stmt->fetchAll();

    // Tags
    $stmt = $db->prepare('SELECT t.* FROM tags t JOIN task_tags tt ON t.id = tt.tag_id WHERE tt.task_id = ?');
    $stmt->execute([$id]);
    $task['tags'] = $stmt->fetchAll();

    // Results
    $stmt = $db->prepare('SELECT r.* FROM results r JOIN task_results tr ON r.id = tr.result_id WHERE tr.task_id = ?');
    $stmt->execute([$id]);
    $task['results'] = $stmt->fetchAll();

    return $task;
}

// GET /api/tasks[/{id}]
if ($method === 'GET') {
    $parts = get_path_parts();
    $id = isset($parts[2]) ? (int)$parts[2] : null;

    if ($id) {
        $task = get_task_full($db, $id);
        if (!$task) json_error('Not found', 404);
        json_success($task);
    } else {
        $archived = isset($_GET['archived']) ? (int)$_GET['archived'] : 0;
        $stage = $_GET['stage'] ?? null;
        $sort = $_GET['sort'] ?? 'priority';
        $sql = 'SELECT * FROM tasks WHERE archived = ?';
        $params = [$archived];
        if ($stage && $stage !== 'all') {
            $sql .= ' AND stage = ?';
            $params[] = $stage;
        }
        $sql .= ($sort === 'priority') ? ' ORDER BY priority ASC' : ' ORDER BY updated_at DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $tasks = $stmt->fetchAll();
        foreach ($tasks as &$t) {
            $stmtP = $db->prepare('SELECT p.* FROM people p JOIN task_people tp ON p.id = tp.people_id WHERE tp.task_id = ?');
            $stmtP->execute([$t['id']]);
            $t['people'] = $stmtP->fetchAll();
            $stmt2 = $db->prepare('SELECT t.* FROM tags t JOIN task_tags tt ON t.id = tt.tag_id WHERE tt.task_id = ?');
            $stmt2->execute([$t['id']]);
            $t['tags'] = $stmt2->fetchAll();
            $stmt3 = $db->prepare('SELECT r.* FROM results r JOIN task_results tr ON r.id = tr.result_id WHERE tr.task_id = ?');
            $stmt3->execute([$t['id']]);
            $t['results'] = $stmt3->fetchAll();
        }
        json_success($tasks);
    }
}

// POST /api/tasks — create
if ($method === 'POST') {
    $data = get_json_input();
    $name = optional_string($data, 'name');
    if (!$name) json_error('Name is required');

    $imp = optional_int($data, 'importance', 3);
    $nec = optional_int($data, 'necessity', 3);
    $pri = optional_int($data, 'priority', 0);
    if (!$pri) {
        // Default: max priority + 1 (bottom of list)
        $stmt = $db->prepare('SELECT COALESCE(MAX(priority), 0) + 1 as next_pri FROM tasks WHERE archived = 0');
        $stmt->execute();
        $pri = (int)$stmt->fetch()['next_pri'];
    }
    $stmt = $db->prepare('INSERT INTO tasks (name, description, stage, stage_number, priority, importance, necessity) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, optional_string($data, 'description'), optional_string($data, 'stage', 'in_progress'), optional_int($data, 'stage_number', 1), $pri, $imp, $nec]);
    $id = $db->lastInsertId();

    if (isset($data['people_ids'])) attach_people($db, $id, optional_array($data, 'people_ids'));
    if (isset($data['tag_ids'])) attach_task_tags($db, $id, optional_array($data, 'tag_ids'));
    if (isset($data['result_ids'])) attach_results($db, $id, optional_array($data, 'result_ids'));

    json_success(get_task_full($db, $id), 'Task created');
}

// PUT /api/tasks/{id} — update (partial updates supported)
if ($method === 'PUT') {
    $parts = get_path_parts();
    $id = isset($parts[2]) ? (int)$parts[2] : 0;
    if (!$id) json_error('ID required');

    $data = get_json_input();
    $fields = [];
    $params = [];
    foreach (['name', 'description', 'stage', 'stage_number', 'archived', 'priority', 'importance', 'necessity'] as $f) {
        if (array_key_exists($f, $data)) {
            $fields[] = "$f = ?";
if (in_array($f, ['stage_number', 'priority', 'importance', 'necessity'])) $params[] = optional_int($data, $f, $f === 'stage_number' ? 1 : 3);
            elseif ($f === 'archived') $params[] = (int)$data[$f];
            else $params[] = optional_string($data, $f, $f === 'stage' ? 'in_progress' : '');
        }
    }
    if ($fields) {
        $params[] = $id;
        $db->prepare('UPDATE tasks SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    }

    if (isset($data['people_ids'])) attach_people($db, $id, optional_array($data, 'people_ids'));
    if (isset($data['tag_ids'])) attach_task_tags($db, $id, optional_array($data, 'tag_ids'));
    if (isset($data['result_ids'])) attach_results($db, $id, optional_array($data, 'result_ids'));

    json_success(get_task_full($db, $id), 'Task updated');
}

// DELETE /api/tasks/{id} — hard delete
if ($method === 'DELETE') {
    $parts = get_path_parts();
    $id = isset($parts[2]) ? (int)$parts[2] : 0;
    if (!$id) json_error('ID required');

    $db->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
    json_success(null, 'Task deleted permanently');
}

// PUT /api/tasks/reorder — reorder priorities { ids: [3, 1, 5, ...] }
if ($method === 'PUT' && ($parts[2] ?? '') === 'reorder') {
    $data = get_json_input();
    $ids = $data['ids'] ?? [];
    if (empty($ids)) json_error('ids array required');
    $stmt = $db->prepare('UPDATE tasks SET priority = ? WHERE id = ?');
    foreach ($ids as $i => $id) {
        $stmt->execute([$i + 1, (int)$id]);
    }
    json_success(null, 'Priorities updated');
}

json_error('Method not allowed', 405);
