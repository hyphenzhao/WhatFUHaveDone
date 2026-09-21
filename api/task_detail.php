<?php
/**
 * Task detail API — everything about one task in a single read, plus task ↔ mail linking.
 *
 * GET  /api/task_detail/{id}                       — task, tags, people, results, work logs + notes, plans, result logs, related mails, match state
 * POST /api/task_detail/{id}/match                 — (manual) compare not-yet-compared analysed mails with this task → { checked, matched, remaining }
 * PUT  /api/task_detail/{id}/mails/{message_id}    — { status: confirmed | rejected | ai }
 * POST /api/task_detail/{id}/mails                 — { message_id } link a mail by hand (status confirmed)
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/ai_client.php';
require_once __DIR__ . '/../includes/mail/TaskMailMatcher.php';
require_once __DIR__ . '/../includes/auth.php';

$method = get_method();
$parts = get_path_parts();
$db = get_db();
$uid = current_user_id();
$id = (int)($parts[2] ?? 0);
$sub = $parts[3] ?? '';
if (!$id) json_error('任务 ID 必填');

$st = $db->prepare('SELECT * FROM tasks WHERE id = ? AND user_id = ?');
$st->execute([$id, $uid]);
$task = $st->fetch();
if (!$task) json_error('任务不存在', 404);

function td_related_mails(PDO $db, int $uid, int $taskId): array {
    $st = $db->prepare("SELECT l.message_id, l.score, l.reason, l.status, m.subject, m.from_name, m.from_email, m.msg_date, m.is_seen, m.has_attachments,
                               a.brief_title, a.summary, a.priority, a.relevance, a.needs_reply, a.deadline_hint
                        FROM task_mail_links l
                        JOIN mail_messages m ON m.id = l.message_id
                        LEFT JOIN mail_analysis a ON a.message_id = m.id
                        WHERE l.task_id = ? AND l.user_id = ? AND l.status <> 'rejected' AND m.is_deleted = 0
                        ORDER BY (l.status = 'confirmed') DESC, l.score DESC, m.msg_date DESC LIMIT 100");
    $st->execute([$taskId, $uid]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) foreach (['message_id', 'score', 'is_seen', 'has_attachments', 'priority', 'relevance', 'needs_reply'] as $k) $r[$k] = (int)$r[$k];
    return $rows;
}

if ($method === 'GET' && $sub === '') {
    $q = function (string $sql, array $p) use ($db) { $s = $db->prepare($sql); $s->execute($p); return $s->fetchAll(); };
    $task['tags'] = $q('SELECT g.id, g.name, g.color FROM tags g JOIN task_tags tt ON tt.tag_id = g.id WHERE tt.task_id = ?', [$id]);
    $task['people'] = $q('SELECT p.id, p.name, p.relationship, p.importance FROM people p JOIN task_people tp ON tp.people_id = p.id WHERE tp.task_id = ?', [$id]);
    $task['results'] = $q('SELECT r.id, r.name, r.level FROM results r JOIN task_results tr ON tr.result_id = r.id WHERE tr.task_id = ?', [$id]);

    $logs = $q('SELECT id, log_date, duration FROM work_logs WHERE task_id = ? ORDER BY log_date DESC LIMIT 90', [$id]);
    $notes = $q('SELECT wn.id, wn.worklog_id, wn.content, wn.created_at,
                        (SELECT COUNT(*) FROM attachments at WHERE at.entity_type = "worklog_note" AND at.entity_id = wn.id) AS attachments
                 FROM worklog_notes wn JOIN work_logs wl ON wl.id = wn.worklog_id WHERE wl.task_id = ? ORDER BY wn.created_at ASC', [$id]);
    $byLog = [];
    foreach ($notes as $n) { $n['attachments'] = (int)$n['attachments']; $byLog[(int)$n['worklog_id']][] = $n; }
    foreach ($logs as &$l) { $l['id'] = (int)$l['id']; $l['notes'] = $byLog[$l['id']] ?? []; }
    unset($l);
    $task['work_logs'] = $logs;
    $cnt = $q('SELECT COUNT(*) AS n, MIN(log_date) AS first_day, MAX(log_date) AS last_day FROM work_logs WHERE task_id = ?', [$id])[0];
    $task['work_log_stats'] = ['days' => (int)$cnt['n'], 'first_day' => $cnt['first_day'], 'last_day' => $cnt['last_day']];

    $task['plans'] = $q('SELECT id, planned_date, plan_time, plan_end_time FROM plans WHERE task_id = ? AND planned_date >= CURDATE() ORDER BY planned_date, plan_time LIMIT 30', [$id]);
    $task['result_logs'] = $q('SELECT rl.id, rl.log_date, r.name FROM result_logs rl JOIN results r ON r.id = rl.result_id WHERE rl.task_id = ? ORDER BY rl.log_date DESC LIMIT 30', [$id]);

    // Mail section is optional (mail tables / migration 005 may be absent on some installs)
    $task['mail'] = ['available' => 0, 'items' => [], 'pending' => 0, 'ai_configured' => 0];
    try {
        $matcher = new TaskMailMatcher($db, $uid);
        $task['mail'] = [
            'available' => 1,
            'items' => td_related_mails($db, $uid, $id),
            'pending' => $matcher->pending($id),
            'ai_configured' => ai_is_configured(ai_load_config($db, $uid)) ? 1 : 0,
        ];
    } catch (Throwable $e) { /* leave unavailable */ }

    json_success($task);
}

if ($method === 'POST' && $sub === 'match') {
    @set_time_limit(110);
    try {
        $res = (new TaskMailMatcher($db, $uid))->run($id, 50);
    } catch (Throwable $e) {
        json_error($e->getMessage());
    }
    $res['items'] = td_related_mails($db, $uid, $id);
    json_success($res, "已比对 {$res['checked']} 封，新匹配 {$res['matched']} 封");
}

if ($sub === 'mails' && $method === 'PUT') {
    $mid = (int)($parts[4] ?? 0);
    $data = get_json_input();
    $status = optional_string($data, 'status', 'confirmed');
    if (!in_array($status, ['confirmed', 'rejected', 'ai'], true)) json_error('status 无效');
    $sql = 'UPDATE task_mail_links SET status = ?' . ($status === 'confirmed' ? ', score = 100' : '') . ' WHERE task_id = ? AND message_id = ? AND user_id = ?';
    $db->prepare($sql)->execute([$status, $id, $mid, $uid]);
    json_success(['items' => td_related_mails($db, $uid, $id)], $status === 'rejected' ? '已排除' : '已确认');
}

if ($sub === 'mails' && $method === 'POST') {
    $data = get_json_input();
    $mid = (int)($data['message_id'] ?? 0);
    $chk = $db->prepare('SELECT 1 FROM mail_messages WHERE id = ? AND user_id = ?');
    $chk->execute([$mid, $uid]);
    if (!$chk->fetchColumn()) json_error('邮件不存在', 404);
    $db->prepare('INSERT INTO task_mail_links (user_id, task_id, message_id, score, reason, status) VALUES (?, ?, ?, 100, "手动关联", "confirmed")
                  ON DUPLICATE KEY UPDATE status = "confirmed", score = 100')->execute([$uid, $id, $mid]);
    json_success(['items' => td_related_mails($db, $uid, $id)], '已关联');
}

json_error('Method not allowed', 405);
