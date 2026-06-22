<?php
/**
 * Plans API — manage future task dates
 *
 * GET    /api/plans?task_id=...  — get plans for a task
 * GET    /api/plans?date=...     — get plans for a date
 * POST   /api/plans              — add plan {task_id, planned_date}
 * DELETE /api/plans/{id}          — remove plan
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';

$method = get_method();
$db = get_db();

if ($method === 'GET') {
    $task_id = $_GET['task_id'] ?? null;
    $date = $_GET['date'] ?? null;

    if ($task_id) {
        $stmt = $db->prepare('SELECT * FROM plans WHERE task_id = ? ORDER BY planned_date ASC');
        $stmt->execute([(int)$task_id]);
        json_success($stmt->fetchAll());
    } elseif ($date) {
        $stmt = $db->prepare('SELECT p.*, t.name as task_name FROM plans p JOIN tasks t ON p.task_id = t.id WHERE p.planned_date = ?');
        $stmt->execute([$date]);
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

    $stmt = $db->prepare('INSERT INTO plans (task_id, planned_date) VALUES (?, ?)');
    $stmt->execute([$task_id, $planned_date]);

    json_success([
        'id' => (int)$db->lastInsertId(),
        'task_id' => $task_id,
        'planned_date' => $planned_date,
    ], 'Plan added');
}

// DELETE /api/plans/{id} — remove plan
if ($method === 'DELETE') {
    $parts = get_path_parts();
    $id = isset($parts[2]) ? (int)$parts[2] : 0;
    if ($id) {
        $db->prepare('DELETE FROM plans WHERE id = ?')->execute([$id]);
        json_success(null, 'Plan removed');
    }
    json_error('ID required');
}

json_error('Method not allowed', 405);
