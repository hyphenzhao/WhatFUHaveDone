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
require_once __DIR__ . '/../includes/attachments_util.php';
require_once __DIR__ . '/../includes/auth.php';

$method = get_method();
$parts = get_path_parts();
$db = get_db();
$uid = current_user_id();

if ($method === 'GET') {
    $worklog_id = (int)($_GET['worklog_id'] ?? 0);
    $task_id = (int)($_GET['task_id'] ?? 0);
    $latest_all = isset($_GET['latest_all']);

    if ($task_id) {
        $sql = "SELECT wn.*, wl.log_date, wl.task_id FROM worklog_notes wn
                JOIN work_logs wl ON wn.worklog_id = wl.id
                JOIN tasks t ON wl.task_id = t.id
                WHERE wl.task_id = ? AND t.user_id = ? ORDER BY wn.created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$task_id, $uid]);
        json_success($stmt->fetchAll());
    } elseif ($latest_all) {
        // Return the single most recent note per task (across all worklogs)
        $sql = "SELECT wl.task_id, wn.content as latest_note
                FROM worklog_notes wn
                JOIN work_logs wl ON wn.worklog_id = wl.id
                JOIN tasks t ON wl.task_id = t.id
                WHERE t.user_id = ? AND wn.id IN (
                    SELECT MAX(wn2.id) FROM worklog_notes wn2
                    JOIN work_logs wl2 ON wn2.worklog_id = wl2.id
                    GROUP BY wl2.task_id
                )
                ORDER BY wn.created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$uid]);
        json_success($stmt->fetchAll());
    } elseif ($worklog_id) {
        $stmt = $db->prepare('SELECT wn.* FROM worklog_notes wn
            JOIN work_logs wl ON wn.worklog_id = wl.id
            JOIN tasks t ON wl.task_id = t.id
            WHERE wn.worklog_id = ? AND t.user_id = ? ORDER BY wn.created_at ASC');
        $stmt->execute([$worklog_id, $uid]);
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
    // Ensure the worklog exists so we return a clean error instead of a FK 500.
    $chk = $db->prepare('SELECT 1 FROM work_logs wl JOIN tasks t ON wl.task_id = t.id WHERE wl.id = ? AND t.user_id = ?');
    $chk->execute([$worklog_id, $uid]);
    if (!$chk->fetchColumn()) json_error('工作量记录不存在，请先记录当日工作量(+1)再添加备注', 404);
    $stmt = $db->prepare('INSERT INTO worklog_notes (worklog_id, content) VALUES (?, ?)');
    $stmt->execute([$worklog_id, $content]);
    json_success(['id' => (int)$db->lastInsertId(), 'content' => $content], 'Note added');
}

if ($method === 'DELETE') {
    $id = (int)($parts[2] ?? 0);
    if (!$id) json_error('ID required');
    $chk = $db->prepare('SELECT 1 FROM worklog_notes wn JOIN work_logs wl ON wn.worklog_id = wl.id JOIN tasks t ON wl.task_id = t.id WHERE wn.id = ? AND t.user_id = ?');
    $chk->execute([$id, $uid]);
    if (!$chk->fetchColumn()) json_error('Not found', 404);
    delete_attachments($db, 'worklog_note', [$id]);
    $db->prepare('DELETE FROM worklog_notes WHERE id = ?')->execute([$id]);
    json_success(null, 'Note deleted');
}

json_error('Method not allowed', 405);
