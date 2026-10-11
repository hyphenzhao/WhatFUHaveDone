<?php
/** Historical activity survives task archiving. Only task deletion cascades records. */

function activity_calendar_events(PDO $db, int $uid, string $start, string $end): array {
    $st = $db->prepare("SELECT DISTINCT t.id,t.name,t.archived,wl.log_date AS event_date,'work' AS event_type,'' AS plan_time,'' AS plan_end_time
        FROM tasks t JOIN work_logs wl ON t.id=wl.task_id
        WHERE wl.log_date BETWEEN ? AND ? AND t.user_id=?
        UNION ALL
        SELECT DISTINCT t.id,t.name,t.archived,p.planned_date AS event_date,'plan' AS event_type,p.plan_time,p.plan_end_time
        FROM tasks t JOIN plans p ON t.id=p.task_id
        WHERE p.planned_date BETWEEN ? AND ? AND t.user_id=?
        UNION ALL
        SELECT DISTINCT t.id,t.name,t.archived,rl.log_date AS event_date,'result' AS event_type,'' AS plan_time,'' AS plan_end_time
        FROM tasks t JOIN result_logs rl ON t.id=rl.task_id
        WHERE rl.log_date BETWEEN ? AND ? AND t.user_id=?
        ORDER BY event_date,id");
    $st->execute([$start,$end,$uid,$start,$end,$uid,$start,$end,$uid]);
    return $st->fetchAll();
}

function activity_daily_records(PDO $db, int $uid, string $date): array {
    $queries = [
        'work_tasks' => 'SELECT t.*,wl.id AS work_log_id,wl.duration FROM tasks t JOIN work_logs wl ON t.id=wl.task_id WHERE wl.log_date=? AND t.user_id=?',
        'result_tasks' => 'SELECT t.*,rl.id AS result_log_id,rl.result_id,r.name AS result_name FROM tasks t JOIN result_logs rl ON t.id=rl.task_id JOIN results r ON r.id=rl.result_id AND r.user_id=t.user_id WHERE rl.log_date=? AND t.user_id=?',
        'plan_tasks' => 'SELECT t.*,p.id AS plan_id,p.plan_time,p.plan_end_time FROM tasks t JOIN plans p ON t.id=p.task_id WHERE p.planned_date=? AND t.user_id=?',
    ];
    $out=['date'=>$date];
    foreach ($queries as $key=>$sql) {
        $st=$db->prepare($sql); $st->execute([$date,$uid]);
        $out[$key]=$st->fetchAll();
    }
    return $out;
}

function activity_workload_detail(PDO $db, int $uid, int $tagId, ?string $start): array {
    // A task with several beneficiaries still contributes one count per work log.
    $sql="SELECT t.id,t.name,t.archived,COUNT(DISTINCT wl.id) AS work_days,
        GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS people_names
        FROM tasks t JOIN task_tags tt ON t.id=tt.task_id JOIN work_logs wl ON t.id=wl.task_id
        LEFT JOIN task_people tp ON t.id=tp.task_id LEFT JOIN people p ON p.id=tp.people_id AND p.user_id=t.user_id
        WHERE tt.tag_id=? AND t.user_id=?";
    $params=[$tagId,$uid];
    if ($start) { $sql.=' AND wl.log_date>=?'; $params[]=$start; }
    $sql.=' GROUP BY t.id,t.name,t.archived ORDER BY work_days DESC';
    $st=$db->prepare($sql); $st->execute($params); return $st->fetchAll();
}
