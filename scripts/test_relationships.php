<?php
/** Synthetic HTTP regression for aggregated relationship counts. No model/IMAP calls. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/db.php';
$db=get_db(); $users=[]; $checks=0;
function rel_insert(string $table,array $values): int {
    global $db; $st=$db->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',array_fill(0,count($values),'?')).')');
    $st->execute(array_values($values)); return (int)$db->lastInsertId();
}
function rel_check(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$message); $checks++; }
$ch=curl_init(); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_COOKIEFILE=>'']);
function rel_http(string $path,?array $data=null): array {
    global $ch; curl_setopt_array($ch,[CURLOPT_URL=>'http://127.0.0.1:8080'.$path,CURLOPT_CUSTOMREQUEST=>$data===null?'GET':'POST',CURLOPT_POSTFIELDS=>$data===null?null:json_encode($data),CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
    $raw=curl_exec($ch); if ($raw===false) throw new RuntimeException('HTTP transport error');
    return [curl_getinfo($ch,CURLINFO_RESPONSE_CODE),json_decode($raw,true)];
}
try {
    $password=bin2hex(random_bytes(24)); $username='graph_test_'.bin2hex(random_bytes(8));
    $uid=rel_insert('users',['username'=>$username,'password_hash'=>password_hash($password,PASSWORD_DEFAULT)]); $users[]=$uid;
    $other=rel_insert('users',['username'=>'graph_other_'.bin2hex(random_bytes(8)),'password_hash'=>password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT)]); $users[]=$other;
    rel_check(rel_http('/api/relationships')[0]===401,'authentication required');
    rel_insert('people',['user_id'=>$uid,'name'=>'合成测试本人','is_me'=>1]);
    $a=rel_insert('people',['user_id'=>$uid,'name'=>'同事甲','closeness'=>4]);
    $b=rel_insert('people',['user_id'=>$uid,'name'=>'同事乙']);
    $c=rel_insert('people',['user_id'=>$uid,'name'=>'只有成果记录']);
    $idle=rel_insert('people',['user_id'=>$uid,'name'=>'未关联记录']);
    $hidden=rel_insert('people',['user_id'=>$uid,'name'=>'归档人物','archived'=>1]);
    $foreign=rel_insert('people',['user_id'=>$other,'name'=>'其他用户人物']);
    $task=rel_insert('tasks',['user_id'=>$uid,'name'=>'已归档任务','archived'=>1]);
    $active=rel_insert('tasks',['user_id'=>$uid,'name'=>'进行中任务']);
    $resultOnly=rel_insert('tasks',['user_id'=>$uid,'name'=>'仅成果任务']);
    $foreignTask=rel_insert('tasks',['user_id'=>$other,'name'=>'其他用户任务']);
    foreach ([[$task,$a],[$task,$b],[$task,$hidden],[$active,$b],[$resultOnly,$c],[$foreignTask,$foreign]] as [$t,$p]) rel_insert('task_people',['task_id'=>$t,'people_id'=>$p]);
    foreach ([[$task,'2026-10-10'],[$task,'2026-10-11'],[$active,'2026-10-11'],[$foreignTask,'2026-10-11']] as [$t,$day]) rel_insert('work_logs',['task_id'=>$t,'log_date'=>$day]);
    $result=rel_insert('results',['user_id'=>$uid,'name'=>'合成成果']);
    foreach ([$task,$active,$active,$resultOnly] as $t) rel_insert('result_logs',['task_id'=>$t,'result_id'=>$result,'log_date'=>'2026-10-11']);
    rel_check(rel_http('/api/auth/login',['username'=>$username,'password'=>$password,'remember'=>false])[0]===200,'temporary user login');
    [$status,$json]=rel_http('/api/relationships'); rel_check($status===200 && !$json['error'],'API response');
    $nodes=array_column($json['data']['nodes'],null,'id'); $edges=array_column($json['data']['edges'],null,'target');
    rel_check(count($nodes)===5 && count($edges)===3,'only eligible people and observed edges');
    rel_check($nodes[0]['subtitle']==='合成测试本人','self identity');
    rel_check($nodes[$a]['workload']===2 && $nodes[$a]['results']===1,'archived task history included');
    rel_check($nodes[$b]['workload']===3 && $nodes[$b]['results']===3,'aggregate does not multiply workload by outputs');
    rel_check($nodes[$c]['workload']===0 && $nodes[$c]['results']===1 && isset($edges[$c]),'result-only relationship retained');
    rel_check($nodes[$idle]['workload']===0 && !isset($edges[$idle]),'no invented edge for idle person');
    rel_check(!isset($nodes[$hidden]) && !isset($nodes[$foreign]),'archive-person and owner filters');
    rel_check($nodes[$a]['closeness']===4,'person score projection');
    rel_check(rel_http('/relationships')[0]===200,'updated graph page renders');
    rel_http('/api/auth/logout',[]);
    echo "PASS: $checks relationship API checks\n";
} finally { curl_close($ch); foreach ($users as $id) $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]); }
