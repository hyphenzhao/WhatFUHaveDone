<?php
/**
 * Plans API — manage future task dates
 *
 * GET    /api/plans?task_id=...  — get plans for a task
 * GET    /api/plans?date=...     — get plans for a date
 * POST   /api/plans              — add plan {task_id, planned_date}
 * POST   /api/plans/{id}/complete — preserve the completed plan and ensure +1 work log
 * DELETE /api/plans/{id}          — remove plan
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/meeting_tasks.php';

$method = get_method();
$db = get_db();
$uid = current_user_id();
$parts = get_path_parts();

// Completes only meetings the user has explicitly enabled for automatic completion.
if ($method === 'POST' && ($parts[2] ?? '') === 'complete-due') {
    $out = complete_due_meetings($db, $uid);
    $out['revision'] = meeting_revision($db, $uid);
    json_success($out);
}

// Explicit per-plan completion, not task-wide completion inferred from work logs.
if ($method === 'POST' && ($parts[2] ?? '') !== '' && ($parts[3] ?? '') === 'complete') {
    $plan = complete_plan($db, $uid, (int)$parts[2]);
    if (!$plan) json_error('Plan not found', 404);
    json_success($plan, 'Plan completed');
}

if ($method === 'GET') {
    $task_id = $_GET['task_id'] ?? null;
    $date = $_GET['date'] ?? null;

    if ($task_id) {
        $stmt = $db->prepare('SELECT p.* FROM plans p JOIN tasks t ON p.task_id = t.id WHERE p.task_id = ? AND t.user_id = ? ORDER BY p.planned_date ASC');
        $stmt->execute([(int)$task_id, $uid]);
        json_success($stmt->fetchAll());
    } elseif ($date) {
        $stmt = $db->prepare('SELECT p.*, t.name as task_name FROM plans p JOIN tasks t ON p.task_id = t.id WHERE p.planned_date = ? AND t.user_id = ?');
        $stmt->execute([$date, $uid]);
        json_success($stmt->fetchAll());
    } else {
        json_error('task_id or date required');
    }
}

// POST /api/plans — add plan
if ($method === 'POST') {
    $data = get_json_input();
    $task_id = (int)($data['task_id'] ?? 0);
    $planned_date = optional_string($data, 'planned_date');

    if (!$task_id) json_error('task_id required');
    if (!$planned_date || !validate_date($planned_date)) json_error('Valid planned_date required (YYYY-MM-DD)');

    $plan_time = optional_string($data, 'plan_time');
    $plan_end_time = optional_string($data, 'plan_end_time');
    $plan = add_task_plan($db, $uid, compact('task_id','planned_date','plan_time','plan_end_time'));
    if (!$plan) json_error('Not found', 404);
    json_success($plan, 'Plan added');
}

// PUT /api/plans/{id} — update time
if ($method === 'PUT') {
    $parts = get_path_parts();
    $id = isset($parts[2]) ? (int)$parts[2] : 0;
    if (!$id) json_error('ID required');
    $data = get_json_input();
    $fields = []; $params = [];
    if (array_key_exists('plan_time', $data)) { $fields[] = 'plan_time = ?'; $params[] = $data['plan_time']; }
    if (array_key_exists('plan_end_time', $data)) { $fields[] = 'plan_end_time = ?'; $params[] = $data['plan_end_time']; }
    if ($fields) {
        $params[] = $id;
        $params[] = $uid;
        $db->prepare('UPDATE plans p JOIN tasks t ON p.task_id = t.id SET ' . implode(', ', array_map(fn($f) => 'p.' . $f, $fields)) . ' WHERE p.id = ? AND t.user_id = ?')->execute($params);
    }
    json_success(null, 'Plan updated');
}

// DELETE /api/plans/{id} — remove plan
if ($method === 'DELETE') {
    $parts = get_path_parts();
    $id = isset($parts[2]) ? (int)$parts[2] : 0;
    if ($id) {
        $db->prepare('DELETE p FROM plans p JOIN tasks t ON p.task_id = t.id WHERE p.id = ? AND t.user_id = ?')->execute([$id, $uid]);
        json_success(null, 'Plan removed');
    }
    json_error('ID required');
}

json_error('Method not allowed', 405);
