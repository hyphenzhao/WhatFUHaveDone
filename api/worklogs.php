<?php
/**
 * Work Logs API — toggle daily +1 workload
 *
 * POST   /api/worklogs           — toggle +1 for task on date
 * GET    /api/worklogs?date=...   — get all work logs for a date
 * GET    /api/worklogs?task_id=.. — get work logs for a task
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
        $stmt = $db->prepare('SELECT wl.*, t.name as task_name,
            (SELECT content FROM worklog_notes WHERE worklog_id = wl.id ORDER BY created_at DESC LIMIT 1) as latest_note
            FROM work_logs wl JOIN tasks t ON wl.task_id = t.id WHERE wl.log_date = ?');
        $stmt->execute([$date]);
        json_success($stmt->fetchAll());
    } elseif ($task_id) {
        $stmt = $db->prepare('SELECT * FROM work_logs WHERE task_id = ? ORDER BY log_date DESC');
        $stmt->execute([(int)$task_id]);
        json_success($stmt->fetchAll());
    } else {
        json_error('date or task_id required');
    }
}

// POST /api/worklogs — toggle
if ($method === 'POST') {
    $data = get_json_input();
    $task_id = (int)($data['task_id'] ?? 0);
    $date = optional_string($data, 'date', today());

    if (!$task_id) json_error('task_id required');
    if (!validate_date($date)) json_error('Invalid date format (YYYY-MM-DD)');

    // Check if already exists
    $stmt = $db->prepare('SELECT id FROM work_logs WHERE task_id = ? AND log_date = ?');
    $stmt->execute([$task_id, $date]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Remove (toggle off)
        $db->prepare('DELETE FROM work_logs WHERE id = ?')->execute([$existing['id']]);
        json_success(['active' => false, 'task_id' => $task_id, 'date' => $date], 'Work log removed');
    } else {
        // Add (toggle on)
        $db->prepare('INSERT INTO work_logs (task_id, log_date) VALUES (?, ?)')->execute([$task_id, $date]);
        json_success(['active' => true, 'task_id' => $task_id, 'date' => $date, 'id' => (int)$db->lastInsertId()], 'Work log added');
    }
}

// DELETE /api/worklogs/{id} — explicit remove
if ($method === 'DELETE') {
    $parts = get_path_parts();
    $id = isset($parts[2]) ? (int)$parts[2] : 0;
    if ($id) {
        $db->prepare('DELETE FROM work_logs WHERE id = ?')->execute([$id]);
        json_success(null, 'Work log removed');
    }

    // Or by task_id + date
    $data = get_json_input();
    $task_id = (int)($data['task_id'] ?? 0);
    $date = optional_string($data, 'date', today());
    if ($task_id) {
        $db->prepare('DELETE FROM work_logs WHERE task_id = ? AND log_date = ?')->execute([$task_id, $date]);
        json_success(null, 'Work log removed');
    }
    json_error('id or task_id+date required');
}

json_error('Method not allowed', 405);
