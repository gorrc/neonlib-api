<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use NeonLib\ReportRepository;
use NeonLib\ApiException;

function ensure(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function expectStatus(int $status, callable $call): void {
    try { $call(); } catch (ApiException $e) { ensure($e->statusCode===$status,'Wrong status: '.$e->statusCode); return; }
    throw new RuntimeException('Expected HTTP '.$status);
}
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE content_reports (id INTEGER PRIMARY KEY AUTOINCREMENT,report_id TEXT UNIQUE,payload_hash TEXT,kind TEXT,target_id TEXT,target_label TEXT,content_version INTEGER,reason TEXT,excerpt TEXT,details TEXT,status TEXT DEFAULT "NEW",admin_note TEXT,revision INTEGER DEFAULT 1,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE report_rate_limits (bucket_key TEXT,window_start INTEGER,request_count INTEGER,PRIMARY KEY(bucket_key,window_start))');
$db->exec('CREATE TABLE admin_audit_log (user_id INTEGER,event_type TEXT,package_id TEXT,ip_address TEXT,details TEXT)');
$repo=new ReportRepository($db);
$secret=str_repeat('s',64);
function payload(int $n=1): array { return ['report_id'=>sprintf('00000000-0000-4000-8000-%012d',$n),'kind'=>'AI','target_id'=>'local-answer-id','target_label'=>'AI answer','content_version'=>null,'reason'=>'OTHER','excerpt'=>'','details'=>'']; }
$data=payload();
$receipt=$repo->submit($data,'test-ip',$secret);
ensure($receipt===['report_id'=>$data['report_id'],'received'=>true],'Receipt mismatch');
ensure($repo->submit($data,'test-ip',$secret)===$receipt,'Retry receipt');
ensure((int)$db->query('SELECT COUNT(*) FROM content_reports')->fetchColumn()===1,'Retry duplicated report');
ensure((int)$db->query('SELECT request_count FROM report_rate_limits')->fetchColumn()===1,'Retry consumed rate allowance');
expectStatus(409,fn()=>$repo->submit(array_replace($data,['details'=>'changed']),'test-ip',$secret));
expectStatus(422,fn()=>$repo->submit(array_replace(payload(2),['prompt'=>'private']),'test-ip',$secret));
expectStatus(422,fn()=>$repo->submit(array_replace(payload(2),['details'=>str_repeat('x',2001)]),'test-ip',$secret));
expectStatus(422,fn()=>$repo->submit(array_replace(payload(2),['excerpt'=>str_repeat('x',8001)]),'test-ip',$secret));
expectStatus(422,fn()=>$repo->submit(array_replace(payload(2),['kind'=>'UNKNOWN']),'test-ip',$secret));
expectStatus(422,fn()=>$repo->submit(array_replace(payload(2),['content_version'=>'2']),'test-ip',$secret));
expectStatus(422,fn()=>$repo->submit(array_replace(payload(2),['report_id'=>'invalid']),'test-ip',$secret));
expectStatus(503,fn()=>$repo->submit(payload(2),'test-ip','short'));
foreach (['SUBSCRIPTION','PUBLISHER'] as $i=>$kind) {
    $repo->submit(array_replace(payload($i+2),['kind'=>$kind,'target_id'=>'test.collection','target_label'=>'<script>alert(1)</script>','content_version'=>3,'excerpt'=>'<b>untrusted</b>']),'test-ip',$secret);
}
for ($i=4;$i<=10;$i++) $repo->submit(payload($i),'test-ip',$secret);
expectStatus(429,fn()=>$repo->submit(payload(11),'test-ip',$secret));
$repo->submit(payload(11),'different-ip',$secret);
ensure(count($repo->list([]))===11,'Listing count');
ensure(!isset($repo->list([])[0]['payload_hash']),'Internal hash exposed');
ensure($db->query('SELECT bucket_key FROM report_rate_limits')->fetchColumn()!=='test-ip','Raw IP stored');
$updated=$repo->update($data['report_id'],['status'=>'REVIEWING','admin_note'=>'Private note','revision'=>1],42);
ensure($updated['revision']===2,'Revision not incremented');
expectStatus(409,fn()=>$repo->update($data['report_id'],['status'=>'RESOLVED','admin_note'=>'stale','revision'=>1],42));
ensure(count($repo->list(['status'=>'REVIEWING']))===1,'Status filtering');
$audit=$db->query('SELECT details FROM admin_audit_log')->fetchColumn();
ensure(!str_contains($audit,'Private note'),'Private note copied into audit');
ensure((int)$db->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn()===1,'Stale update produced audit');
$db->exec('DROP TABLE admin_audit_log');
try { $repo->update($data['report_id'],['status'=>'RESOLVED','admin_note'=>'no audit','revision'=>2],42); throw new RuntimeException('Expected audit failure'); }
catch (PDOException $e) {}
ensure(count($repo->list(['status'=>'REVIEWING']))===1,'Failed audit did not roll back update');
expectStatus(422,fn()=>$repo->list(['page'=>-1]));
expectStatus(422,fn()=>$repo->list(['status'=>'invalid']));
echo "Report tests passed: validation, privacy fields, idempotency, rate limit, all kinds, queue, revision conflict and atomic audit.\n";
