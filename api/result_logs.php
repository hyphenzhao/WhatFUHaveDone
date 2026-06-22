<?php
/**
 * Result Logs API — log daily outputs against tasks
 *
 * POST /api/result_logs              — add result log {task_id, result_id, date}
 * GET  /api/result_logs?date=...      — get result logs for a date
 * GET  /api/result_logs?task_id=...   — get result logs for a task
 * DELETE /api/result_logs/{id}        — remove result log
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';

$method = get_method();
$db = get_db();

if ($method === 'GET') {
    $date = $_GET['date'] ?? null;
    $task_id = $_GET['task_id'] ?? null;

    if ($date) {
        $stmt = $db->prepare('SELECT rl.*, t.name as task_name, r.name as result_name FROM result_logs rl JOIN tasks t ON rl.task_id = t.id JOIN results r ON rl.result_id = r.id WHERE rl.log_date = ?');
        $stmt->execute([$date]);
        json_success($stmt->fetchAll());
    } elseif ($task_id) {
        $stmt = $db->prepare('SELECT rl.*, r.name as result_name FROM result_logs rl JOIN results r ON rl.result_id = r.id WHERE rl.task_id = ? ORDER BY rl.log_date DESC');
        $stmt->execute([(int)$task_id]);
        json_success($stmt->fetchAll());
    } else {
        json_error('date or task_id required');
    }
}

// POST /api/result_logs — add result log
if ($method === 'POST') {
    $data = get_json_input();
    $task_id = (int)($data['task_id'] ?? 0);
    $result_id = (int)($data['result_id'] ?? 0);
    $date = optional_string($data, 'date', today());

    if (!$task_id) json_error('task_id required');
    if (!$result_id) json_error('result_id required');
    if (!validate_date($date)) json_error('Invalid date format (YYYY-MM-DD)');

    $stmt = $db->prepare('INSERT INTO result_logs (task_id, result_id, log_date) VALUES (?, ?, ?)');
    $stmt->execute([$task_id, $result_id, $date]);

    // Also link result to task in task_results if not already linked
    $check = $db->prepare('SELECT 1 FROM task_results WHERE task_id = ? AND result_id = ?');
    $check->execute([$task_id, $result_id]);
    if (!$check->fetch()) {
        $db->prepare('INSERT INTO task_results (task_id, result_id) VALUES (?, ?)')->execute([$task_id, $result_id]);
    }

    json_success([
        'id' => (int)$db->lastInsertId(),
        'task_id' => $task_id,
        'result_id' => $result_id,
        'date' => $date,
    ], 'Result log added');
}

// DELETE /api/result_logs/{id}
if ($method === 'DELETE') {
    $parts = get_path_parts();
    $id = isset($parts[2]) ? (int)$parts[2] : 0;
    if ($id) {
        $db->prepare('DELETE FROM result_logs WHERE id = ?')->execute([$id]);
        json_success(null, 'Result log removed');
    }
    json_error('ID required');
}

json_error('Method not allowed', 405);
