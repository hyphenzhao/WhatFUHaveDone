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
require_once __DIR__ . '/../includes/attachments_util.php';
require_once __DIR__ . '/../includes/auth.php';

$method = get_method();
$db = get_db();
$uid = current_user_id();

// Verify a task belongs to the current user
function assert_task_owned(PDO $db, int $task_id, int $uid): void {
    $stmt = $db->prepare('SELECT 1 FROM tasks WHERE id = ? AND user_id = ?');
    $stmt->execute([$task_id, $uid]);
    if (!$stmt->fetchColumn()) json_error('Not found', 404);
}

// Deleting a work_log cascades to its worklog_notes (FK), which would orphan those
// notes' attachments. Clean them first.
function purge_worklog_note_attachments(PDO $db, array $worklogIds): void {
    $worklogIds = array_values(array_filter(array_map('intval', $worklogIds)));
    if (empty($worklogIds)) return;
    $ph = implode(',', array_fill(0, count($worklogIds), '?'));
    $st = $db->prepare("SELECT id FROM worklog_notes WHERE worklog_id IN ($ph)");
    $st->execute($worklogIds);
    delete_attachments($db, 'worklog_note', array_column($st->fetchAll(), 'id'));
}

if ($method === 'GET') {
    $date = $_GET['date'] ?? null;
    $task_id = $_GET['task_id'] ?? null;

    if ($date) {
        $stmt = $db->prepare('SELECT wl.*, t.name as task_name,
            (SELECT content FROM worklog_notes WHERE worklog_id = wl.id ORDER BY created_at DESC LIMIT 1) as latest_note
            FROM work_logs wl JOIN tasks t ON wl.task_id = t.id WHERE wl.log_date = ? AND t.user_id = ?');
        $stmt->execute([$date, $uid]);
        json_success($stmt->fetchAll());
    } elseif ($task_id) {
        assert_task_owned($db, (int)$task_id, $uid);
        $stmt = $db->prepare('SELECT * FROM work_logs WHERE task_id = ? ORDER BY log_date DESC');
        $stmt->execute([(int)$task_id]);
        json_success($stmt->fetchAll());
    } else {
        json_error('date or task_id required');
    }
}

// PUT /api/worklogs — update duration
if ($method === 'PUT') {
    $data = get_json_input();
    $id = (int)($data['id'] ?? 0);
    $duration = optional_string($data, 'duration');
    if ($id) {
        $db->prepare('UPDATE work_logs wl JOIN tasks t ON wl.task_id = t.id SET wl.duration = ? WHERE wl.id = ? AND t.user_id = ?')->execute([$duration, $id, $uid]);
        json_success(null, 'Duration updated');
    }
    json_error('id required');
}

// POST /api/worklogs — toggle
if ($method === 'POST') {
    $data = get_json_input();
    $task_id = (int)($data['task_id'] ?? 0);
    $date = optional_string($data, 'date', today());

    if (!$task_id) json_error('task_id required');
    if (!validate_date($date)) json_error('Invalid date format (YYYY-MM-DD)');
    assert_task_owned($db, $task_id, $uid);

    // Check if already exists
    $stmt = $db->prepare('SELECT id FROM work_logs WHERE task_id = ? AND log_date = ?');
    $stmt->execute([$task_id, $date]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Remove (toggle off)
        purge_worklog_note_attachments($db, [$existing['id']]);
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
        $chk = $db->prepare('SELECT wl.id FROM work_logs wl JOIN tasks t ON wl.task_id = t.id WHERE wl.id = ? AND t.user_id = ?');
        $chk->execute([$id, $uid]);
        if (!$chk->fetchColumn()) json_error('Not found', 404);
        purge_worklog_note_attachments($db, [$id]);
        $db->prepare('DELETE FROM work_logs WHERE id = ?')->execute([$id]);
        json_success(null, 'Work log removed');
    }

    // Or by task_id + date
    $data = get_json_input();
    $task_id = (int)($data['task_id'] ?? 0);
    $date = optional_string($data, 'date', today());
    if ($task_id) {
        assert_task_owned($db, $task_id, $uid);
        $sel = $db->prepare('SELECT id FROM work_logs WHERE task_id = ? AND log_date = ?');
        $sel->execute([$task_id, $date]);
        purge_worklog_note_attachments($db, array_column($sel->fetchAll(), 'id'));
        $db->prepare('DELETE FROM work_logs WHERE task_id = ? AND log_date = ?')->execute([$task_id, $date]);
        json_success(null, 'Work log removed');
    }
    json_error('id or task_id+date required');
}

json_error('Method not allowed', 405);
