<?php
declare(strict_types=1);
$testRoot=is_dir(__DIR__.'/src')?__DIR__:dirname(__DIR__);
require is_file($testRoot.'/src/ApiException.php')?$testRoot.'/src/ApiException.php':'C:/wamp64/www/mobileai-api/src/ApiException.php';
require $testRoot.'/src/PreparedVectorStore.php';
require $testRoot.'/src/PreparedVectorRepository.php';
require $testRoot.'/src/SubscriptionRepository.php';
require $testRoot.'/src/VectorJobs.php';
use NeonLib\PreparedVectorStore;
use NeonLib\PreparedVectorRepository;
use NeonLib\ApiException;
$directory=__DIR__.'/test-storage-'.bin2hex(random_bytes(4));
$store=new PreparedVectorStore($directory);
$source=['packageId'=>'test-package','version'=>1,'documents'=>[['id'=>'doc','title'=>'Title','content'=>'Hello world']]];
$prepared=['profile'=>['modelSha256'=>str_repeat('a',64),'tokenizerSha256'=>str_repeat('b',64),
    'dimensions'=>768,'sequenceLength'=>1024,'bos'=>2,'pad'=>0,'chunkMaxTokens'=>450,'chunkOverlapTokens'=>60,
    'queryPrefix'=>'task: search result | query: ','documentPrefix'=>'title: none | text: '],
    'documents'=>$source['documents']];
