<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE subscriptions (package_id TEXT,status TEXT,visibility TEXT,published_version_id INTEGER)');
$db->exec('CREATE TABLE subscription_versions (id INTEGER,status TEXT)');
$db->exec("INSERT INTO subscription_versions VALUES (1,'PUBLISHED'),(2,'DRAFT')");
$db->exec("INSERT INTO subscriptions VALUES ('test.public','PUBLISHED','PUBLIC',1),('test.archived','ARCHIVED','PUBLIC',1),('test.private','PUBLISHED','PRIVATE',1),('test.draft','DRAFT','PUBLIC',1),('test.badversion','PUBLISHED','PUBLIC',2)");
$repo=new NeonLib\SubscriptionRepository($db);
$rows=$repo->availability('test.public,test.archived,test.private,test.draft,test.badversion,test.missing');
foreach($rows as $row) {
    $expected=$row['packageId']==='test.public'?'AVAILABLE':'UNAVAILABLE';
    if($row['status']!==$expected || count($row)!==2) throw new RuntimeException('Incorrect availability or leaked metadata');
}
$db->exec("UPDATE subscriptions SET status='ARCHIVED' WHERE package_id='test.public'");
if($repo->availability('test.public')[0]['status']!=='UNAVAILABLE') throw new RuntimeException('Archive not observed');
$db->exec("UPDATE subscriptions SET status='PUBLISHED' WHERE package_id='test.public'");
if($repo->availability('test.public')[0]['status']!=='AVAILABLE') throw new RuntimeException('Republication not observed');
foreach(['','../private',implode(',',array_map(fn($n)=>'test.'.$n,range(1,51)))] as $invalid) {
    try { $repo->availability($invalid); throw new RuntimeException('Accepted invalid input'); }
    catch(NeonLib\ApiException $e) { if($e->statusCode!==422) throw $e; }
}
echo "Availability tests passed: published, archived, private, draft, missing, invalid version, restored and validation.\n";
