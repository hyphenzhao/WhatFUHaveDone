<?php
require_once __DIR__ . '/plan_completion.php';

function task_type_valid(string $type): bool {
    return in_array($type, ['general', 'meeting'], true);
}

/** Without an explicit end time, the meeting finishes at the next local midnight. */
function meeting_plan_end(array $plan): ?DateTimeImmutable {
    $zone = new DateTimeZone(TIMEZONE);
    $date = (string)($plan['planned_date'] ?? '');
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
    if (!$day || $day->format('Y-m-d') !== $date) return null;
    $start = trim((string)($plan['plan_time'] ?? ''));
    $end = trim((string)($plan['plan_end_time'] ?? ''));
    foreach ([$start, $end] as $time) {
        if ($time !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) return null;
    }
    if ($end === '') return $day->modify('+1 day');
    [$h, $m] = array_map('intval', explode(':', $end));
    $finish = $day->setTime($h, $m);
    return $start !== '' && $end < $start ? $finish->modify('+1 day') : $finish;
}

/** A new meeting plan reopens an already-completed meeting task. */
function meeting_reopen_task(PDO $db, int $uid, int $taskId): void {
    $db->prepare("UPDATE tasks t SET t.stage='in_progress',t.stage_changed_at=NOW()
        WHERE t.id=? AND t.user_id=? AND t.task_type='meeting' AND t.archived=0 AND t.stage='completed'
          AND EXISTS(SELECT 1 FROM plans p WHERE p.task_id=t.id AND p.is_completed=0
                     AND p.planned_date>=t.meeting_auto_from)")->execute([$taskId, $uid]);
}

/** Session API and AI share the same ownership check and meeting reopening rule. */
function add_task_plan(PDO $db, int $uid, array $data): ?array {
    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT id FROM tasks WHERE id=? AND user_id=? FOR UPDATE');
        $st->execute([(int)$data['task_id'], $uid]);
        if (!$st->fetchColumn()) { $db->rollBack(); return null; }
        $st = $db->prepare('INSERT INTO plans (task_id,planned_date,plan_time,plan_end_time) VALUES (?,?,?,?)');
        $st->execute([(int)$data['task_id'], $data['planned_date'], $data['plan_time'] ?? '', $data['plan_end_time'] ?? '']);
        $id = (int)$db->lastInsertId();
        meeting_reopen_task($db, $uid, (int)$data['task_id']);
        $st = $db->prepare('SELECT * FROM plans WHERE id=?'); $st->execute([$id]); $plan = $st->fetch();
        $db->commit();
        return $plan;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Background worker and the signed-in page may safely run this concurrently. */
function complete_due_meetings(PDO $db, ?int $uid = null, ?DateTimeImmutable $now = null): array {
    $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone(TIMEZONE)))->setTimezone(new DateTimeZone(TIMEZONE));
    $sql = "SELECT p.*,t.user_id FROM plans p JOIN tasks t ON t.id=p.task_id
        WHERE t.task_type='meeting' AND t.archived=0 AND t.stage<>'failed' AND p.is_completed=0
          AND p.planned_date>=t.meeting_auto_from AND p.planned_date<=?";
    $params = [$now->format('Y-m-d')];
    if ($uid !== null) { $sql .= ' AND t.user_id=?'; $params[] = $uid; }
    $sql .= ' ORDER BY p.planned_date,p.id';
    $st = $db->prepare($sql); $st->execute($params);
    $out = ['completed'=>0, 'tasks_completed'=>0, 'errors'=>[]];
    foreach ($st->fetchAll() as $plan) {
        $end = meeting_plan_end($plan);
        if (!$end || $end > $now) continue;
        try {
            $result = complete_plan($db, (int)$plan['user_id'], (int)$plan['id'], $now);
            if ($result && !empty($result['newly_completed'])) {
                $out['completed']++;
                if (!empty($result['task_completed'])) $out['tasks_completed']++;
            }
        } catch (Throwable $e) {
            $out['errors'][] = ['plan_id'=>(int)$plan['id'], 'error'=>$e->getMessage()];
        }
    }
    return $out;
}

function meeting_revision(PDO $db, int $uid): string {
    $st = $db->prepare("SELECT COUNT(p.id) AS plans,COALESCE(SUM(p.is_completed),0) AS completed,
        MAX(p.completed_at) AS last_completion,MAX(t.updated_at) AS task_update
        FROM tasks t LEFT JOIN plans p ON p.task_id=t.id WHERE t.user_id=? AND t.task_type='meeting'");
    $st->execute([$uid]);
    return hash('sha256', json_encode($st->fetch()));
}
