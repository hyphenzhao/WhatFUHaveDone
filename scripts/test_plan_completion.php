<?php
/** Synthetic HTTP tests; no user documents or real files are changed. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/external_read.php';
$db=get_db(); $users=[]; $checks=0; $day='2026-10-12';
function pc_check(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$message); $checks++; }
function pc_insert(string $table,array $data): int {
    global $db; $st=$db->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')'); $st->execute(array_values($data)); return (int)$db->lastInsertId();
}
function pc_count(string $table,string $field,int $id): int { global $db; $st=$db->prepare("SELECT COUNT(*) FROM $table WHERE $field=?");$st->execute([$id]);return (int)$st->fetchColumn(); }
function pc_note_attachments(int $uid,int $note): int { global $db; $st=$db->prepare("SELECT COUNT(*) FROM attachments WHERE user_id=? AND entity_type='worklog_note' AND entity_id=?");$st->execute([$uid,$note]);return (int)$st->fetchColumn(); }
function pc_request($curl,string $path,?array $data=null,string $method='GET'): array {
    curl_setopt_array($curl,[CURLOPT_URL=>'http://127.0.0.1:8080'.$path,CURLOPT_CUSTOMREQUEST=>$data===null?$method:'POST',CURLOPT_POSTFIELDS=>$data===null?null:json_encode($data),CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
    $text=curl_exec($curl); if ($text===false) throw new RuntimeException('HTTP transport failed');
    return ['status'=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>json_decode($text,true)];
}
function pc_client() { $c=curl_init();curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_COOKIEFILE=>'']);return $c; }
$client=pc_client(); $parallel=[];
try {
    $password=bin2hex(random_bytes(24)); $username='plan_test_'.bin2hex(random_bytes(8));
    $uid=pc_insert('users',['username'=>$username,'password_hash'=>password_hash($password,PASSWORD_DEFAULT)]);$users[]=$uid;
    $other=pc_insert('users',['username'=>'plan_other_'.bin2hex(random_bytes(8)),'password_hash'=>password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT)]);$users[]=$other;
    $task=pc_insert('tasks',['user_id'=>$uid,'name'=>'合成计划任务']);
    $task2=pc_insert('tasks',['user_id'=>$uid,'name'=>'新工作记录任务']);
    $foreignTask=pc_insert('tasks',['user_id'=>$other,'name'=>'其他用户任务']);
    $log=pc_insert('work_logs',['task_id'=>$task,'log_date'=>$day,'duration'=>'2h']);
    $note=pc_insert('worklog_notes',['worklog_id'=>$log,'content'=>'必须保留的原备注']);
    pc_insert('attachments',['user_id'=>$uid,'entity_type'=>'worklog_note','entity_id'=>$note,'file_name'=>'synthetic.txt','stored_name'=>'plan-test-no-file-'.bin2hex(random_bytes(8))]);
    $p1=pc_insert('plans',['task_id'=>$task,'planned_date'=>$day,'plan_time'=>'09:00','plan_end_time'=>'10:00']);
    $p2=pc_insert('plans',['task_id'=>$task,'planned_date'=>$day,'plan_time'=>'14:00','plan_end_time'=>'15:00']);
    $p3=pc_insert('plans',['task_id'=>$task2,'planned_date'=>$day,'plan_time'=>'10:00']);
    $foreign=pc_insert('plans',['task_id'=>$foreignTask,'planned_date'=>$day]);
    pc_check(pc_request($client,'/api/auth/login',['username'=>$username,'password'=>$password,'remember'=>false])['status']===200,'login');
    $response=pc_request($client,"/api/plans/$p1/complete",[]);$first=$response['body']['data'];
    pc_check($response['status']===200 && (int)$first['is_completed']===1,'complete saves state');
    pc_check($first['plan_time']==='09:00' && $first['plan_end_time']==='10:00' && $first['completed_at'],'schedule and timestamp retained');
    pc_check($first['work_log_id']===$log && pc_count('work_logs','task_id',$task)===1,'existing work log reused');
    pc_check(pc_count('worklog_notes','id',$note)===1 && pc_note_attachments($uid,$note)===1,'notes and attachment preserved');
    pc_check(pc_count('worklog_notes','worklog_id',$log)===2,'one completion note appended');
    $executionNote=$db->prepare('SELECT content FROM worklog_notes WHERE worklog_id=? AND id<>?');$executionNote->execute([$log,$note]);
    pc_check($executionNote->fetchColumn()==='已按计划执行（09:00–10:00）','execution history also keeps the original scheduled range');
    $again=pc_request($client,"/api/plans/$p1/complete",[])['body']['data'];
    pc_check($again['completed_at']===$first['completed_at'] && pc_count('worklog_notes','worklog_id',$log)===2,'retry is idempotent');
    $daily=pc_request($client,'/api/stats?type=daily&date='.$day)['body']['data']; $plans=array_column($daily['plan_tasks'],null,'plan_id');
    pc_check(count($plans)===3 && (int)$plans[$p1]['is_completed']===1 && (int)$plans[$p2]['is_completed']===0,'same-day plans have independent state');
    $events=pc_request($client,'/api/stats?type=calendar&month=2026-10')['body']['data']['days'][$day];
    $events=array_values(array_filter($events,fn($e)=>$e['event_type']==='plan'));
    pc_check(count($events)===3 && count(array_unique(array_column($events,'plan_id')))===3,'calendar exposes every plan id');
    $calendarPlans=array_column($events,null,'plan_id');
    pc_check($calendarPlans[$p1]['plan_time']==='09:00' && $calendarPlans[$p1]['plan_end_time']==='10:00','completed calendar entry keeps both scheduled times');
    pc_check(pc_request($client,"/api/plans/$foreign/complete",[])['status']===404,'foreign plan cannot complete');
    pc_check(pc_count('work_logs','task_id',$foreignTask)===0,'foreign workload unaffected');
    $result=pc_request($client,"/api/plans/$p3/complete",[])['body']['data'];
    pc_check($result['work_log_id']>0 && pc_count('work_logs','task_id',$task2)===1,'new workload created once');
    $external=new ExternalReader($db,$uid,['work']);
    pc_check((int)$external->read(['refs'=>['plan:'.$p1]])['items'][0]['is_completed']===1,'external AI sees completed state');

    // Two independent login sessions complete different plans on one task/day.
    foreach ([$p1,$p2] as $p) {
        $c=pc_client();pc_request($c,'/api/auth/login',['username'=>$username,'password'=>$password,'remember'=>false]);
        curl_setopt_array($c,[CURLOPT_URL=>"http://127.0.0.1:8080/api/plans/$p/complete",CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_POSTFIELDS=>'{}',CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);$parallel[]=$c;
    }
    $multi=curl_multi_init(); foreach ($parallel as $c) curl_multi_add_handle($multi,$c);
    do { curl_multi_exec($multi,$running); if ($running) curl_multi_select($multi,1); } while ($running);
    foreach ($parallel as $c) { pc_check(curl_getinfo($c,CURLINFO_RESPONSE_CODE)===200,'parallel completion succeeds');curl_multi_remove_handle($multi,$c); } curl_multi_close($multi);
    pc_check(pc_count('work_logs','task_id',$task)===1 && pc_count('worklog_notes','worklog_id',$log)===3,'concurrent plans share workload without duplicate retries');
    $deleted=pc_request($client,"/api/plans/$p1",null,'DELETE');
    pc_check($deleted['status']===200 && pc_count('plans','id',$p1)===0,'explicit deletion removes plan');
    pc_check(pc_count('worklog_notes','worklog_id',$log)===3 && pc_note_attachments($uid,$note)===1,'plan deletion preserves workload history');
    echo "PASS: $checks plan completion checks\n";
} finally {
    curl_close($client); foreach ($parallel as $c) curl_close($c);
    foreach ($users as $id) { $db->prepare('DELETE FROM attachments WHERE user_id=?')->execute([$id]);$db->prepare('DELETE FROM users WHERE id=?')->execute([$id]); }
}
