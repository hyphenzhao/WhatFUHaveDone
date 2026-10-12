<?php
/** Complete one plan atomically, preserving any existing work log and its notes. */
function complete_plan(PDO $db, int $uid, int $planId): ?array {
    $db->beginTransaction();
    try {
        $st=$db->prepare('SELECT p.* FROM plans p JOIN tasks t ON t.id=p.task_id WHERE p.id=? AND t.user_id=? FOR UPDATE');
        $st->execute([$planId,$uid]);
        $plan=$st->fetch();
        if (!$plan) { $db->rollBack(); return null; }

        if (!(int)$plan['is_completed']) {
            // The task/date unique key also serializes two different plans completed
            // for the same task/day. Never toggle off or recreate an existing log.
            $st=$db->prepare('INSERT INTO work_logs (task_id,log_date) VALUES (?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
            $st->execute([$plan['task_id'],$plan['planned_date']]);
            $logId=(int)$db->lastInsertId();
            $time=trim((string)$plan['plan_time']);
            if ($time !== '' && $plan['plan_end_time']) $time.='–'.$plan['plan_end_time'];
            $note='已按计划执行'.($time !== '' ? '（'.$time.'）' : '');
            $db->prepare('INSERT INTO worklog_notes (worklog_id,content) VALUES (?,?)')->execute([$logId,$note]);
            $db->prepare('UPDATE plans SET is_completed=1,completed_at=NOW() WHERE id=?')->execute([$planId]);
        }
        $st=$db->prepare('SELECT * FROM plans WHERE id=?'); $st->execute([$planId]); $result=$st->fetch();
        $st=$db->prepare('SELECT id FROM work_logs WHERE task_id=? AND log_date=?'); $st->execute([$plan['task_id'],$plan['planned_date']]);
        $result['work_log_id']=($id=$st->fetchColumn()) ? (int)$id : null;
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