$prepared['documents'][0]['chunks']=[['chunkIndex'=>0,'text'=>'Hello world','vector'=>array_fill(0,768,0.01)]];
$count=0;
function check(bool $ok):void {global $count; if(!$ok) throw new RuntimeException('Check failed '.($count+1)); $count++;}
function rejects(callable $operation,int $code):void {try {$operation();}catch(ApiException $error){check($error->statusCode===$code);return;}throw new RuntimeException('Expected rejection');}
try {
    check($store->attach($source)===$source);
    $store->save($source,$prepared);
    check(isset($store->attach($source)['documents'][0]['chunks']));
    $changed=$source; $changed['documents'][0]['content']='Different text';
    check($store->attach($changed)===$changed);
    $changed=$source; $changed['version']=2;
    check($store->attach($changed)===$changed);
    foreach(['text','dimension','zero','nan','document','profile','index'] as $kind){
        $bad=$prepared;
        switch($kind){
            case 'text':$bad['documents'][0]['chunks'][0]['text']='Unrelated';break;
            case 'dimension':array_pop($bad['documents'][0]['chunks'][0]['vector']);break;
            case 'zero':$bad['documents'][0]['chunks'][0]['vector']=array_fill(0,768,0);break;
            case 'nan':$bad['documents'][0]['chunks'][0]['vector'][0]=NAN;break;
            case 'document':$bad['documents'][0]['id']='other';break;
            case 'profile':$bad['profile']['dimensions']=384;break;
            case 'index':$bad['documents'][0]['chunks'][0]['chunkIndex']=1;break;
        }
        rejects(fn()=>$store->save($source,$bad),422);
    }
    $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE subscriptions(id INTEGER,package_id TEXT); CREATE TABLE subscription_versions(id INTEGER,subscription_id INTEGER,version_number INTEGER,status TEXT); CREATE TABLE subscription_documents(id INTEGER,version_id INTEGER,document_key TEXT,title TEXT,content TEXT,sort_order INTEGER); CREATE TABLE admin_audit_log(user_id INTEGER,event_type TEXT,package_id TEXT,ip_address TEXT,details TEXT);
        INSERT INTO subscriptions VALUES(1,'test-package');INSERT INTO subscription_versions VALUES(1,1,1,'DRAFT');INSERT INTO subscription_documents VALUES(1,1,'doc','Title','Hello world',0);");
    $repository=new PreparedVectorRepository($db,$store);
    check($repository->upload('test-package',1,$prepared,1)['prepared']===true);
    check((int)$db->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn()===1);
    $jobs=new NeonLib\VectorJobs($db,$store,$directory);
    $queued=$jobs->enqueue('test-package',1,1);
    check($queued['status']==='QUEUED');
    check($jobs->enqueue('test-package',1,1)['id']===$queued['id']);
    $claimed=$jobs->claim();check($claimed['source']===$source);
    check($jobs->claim()===null);
    check(!isset($jobs->status('test-package',1)['lease']));
    $queuePath=$directory.'/queue.json';
    $queue=json_decode(file_get_contents($queuePath),true,32,JSON_THROW_ON_ERROR);
    $queue[$queued['id']]['leaseUntil']=time()-1;
    file_put_contents($queuePath,json_encode($queue,JSON_THROW_ON_ERROR));
    rejects(fn()=>$jobs->update($queued['id'],['lease'=>$claimed['lease']],1),409);
    $oldLease=$claimed['lease'];$claimed=$jobs->claim();
    check($claimed['lease']!==$oldLease);
    rejects(fn()=>$jobs->update($queued['id'],['lease'=>'incorrect','status'=>'DONE','prepared'=>$prepared],1),409);
    check($jobs->update($queued['id'],['lease'=>$claimed['lease'],'status'=>'RUNNING','progress'=>1,'total'=>1],1)['progress']===1);
    check($jobs->update($queued['id'],['lease'=>$claimed['lease'],'status'=>'DONE','prepared'=>$prepared],1)['status']==='DONE');
    rejects(fn()=>$jobs->update($queued['id'],['lease'=>$claimed['lease'],'status'=>'DONE','prepared'=>$prepared],1),409);
    $retry=$jobs->enqueue('test-package',1,1);check($retry['id']!==$queued['id']);
    $claimed=$jobs->claim();
    $db->exec("UPDATE subscription_versions SET status='PUBLISHED'");
    rejects(fn()=>$jobs->update($retry['id'],['lease'=>$claimed['lease'],'status'=>'DONE','prepared'=>$prepared],1),409);
    check($jobs->update($retry['id'],['lease'=>$claimed['lease'],'status'=>'FAILED','error'=>'secret text'],1)['error']==='Preparation failed. Check the desktop worker and retry.');
    rejects(fn()=>$jobs->enqueue('test-package',1,1),409);
    rejects(fn()=>$repository->upload('test-package',1,$prepared,1),409);
    rejects(fn()=>$repository->upload('test-package',2,$prepared,1),404);
    check(!$db->inTransaction());
    $db->exec("ALTER TABLE subscriptions ADD title TEXT DEFAULT 'Title'; ALTER TABLE subscriptions ADD description TEXT DEFAULT 'Description'; ALTER TABLE subscriptions ADD language TEXT DEFAULT 'en'; ALTER TABLE subscriptions ADD publisher_id INTEGER DEFAULT 1; ALTER TABLE subscriptions ADD published_version_id INTEGER DEFAULT 1; ALTER TABLE subscriptions ADD visibility TEXT DEFAULT 'PUBLIC'; ALTER TABLE subscriptions ADD status TEXT DEFAULT 'PUBLISHED';
        ALTER TABLE subscription_versions ADD document_count INTEGER DEFAULT 1; ALTER TABLE subscription_versions ADD content_bytes INTEGER DEFAULT 0; ALTER TABLE subscription_versions ADD content_sha256 TEXT DEFAULT ''; ALTER TABLE subscription_versions ADD published_at TEXT DEFAULT ''; CREATE TABLE publishers(id INTEGER,slug TEXT,name TEXT);INSERT INTO publishers VALUES(1,'test','Test');");
    $_ENV['PREPARED_VECTORS_DIR']=$directory;
    $subscriptions=new NeonLib\SubscriptionRepository($db);
    $payload=json_encode($subscriptions->content('test-package'),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $manifest=$subscriptions->manifest('test-package');
    check($manifest['contentHash']===hash('sha256',$payload));
    check($manifest['sizeBytes']===strlen($payload));
    file_put_contents($directory.'/'.hash('sha256','test-package').'.1.json','{broken');
    check($store->attach($source)===$source);
    echo "Prepared vector checks passed: $count\n";
} finally {foreach(glob($directory.'/*')?:[] as $file)unlink($file);if(is_dir($directory))rmdir($directory);}
