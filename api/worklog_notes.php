<?php
/**
 * Worklog Notes API
 *
 * GET    /api/worklog_notes?worklog_id=N — list notes for a worklog
 * POST   /api/worklog_notes              — add note { worklog_id, content }
 * DELETE /api/worklog_notes/{id}          — delete note
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';

$method = get_method();
$parts = get_path_parts();
$db = get_db();

if ($method === 'GET') {
    $worklog_id = (int)($_GET['worklog_id'] ?? 0);
    $latest_all = isset($_GET['latest_all']);

    if ($latest_all) {
        // Return the latest note for every task that has worklogs
        $sql = "SELECT wl.task_id, wn.content as latest_note
                FROM worklog_notes wn
                JOIN work_logs wl ON wn.worklog_id = wl.id
                WHERE wn.id IN (
                    SELECT MAX(id) FROM worklog_notes GROUP BY worklog_id
                )
                ORDER BY wn.created_at DESC";
        json_success($db->query($sql)->fetchAll());
    } elseif ($worklog_id) {
        $stmt = $db->prepare('SELECT * FROM worklog_notes WHERE worklog_id = ? ORDER BY created_at ASC');
        $stmt->execute([$worklog_id]);
        json_success($stmt->fetchAll());
    } else {
        json_error('worklog_id or latest_all required');
    }
}

if ($method === 'POST') {
    $data = get_json_input();
    $worklog_id = (int)($data['worklog_id'] ?? 0);
    $content = trim(optional_string($data, 'content'));
    if (!$worklog_id || !$content) json_error('worklog_id and content required');
    $stmt = $db->prepare('INSERT INTO worklog_notes (worklog_id, content) VALUES (?, ?)');
    $stmt->execute([$worklog_id, $content]);
    json_success(['id' => (int)$db->lastInsertId(), 'content' => $content], 'Note added');
}

if ($method === 'DELETE') {
    $id = (int)($parts[2] ?? 0);
    if (!$id) json_error('ID required');
    $db->prepare('DELETE FROM worklog_notes WHERE id = ?')->execute([$id]);
    json_success(null, 'Note deleted');
}

json_error('Method not allowed', 405);
