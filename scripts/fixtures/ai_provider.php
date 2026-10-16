<?php
/** Loopback mock provider. Apache must never serve this fixture. */
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) { http_response_code(403); exit('Test fixture only'); }
header('Content-Type: application/json');
function fixture_fail(string $message): void { http_response_code(400);echo json_encode(['error'=>['message'=>$message]]);exit; }
$body=json_decode(file_get_contents('php://input'),true);if(!is_array($body))fixture_fail('Invalid request JSON');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$native=str_starts_with($path,'/anthropic/');$open=str_starts_with($path,'/openai/');
$auth=$native?($_SERVER['HTTP_X_API_KEY']??''):str_replace('Bearer ','',$_SERVER['HTTP_AUTHORIZATION']??'');
$expected=$native?'fixture-anthropic':($open?'fixture-openai':(str_starts_with($path,'/compat/')?'fixture-compat':'fixture-deepseek'));
if($auth!==$expected)fixture_fail('Wrong authentication for selected provider');
if($native){
 if($path!=='/anthropic/v1/messages'||($_SERVER['HTTP_ANTHROPIC_VERSION']??'')!=='2023-06-01'||isset($_SERVER['HTTP_AUTHORIZATION']))fixture_fail('Invalid native endpoint or headers');
 if(!isset($body['max_tokens'])||isset($body['temperature']))fixture_fail('Invalid native parameters');
 foreach($body['messages'] as $m)if(!in_array($m['role'],['user','assistant'],true))fixture_fail('System/tool roles were not converted');
 foreach($body['tools']??[] as $t)if(!isset($t['input_schema'])||isset($t['function']))fixture_fail('Native tool schema missing');
} elseif($open){
 if(!isset($body['max_completion_tokens'])||isset($body['max_tokens'])||isset($body['temperature']))fixture_fail('Invalid OpenAI parameters');
 foreach($body['messages'] as $m)if(isset($m['reasoning_content'])||isset($m['_anthropic_content']))fixture_fail('Foreign metadata leaked');
} elseif(!isset($body['max_tokens'],$body['temperature']))fixture_fail('Legacy-compatible parameters changed');
if($log=getenv('WORKLOG_AI_FIXTURE_LOG'))file_put_contents($log,json_encode(['path'=>$path,'body'=>$body],JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND|LOCK_EX);
$model=$body['model'];
if($model==='fixture-error'){http_response_code(401);echo json_encode(['error'=>['message'=>'Invalid key '.$auth]]);exit;}
if($model==='fixture-malformed'){echo '<html>not JSON</html>';exit;}
$hasResult=false;$resultCount=0;
foreach($body['messages'] as $m){
 if($m['role']==='tool'){$hasResult=true;$resultCount++;}
 foreach(is_array($m['content'])?$m['content']:[] as $block)if(($block['type']??'')==='tool_result'){$hasResult=true;$resultCount++;}
}
$calls=[];
if(!$hasResult&&in_array($model,['fixture-read','fixture-write','fixture-parallel'],true)){
 $calls[]=['id'=>'call_fixture_1','type'=>'function','function'=>['name'=>$model==='fixture-write'?'create_task':'list_tasks','arguments'=>$model==='fixture-write'?'{"name":"Fixture AI-created task"}':'{}']];
 if($model==='fixture-parallel')$calls[]=['id'=>'call_fixture_2','type'=>'function','function'=>['name'=>'list_tasks','arguments'=>'{"archived":1}']];
}
if($native&&$hasResult&&$model==='fixture-parallel'&&$resultCount!==2)fixture_fail('Parallel tool results lost');
$text=$model==='fixture-mail'?json_encode(['brief_title'=>'Fixture mail analysis','summary'=>'Synthetic summary','priority'=>2,'relevance'=>85,'category'=>'work','needs_reply'=>true,'deadline_hint'=>'','detailed_md'=>'Prepare fixture materials.','actions'=>['Prepare materials']],JSON_UNESCAPED_UNICODE):'Fixture OK';
if($native){
 $content=$calls?[['type'=>'text','text'=>'I will use the requested tool.']]:[['type'=>'text','text'=>$text]];
 foreach($calls as $c)$content[]=['type'=>'tool_use','id'=>$c['id'],'name'=>$c['function']['name'],'input'=>json_decode($c['function']['arguments'])];
 echo json_encode(['type'=>'message','role'=>'assistant','model'=>$model,'content'=>$content,'stop_reason'=>$calls?'tool_use':'end_turn','usage'=>['input_tokens'=>15,'output_tokens'=>8]],JSON_UNESCAPED_UNICODE);
}else{
 $message=['role'=>'assistant','content'=>$calls?'I will use the requested tool.':$text];if($calls)$message['tool_calls']=$calls;
 if(!$open)$message['reasoning_content']='Fixture reasoning';
 echo json_encode(['model'=>$model,'choices'=>[['message'=>$message,'finish_reason'=>$calls?'tool_calls':'stop']],'usage'=>['prompt_tokens'=>15,'completion_tokens'=>8,'total_tokens'=>23]],JSON_UNESCAPED_UNICODE);
}
