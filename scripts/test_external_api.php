<?php
/** Synthetic records only. DB tests roll back; --http creates/deletes one temporary user. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/external_tools.php';
$db=get_db(); $checks=0;
function check(bool $ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; }
function insertRow(string $table,array $row): int {
    global $db; $st=$db->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');
    $st->execute(array_values($row)); return (int)$db->lastInsertId();
}
function rejects(callable $call,int $status,string $label): void {
    try { $call(); } catch (ExternalApiError $e) { check($e->status===$status,$label.' status'); return; }
    throw new RuntimeException('FAIL: '.$label.' accepted');
}
function testUser(string $password='unusable'): int {
    return insertRow('users',['username'=>'ext_test_'.bin2hex(random_bytes(8)),'password_hash'=>password_hash($password,PASSWORD_DEFAULT)]);
}
$db->beginTransaction();
try {
    $uid=testUser(); $other=testUser(); $today=date('Y-m-d');
    $task=insertRow('tasks',['user_id'=>$uid,'name'=>'研究甲 100%','description'=>str_repeat('研究🙂',120),'priority'=>4]);
    $task2=insertRow('tasks',['user_id'=>$uid,'name'=>'研究乙','description'=>'short']);
    $foreign=insertRow('tasks',['user_id'=>$other,'name'=>'private-foreign']);
    $work=insertRow('work_logs',['task_id'=>$task,'log_date'=>$today,'duration'=>'2h']);
    $note=insertRow('worklog_notes',['worklog_id'=>$work,'content'=>'实验记录']);
    insertRow('plans',['task_id'=>$task,'planned_date'=>$today,'plan_time'=>'09:00','plan_end_time'=>'10:00']);
    $result=insertRow('results',['user_id'=>$uid,'name'=>'实验结果']);
    insertRow('result_logs',['task_id'=>$task,'result_id'=>$result,'log_date'=>$today]);
    insertRow('task_results',['task_id'=>$task,'result_id'=>$result]);
    $tag=insertRow('tags',['user_id'=>$uid,'name'=>'科研']); insertRow('task_tags',['task_id'=>$task,'tag_id'=>$tag]);
    $person=insertRow('people',['user_id'=>$uid,'name'=>'研究同事','bio'=>'仅联系人范围可见']); insertRow('task_people',['task_id'=>$task,'people_id'=>$person]);
    $account=insertRow('mail_accounts',['user_id'=>$uid,'email'=>'synthetic@example.invalid','password_enc'=>'secret-not-readable']);
    $folder=insertRow('mail_folders',['user_id'=>$uid,'account_id'=>$account,'path'=>'INBOX','kind'=>'inbox']);
    $mail=insertRow('mail_messages',['user_id'=>$uid,'account_id'=>$account,'folder_id'=>$folder,'uid'=>1,'subject'=>'实验邀请','is_seen'=>0,'read_at'=>'2001-01-02','msg_date'=>'2000-01-01','body_text'=>str_repeat('原始邮件正文',1200),'snippet'=>'原文节选']);
    insertRow('mail_analysis',['user_id'=>$uid,'message_id'=>$mail,'brief_title'=>'参会准备','summary'=>'明日准备材料','priority'=>1,'relevance'=>90,'detailed_md'=>'请准备论文和报告','status'=>'ok']);
    insertRow('task_mail_links',['user_id'=>$uid,'task_id'=>$task,'message_id'=>$mail,'score'=>90,'status'=>'confirmed']);
    $report=insertRow('reports',['user_id'=>$uid,'title'=>'测试报告','period_type'=>'daily','period_key'=>$today,'period_start'=>$today,'period_end'=>$today,'content_md'=>'报告正文']);
    $doc=insertRow('profile_documents',['user_id'=>$uid,'title'=>'简历','file_name'=>'cv.pdf','stored_name'=>'not-a-file','is_primary'=>1,'extracted_text'=>'权威信息']);
    insertRow('user_profile',['user_id'=>$uid,'name'=>'研究员','resume'=>'简介','goals'=>'目标']);
    insertRow('profile_impressions',['user_id'=>$uid,'field'=>'work_focus','value'=>'研究','evidence'=>'交流']);
    insertRow('profile_impression_snapshots',['user_id'=>$uid,'kind'=>'stage','content_md'=>'旧阶段印象']);
    $base=insertRow('profile_impression_snapshots',['user_id'=>$uid,'kind'=>'base','content_md'=>'重新生成的基础印象']);
    $inc=insertRow('profile_impression_snapshots',['user_id'=>$uid,'kind'=>'incremental','content_md'=>'新增量']);
    $conversation=insertRow('ai_conversations',['user_id'=>$uid,'title'=>'交流','messages_json'=>'[{"role":"user","content":"正文独有关键词"}]','summary_text'=>'已保存的对话摘要']);
    $attachment=insertRow('attachments',['user_id'=>$uid,'entity_type'=>'mail_message','entity_id'=>$mail,'file_name'=>'test.txt','stored_name'=>'not-a-file','extracted_text'=>'附件原文']);
    $reader=new ExternalReader($db,$uid,array_keys(external_scopes()));
    foreach ($reader->kinds() as $kind) {
        $found=$reader->search(['kind'=>$kind]);
        check(count($found['items'])>0,'kind '.$kind.' searchable');
        $one=$reader->read(['refs'=>[$found['items'][0]['ref']]]);
        check(!isset($one['items'][0]['error']),'kind '.$kind.' readable');
    }
    $overview=$reader->execute('overview',[]);
    check($overview['data']['work_totals']['task_days']===1,'work aggregate');
    check($overview['data']['mail']['items'][0]['ref']==='mail:'.$mail,'unread belongs today despite old read_at');
    check($overview['data']['profile_anchor']['items'][0]['ref']==='profile_snapshot:'.$base,'new base supersedes old stage');
    check($overview['data']['profile_increment']['items'][0]['ref']==='profile_snapshot:'.$inc,'increment after anchor');
    $cached=$reader->execute('overview',['if_version'=>$overview['version']]);
    check(isset($cached['unchanged']) && !isset($cached['data']),'version omits duplicate content');
    $db->prepare('UPDATE tasks SET name=? WHERE id=?')->execute(['研究甲已更新',$task]);
    check(isset($reader->execute('overview',['if_version'=>$overview['version']])['data']),'cache invalidated by relevant change');
    $page=$reader->search(['kind'=>'task','limit'=>1]);
    check($page['has_more'] && $page['next_before_id']===$task2,'keyset first page');
    $next=$reader->search(['kind'=>'task','limit'=>1,'before_id'=>$page['next_before_id']]);
    check(!$next['has_more'] && $next['items'][0]['ref']==='task:'.$task,'keyset complete no duplicate');
    check($reader->read(['refs'=>['task:'.$foreign]])['items'][0]['error']==='not_found','cross-user ref hidden');
    check(!$reader->search(['kind'=>'task','query'=>'private-foreign'])['items'],'cross-user search hidden');
    check(!$reader->search(['kind'=>'task','query'=>'%'])['items'],'literal wildcard escaped');
    $chunk=$reader->read(['refs'=>['task:'.$task],'max_chars'=>100])['items'][0];
    check(mb_strlen($chunk['text'])===100 && $chunk['next_offset']===100,'Unicode truncation');
    $tail=$reader->read(['refs'=>['task:'.$task],'offset'=>100,'max_chars'=>8000])['items'][0];
    check($chunk['text'].$tail['text']===str_repeat('研究🙂',120) && $tail['next_offset']===null,'lossless Unicode continuation');
    check(count($chunk['related']['tags'])===1 && count($chunk['related']['results'])===1 && count($chunk['related']['people'])===1 && count($chunk['related']['mail'])===1,'task related evidence');
    $restricted=new ExternalReader($db,$uid,['work','attachments']);
    rejects(fn()=>$restricted->search(['kind'=>'mail']),403,'scope denied');
    check(!$restricted->search(['kind'=>'attachment'])['items'],'attachment requires owner scope');
    check(!isset($restricted->read(['refs'=>['task:'.$task]])['items'][0]['related']['people']),'related contacts scope respected');
    check($reader->read(['refs'=>['profile_document:'.$doc]])['items'][0]['authority']==='user_uploaded','authority annotated');
    check(!$reader->search(['kind'=>'conversation','query'=>'正文独有关键词'])['items'],'conversation search only summary');
    check($reader->read(['refs'=>['mail:'.$mail],'part'=>'analysis'])['items'][0]['text']==='请准备论文和报告','reuse saved analysis');
    $db->prepare('UPDATE mail_messages SET is_seen=1 WHERE id=?')->execute([$mail]);
    check(!$reader->search(['kind'=>'mail','date_from'=>$today,'date_to'=>$today])['items'],'read mail pinned to old read date');
    check(count($reader->search(['kind'=>'mail','date_from'=>'2001-01-02','date_to'=>'2001-01-02'])['items'])===1,'read mail date search');
    rejects(fn()=>$reader->read(['refs'=>['task:'.$task,'mail:'.$mail,'report:'.$report],'max_chars'=>8000]),400,'text budget');
    rejects(fn()=>$reader->overview(['date_from'=>'2026-02-30']),400,'invalid date');
    rejects(fn()=>external_validate_args('read',['refs'=>[['bad']]]),400,'nested ref rejected');
    $key=external_create_token($db,$uid,'test',['work'],1);
    $stored=$db->query('SELECT token_hash FROM external_api_tokens WHERE id='.(int)$key['id'])->fetchColumn();
    check($stored===hash('sha256',$key['token']) && $stored!==$key['token'],'hash at rest');
    check(external_authenticate($db,'Bearer '.$key['token'])['user_id']===$uid,'bearer auth');
    $db->exec('UPDATE external_api_tokens SET request_count=120,window_at=NOW() WHERE id='.(int)$key['id']);
    rejects(fn()=>external_authenticate($db,'Bearer '.$key['token']),429,'rate limit');
    $db->exec('UPDATE external_api_tokens SET revoked_at=NOW() WHERE id='.(int)$key['id']);
    rejects(fn()=>external_authenticate($db,'Bearer '.$key['token']),401,'revoked key');
    $db->exec('UPDATE external_api_tokens SET revoked_at=NULL,expires_at=NOW()-INTERVAL 1 DAY WHERE id='.(int)$key['id']);
    rejects(fn()=>external_authenticate($db,'Bearer '.$key['token']),401,'expired key');
    rejects(fn()=>external_create_token($db,$uid,'bad',[['work']],90),400,'invalid scope input');
    $defs=external_tools($reader); check(count($defs)===3,'three tools only');
    $rpc=external_rpc($reader,['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>'worklog_search','arguments'=>['kind'=>'mail','limit'=>1]]]);
    check($rpc['result']['isError']===false && count($rpc['result']['content'])===1 && !isset($rpc['result']['structuredContent']),'MCP no duplicated payload');
    $bad=external_rpc($reader,['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>'send_email']]);
    check($bad['error']['code']===-32602,'no write tool');
    echo 'Tool definitions: '.strlen(json_encode($defs,JSON_UNESCAPED_UNICODE))." bytes (not tokens)\n";
    echo 'Synthetic overview: '.strlen(json_encode($overview,JSON_UNESCAPED_UNICODE)).' bytes; unchanged response: '.strlen(json_encode($cached))." bytes\n";
} finally { $db->rollBack(); }

if (in_array('--http',$argv,true)) {
    $url='http://127.0.0.1:8080'; $password=bin2hex(random_bytes(24)); $uid=testUser($password);
    $curl=curl_init(); curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_COOKIEFILE=>'']);
    $call=static function(string $path,string $method='GET',?array $data=null,array $headers=[]) use($curl,$url): array {
        curl_setopt_array($curl,[CURLOPT_URL=>$url.$path,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json','Accept: application/json, text/event-stream'],$headers),CURLOPT_POSTFIELDS=>$data===null?null:json_encode($data)]);
        $raw=curl_exec($curl); if ($raw===false) throw new RuntimeException('HTTP test transport failed');
        return [curl_getinfo($curl,CURLINFO_RESPONSE_CODE),json_decode($raw,true),$raw];
    };
    try {
        $task=insertRow('tasks',['user_id'=>$uid,'name'=>'HTTP synthetic task']);
        $key=external_create_token($db,$uid,'http test',['work'],1); $bearer=['Authorization: Bearer '.$key['token']];
        check($call('/api/external/v1/overview')[0]===401,'HTTP missing bearer');
        $http=$call('/api/external/v1/overview','GET',null,$bearer);
        check($http[0]===200 && $http[1]['data']['active_tasks']['items'][0]['ref']==='task:'.$task,'HTTP bearer passed by Apache');
        check($call('/api/external/v1/overview','GET',null,array_merge($bearer,['If-None-Match: "'.$http[1]['version'].'"']))[0]===304,'HTTP ETag');
        check($call('/api/external/v1/read','POST',['refs'=>['task:'.$task]],$bearer)[0]===200,'HTTP batch read');
        check($call('/api/external/v1/search?kind=mail','GET',null,$bearer)[0]===403,'HTTP scope denial');
        check($call('/api/external/v1/overview','DELETE',null,$bearer)[0]===405,'HTTP write method rejected');
        check($call('/api/tasks','POST',['name'=>'MUST NOT EXIST'],$bearer)[0]===401,'bearer cannot authorize browser writes');
        check($call('/api/external/v1/overview','GET',null,array_merge($bearer,['Origin: https://example.invalid']))[0]===403,'HTTP origin rejected');
        check($call('/api/external/mcp','GET',null,$bearer)[0]===405,'HTTP MCP stream method');
        $rpc=['jsonrpc'=>'2.0','id'=>1,'method'=>'initialize','params'=>['protocolVersion'=>'2025-11-25','capabilities'=>new stdClass(),'clientInfo'=>['name'=>'test','version'=>'1']]];
        $init=$call('/api/external/mcp','POST',$rpc,$bearer);
        check($init[0]===200 && $init[1]['result']['protocolVersion']==='2025-06-18','HTTP MCP negotiation');
        $protocol=array_merge($bearer,['MCP-Protocol-Version: 2025-06-18']);
        check($call('/api/external/mcp','POST',['jsonrpc'=>'2.0','method'=>'notifications/initialized'],$protocol)[0]===202,'HTTP notification accepted');
        $list=$call('/api/external/mcp','POST',['jsonrpc'=>'2.0','id'=>2,'method'=>'tools/list'],$protocol);
        check(count($list[1]['result']['tools'])===3,'HTTP tool discovery');
        $callResult=$call('/api/external/mcp','POST',['jsonrpc'=>'2.0','id'=>3,'method'=>'tools/call','params'=>['name'=>'worklog_read','arguments'=>['refs'=>['task:'.$task]]]],$protocol);
        check($callResult[0]===200 && $callResult[1]['result']['isError']===false,'HTTP tool call');
        $username=$db->query('SELECT username FROM users WHERE id='.$uid)->fetchColumn();
        check($call('/api/auth/login','POST',['username'=>$username,'password'=>$password,'remember'=>false])[0]===200,'HTTP temporary user login');
        check($call('/external-access')[0]===200,'access page renders');
        $keys=$call('/api/external_keys'); check($keys[0]===200 && !str_contains($keys[2],$key['token']) && !str_contains($keys[2],'token_hash'),'key list hides secret');
        check($call('/api/external_keys','POST',['name'=>'test','scopes'=>['work'],'days'=>1])[0]===403,'key creation CSRF');
        $csrf=['X-CSRF-Token: '.$keys[1]['data']['csrf']];
        $made=$call('/api/external_keys','POST',['name'=>'test','scopes'=>['work'],'days'=>1],$csrf);
        check($made[0]===200 && str_starts_with($made[1]['data']['token'],'wlr_'),'key creation once');
        check($call('/api/external_keys/'.$made[1]['data']['id'],'DELETE',null,$csrf)[0]===200,'key manager revoke');
        check($call('/api/external/v1/overview','GET',null,['Authorization: Bearer '.$made[1]['data']['token']])[0]===401,'revoked key rejected over HTTP');
        $call('/api/auth/logout','POST',[]);
    } finally {
        curl_close($curl); $db->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
    }
}
echo "PASS: $checks checks; synthetic data cleaned up\n";
