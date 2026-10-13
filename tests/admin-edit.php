<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec('CREATE TABLE accounts (account_id TEXT PRIMARY KEY, status TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$database->exec('CREATE TABLE external_account_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT, account_id TEXT NOT NULL, provider TEXT NOT NULL, site_id TEXT NOT NULL,
    external_user_id TEXT NOT NULL, verified_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, revoked_at TEXT,
    UNIQUE(provider, site_id, external_user_id), UNIQUE(account_id, provider, site_id))');
$database->exec('CREATE TABLE api_request_nonces (
    client_id TEXT NOT NULL, nonce TEXT NOT NULL, expires_at TEXT NOT NULL,
    PRIMARY KEY(client_id, nonce))');
$database->exec('CREATE TABLE publishers (
    id INTEGER PRIMARY KEY AUTOINCREMENT, owner_account_id TEXT UNIQUE, slug TEXT UNIQUE, name TEXT,
    owner_user_id INTEGER, is_verified INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$database->exec('CREATE TABLE subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, publisher_id INTEGER NOT NULL, owner_account_id TEXT,
    package_id TEXT UNIQUE NOT NULL, title TEXT NOT NULL, description TEXT NOT NULL, language TEXT NOT NULL,
    visibility TEXT NOT NULL, status TEXT NOT NULL, is_featured INTEGER DEFAULT 0, published_version_id INTEGER,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$database->exec('CREATE TABLE subscription_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, subscription_id INTEGER NOT NULL, version_number INTEGER NOT NULL,
    document_count INTEGER NOT NULL, content_bytes INTEGER NOT NULL, content_sha256 TEXT NOT NULL,
    status TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, published_at TEXT,
    UNIQUE(subscription_id, version_number))');
$database->exec('CREATE TABLE subscription_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT, version_id INTEGER NOT NULL, document_key TEXT NOT NULL,
    title TEXT NOT NULL, content TEXT NOT NULL, sort_order INTEGER NOT NULL,
    UNIQUE(version_id, document_key))');
$database->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, role TEXT, status TEXT)');
$database->exec('CREATE TABLE admin_api_tokens (id INTEGER PRIMARY KEY, user_id INTEGER, token_hash TEXT UNIQUE, token_prefix TEXT, label TEXT, last_used_at TEXT, expires_at TEXT, revoked_at TEXT)');
$database->exec('CREATE TABLE admin_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, event_type TEXT, package_id TEXT, ip_address TEXT, details TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');

$first = ['account'=>['account_id'=>'acc_test_editor']];
$database->exec("INSERT INTO accounts(account_id,status) VALUES('acc_test_editor','ACTIVE')");
$owned = new NeonLib\OwnedSubscriptionRepository($database);
$package = 'editor.test';
$owned->create($first['account']['account_id'], ['package_id'=>$package,'title'=>'Original','description'=>'Original description','language'=>'en','visibility'=>'public']);
$versions = new NeonLib\OwnedSubscriptionVersionRepository($database);
$versions->publish($first['account']['account_id'], $package, ['documents'=>[['id'=>'first','title'=>'First','content'=>'Original body']]]);
$adminEditor = new NeonLib\AdminApiRepository($database, 1);
$review = $adminEditor->subscription($package)['review'];
$adminEditor->updateSubscription($package, ['approve_version'=>1,'review_token'=>$review['review_token']]);
$state = $adminEditor->subscription($package)['editor'];
$edit = ['edit_token'=>$state['edit_token'],'title'=>'Updated','description'=>'Edited description','language'=>'hr','visibility'=>'public',
    'documents'=>[['id'=>'first','title'=>'Prvi','content'=>'Novi sadržaj čćžšđ']]];
$result = $adminEditor->updateSubscription($package, ['edit'=>$edit]);
check($result['status']==='draft', 'Edit must require approval');
check((int)$result['review']['version']['version_number']===2, 'Missing new version');
check($versions->get($first['account']['account_id'],$package,1)['documents'][0]['content']==='Original body', 'Published payload mutated');
check($result['review']['documents'][0]['content']==='Novi sadržaj čćžšđ', 'Content lost');
try { $adminEditor->updateSubscription($package,['edit'=>$edit]); throw new RuntimeException('Stale edit accepted'); }
catch (NeonLib\ApiException $e) { check($e->statusCode===409,'Wrong conflict'); }
$invalid = $edit; $invalid['edit_token']=$result['editor']['edit_token']; $invalid['documents'][]=$invalid['documents'][0];
try { $adminEditor->updateSubscription($package,['edit'=>$invalid]); throw new RuntimeException('Duplicate accepted'); }
catch (NeonLib\ApiException $e) { check($e->statusCode===422,'Wrong validation'); }
$review=$result['review'];
$adminEditor->updateSubscription($package,['approve_version'=>2,'review_token'=>$review['review_token']]);
$adminEditor->updateSubscription($package,['status'=>'archived']);
$state=$adminEditor->subscription($package)['editor']; $edit['edit_token']=$state['edit_token'];
$result=$adminEditor->updateSubscription($package,['edit'=>$edit]);
check($result['status']==='archived','Edit unexpectedly restored archive');
$adminEditor->updateSubscription($package,['status'=>'draft']);
$review=$adminEditor->subscription($package)['review'];
$result=$adminEditor->updateSubscription($package,['approve_version'=>3,'review_token'=>$review['review_token']]);
check($result['status']==='published','Archive restore failed');
check((int)$database->query("SELECT COUNT(*) FROM admin_audit_log WHERE event_type='admin_subscription_edited'")->fetchColumn()===2,'Missing audit');
$edit['edit_token']=$result['editor']['edit_token'];
$database->exec('DROP TABLE admin_audit_log');
try { $adminEditor->updateSubscription($package,['edit'=>$edit]); throw new RuntimeException('Audit failure not rejected'); }
catch (PDOException $e) {}
check((int)$adminEditor->subscription($package)['review']['version']['version_number']===3,'Failed edit not rolled back');
echo "Admin editor passed: immutable versions, approval, stale edits, validation, archive restore, audit rollback.\n";
