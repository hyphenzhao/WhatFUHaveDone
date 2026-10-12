<?php
/** Historical activity survives task archiving. Only task deletion cascades records. */

function activity_calendar_events(PDO $db, int $uid, string $start, string $end): array {
    $st = $db->prepare("SELECT DISTINCT t.id,t.name,t.archived,wl.log_date AS event_date,'work' AS event_type,'' AS plan_time,'' AS plan_end_time,NULL AS plan_id,0 AS is_completed,NULL AS completed_at
        FROM tasks t JOIN work_logs wl ON t.id=wl.task_id
        WHERE wl.log_date BETWEEN ? AND ? AND t.user_id=?
        UNION ALL
        SELECT DISTINCT t.id,t.name,t.archived,p.planned_date AS event_date,'plan' AS event_type,p.plan_time,p.plan_end_time,p.id AS plan_id,p.is_completed,p.completed_at
        FROM tasks t JOIN plans p ON t.id=p.task_id
        WHERE p.planned_date BETWEEN ? AND ? AND t.user_id=?
        UNION ALL
        SELECT DISTINCT t.id,t.name,t.archived,rl.log_date AS event_date,'result' AS event_type,'' AS plan_time,'' AS plan_end_time,NULL AS plan_id,0 AS is_completed,NULL AS completed_at
        FROM tasks t JOIN result_logs rl ON t.id=rl.task_id
        WHERE rl.log_date BETWEEN ? AND ? AND t.user_id=?
        ORDER BY event_date,id");
    $st->execute([$start,$end,$uid,$start,$end,$uid,$start,$end,$uid]);
    $events = $st->fetchAll();
    return activity_add_execution_history($db, $uid, $start, $end, $events);
}

/** Recognise the application's execution marker, not arbitrary mentions of plans. */
function activity_execution_time(string $content): ?array {
    $text = trim($content);
    if (!preg_match('/^已按计划执行(?:执行)?(?:[（(](\d{2}:\d{2})(?:\s*[-–—~～至]\s*(\d{2}:\d{2}))?[）)])?[。.!！]?$/u', $text, $m)) return null;
    $from = $m[1] ?? ''; $to = $m[2] ?? '';
    foreach ([$from, $to] as $time) {
        if ($time !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) return null;
    }
    return [$from, $to];
}

/** Old releases deleted the plan on execution; project the surviving evidence only.
 * No plan rows or workloads are created or changed by this read path.
 */
function activity_add_execution_history(PDO $db, int $uid, string $start, string $end, array $events): array {
    $represented = [];
    foreach ($events as $event) {
        if ($event['event_type'] === 'plan' && (int)$event['is_completed'] === 1) {
            $represented[$event['id'].'|'.$event['event_date']] = true;
        }
    }
    $st = $db->prepare("SELECT t.id,t.name,t.archived,w.id AS work_log_id,w.log_date,n.id AS note_id,n.content,n.created_at
        FROM work_logs w JOIN tasks t ON t.id=w.task_id JOIN worklog_notes n ON n.worklog_id=w.id
        WHERE t.user_id=? AND w.log_date BETWEEN ? AND ? AND TRIM(n.content) LIKE '已按计划执行%'
        ORDER BY n.created_at,n.id");
    $st->execute([$uid,$start,$end]);
    $seen = [];
    foreach ($st->fetchAll() as $row) {
        $time = activity_execution_time($row['content']);
        if ($time === null || isset($represented[$row['id'].'|'.$row['log_date']])) continue;
        $key = $row['work_log_id'].'|'.implode('|',$time);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $events[] = [
            'id'=>$row['id'], 'name'=>$row['name'], 'archived'=>$row['archived'],
            'event_date'=>$row['log_date'], 'event_type'=>'plan',
            'plan_time'=>$time[0], 'plan_end_time'=>$time[1], 'plan_id'=>null,
            'is_completed'=>1, 'completed_at'=>$row['created_at'],
            'completion_source'=>'execution_note', 'source_work_log_id'=>$row['work_log_id'],
            'source_note_id'=>$row['note_id'], 'completion_key'=>'execution:'.$key,
        ];
    }
    usort($events, static fn($a,$b) => strcmp($a['event_date'],$b['event_date']) ?: (int)$a['id'] <=> (int)$b['id']);
    return $events;
}

function activity_daily_records(PDO $db, int $uid, string $date): array {
    $queries = [
        'work_tasks' => 'SELECT t.*,wl.id AS work_log_id,wl.duration FROM tasks t JOIN work_logs wl ON t.id=wl.task_id WHERE wl.log_date=? AND t.user_id=?',
        'result_tasks' => 'SELECT t.*,rl.id AS result_log_id,rl.result_id,r.name AS result_name FROM tasks t JOIN result_logs rl ON t.id=rl.task_id JOIN results r ON r.id=rl.result_id AND r.user_id=t.user_id WHERE rl.log_date=? AND t.user_id=?',
        'plan_tasks' => 'SELECT t.*,p.id AS plan_id,p.plan_time,p.plan_end_time,p.is_completed,p.completed_at FROM tasks t JOIN plans p ON t.id=p.task_id WHERE p.planned_date=? AND t.user_id=?',
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
