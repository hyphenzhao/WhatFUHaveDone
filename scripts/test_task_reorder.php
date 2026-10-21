<?php
/** Complete-order API regressions; isolated users are removed in finally. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__.'/../includes/db.php';
$db=get_db();$users=[];$checks=0;
function reorder_check(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;}
function reorder_insert(string $table,array $data): int {global $db;$s=$db->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')');$s->execute(array_values($data));return (int)$db->lastInsertId();}
function reorder_priorities(int $uid): array {global $db;$s=$db->prepare('SELECT id,priority,archived,stage FROM tasks WHERE user_id=? ORDER BY id');$s->execute([$uid]);return $s->fetchAll();}
$realBefore=hash('sha256',json_encode(reorder_priorities(1)));
$c=curl_init();curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_COOKIEFILE=>'']);
function reorder_http(string $path,string $method,array $data): array {global $c;curl_setopt_array($c,[CURLOPT_URL=>'http://127.0.0.1:8080'.$path,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);$raw=curl_exec($c);if($raw===false)throw new RuntimeException('HTTP failed');return ['status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),'body'=>json_decode($raw,true)];}
try {
    $password=bin2hex(random_bytes(18));$username='reorder_test_'.bin2hex(random_bytes(8));
    $uid=reorder_insert('users',['username'=>$username,'password_hash'=>password_hash($password,PASSWORD_DEFAULT)]);$users[]=$uid;
    $other=reorder_insert('users',['username'=>'reorder_other_'.bin2hex(random_bytes(8)),'password_hash'=>'not-a-login']);$users[]=$other;
    $a=reorder_insert('tasks',['user_id'=>$uid,'name'=>'排序示例甲','priority'=>10]);
    $b=reorder_insert('tasks',['user_id'=>$uid,'name'=>'排序示例乙','priority'=>30,'stage'=>'completed']);
    $d=reorder_insert('tasks',['user_id'=>$uid,'name'=>'排序示例丙','priority'=>70]);
    $archive=reorder_insert('tasks',['user_id'=>$uid,'name'=>'不参与的归档任务','priority'=>9,'archived'=>1]);
    $foreign=reorder_insert('tasks',['user_id'=>$other,'name'=>'另一用户任务','priority'=>50]);
    reorder_check(reorder_http('/api/auth/login','POST',['username'=>$username,'password'=>$password,'remember'=>false])['status']===200,'test login');
    $r=reorder_http('/api/tasks/reorder','PUT',['ids'=>[$d,$a,$b]]);
    reorder_check($r['status']===200 && $r['body']['data']['ids']===[$d,$a,$b],'complete reorder accepted');
    $rows=array_column(reorder_priorities($uid),null,'id');
    reorder_check((int)$rows[$d]['priority']===1 && (int)$rows[$a]['priority']===2 && (int)$rows[$b]['priority']===3,'last-to-first priorities become exactly 1,2,3');
    reorder_check((int)$rows[$archive]['priority']===9 && $rows[$b]['stage']==='completed','archived priority and task stages are preserved');
    $baseline=json_encode(reorder_priorities($uid));
    foreach ([[$a,$d],[$a,$a,$d],[$a,$b,$foreign],[$a,$b,$archive],[$a,$b,999999999],['bad',$a,$b],[$a,$b,1.5]] as $ids) {
        $r=reorder_http('/api/tasks/reorder','PUT',['ids'=>$ids]);
        reorder_check(in_array($r['status'],[400,409],true),'invalid or incomplete reorder rejected');
        reorder_check(json_encode(reorder_priorities($uid))===$baseline,'rejected reorder makes no partial priority changes');
    }
    foreach (['not-an-array',[],['first'=>$a,'second'=>$b,'third'=>$d]] as $ids) reorder_check(reorder_http('/api/tasks/reorder','PUT',['ids'=>$ids])['status']===400,'invalid array shape rejected');
    $new=reorder_insert('tasks',['user_id'=>$uid,'name'=>'新建任务使旧列表失效','priority'=>80]);
    $withNew=json_encode(reorder_priorities($uid));
    reorder_check(reorder_http('/api/tasks/reorder','PUT',['ids'=>[$d,$a,$b]])['status']===409,'stale list after a concurrent creation is rejected');
    reorder_check(json_encode(reorder_priorities($uid))===$withNew,'stale reorder does not renumber remaining tasks');
    reorder_check(reorder_http('/api/tasks/reorder','PUT',['ids'=>[$b,$new,$a,$d]])['status']===200,'updated complete list can be saved');
    $rows=array_column(reorder_priorities($uid),null,'id');
    reorder_check((int)$rows[$b]['priority']===1 && (int)$rows[$new]['priority']===2 && (int)$rows[$d]['priority']===4,'second complete order persists');
    $hidden=reorder_insert('tasks',['user_id'=>$uid,'name'=>'筛选后隐藏的已完成任务','priority'=>80,'stage'=>'completed']);
    $before=[$a,$hidden,$new,$b,$d];
    reorder_check(reorder_http('/api/tasks/reorder','PUT',['ids'=>$before])['status']===200,'interleaved fixture order saved');
    $filteredOrder=[$d,$hidden,$a,$b,$new];
    reorder_check(reorder_http('/api/tasks/reorder','PUT',['ids'=>$filteredOrder,'previous_ids'=>$before])['status']===200,'filtered order merged into the complete list accepted');
    $rows=array_column(reorder_priorities($uid),null,'id');
    reorder_check((int)$rows[$hidden]['priority']===2 && (int)$rows[$b]['priority']===4 && (int)$rows[$d]['priority']===1 && (int)$rows[$new]['priority']===5,'hidden completed tasks keep their original slots');
    $unchanged=json_encode(reorder_priorities($uid));
    reorder_check(reorder_http('/api/tasks/reorder','PUT',['ids'=>$before,'previous_ids'=>$before])['status']===409,'concurrent priority changes invalidate an old full-order snapshot');
    reorder_check(json_encode(reorder_priorities($uid))===$unchanged,'stale order does not overwrite a newer reorder');
    reorder_check(reorder_http('/api/tasks/reorder','PUT',['ids'=>$before,'previous_ids'=>'bad'])['status']===400,'malformed previous order rejected');
    reorder_check((int)reorder_priorities($other)[0]['priority']===50 && hash('sha256',json_encode(reorder_priorities(1)))===$realBefore,'real and other-user priorities are untouched');
    echo "PASS: $checks task reorder API / ownership / stale-list checks\n";
} finally {
    curl_close($c);
    if($db->inTransaction())$db->rollBack();
    foreach(array_reverse($users) as $id)$db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
}
