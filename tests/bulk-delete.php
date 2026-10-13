<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
function checkDeletion(bool $ok): void { if(!$ok) throw new RuntimeException('Bulk deletion assertion failed'); }
function rejected(callable $fn, int $code): void {
    try { $fn(); } catch(NeonLib\ApiException $e) { checkDeletion($e->statusCode===$code); return; }
    throw new RuntimeException('Expected rejection');
}
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE subscriptions(id INTEGER PRIMARY KEY,package_id TEXT UNIQUE)');
$db->exec('CREATE TABLE subscription_versions(id INTEGER PRIMARY KEY,subscription_id INTEGER REFERENCES subscriptions(id) ON DELETE CASCADE)');
$db->exec('CREATE TABLE subscription_documents(id INTEGER PRIMARY KEY,version_id INTEGER REFERENCES subscription_versions(id) ON DELETE CASCADE)');
$db->exec('CREATE TABLE admin_audit_log(user_id INTEGER,event_type TEXT,package_id TEXT,ip_address TEXT,details TEXT)');
$db->exec("INSERT INTO subscriptions VALUES(1,'test.one'),(2,'test.two'),(3,'test.keep')");
$db->exec('INSERT INTO subscription_versions VALUES(1,1),(2,2),(3,3)');
$db->exec('INSERT INTO subscription_documents VALUES(1,1),(2,2),(3,3)');
$repo=new NeonLib\AdminApiRepository($db,1);
rejected(fn()=>$repo->deleteSubscriptions(['package_ids'=>['test.one']]),422);
rejected(fn()=>$repo->deleteSubscriptions(['package_ids'=>[],'confirm'=>true]),422);
rejected(fn()=>$repo->deleteSubscriptions(['package_ids'=>['test.one','missing.id'],'confirm'=>true]),409);
checkDeletion((int)$db->query('SELECT COUNT(*) FROM subscriptions')->fetchColumn()===3);
$result=$repo->deleteSubscriptions(['package_ids'=>['test.one','test.two','test.one'],'confirm'=>true]);
checkDeletion($result['deleted']===2);
foreach(['subscriptions','subscription_versions','subscription_documents'] as $table) checkDeletion((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===1);
checkDeletion((int)$db->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn()===2);
$db->exec('DROP TABLE admin_audit_log');
try { $repo->deleteSubscriptions(['package_ids'=>['test.keep'],'confirm'=>true]); throw new RuntimeException('Expected audit failure'); }
catch(PDOException $e) {}
checkDeletion((int)$db->query('SELECT COUNT(*) FROM subscription_documents')->fetchColumn()===1);
echo "Bulk delete tests passed: confirmation, selection, cascade, stale list, audit and rollback.\n";
