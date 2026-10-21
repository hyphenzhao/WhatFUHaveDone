<?php
/**
 * Tasks API — CRUD + stage changes + people/tag/result association
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/attachments_util.php';
require_once __DIR__ . '/../includes/meeting_tasks.php';

require_once __DIR__ . '/../includes/auth.php';

$method = get_method();
$db = get_db();
$uid = current_user_id();
$parts = get_path_parts(); // available to all methods (reorder route needs it on PUT)

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
function get_task_full(PDO $db, int $id, int $uid): ?array {
    $stmt = $db->prepare('SELECT * FROM tasks WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $uid]);
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
        $task = get_task_full($db, $id, $uid);
        if (!$task) json_error('Not found', 404);
        json_success($task);
    } else {
        $archived = isset($_GET['archived']) ? (int)$_GET['archived'] : 0;
        $stage = $_GET['stage'] ?? null;
        $taskType = $_GET['task_type'] ?? null;
        if ($taskType !== null && !task_type_valid((string)$taskType)) json_error('任务分类无效');
        $sort = $_GET['sort'] ?? 'priority';
        $sql = 'SELECT * FROM tasks WHERE archived = ? AND user_id = ?';
        $params = [$archived, $uid];
        if ($taskType) { $sql .= ' AND task_type=?'; $params[] = $taskType; }
        if ($stage && $stage !== 'all') {
            $sql .= ' AND stage = ?';
            $params[] = $stage;
        }
        $sql .= ($sort === 'priority') ? ' ORDER BY priority ASC, id ASC' : ' ORDER BY updated_at DESC';
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
    $taskType = optional_string($data, 'task_type', 'general');
    if (!task_type_valid($taskType)) json_error('任务分类无效');

    $imp = optional_int($data, 'importance', 3);
    $nec = optional_int($data, 'necessity', 3);
    $pri = optional_int($data, 'priority', 0);
    if (!$pri) {
        // Default: max priority + 1 (bottom of list)
        $stmt = $db->prepare('SELECT COALESCE(MAX(priority), 0) + 1 as next_pri FROM tasks WHERE archived = 0 AND user_id = ?');
        $stmt->execute([$uid]);
        $pri = (int)$stmt->fetch()['next_pri'];
    }
    $stmt = $db->prepare('INSERT INTO tasks (user_id, name, description, stage, stage_number, priority, importance, necessity, deadline, location, task_type, meeting_auto_from) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$uid, $name, optional_string($data, 'description'), optional_string($data, 'stage', 'in_progress'), optional_int($data, 'stage_number', 1), $pri, $imp, $nec, optional_string($data, 'deadline'), optional_string($data, 'location'), $taskType, $taskType === 'meeting' ? today() : null]);
    $id = $db->lastInsertId();

    if (isset($data['people_ids'])) attach_people($db, $id, optional_array($data, 'people_ids'));
    if (isset($data['tag_ids'])) attach_task_tags($db, $id, optional_array($data, 'tag_ids'));
    if (isset($data['result_ids'])) attach_results($db, $id, optional_array($data, 'result_ids'));

    json_success(get_task_full($db, $id, $uid), 'Task created');
}

// PUT /api/tasks/reorder — reorder priorities { ids: [3, 1, 5, ...] }
if ($method === 'PUT' && ($parts[2] ?? '') === 'reorder') {
    $data = get_json_input();
    $ids = $data['ids'] ?? [];
    if (!is_array($ids) || !array_is_list($ids) || !$ids) json_error('请提供完整的任务顺序');
    foreach ($ids as &$id) {
        if ((!is_int($id) && !is_string($id)) || filter_var($id, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false) json_error('任务 ID 无效');
        $id = (int)$id;
    }
    unset($id);
    if (count(array_unique($ids)) !== count($ids)) json_error('任务顺序中有重复项');
    $previous = $data['previous_ids'] ?? null;
    if ($previous !== null) {
        if (!is_array($previous) || !array_is_list($previous) || count($previous) !== count($ids)) json_error('原任务顺序无效');
        foreach ($previous as &$id) {
            if ((!is_int($id) && !is_string($id)) || filter_var($id, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false) json_error('原任务 ID 无效');
            $id = (int)$id;
        }
        unset($id);
        if (count(array_unique($previous)) !== count($previous)) json_error('原任务顺序中有重复项');
    }
    try {
        $db->beginTransaction();
        $stmt = $db->prepare('SELECT id FROM tasks WHERE user_id=? AND archived=0 ORDER BY priority,id FOR UPDATE');
        $stmt->execute([$uid]);
        $current = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $submitted = $ids; sort($submitted, SORT_NUMERIC);
        $currentSet = $current; sort($currentSet, SORT_NUMERIC);
        if ($submitted !== $currentSet || ($previous !== null && $previous !== $current)) {
            $db->rollBack();
            json_error('任务列表已变化，请刷新后重新调整优先级', 409);
        }
        $stmt = $db->prepare('UPDATE tasks SET priority=? WHERE id=? AND user_id=? AND archived=0');
        foreach ($ids as $i => $id) $stmt->execute([$i + 1, $id, $uid]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    json_success(['ids'=>$ids], 'Priorities updated');
}

// PUT /api/tasks/{id} — update (partial updates supported)
if ($method === 'PUT') {
    $parts = get_path_parts();
    $id = isset($parts[2]) ? (int)$parts[2] : 0;
    if (!$id) json_error('ID required');

    $data = get_json_input();
    $fields = [];
    $params = [];
    if (array_key_exists('task_type', $data) && !task_type_valid((string)$data['task_type'])) json_error('任务分类无效');
    foreach (['name', 'description', 'stage', 'stage_number', 'archived', 'priority', 'importance', 'necessity', 'deadline', 'location', 'task_type'] as $f) {
        if (array_key_exists($f, $data)) {
            $fields[] = "$f = ?";
if (in_array($f, ['stage_number', 'priority', 'importance', 'necessity'])) $params[] = optional_int($data, $f, $f === 'stage_number' ? 1 : 3);
            elseif ($f === 'archived') $params[] = (int)$data[$f];
            else $params[] = optional_string($data, $f, $f === 'stage' ? 'in_progress' : '');
        }
    }
    if (array_key_exists('task_type', $data)) {
        $fields[] = "meeting_auto_from = CASE WHEN ?='meeting' THEN COALESCE(meeting_auto_from,CURDATE()) ELSE NULL END";
        $params[] = $data['task_type'];
    }
    if ($fields) {
        if (array_key_exists('stage', $data)) {
            $fields[] = 'stage_changed_at = NOW()';
        }
        $params[] = $id;
        $params[] = $uid;
        $db->prepare('UPDATE tasks SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?')->execute($params);
        if (array_key_exists('task_type', $data) && $data['task_type'] === 'meeting') meeting_reopen_task($db, $uid, $id);
    }

    if (isset($data['people_ids'])) attach_people($db, $id, optional_array($data, 'people_ids'));
    if (isset($data['tag_ids'])) attach_task_tags($db, $id, optional_array($data, 'tag_ids'));
    if (isset($data['result_ids'])) attach_results($db, $id, optional_array($data, 'result_ids'));

    json_success(get_task_full($db, $id, $uid), 'Task updated');
}

// DELETE /api/tasks/{id} — hard delete
if ($method === 'DELETE') {
    $parts = get_path_parts();
    $id = isset($parts[2]) ? (int)$parts[2] : 0;
    if (!$id) json_error('ID required');

    $check = $db->prepare('SELECT id FROM tasks WHERE id = ? AND user_id = ?');
    $check->execute([$id, $uid]);
    if (!$check->fetch()) json_error('Not found', 404);

    // Task delete cascades work_logs -> worklog_notes; clean those notes' attachments,
    // plus any attachments on the task itself.
    $selNotes = $db->prepare('SELECT wn.id FROM worklog_notes wn JOIN work_logs wl ON wn.worklog_id = wl.id WHERE wl.task_id = ?');
    $selNotes->execute([$id]);
    delete_attachments($db, 'worklog_note', array_column($selNotes->fetchAll(), 'id'));
    delete_attachments($db, 'task', [$id]);

    $db->prepare('DELETE FROM tasks WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    json_success(null, 'Task deleted permanently');
}

json_error('Method not allowed', 405);
