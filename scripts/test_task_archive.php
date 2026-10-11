<?php
/** End-to-end archive/restore/delete regressions. Synthetic users are removed in finally. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/activity_queries.php';
require_once __DIR__ . '/../includes/profile_context.php';
require_once __DIR__ . '/../includes/external_read.php';
$db=get_db(); $checks=0; $users=[];
// Two synthetic days in the same month, including when the suite runs on day 1.
$day=date('Y-m-15'); $previous=date('Y-m-14');
function archive_check(bool $ok,string $message): void {
    global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$message); $checks++;
}
function archive_insert(string $table,array $row): int {
    global $db; $st=$db->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');
    $st->execute(array_values($row)); return (int)$db->lastInsertId();
}
function archive_user(string $password): array {
    global $users;
    $username='archive_test_'.bin2hex(random_bytes(8));
    $id=archive_insert('users',['username'=>$username,'password_hash'=>password_hash($password,PASSWORD_DEFAULT)]);
    $users[]=$id; return [$id,$username];
}
function archive_count(string $table,string $field,int $id): int {
    global $db; $st=$db->prepare("SELECT COUNT(*) FROM $table WHERE $field=?"); $st->execute([$id]); return (int)$st->fetchColumn();
}
$curl=curl_init(); curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_COOKIEFILE=>'']);
function archive_http(string $path,string $method='GET',?array $body=null): array {
    global $curl;
    curl_setopt_array($curl,[CURLOPT_URL=>'http://127.0.0.1:8080'.$path,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>$body===null?null:json_encode($body)]);
    $raw=curl_exec($curl); if ($raw===false) throw new RuntimeException('HTTP transport failed');
    $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); $json=json_decode($raw,true);
    return ['status'=>$status,'data'=>$json['data']??null,'error'=>$json['error']??true];
}
function archive_data(string $path,string $method='GET',?array $body=null): mixed {
    $r=archive_http($path,$method,$body); archive_check($r['status']===200 && !$r['error'],'HTTP '.$method.' '.$path); return $r['data'];
}
try {
    $password=bin2hex(random_bytes(24)); [$uid,$username]=archive_user($password); [$other]=archive_user(bin2hex(random_bytes(24)));
    $task=archive_insert('tasks',['user_id'=>$uid,'name'=>'归档历史测试','stage'=>'completed','stage_changed_at'=>$day.' 12:00:00']);
    $active=archive_insert('tasks',['user_id'=>$uid,'name'=>'继续进行的任务']);
    $foreign=archive_insert('tasks',['user_id'=>$other,'name'=>'另一用户的任务','archived'=>1]);
    $tag=archive_insert('tags',['user_id'=>$uid,'name'=>'回归测试标签']); archive_insert('task_tags',['task_id'=>$task,'tag_id'=>$tag]);
    foreach (['同事甲','同事乙'] as $name) { $person=archive_insert('people',['user_id'=>$uid,'name'=>$name]); archive_insert('task_people',['task_id'=>$task,'people_id'=>$person]); }
    $work=archive_insert('work_logs',['task_id'=>$task,'log_date'=>$day,'duration'=>'2h']);
    archive_insert('work_logs',['task_id'=>$task,'log_date'=>$previous]);
    archive_insert('work_logs',['task_id'=>$foreign,'log_date'=>$day]);
    $note=archive_insert('worklog_notes',['worklog_id'=>$work,'content'=>'归档后仍可读取的实验记录']);
    $result=archive_insert('results',['user_id'=>$uid,'name'=>'保留的成果实体']);
    $resultLog=archive_insert('result_logs',['task_id'=>$task,'result_id'=>$result,'log_date'=>$day]);
    archive_insert('task_results',['task_id'=>$task,'result_id'=>$result]);
    $plan=archive_insert('plans',['task_id'=>$task,'planned_date'=>$day,'plan_time'=>'09:00','plan_end_time'=>'10:00']);
    foreach ([['task',$task],['worklog_note',$note]] as [$type,$entity]) {
        archive_insert('attachments',['user_id'=>$uid,'entity_type'=>$type,'entity_id'=>$entity,'file_name'=>'synthetic.txt','stored_name'=>'archive-test-no-file-'.bin2hex(random_bytes(8)),'extracted_text'=>'合成附件文本']);
    }
    $account=archive_insert('mail_accounts',['user_id'=>$uid,'email'=>'archive-test@example.invalid','enabled'=>0]);
    $folder=archive_insert('mail_folders',['user_id'=>$uid,'account_id'=>$account,'path'=>'INBOX','kind'=>'inbox']);
    $mail=archive_insert('mail_messages',['user_id'=>$uid,'account_id'=>$account,'folder_id'=>$folder,'uid'=>1,'subject'=>'合成邮件']);
    archive_insert('task_mail_links',['user_id'=>$uid,'task_id'=>$task,'message_id'=>$mail,'status'=>'confirmed']);
    // Empty endpoint fails locally before any model request; report statistics still save.
    archive_insert('ai_config',['user_id'=>$uid,'provider'=>'deepseek','endpoint'=>'','api_key'=>'','model'=>'test-no-ai']);
    archive_data('/api/auth/login','POST',['username'=>$username,'password'=>$password,'remember'=>false]);
    $before=archive_data('/api/stats?type=daily&date='.$day);
    archive_check(count($before['work_tasks'])===1 && count($before['result_tasks'])===1 && count($before['plan_tasks'])===1,'baseline daily activity');
    $reportBefore=archive_data('/api/reports','POST',['period_type'=>'monthly','period_key'=>substr($day,0,7)]);
    $statsBefore=json_decode($reportBefore['stats_json'],true)['stats'];
    archive_check($statsBefore['total_workload']===2 && $statsBefore['total_results']===1 && $statsBefore['plans_count']===1,'baseline period stats');
    archive_data('/api/tasks/'.$task,'PUT',['archived'=>1]);
    $tasks=archive_data('/api/tasks?archived=0');
    archive_check(array_column($tasks,'id')===[$active],'active task lists omit archived task');
    archive_check(array_column(archive_data('/api/tasks?archived=1'),'id')===[$task],'archived list retains task');
    $after=archive_data('/api/stats?type=daily&date='.$day);
    foreach (['work_tasks'=>'work_log_id','result_tasks'=>'result_log_id','plan_tasks'=>'plan_id'] as $key=>$recordKey) {
        archive_check(array_column($after[$key],$recordKey)===array_column($before[$key],$recordKey),'daily '.$key.' records preserved');
        archive_check((int)$after[$key][0]['archived']===1,'daily '.$key.' marked archived');
    }
    $events=archive_data('/api/stats?type=calendar&month='.substr($day,0,7))['days'][$day];
    $types=array_column($events,'event_type'); sort($types);
    archive_check($types===['plan','result','work'],'calendar preserves all activity types');
    archive_check(count(array_unique(array_column($events,'id')))===1 && (int)$events[0]['id']===$task,'calendar excludes foreign user');
    archive_check(count(archive_data('/api/worklogs?task_id='.$task))===2,'worklog history accessible');
    archive_check(count(archive_data('/api/result_logs?task_id='.$task))===1,'result history accessible');
    archive_check(archive_count('worklog_notes','worklog_id',$work)===1 && archive_count('attachments','user_id',$uid)===2,'archive preserves notes and attachments');
    $workRanks=archive_data('/api/stats?type=workload');
    archive_check((int)$workRanks[0]['total_workload']===2,'workload leaderboard retains archived task');
    $resultRanks=archive_data('/api/stats?type=results');
    archive_check((int)$resultRanks[0]['total_results']===1,'result leaderboard retains archived task');
    $detail=archive_data('/api/stats?type=workload_detail&tag_id='.$tag)['tasks'];
    archive_check(count($detail)===1 && (int)$detail[0]['work_days']===2,'detail preserves task without counting beneficiaries twice');
    archive_check(count(archive_data('/api/stats?type=results_detail&tag_id='.$tag)['results'])===1,'results detail preserved');
    $shared=activity_daily_records($db,$uid,$day);
    archive_check(count($shared['work_tasks'])===1 && count($shared['result_tasks'])===1 && count($shared['plan_tasks'])===1,'shared AI activity query retains history');
    $updates=profile_collect_updates($db,$uid,'2000-01-01 00:00:00',10000);
    archive_check($updates['counts']['tasks']===2 && str_contains($updates['text'],'已归档，保留历史记录'),'impression updates retain archived context');
    $external=new ExternalReader($db,$uid,['work']);
    archive_check((int)$external->overview(['date_from'=>$previous,'date_to'=>$day])['work_totals']['task_days']===2,'external API workload unchanged');
    $reportAfter=archive_data('/api/reports','POST',['period_type'=>'monthly','period_key'=>substr($day,0,7)]);
    archive_check(json_decode($reportAfter['stats_json'],true)['stats']===$statsBefore,'period report unchanged after archive');
    archive_check(archive_http('/api/tasks/'.$foreign)['status']===404,'foreign task hidden');
    archive_data('/api/tasks/'.$task,'PUT',['archived'=>0]);
    archive_check(count(archive_data('/api/tasks?archived=0'))===2,'restore returns task to active list');
    archive_check(count(archive_data('/api/stats?type=daily&date='.$day)['work_tasks'])===1,'restore does not duplicate work');
    archive_data('/api/tasks/'.$task,'PUT',['archived'=>1]);
    archive_data('/api/tasks/'.$task,'DELETE');
    foreach (['work_logs','result_logs','plans','task_tags','task_people','task_results','task_mail_links'] as $table) archive_check(archive_count($table,'task_id',$task)===0,'delete cascades '.$table);
    archive_check(archive_count('worklog_notes','id',$note)===0 && archive_count('attachments','user_id',$uid)===0,'delete cleans notes and synthetic attachments');
    archive_check(archive_count('results','id',$result)===1 && archive_count('mail_messages','id',$mail)===1,'delete preserves independent result and mail entities');
    archive_check(!archive_data('/api/stats?type=daily&date='.$day)['work_tasks'],'deleted task absent from historical activity');
    archive_data('/api/auth/logout','POST',[]);
    echo "PASS: $checks archive/restore/delete checks\n";
} finally {
    curl_close($curl);
    foreach ($users as $id) $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    echo "Synthetic users cleaned up\n";
}
