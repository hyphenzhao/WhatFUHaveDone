<?php
/** Synthetic meeting schedules only. Test users and their records are removed. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/meeting_tasks.php';
require_once __DIR__ . '/../includes/activity_queries.php';
require_once __DIR__ . '/../includes/external_read.php';
$db=get_db(); $users=[]; $checks=0; $day='2031-02-03';
function mt_check(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$message); $checks++; }
function mt_insert(string $table,array $data): int {
    global $db; $s=$db->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')'); $s->execute(array_values($data)); return (int)$db->lastInsertId();
}
function mt_row(string $table,int $id): array { global $db; $s=$db->prepare('SELECT * FROM '.$table.' WHERE id=?');$s->execute([$id]);return $s->fetch(); }
function mt_task(int $uid,string $name,array $extra=[]): int { global $day;return mt_insert('tasks',array_merge(['user_id'=>$uid,'name'=>$name,'task_type'=>'meeting','meeting_auto_from'=>$day],$extra)); }
function mt_plan(int $task,string $from='',string $to='',?string $date=null): int { global $day;return mt_insert('plans',['task_id'=>$task,'planned_date'=>$date??$day,'plan_time'=>$from,'plan_end_time'=>$to]); }
function mt_now(string $time): DateTimeImmutable { return new DateTimeImmutable($time,new DateTimeZone(TIMEZONE)); }
function mt_logs(int $task): int { global $db;$s=$db->prepare('SELECT COUNT(*) FROM work_logs WHERE task_id=?');$s->execute([$task]);return (int)$s->fetchColumn(); }
function mt_http($c,string $path,string $method='GET',?array $body=null): array {
    curl_setopt_array($c,[CURLOPT_URL=>'http://127.0.0.1:8080'.$path,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_POSTFIELDS=>$body===null?null:json_encode($body),CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
    $raw=curl_exec($c);if($raw===false)throw new RuntimeException('HTTP transport failed');return ['status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),'body'=>json_decode($raw,true)];
}
$c=curl_init();curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_COOKIEFILE=>'']);
try {
    $password=bin2hex(random_bytes(24));$username='meeting_test_'.bin2hex(random_bytes(8));
    $uid=mt_insert('users',['username'=>$username,'password_hash'=>password_hash($password,PASSWORD_DEFAULT)]);$users[]=$uid;
    $other=mt_insert('users',['username'=>'meeting_other_'.bin2hex(random_bytes(8)),'password_hash'=>'not-a-login']);$users[]=$other;
    $timed=mt_task($uid,'定时会议');$p=mt_plan($timed,'09:00','10:00');
    $log=mt_insert('work_logs',['task_id'=>$timed,'log_date'=>$day,'duration'=>'2h']);
    $note=mt_insert('worklog_notes',['worklog_id'=>$log,'content'=>'保留的会议材料']);
    $attachment=mt_insert('attachments',['user_id'=>$uid,'entity_type'=>'worklog_note','entity_id'=>$note,'file_name'=>'synthetic.txt','stored_name'=>'meeting-test-no-file-'.bin2hex(random_bytes(8))]);
    $normal=mt_task($uid,'一般任务',['task_type'=>'general','meeting_auto_from'=>null]);$normalPlan=mt_plan($normal,'09:00','10:00');
    $allDay=mt_task($uid,'全天会议');$allDayPlan=mt_plan($allDay);
    $startOnly=mt_task($uid,'只有开始时间');$startPlan=mt_plan($startOnly,'09:00');
    $overnight=mt_task($uid,'跨午夜会议');$nightPlan=mt_plan($overnight,'23:00','01:00');
    $invalid=mt_task($uid,'错误时间');$invalidPlan=mt_plan($invalid,'09:00','25:00');
    $archived=mt_task($uid,'归档会议',['archived'=>1]);$archivedPlan=mt_plan($archived,'09:00','10:00');
    $failed=mt_task($uid,'已取消会议',['stage'=>'failed']);$failedPlan=mt_plan($failed,'09:00','10:00');
    $old=mt_task($uid,'启用之前的计划');$oldPlan=mt_plan($old,'09:00','10:00','2031-02-02');
    $series=mt_task($uid,'多场会议');$seriesFirst=mt_plan($series,'09:00','10:00');$seriesLast=mt_plan($series,'13:00','14:00','2031-02-04');
    $foreign=mt_task($other,'另一用户的会议');$foreignPlan=mt_plan($foreign,'09:00','10:00');
    mt_check(meeting_plan_end(mt_row('plans',$nightPlan))->format('Y-m-d H:i')==='2031-02-04 01:00','overnight end is on the next day');
    mt_check(meeting_plan_end(mt_row('plans',$startPlan))->format('Y-m-d H:i')==='2031-02-04 00:00','missing end does not mean the start is the end');
    mt_check(meeting_plan_end(mt_row('plans',$invalidPlan))===null,'invalid time cannot auto-complete');
    mt_check(complete_due_meetings($db,$uid,mt_now($day.' 09:59:59'))['completed']===0,'not completed before end');
    $out=complete_due_meetings($db,$uid,mt_now($day.' 10:00:00'));
    mt_check($out['completed']===2 && $out['tasks_completed']===1 && !$out['errors'],'each due meeting is completed at its own end');
    $done=mt_row('plans',$p);
    mt_check($done['plan_time']==='09:00' && $done['plan_end_time']==='10:00' && $done['completed_at']===$day.' 10:00:00','planned times retained and completion time is scheduled end');
    mt_check(mt_row('tasks',$timed)['stage']==='completed','single-meeting task completes');
    mt_check(mt_row('tasks',$series)['stage']==='in_progress' && !(int)mt_row('plans',$seriesLast)['is_completed'],'future meeting keeps recurring task active');
    mt_check(mt_logs($timed)===1 && mt_row('work_logs',$log)['duration']==='2h','existing workload preserved');
    mt_check(mt_row('worklog_notes',$note)['content']==='保留的会议材料' && mt_row('attachments',$attachment)['entity_id']==$note,'notes and attachment preserved');
    mt_check(complete_due_meetings($db,$uid,mt_now($day.' 10:00:00'))['completed']===0 && mt_logs($timed)===1,'repeated worker run is idempotent');
    foreach ([$normalPlan,$archivedPlan,$failedPlan,$oldPlan,$foreignPlan,$invalidPlan] as $id) mt_check(!(int)mt_row('plans',$id)['is_completed'],'ineligible/other-user plan '.$id.' unchanged');
    mt_check(complete_plan($db,$other,$p)===null,'manual completion cannot cross users');
    $calendar=activity_calendar_events($db,$uid,$day,$day);$byId=array_column(array_filter($calendar,fn($e)=>$e['event_type']==='plan'),null,'plan_id');
    mt_check($byId[$p]['task_type']==='meeting' && $byId[$p]['plan_time']==='09:00' && (int)$byId[$p]['is_completed']===1,'calendar carries classification, completion and schedule');
    complete_due_meetings($db,$uid,mt_now($day.' 23:59:59'));
    mt_check(!(int)mt_row('plans',$allDayPlan)['is_completed'] && !(int)mt_row('plans',$startPlan)['is_completed'],'unknown end waits until the day is over');
    complete_due_meetings($db,$uid,mt_now('2031-02-04 00:00:00'));
    mt_check((int)mt_row('plans',$allDayPlan)['is_completed']===1 && (int)mt_row('plans',$startPlan)['is_completed']===1 && !(int)mt_row('plans',$nightPlan)['is_completed'],'all-day completion at midnight does not finish overnight meeting early');
    complete_due_meetings($db,$uid,mt_now('2031-02-04 01:00:00'));
    mt_check((int)mt_row('plans',$nightPlan)['is_completed']===1,'overnight meeting completes at next-day end');
    $s=$db->prepare('SELECT log_date FROM work_logs WHERE task_id=?');$s->execute([$overnight]);mt_check($s->fetchColumn()===$day,'overnight workload remains on the original plan date');
    complete_due_meetings($db,$uid,mt_now('2031-02-04 14:00:00'));
    mt_check(mt_row('tasks',$series)['stage']==='completed' && mt_logs($series)===2,'final meeting completes task and retains both activity dates');
    $new=add_task_plan($db,$uid,['task_id'=>$series,'planned_date'=>'2031-02-05','plan_time'=>'09:00','plan_end_time'=>'10:00']);
    mt_check(mt_row('tasks',$series)['stage']==='in_progress' && !(int)$new['is_completed'],'new meeting reopens a completed meeting task');
    mt_check(add_task_plan($db,$other,['task_id'=>$series,'planned_date'=>'2031-02-05'])===null,'plan creation checks ownership');
    $outOfOrder=mt_task($uid,'乱序录入的会议');mt_plan($outOfOrder,'15:00','16:00');mt_plan($outOfOrder,'11:00','12:00');
    complete_due_meetings($db,$uid,mt_now($day.' 16:00:00'));
    mt_check(mt_row('tasks',$outOfOrder)['stage_changed_at']===$day.' 16:00:00','task completion timestamp uses latest meeting end, not insertion order');

    mt_check(mt_http($c,'/api/auth/login','POST',['username'=>$username,'password'=>$password,'remember'=>false])['status']===200,'HTTP login');
    $created=mt_http($c,'/api/tasks','POST',['name'=>'通过页面接口创建的会议','task_type'=>'meeting']);$apiTask=$created['body']['data'];
    mt_check($created['status']===200 && $apiTask['task_type']==='meeting' && $apiTask['meeting_auto_from']===date('Y-m-d'),'API creates meeting with activation date');
    mt_check(mt_http($c,'/api/tasks','POST',['name'=>'无效分类','task_type'=>'other'])['status']===400,'invalid task type rejected');
    $filtered=mt_http($c,'/api/tasks?task_type=meeting')['body']['data'];mt_check(!array_filter($filtered,fn($t)=>$t['task_type']!=='meeting'),'API classification filter');
    $changed=mt_http($c,'/api/tasks/'.$apiTask['id'],'PUT',['task_type'=>'general'])['body']['data'];
    mt_check($changed['task_type']==='general' && $changed['meeting_auto_from']===null,'changing to general disables automatic completion');
    mt_http($c,'/api/tasks/'.$apiTask['id'],'PUT',['task_type'=>'meeting','stage'=>'completed']);
    $added=mt_http($c,'/api/plans','POST',['task_id'=>$apiTask['id'],'planned_date'=>'2031-03-01','plan_time'=>'09:00','plan_end_time'=>'10:00']);
    mt_check($added['status']===200 && mt_row('tasks',(int)$apiTask['id'])['stage']==='in_progress','HTTP adding meeting reopens its task');
    $rev=mt_http($c,'/api/plans/complete-due','POST',[])['body']['data'];
    mt_check(isset($rev['revision']) && $rev['completed']===0,'page reconciliation uses real time and cannot complete future meetings');
    $reader=new ExternalReader($db,$uid,['work']);
    mt_check($reader->read(['refs'=>['task:'.$apiTask['id']]])['items'][0]['task_type']==='meeting','external AI reads classification');
    mt_check(mt_http($c,'/scripts/meeting_worker.php')['status']===403,'worker cannot run over HTTP');
    $yesterday=date('Y-m-d',strtotime('-1 day'));
    $raceTask=mt_task($uid,'重复自动处理测试',['meeting_auto_from'=>$yesterday]);$racePlan=mt_plan($raceTask,'22:00','23:00',$yesterday);
    $parallel=[];$multi=curl_multi_init();
    foreach([1,2] as $i){$client=curl_init();curl_setopt_array($client,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_COOKIEFILE=>'']);mt_http($client,'/api/auth/login','POST',['username'=>$username,'password'=>$password,'remember'=>false]);curl_setopt_array($client,[CURLOPT_URL=>'http://127.0.0.1:8080/api/plans/complete-due',CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_POSTFIELDS=>'{}']);$parallel[]=$client;curl_multi_add_handle($multi,$client);}
    do{curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,1);}while($running);
    foreach($parallel as $client){mt_check(curl_getinfo($client,CURLINFO_RESPONSE_CODE)===200,'concurrent automatic completion succeeds');curl_multi_remove_handle($multi,$client);curl_close($client);}curl_multi_close($multi);
    $s=$db->prepare('SELECT COUNT(*) FROM worklog_notes n JOIN work_logs w ON w.id=n.worklog_id WHERE w.task_id=?');$s->execute([$raceTask]);
    mt_check(mt_logs($raceTask)===1 && (int)$s->fetchColumn()===1 && (int)mt_row('plans',$racePlan)['is_completed']===1,'overlapping page and worker checks append exactly one activity and note');
    $twoTask=mt_task($uid,'并行完成同一天的两场会议',['meeting_auto_from'=>$yesterday]);$twoPlans=[mt_plan($twoTask,'09:00','10:00',$yesterday),mt_plan($twoTask,'14:00','15:00',$yesterday)];
    $parallel=[];$multi=curl_multi_init();
    foreach($twoPlans as $pid){$client=curl_init();curl_setopt_array($client,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_COOKIEFILE=>'']);mt_http($client,'/api/auth/login','POST',['username'=>$username,'password'=>$password,'remember'=>false]);curl_setopt_array($client,[CURLOPT_URL=>'http://127.0.0.1:8080/api/plans/'.$pid.'/complete',CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_POSTFIELDS=>'{}']);$parallel[]=$client;curl_multi_add_handle($multi,$client);}
    do{curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,1);}while($running);
    foreach($parallel as $client){mt_check(curl_getinfo($client,CURLINFO_RESPONSE_CODE)===200,'different meetings complete concurrently');$reply=json_decode(curl_multi_getcontent($client),true);mt_check((int)$reply['data']['is_completed']===1 && $reply['data']['work_log_id']>0,'concurrent response reflects committed completion and workload');curl_multi_remove_handle($multi,$client);curl_close($client);}curl_multi_close($multi);
    $s=$db->prepare('SELECT COUNT(*) FROM worklog_notes n JOIN work_logs w ON w.id=n.worklog_id WHERE w.task_id=?');$s->execute([$twoTask]);
    mt_check(mt_row('tasks',$twoTask)['stage']==='completed' && mt_logs($twoTask)===1 && (int)$s->fetchColumn()===2,'last concurrent meeting sees the committed state of the first');
    echo "PASS: $checks meeting checks\n";
} finally {
    curl_close($c);
    foreach($users as $id){$db->prepare('DELETE FROM attachments WHERE user_id=?')->execute([$id]);$db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);}
}
