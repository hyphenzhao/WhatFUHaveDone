<?php
/** Pure protocol checks. No network, user data or real API keys. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__.'/../includes/ai_client.php';
$checks=0;
function aic_check(bool $ok,string $message): void { global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$message);$checks++; }
function aic_reject(callable $fn,string $message): void { try{$fn();}catch(InvalidArgumentException $e){aic_check(true,$message);return;}throw new RuntimeException('FAIL: '.$message); }
$ds=['provider'=>'deepseek','endpoint'=>'https://api.deepseek.com/v1','model'=>'deepseek-chat','api_key'=>'fixture-deepseek'];
$open=['provider'=>'openai','endpoint'=>'https://api.openai.com/v1','model'=>'gpt-5','api_key'=>'fixture-openai'];
$anth=['provider'=>'anthropic','endpoint'=>'https://api.anthropic.com/v1','model'=>'fixture-claude','api_key'=>'fixture-anthropic'];
$tools=[['type'=>'function','function'=>['name'=>'list_tasks','description'=>'List tasks','parameters'=>['type'=>'object','properties'=>new stdClass()]]]];
$messages=[['role'=>'system','content'=>'identity'],['role'=>'user','content'=>'hello']];
$request=ai_build_request($ds,$messages,$tools);
aic_check($request['body']===['model'=>'deepseek-chat','stream'=>false,'messages'=>$messages,'max_tokens'=>AI_MAX_TOKENS,'temperature'=>AI_TEMPERATURE,'tools'=>$tools],'DeepSeek payload and thinking-compatible message shape unchanged');
aic_check($request['url']==='https://api.deepseek.com/v1/chat/completions' && $request['headers']===['Content-Type: application/json','Authorization: Bearer fixture-deepseek'],'DeepSeek endpoint and authentication unchanged');
$thinking=['role'=>'assistant','content'=>'','reasoning_content'=>'DS reasoning','tool_calls'=>[['id'=>'call_1','type'=>'function','function'=>['name'=>'list_tasks','arguments'=>'{}']]],'_anthropic_content'=>[['type'=>'thinking','signature'=>'opaque','thinking'=>'reasoning']]];
$r=ai_build_request($ds,[$thinking]);aic_check($r['body']['messages'][0]['reasoning_content']==='DS reasoning' && !isset($r['body']['messages'][0]['_anthropic_content']),'preserve DS reasoning, remove foreign metadata');
$r=ai_build_request($open,array_merge($messages,[$thinking]));
aic_check(isset($r['body']['max_completion_tokens']) && !isset($r['body']['max_tokens']) && !isset($r['body']['temperature']),'OpenAI uses modern token limit and no unsupported sampling parameter');
aic_check($r['body']['messages'][0]['role']==='developer' && !isset($r['body']['messages'][2]['reasoning_content']),'OpenAI reasoning roles and cross-provider history');
aic_check(!isset(ai_build_request($open,$messages)['body']['tools']),'plain completions omit empty tools');
foreach(['openai','anthropic'] as $provider){$c=$provider==='openai'?$open:$anth;$c['endpoint']='https://provider.example';aic_check(str_contains(ai_endpoint_url($c),'/v1/'),'official protocol root URL adds v1');}
foreach([$ds,$open,$anth] as $c){$full=ai_endpoint_url($c);$c['endpoint']=$full.'/';aic_check(ai_endpoint_url($c)===$full,'full request URL is not duplicated');}
$custom=$ds;$custom['provider']='openai_compatible';$custom['endpoint']='http://127.0.0.1:1234/proxy/v1';aic_check(ai_endpoint_url($custom)==='http://127.0.0.1:1234/proxy/v1/chat/completions','custom proxy path is retained');
aic_reject(fn()=>ai_endpoint_url(['endpoint'=>'file:///tmp/key','provider'=>'openai']),'non-HTTP URL rejected');
aic_reject(fn()=>ai_endpoint_url(['endpoint'=>'https://user:pass@example.test/v1','provider'=>'openai']),'URL credentials rejected');
aic_reject(fn()=>ai_endpoint_url(['endpoint'=>'https://example.test/v1/messages','provider'=>'openai']),'wrong protocol endpoint rejected');
$turn=['role'=>'assistant','content'=>'checking','tool_calls'=>[
 ['id'=>'one','type'=>'function','function'=>['name'=>'list_tasks','arguments'=>'{}']],
 ['id'=>'two','type'=>'function','function'=>['name'=>'list_tasks','arguments'=>'{"archived":1}']],
]];
$history=array_merge($messages,[$turn,['role'=>'tool','tool_call_id'=>'one','content'=>'[]'],['role'=>'tool','tool_call_id'=>'two','content'=>'{"error":"rejected"}']]);
$r=ai_build_request($anth,$history,$tools);
aic_check($r['body']['system']==='identity' && count($r['body']['messages'])===3,'Anthropic system prompt moves out of message turns');
aic_check($r['headers']===['Content-Type: application/json','anthropic-version: 2023-06-01','x-api-key: fixture-anthropic'],'Anthropic native auth and version');
aic_check(!isset($r['body']['temperature']) && $r['body']['max_tokens']===AI_MAX_TOKENS,'Anthropic supports models without sampling parameters');
aic_check($r['body']['tools'][0]['input_schema']['type']==='object' && !isset($r['body']['tools'][0]['function']),'function schemas become native input_schema');
aic_check($r['body']['messages'][1]['content'][1]['type']==='tool_use' && is_object($r['body']['messages'][1]['content'][1]['input']),'tool call names, ids and empty object arguments converted');
$results=$r['body']['messages'][2]['content'];
aic_check(count($results)===2 && $results[0]['tool_use_id']==='one' && $results[1]['tool_use_id']==='two','parallel tool results share one user turn in order');
aic_check($results[1]['is_error']===true,'rejected operations are tool errors');
$data=['type'=>'message','model'=>'fixture-claude','stop_reason'=>'tool_use','usage'=>['input_tokens'=>11,'output_tokens'=>7,'cache_read_input_tokens'=>3,'cache_creation_input_tokens'=>5],'content'=>[
 ['type'=>'thinking','signature'=>'opaque-signature','thinking'=>'signed thought'],['type'=>'text','text'=>'checking'],['type'=>'tool_use','id'=>'one','name'=>'list_tasks','input'=>[]]
]];
$normalized=ai_parse_response($anth,$data);
aic_check($normalized['tool_calls'][0]['function']['arguments']==='{}' && $normalized['tool_calls'][0]['id']==='one','native tool call normalized for existing handlers');
aic_check($normalized['_usage']===['prompt_tokens'=>19,'completion_tokens'=>7,'total_tokens'=>26],'native and cached token usage normalized');
aic_check($normalized['_finish_reason']==='tool_calls' && $normalized['content']==='checking','native text and stop reason normalized');
$r=ai_build_request($anth,[$messages[0],$messages[1],$normalized,['role'=>'tool','tool_call_id'=>'one','content'=>'[]']]);
aic_check($r['body']['messages'][1]['content'][0]=== $data['content'][0],'signed thinking blocks survive tool round trips unchanged');
$nested=$normalized;$nested['tool_calls'][0]['function']['arguments']='{"filters":{},"ids":[]}';
$nested=json_decode(json_encode($nested),true);
$nestedWire=ai_build_request($anth,[$messages[0],$messages[1],$nested]);
$nestedArgs=$nestedWire['body']['messages'][1]['content'][2]['input'];
aic_check(is_object($nestedArgs->filters) && is_array($nestedArgs->ids),'nested object and array argument types survive browser confirmation');
$data['stop_reason']='max_tokens';$data['content']=[['type'=>'text','text'=>'short']];$n=ai_parse_response($anth,$data);
aic_check($n['_finish_reason']==='length' && !isset($n['_anthropic_content']),'truncation guard works; no redundant raw final reply stored');
$wire=['model'=>'deepseek-chat','choices'=>[['message'=>['content'=>'answer','reasoning_content'=>'reason','tool_calls'=>$turn['tool_calls']],'finish_reason'=>'tool_calls']],'usage'=>['prompt_tokens'=>2]];
$n=ai_parse_response($ds,$wire);aic_check($n['content']==='answer' && $n['reasoning_content']==='reason' && $n['tool_calls']===$turn['tool_calls'] && $n['_usage']===$wire['usage'],'DeepSeek response and tool calls unchanged');
$new=ai_config_candidate($ds,['api_key'=>'','model'=>'another-model']);aic_check($new['api_key']===$ds['api_key'] && $new['model']==='another-model','saving model with blank key retains existing key');
$new=ai_config_candidate($ds,['endpoint'=>ai_endpoint_url($ds),'api_key'=>'']);aic_check($new['api_key']===$ds['api_key'],'equivalent full/base URL retains key');
aic_check(ai_config_candidate($ds,['api_key'=>'***seek'])['api_key']===$ds['api_key'],'legacy masked config clients retain the original key');
aic_reject(fn()=>ai_config_candidate($ds,['provider'=>'anthropic','endpoint'=>$anth['endpoint'],'model'=>$anth['model'],'api_key'=>'']),'cannot leak saved key to a different provider');
aic_reject(fn()=>ai_config_candidate($ds,['endpoint'=>'https://other.example/v1','api_key'=>'']),'cannot leak saved key to a different destination');
aic_reject(fn()=>ai_config_candidate($ds,['api_key'=>"fixture\r\nBad: value"]),'header injection rejected');
aic_reject(fn()=>ai_config_candidate($ds,['provider'=>'unknown']),'unsupported provider rejected');
aic_reject(fn()=>ai_config_candidate($ds,['model'=>'']),'missing model rejected');
$new=ai_config_candidate($ds,['provider'=>'ollama','endpoint'=>'http://localhost:11434/v1','model'=>'local','api_key'=>'']);aic_check($new['api_key']==='','local model needs no key and does not inherit a cloud key');
echo "PASS: $checks AI protocol and credential checks\n";
