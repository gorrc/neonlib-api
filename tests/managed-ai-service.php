<?php
require dirname(__DIR__).'/src/ApiException.php';
require dirname(__DIR__).'/src/ManagedAiService.php';
use NeonLib\ManagedAiService;
use NeonLib\ApiException;
$_ENV['WORDPRESS_CLIENT_SECRET']=str_repeat('test-only-',5);
$db=new PDO('sqlite::memory:');
$db->exec("CREATE TABLE accounts(account_id TEXT,status TEXT); CREATE TABLE external_account_links(account_id TEXT,provider TEXT,verified_at TEXT,revoked_at TEXT); INSERT INTO accounts VALUES('one','ACTIVE'); INSERT INTO external_account_links VALUES('one','wordpress','yes',NULL)");
$now=strtotime('2026-10-13 12:00 UTC'); $calls=0; $last=[];
$config=['enabled'=>true,'key'=>'fake','model'=>'openai/gpt-oss-120b','daily'=>2,'budget'=>10.0,'inputRate'=>0.15,'outputRate'=>0.60];
$dir=sys_get_temp_dir().'/neonlib-ai-test-'.bin2hex(random_bytes(6));
$transport=function($payload)use(&$calls,&$last){$calls++;$last=$payload;return ['choices'=>[['message'=>['content'=>'Jupiter.']]],'usage'=>['prompt_tokens'=>100,'completion_tokens'=>10]];};
$service=new ManagedAiService($db,$dir,$config,$transport,fn()=>$GLOBALS['now']);
$tests=0;
function check($ok){global $tests;if(!$ok)throw new Exception('Assertion failed at '.($tests+1));$tests++;}
function fails($code,$call){try{$call();throw new Exception('Expected '.$code);}catch(ApiException $e){check($e->errorCode===$code);}}
function connect($service){$v=bin2hex(random_bytes(32));$p=$service->start(['challenge'=>hash('sha256',$v)],'127.0.0.1');check($service->poll(['id'=>$p['id'],'verifier'=>$v])['status']==='WAITING');$service->approve($p['id'],$p['code'],'one');return $service->poll(['id'=>$p['id'],'verifier'=>$v])['token'];}
$a=connect($service);$b=connect($service);
fails('ai_sign_in_required',fn()=>$service->usage($a.'x'));
$body=['model'=>'expensive-ignore','messages'=>[['role'=>'system','content'=>'Document only'],['role'=>'user','content'=>'Which planet?']],'max_completion_tokens'=>999999];
$r=$service->complete($a,$body);check($r['quota']['remaining']===1);check($last['model']===$config['model']&&$last['max_completion_tokens']===2048&&$last['messages'][0]['role']==='system'&&$last['messages'][1]['role']==='user');
$service->complete($b,$body);fails('ai_daily_quota',fn()=>$service->complete($a,$body));check($calls===2);
$now+=86400;check($service->usage($b)['remaining']===2);
fails('ai_input_too_large',fn()=>$service->complete($a,['messages'=>[['role'=>'user','content'=>str_repeat('x',33000)]]]));check($calls===2);
$service->revoke($a);fails('ai_sign_in_required',fn()=>$service->usage($a));
$db->exec('UPDATE external_account_links SET revoked_at="now"');fails('account_unavailable',fn()=>$service->usage($b));$db->exec('UPDATE external_account_links SET revoked_at=NULL');
$tiny=$config;$tiny['budget']=0.00001;$limited=new ManagedAiService($db,$dir,$tiny,$transport,fn()=>$GLOBALS['now']);fails('ai_budget_limit',fn()=>$limited->complete($b,$body));check($calls===2);
$disabled=$config;$disabled['enabled']=false;$off=new ManagedAiService($db,$dir,$disabled,$transport);fails('ai_unavailable',fn()=>$off->start(['challenge'=>str_repeat('a',64)],'ip'));
$now+=31*86400;fails('ai_sign_in_required',fn()=>$service->usage($b));

$now=strtotime('2026-11-01 12:00 UTC');
$c=connect($service);
$badVerifier=str_repeat('f',64);$pending=$service->start(['challenge'=>hash('sha256',$badVerifier)],'test');
fails('invalid_device',fn()=>$service->poll(['id'=>$pending['id'],'verifier'=>str_repeat('e',64)]));
fails('invalid_device',fn()=>$service->approve($pending['id'],'wrong','one'));
$failure=new ManagedAiService($db,$dir,$config,fn()=>throw new Exception('Simulated timeout'),fn()=>$GLOBALS['now']);
fails('ai_provider_error',fn()=>$failure->complete($c,$body));
check($service->usage($c)['used']===1);
$stored=json_decode(file_get_contents($dir.'/state.json'),true);check($stored['months']['2026-11']['spent']>0&&!str_contains(file_get_contents($dir.'/state.json'),'Which planet'));
$truncated=new ManagedAiService($db,$dir,$config,fn()=>['choices'=>[['finish_reason'=>'length','message'=>['content'=>'Incomplete']]],'usage'=>['prompt_tokens'=>10,'completion_tokens'=>2048]],fn()=>$GLOBALS['now']);
fails('ai_provider_error',fn()=>$truncated->complete($c,$body));check($service->usage($c)['used']===2);
$now+=86400;
$ocr=$body;$ocr['purpose']='ocr';$service->complete($c,$ocr);check($last['max_completion_tokens']===8192);
$now+=601;fails('ai_connection_expired',fn()=>$service->poll(['id'=>$pending['id'],'verifier'=>$badVerifier]));
unlink($dir.'/state.json');rmdir($dir);echo "$tests assertions passed\n";
