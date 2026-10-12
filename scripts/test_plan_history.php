<?php
/** Legacy plan projections use synthetic records and are rolled back. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/activity_queries.php';
$db=get_db(); $checks=0;
function ph_check(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$message); $checks++; }
function ph_insert(string $table,array $data): int {
    global $db; $s=$db->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')');$s->execute(array_values($data));return (int)$db->lastInsertId();
}
$db->beginTransaction();
try {
    $user=ph_insert('users',['username'=>'history_test_'.bin2hex(random_bytes(8)),'password_hash'=>'not-a-login']);
    $other=ph_insert('users',['username'=>'history_other_'.bin2hex(random_bytes(8)),'password_hash'=>'not-a-login']);
    $day='2026-09-21';
    $old=ph_insert('tasks',['user_id'=>$user,'name'=>'旧版全天计划','archived'=>1]);
    $log=ph_insert('work_logs',['task_id'=>$old,'log_date'=>$day]);
    ph_insert('worklog_notes',['worklog_id'=>$log,'content'=>'已按计划执行']);
    ph_insert('worklog_notes',['worklog_id'=>$log,'content'=>'已按计划执行']);
    $timed=ph_insert('tasks',['user_id'=>$user,'name'=>'有原时间的旧计划']);
    $timedLog=ph_insert('work_logs',['task_id'=>$timed,'log_date'=>$day]);
    ph_insert('worklog_notes',['worklog_id'=>$timedLog,'content'=>'已按计划执行（09:00–10:00）']);
    ph_insert('worklog_notes',['worklog_id'=>$timedLog,'content'=>'已按计划执行（14:00-15:00）']);
    $modern=ph_insert('tasks',['user_id'=>$user,'name'=>'已保留完成状态的计划']);
    $modernLog=ph_insert('work_logs',['task_id'=>$modern,'log_date'=>$day]);
    ph_insert('worklog_notes',['worklog_id'=>$modernLog,'content'=>'已按计划执行']);
    ph_insert('plans',['task_id'=>$modern,'planned_date'=>$day,'is_completed'=>1]);
    $plain=ph_insert('tasks',['user_id'=>$user,'name'=>'普通工作量']);
    $plainLog=ph_insert('work_logs',['task_id'=>$plain,'log_date'=>$day]);
    foreach (['讨论计划','未按计划执行','已按计划执行吗','已按计划执行（25:99）'] as $text) ph_insert('worklog_notes',['worklog_id'=>$plainLog,'content'=>$text]);
    $foreign=ph_insert('tasks',['user_id'=>$other,'name'=>'另一个用户']);
    $foreignLog=ph_insert('work_logs',['task_id'=>$foreign,'log_date'=>$day]);
    ph_insert('worklog_notes',['worklog_id'=>$foreignLog,'content'=>'已按计划执行']);

    $beforePlans=(int)$db->query('SELECT COUNT(*) FROM plans')->fetchColumn();
    $beforeLogs=(int)$db->query('SELECT COUNT(*) FROM work_logs')->fetchColumn();
    $events=activity_calendar_events($db,$user,$day,$day);
    $legacy=array_values(array_filter($events,fn($e)=>($e['completion_source']??'')==='execution_note'));
    ph_check(count($legacy)===3,'old all-day and both timed executions projected once');
    $allDay=array_values(array_filter($legacy,fn($e)=>(int)$e['id']===$old));
    ph_check(count($allDay)===1 && $allDay[0]['plan_time']==='' && $allDay[0]['plan_end_time']==='','unknown times remain absent');
    ph_check((int)$allDay[0]['is_completed']===1 && (int)$allDay[0]['archived']===1,'completed history survives task archive');
    ph_check((int)$allDay[0]['source_work_log_id']===$log && $allDay[0]['event_date']===$day,'projection linked to original work record and day');
    ph_check(count(array_unique(array_column($legacy,'completion_key')))===3,'multiple executions have stable distinct keys');
    ph_check(!array_filter($legacy,fn($e)=>(int)$e['id']===$plain || (int)$e['id']===$foreign),'ordinary work and other users are not marked complete');
    ph_check(count(array_filter($events,fn($e)=>$e['event_type']==='plan' && (int)$e['id']===$modern))===1,'saved completed plan is not duplicated by its note');
    ph_check($beforePlans===(int)$db->query('SELECT COUNT(*) FROM plans')->fetchColumn() && $beforeLogs===(int)$db->query('SELECT COUNT(*) FROM work_logs')->fetchColumn(),'reading history never changes plan or workload counts');
    ph_check($events===activity_calendar_events($db,$user,$day,$day),'repeat reads are stable');
    ph_check(!activity_calendar_events($db,$user,'2026-09-22','2026-09-22'),'date filter respected');
    ph_check(activity_execution_time('已按计划执行执行')===['',''],'duplicated execution wording recognised');
    ph_check(activity_execution_time(' 已按计划执行。 ')===['',''],'plain confirmation recognised');
    ph_check(activity_execution_time('还没有按计划执行')===null,'negative wording rejected');
    $db->prepare('DELETE FROM work_logs WHERE id=?')->execute([$log]);
    ph_check(!array_filter(activity_calendar_events($db,$user,$day,$day),fn($e)=>(int)$e['id']===$old),'deleted source history does not reappear');
    echo "PASS: $checks legacy plan history checks\n";
} finally { $db->rollBack(); }
