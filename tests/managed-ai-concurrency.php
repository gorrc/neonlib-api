<?php
require dirname(__DIR__).'/src/ApiException.php';require dirname(__DIR__).'/src/ManagedAiService.php';
use NeonLib\ManagedAiService;use NeonLib\ApiException;
$_ENV['WORDPRESS_CLIENT_SECRET']=str_repeat('test-only-',5);
$config=['enabled'=>true,'key'=>'fake','model'=>'openai/gpt-oss-120b','daily'=>2,'budget'=>10.0,'inputRate'=>0.15,'outputRate'=>0.60];
if (($argv[1]??'')==='worker') {
 $db=new PDO('sqlite:'.$argv[2].'/db.sqlite');
 $service=new ManagedAiService($db,$argv[2],$config,function(){usleep(200000);return ['choices'=>[['message'=>['content'=>'test']]],'usage'=>['prompt_tokens'=>100,'completion_tokens'=>10]];});
 try {$service->complete($argv[3],['messages'=>[['role'=>'user','content'=>'test']]]);echo 'OK';}catch(ApiException $e){echo $e->errorCode;}exit;
}
$dir=sys_get_temp_dir().'/neonlib-ai-concurrency-'.bin2hex(random_bytes(6));mkdir($dir);
$db=new PDO('sqlite:'.$dir.'/db.sqlite');$db->exec("CREATE TABLE accounts(account_id TEXT,status TEXT); CREATE TABLE external_account_links(account_id TEXT,provider TEXT,verified_at TEXT,revoked_at TEXT); INSERT INTO accounts VALUES('one','ACTIVE'); INSERT INTO external_account_links VALUES('one','wordpress','yes',NULL)");
$service=new ManagedAiService($db,$dir,$config);$verifier=bin2hex(random_bytes(32));$pair=$service->start(['challenge'=>hash('sha256',$verifier)],'ip');$service->approve($pair['id'],$pair['code'],'one');$token=$service->poll(['id'=>$pair['id'],'verifier'=>$verifier])['token'];
$children=[];for($i=0;$i<8;$i++){$proc=proc_open([PHP_BINARY,'-d','xdebug.mode=off',__FILE__,'worker',$dir,$token],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$proc,$pipes];}
$results=[];foreach($children as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);if($code!==0||$err!=='')throw new Exception('Worker failed');$results[]=$out;}
if(count(array_filter($results,fn($v)=>$v==='OK'))!==2||count(array_filter($results,fn($v)=>$v==='ai_daily_quota'))!==6||$service->usage($token)['used']!==2)throw new Exception('Concurrent quota exceeded');
$service=null;$db=null;gc_collect_cycles();unlink($dir.'/state.json');unlink($dir.'/db.sqlite');rmdir($dir);echo "PASS: 8 concurrent requests, exactly 2 accepted, 6 stopped before Groq\n";
