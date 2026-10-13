<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use NeonLib\MobileTransferService;
use NeonLib\ApiException;
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE accounts(account_id TEXT,status TEXT); CREATE TABLE external_account_links(account_id TEXT,provider TEXT,revoked_at TEXT,verified_at TEXT); CREATE TABLE publishers(owner_account_id TEXT,name TEXT);
INSERT INTO accounts VALUES('owner','ACTIVE'),('other','ACTIVE'); INSERT INTO external_account_links VALUES('owner','wordpress',NULL,'verified'),('other','wordpress',NULL,'verified'); INSERT INTO publishers VALUES('owner','Publisher');");
$directory=__DIR__.'/storage-'.bin2hex(random_bytes(4));
$service=new MobileTransferService($db,$directory,str_repeat('s',64));
$count=0;
function check(bool $value):void {global $count;if(!$value)throw new RuntimeException('Failed check '.($count+1));$count++;}
function reject(callable $call,int $status):void {try{$call();}catch(ApiException $e){check($e->statusCode===$status);return;}throw new RuntimeException('Expected '.$status);}
function expire(string $dir,string $id):void {$path=$dir.'/session-'.$id.'.json';$row=json_decode(file_get_contents($path),true);$row['pairExpiresAt']=time()-1;$row['expiresAt']=time()-1;file_put_contents($path,json_encode($row));}
$nonce=str_repeat('a',64);
$payload=['collection'=>['title'=>'Personal collection','description'=>'Example','language'=>'en'],'documents'=>[['id'=>'doc','title'=>'Document','content'=>'Hello world']]];
try {
 $session=$service->create('owner');$id=$session['sessionId'];
 check($session['status']==='WAITING' && strlen(str_replace('-','',$session['manualCode']))===12);
 reject(fn()=>$service->owned('other',$id),404);
 reject(fn()=>$service->claim(['sessionId'=>$id,'pairingToken'=>str_repeat('0',64),'clientNonce'=>$nonce],'ip1'),401);
 $pair=$service->claim($session['pairing']+['clientNonce'=>$nonce],'ip1');
 check($pair['recipient']['publisherName']==='Publisher');
 check($service->claim($session['pairing']+['clientNonce'=>$nonce],'ip1')['uploadToken']===$pair['uploadToken']);
 reject(fn()=>$service->claim($session['pairing']+['clientNonce'=>str_repeat('b',64)],'ip1'),409);
 reject(fn()=>$service->upload($id,'invalid',$payload),401);
 $bad=$payload;$bad['accountId']='other';reject(fn()=>$service->upload($id,$pair['uploadToken'],$bad),422);
 $bad=$payload;$bad['documents'][]=$bad['documents'][0];reject(fn()=>$service->upload($id,$pair['uploadToken'],$bad),422);
 $uploaded=$service->upload($id,$pair['uploadToken'],$payload);
 check($uploaded['status']==='UPLOADED' && $uploaded['documentCount']===1 && $uploaded['vectorStatus']==='ABSENT');
 check($service->upload($id,$pair['uploadToken'],$payload)===$uploaded);
 $different=$payload;$different['documents'][0]['content']='Changed';reject(fn()=>$service->upload($id,$pair['uploadToken'],$different),409);
 check($service->owned('owner',$id,'draft')['draft']===$payload);
 check(!isset($service->owned('owner',$id)['uploadHash']));
 $db->exec("UPDATE accounts SET status='SUSPENDED' WHERE account_id='owner'");
 reject(fn()=>$service->owned('owner',$id),403);reject(fn()=>$service->upload($id,$pair['uploadToken'],$payload),403);
 $db->exec("UPDATE accounts SET status='ACTIVE' WHERE account_id='owner'; UPDATE external_account_links SET revoked_at='revoked' WHERE account_id='owner'");
 reject(fn()=>$service->upload($id,$pair['uploadToken'],$payload),403);
 $db->exec("UPDATE external_account_links SET revoked_at=NULL WHERE account_id='owner'");
 $service->owned('owner',$id,'cancel');reject(fn()=>$service->upload($id,$pair['uploadToken'],$payload),401);
 check(!is_file($directory.'/draft-'.$id.'.json'));
 $manual=$service->create('owner');
 $paired=$service->claim(['manualCode'=>strtolower($manual['manualCode']),'clientNonce'=>$nonce],'ip2');check($paired['sessionId']===$manual['sessionId']);
 expire($directory,$manual['sessionId']);reject(fn()=>$service->upload($manual['sessionId'],$paired['uploadToken'],$payload),409);
 $vector=$service->create('owner');$pair=$service->claim($vector['pairing']+['clientNonce'=>$nonce],'ip3');
 $prepared=['profile'=>['modelSha256'=>str_repeat('a',64),'tokenizerSha256'=>str_repeat('b',64),'dimensions'=>768,'sequenceLength'=>1024,'bos'=>2,'pad'=>0,'chunkMaxTokens'=>450,'chunkOverlapTokens'=>60,'queryPrefix'=>'task: search result | query: ','documentPrefix'=>'title: none | text: '], 'documents'=>$payload['documents']];
 $prepared['documents'][0]['chunks']=[['chunkIndex'=>0,'text'=>'Hello world','vector'=>array_fill(0,768,0.01)]];
 $bad=$payload;$bad['prepared']=$prepared;$bad['prepared']['documents'][0]['chunks'][0]['vector']=[0];reject(fn()=>$service->upload($vector['sessionId'],$pair['uploadToken'],$bad),422);
 $withVectors=$payload+['prepared'=>$prepared];
 check($service->upload($vector['sessionId'],$pair['uploadToken'],$withVectors)['vectorStatus']==='UNVERIFIED');
 check($service->owned('owner',$vector['sessionId'],'draft')['draft']['prepared']===$prepared);
 expire($directory,$vector['sessionId']);check($service->owned('owner',$vector['sessionId'])['status']==='UPLOADED');
 check(count($service->drafts('owner'))===1);check($service->drafts('other')===[]);
 check((new MobileTransferService($db,$directory,str_repeat('s',64)))->owned('owner',$vector['sessionId'],'draft')['draft']['prepared']===$prepared);
 check($service->cleanup()>=1);check(is_file($directory.'/draft-'.$vector['sessionId'].'.json'));check($service->drafts('owner')[0]['expiresAt']===null);
 reject(fn()=>$service->owned('other',$vector['sessionId'],'draft'),404);
 for($i=0;$i<10;$i++)reject(fn()=>$service->claim(['manualCode'=>'FFFFFFFFFFFF','clientNonce'=>$nonce],'limited-ip'),404);
 reject(fn()=>$service->claim(['manualCode'=>'FFFFFFFFFFFF','clientNonce'=>$nonce],'limited-ip'),429);
 $service->create('owner');$service->create('owner');reject(fn()=>$service->create('owner'),429);
 reject(fn()=>$service->owned('owner','../private'),404);
 echo "PASS: $count checks (ownership, pairing, retry, cancellation, expiry, revoked accounts, rate limits, vectors).\n";
} finally {foreach(glob($directory.'/*')?:[] as $file)unlink($file);if(is_dir($directory))rmdir($directory);}
