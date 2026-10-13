<?php
/** Complete one plan atomically, preserving any existing work log and its notes. */
function complete_plan(PDO $db, int $uid, int $planId, ?DateTimeImmutable $autoNow = null): ?array {
    $db->beginTransaction();
    try {
        // Lock the task first: two meetings on one task must agree on its final stage.
        $st=$db->prepare('SELECT task_id FROM plans WHERE id=?'); $st->execute([$planId]);
        $taskId=$st->fetchColumn();
        $st=$db->prepare('SELECT * FROM tasks WHERE id=? AND user_id=? FOR UPDATE'); $st->execute([$taskId ?: 0,$uid]);
        $task=$st->fetch();
        if (!$task) { $db->rollBack(); return null; }
        $st=$db->prepare('SELECT * FROM plans WHERE id=? AND task_id=? FOR UPDATE');
        $st->execute([$planId,$task['id']]);
        $plan=$st->fetch();
        if (!$plan) { $db->rollBack(); return null; }

        $finishedAt=date('Y-m-d H:i:s');
        if ($autoNow !== null) {
            $end=meeting_plan_end($plan);
            if ($task['task_type'] !== 'meeting' || $task['archived'] || $task['stage'] === 'failed'
                || !$task['meeting_auto_from'] || $plan['planned_date'] < $task['meeting_auto_from']
                || !$end || $end > $autoNow) { $db->rollBack(); return null; }
            $finishedAt=$end->format('Y-m-d H:i:s');
        }

        $newlyCompleted=!(int)$plan['is_completed'];
        $taskCompleted=false;
        if ($newlyCompleted) {
            // The task/date unique key also serializes two different plans completed
            // for the same task/day. Never toggle off or recreate an existing log.
            $st=$db->prepare('INSERT INTO work_logs (task_id,log_date) VALUES (?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
            $st->execute([$plan['task_id'],$plan['planned_date']]);
            $logId=(int)$db->lastInsertId();
            $time=trim((string)$plan['plan_time']);
            if ($time !== '' && $plan['plan_end_time']) $time.='–'.$plan['plan_end_time'];
            $note='已按计划执行'.($time !== '' ? '（'.$time.'）' : '');
            $db->prepare('INSERT INTO worklog_notes (worklog_id,content) VALUES (?,?)')->execute([$logId,$note]);
            $db->prepare('UPDATE plans SET is_completed=1,completed_at=? WHERE id=?')->execute([$finishedAt,$planId]);
            if ($task['task_type'] === 'meeting' && !$task['archived'] && in_array($task['stage'], ['in_progress','stage_complete'], true)) {
                $pending=$db->prepare('SELECT 1 FROM plans WHERE task_id=? AND is_completed=0 AND planned_date>=? LIMIT 1 FOR UPDATE');
                $pending->execute([$task['id'],$task['meeting_auto_from']]);
                if (!$pending->fetchColumn()) {
                    $last=$db->prepare('SELECT completed_at FROM plans WHERE task_id=? AND planned_date>=? AND is_completed=1 ORDER BY completed_at DESC LIMIT 1 FOR UPDATE');
                    $last->execute([$task['id'],$task['meeting_auto_from']]);
                    $db->prepare("UPDATE tasks SET stage='completed',stage_changed_at=? WHERE id=?")->execute([$last->fetchColumn() ?: $finishedAt,$task['id']]);
                    $taskCompleted=true;
                }
            }
        }
        $st=$db->prepare('SELECT * FROM plans WHERE id=? FOR UPDATE'); $st->execute([$planId]); $result=$st->fetch();
        $st=$db->prepare('SELECT id FROM work_logs WHERE task_id=? AND log_date=? FOR UPDATE'); $st->execute([$plan['task_id'],$plan['planned_date']]);
        $result['work_log_id']=($id=$st->fetchColumn()) ? (int)$id : null;
        $result['newly_completed']=$newlyCompleted;
        $result['task_completed']=$taskCompleted;
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
