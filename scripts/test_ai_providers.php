<?php
/** HTTP/tool/config regression tests with a loopback mock and disposable users. No paid API calls. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only');}
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/ai_client.php';
require_once __DIR__.'/../includes/mail/MailAnalyzer.php';
$db=get_db();$users=[];$checks=0;$process=null;
function aip_check(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$message);$checks++;}
function aip_insert(string $table,array $data): int {global $db;$s=$db->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')');$s->execute(array_values($data));return (int)$db->lastInsertId();}
function aip_http($c,string $path,?array $data=null): array {
 curl_setopt_array($c,[CURLOPT_URL=>'http://127.0.0.1:8080'.$path,CURLOPT_CUSTOMREQUEST=>$data===null?'GET':'POST',CURLOPT_POSTFIELDS=>$data===null?null:json_encode($data),CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
 $text=curl_exec($c);if($text===false)throw new RuntimeException('HTTP transport failed');return ['status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),'data'=>json_decode($text,true),'text'=>$text];
}
function aip_config_hash(int $uid): string {global $db;$s=$db->prepare('SELECT provider,endpoint,api_key,model FROM ai_config WHERE user_id=?');$s->execute([$uid]);return hash('sha256',json_encode($s->fetch()));}
function aip_tasks(int $uid): int {global $db;$s=$db->prepare('SELECT COUNT(*) FROM tasks WHERE user_id=?');$s->execute([$uid]);return (int)$s->fetchColumn();}
$c=curl_init();curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>35,CURLOPT_COOKIEFILE=>'']);
$log=tempnam(sys_get_temp_dir(),'worklog_ai_wire_');$serverLog=tempnam(sys_get_temp_dir(),'worklog_ai_server_');
$original=aip_config_hash(1);
try {
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Cannot reserve mock server port');
 $port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
 $process=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,__DIR__.'/fixtures/ai_provider.php'],[0=>['file','/dev/null','r'],1=>['file',$serverLog,'a'],2=>['file',$serverLog,'a']],$pipes,null,['WORKLOG_AI_FIXTURE_LOG'=>$log]);
 if(!is_resource($process))throw new RuntimeException('Cannot start mock provider');
 $ready=false;for($i=0;$i<40;$i++){if($conn=@fsockopen('127.0.0.1',$port,$e,$m,.1)){fclose($conn);$ready=true;break;}usleep(50000);}if(!$ready)throw new RuntimeException('Mock provider did not start');
 $base='http://127.0.0.1:'.$port;
 $password=bin2hex(random_bytes(24));$username='ai_provider_test_'.bin2hex(random_bytes(8));
 $uid=aip_insert('users',['username'=>$username,'password_hash'=>password_hash($password,PASSWORD_DEFAULT)]);$users[]=$uid;
 aip_check(aip_http($c,'/api/auth/login',['username'=>$username,'password'=>$password,'remember'=>false])['status']===200,'login');
 $page=aip_http($c,'/ai-admin');aip_check($page['status']===200 && str_contains($page['text'],'/assets/js/ai-admin.js?') && str_contains($page['text'],'id="aiProviderHint"'),'configuration page loads the shared provider form script');
 $ds=['provider'=>'deepseek','endpoint'=>$base.'/deepseek/v1','model'=>'fixture-text','api_key'=>'fixture-deepseek'];
 aip_check(aip_http($c,'/api/ai/config',$ds)['status']===200,'save DeepSeek');
 $public=aip_http($c,'/api/ai/config')['data']['data'];
 aip_check($public['has_api_key'] && $public['api_key']==='***seek' && !str_contains(json_encode($public),'fixture-deepseek'),'config masks key and exposes only key presence');
 aip_check(count($public['providers'])===5 && isset($public['providers']['openai'],$public['providers']['anthropic']),'provider presets available');
 aip_check(aip_http($c,'/api/ai/config',['model'=>'fixture-text','api_key'=>''])['status']===200 && ai_load_config($db,$uid)['api_key']===$ds['api_key'],'blank save retains saved key');
 $saved=aip_config_hash($uid);
 aip_check(aip_http($c,'/api/ai/config/test',['endpoint'=>$ds['endpoint'],'model'=>'fixture-text','api_key'=>''])['status']===200,'legacy test payload reuses same-destination saved key');
 aip_check(aip_config_hash($uid)===$saved,'testing does not change saved config');
 $anth=['provider'=>'anthropic','endpoint'=>$base.'/anthropic/v1','model'=>'fixture-text','api_key'=>'fixture-anthropic'];
 aip_check(aip_http($c,'/api/ai/config/test',$anth)['status']===200 && aip_config_hash($uid)===$saved,'native draft tests without switching away from DeepSeek');
 $unsafe=$anth;$unsafe['api_key']='';aip_check(aip_http($c,'/api/ai/config/test',$unsafe)['status']===400,'saved DS key cannot be reused by another provider');
 $unsafe=$ds;$unsafe['endpoint']=$base.'/other/v1';$unsafe['api_key']='';aip_check(aip_http($c,'/api/ai/config/test',$unsafe)['status']===400,'saved key cannot be forwarded to a different URL');
 foreach(['deepseek','openai','openai_compatible','anthropic'] as $provider){
  $path=['deepseek'=>'deepseek','openai'=>'openai','openai_compatible'=>'compat','anthropic'=>'anthropic'][$provider];
  $key=['deepseek'=>'fixture-deepseek','openai'=>'fixture-openai','openai_compatible'=>'fixture-compat','anthropic'=>'fixture-anthropic'][$provider];
  $cfg=['provider'=>$provider,'endpoint'=>$base.'/'.$path.'/v1','model'=>'fixture-read','api_key'=>$key];
  aip_check(aip_http($c,'/api/ai/config',$cfg)['status']===200,'save '.$provider);
  $chat=aip_http($c,'/api/ai/chat',['messages'=>[['role'=>'user','content'=>'Read the fixture tasks.']]]);
  aip_check($chat['status']===200 && ($chat['data']['data']['content']??'')==='Fixture OK','tool round trip '.$provider);
  aip_check(in_array('list_tasks',array_column($chat['data']['data']['steps'],'name'),true),'read tool executed '.$provider);
  $cfg['model']='fixture-text';aip_check(ai_complete_text($cfg,[['role'=>'user','content'=>'Plain analysis prompt']])==='Fixture OK','plain completion shared by analysis modules '.$provider);
 }
 $anth['model']='fixture-parallel';aip_http($c,'/api/ai/config',$anth);
 $chat=aip_http($c,'/api/ai/chat',['messages'=>[['role'=>'user','content'=>'Read both fixture lists.']]]);
 aip_check($chat['status']===200 && count(array_filter($chat['data']['data']['steps'],fn($s)=>($s['name']??'')==='list_tasks'))===2,'native parallel tool results merge into one user turn');
 $anth['model']='fixture-write';aip_http($c,'/api/ai/config',$anth);
 $history=[['role'=>'user','content'=>'Create the fixture task.']];
 $pending=aip_http($c,'/api/ai/chat',['messages'=>$history])['data']['data'];
 aip_check($pending['type']==='confirmation' && aip_tasks($uid)===0,'native write waits for user consent');
 aip_check(isset($pending['message']['_anthropic_content']),'native block transcript preserved for confirmation');
 $id=$pending['pending_calls'][0]['id'];
 $confirmed=aip_http($c,'/api/ai/confirm',['messages'=>$history,'message'=>$pending['message'],'confirmations'=>[['id'=>$id,'action'=>'confirm']]]);
 aip_check($confirmed['status']===200 && $confirmed['data']['data']['content']==='Fixture OK' && aip_tasks($uid)===1,'confirmed native write executes and continues chat');
 $pending=aip_http($c,'/api/ai/chat',['messages'=>$history])['data']['data'];$id=$pending['pending_calls'][0]['id'];
 $rejected=aip_http($c,'/api/ai/confirm',['messages'=>$history,'message'=>$pending['message'],'confirmations'=>[['id'=>$id,'action'=>'reject']]]);
 aip_check($rejected['status']===200 && aip_tasks($uid)===1,'rejected native write leaves user data unchanged');
 $anth['model']='fixture-mail';aip_http($c,'/api/ai/config',$anth);
 $account=aip_insert('mail_accounts',['user_id'=>$uid,'email'=>'ai-fixture@example.invalid','enabled'=>0]);
 $folder=aip_insert('mail_folders',['user_id'=>$uid,'account_id'=>$account,'path'=>'INBOX','kind'=>'inbox']);
 $mail=aip_insert('mail_messages',['user_id'=>$uid,'account_id'=>$account,'folder_id'=>$folder,'uid'=>1,'subject'=>'Fixture mail','body_text'=>'Fixture mail body']);
 aip_insert('attachments',['user_id'=>$uid,'entity_type'=>'mail_message','entity_id'=>$mail,'file_name'=>'fixture.txt','stored_name'=>'ai-fixture-no-file','extracted_text'=>'Fixture attachment body']);
 $analysis=(new MailAnalyzer($db,$uid))->analyze($mail);
 aip_check($analysis['brief_title']==='Fixture mail analysis' && $analysis['priority']===2 && $analysis['relevance']===85,'mail analysis works through native protocol');
 $wire=file_get_contents($log);aip_check(str_contains($wire,'Fixture attachment body') && str_contains($wire,'Fixture mail body'),'native mail request includes body and attachment text');
 $anth['model']='fixture-error';$error=aip_http($c,'/api/ai/config/test',$anth);
 aip_check($error['status']===400 && !str_contains($error['text'],$anth['api_key']) && str_contains($error['text'],'redacted'),'provider errors do not expose API keys');
 $anth['model']='fixture-malformed';aip_check(aip_http($c,'/api/ai/config/test',$anth)['status']===400,'malformed successful HTTP response is not a false successful test');
 aip_check(aip_config_hash(1)===$original,'existing real-user AI configuration unchanged');
 echo "PASS: $checks provider/config/chat/mail checks; no paid API calls\n";
} finally {
 curl_close($c);
 foreach($users as $id){$db->prepare('DELETE FROM attachments WHERE user_id=?')->execute([$id]);$db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);}
 if(is_resource($process)){proc_terminate($process);proc_close($process);}
 unlink($log);unlink($serverLog);
}
