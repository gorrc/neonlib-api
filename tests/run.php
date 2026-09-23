<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use NeonLib\AccountId;
use NeonLib\AccountLinkService;
use NeonLib\ApiException;
use NeonLib\JsonBody;
use NeonLib\RequestAuthenticator;
use NeonLib\OwnedSubscriptionRepository;
use NeonLib\OwnedSubscriptionVersionRepository;
use NeonLib\AdminApiAuthenticator;
use NeonLib\AdminApiRepository;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

for ($i = 0; $i < 100; $i++) {
    check((bool) preg_match('/^acc_[0-9a-hjkmnp-tv-z]{26}$/', AccountId::generate()), 'Invalid account ID');
}

check(JsonBody::decode('{"ok":true}') === ['ok' => true], 'JSON object was not decoded');
try {
    JsonBody::decode('[]');
    throw new RuntimeException('JSON list should fail');
} catch (ApiException $exception) {
    check($exception->errorCode === 'invalid_request', 'Wrong JSON list error');
}

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
$_ENV['WORDPRESS_SITE_ID'] = 'example.com';

try {
    (new AccountLinkService($database))->link([
        'wordpress_site_id' => 'example.com',
        'wordpress_user_id' => '42',
        'email_verified' => false,
    ]);
    throw new RuntimeException('Unverified e-mail should fail');
} catch (ApiException $exception) {
    check($exception->statusCode === 422, 'Wrong verification status');
}

$service = new AccountLinkService($database);
$input = ['wordpress_site_id' => 'example.com', 'wordpress_user_id' => '42', 'email_verified' => true];
$first = $service->link($input);
$second = $service->link($input);
check($first['created'] === true && $second['created'] === false, 'Link must be idempotent');
check($first['account']['account_id'] === $second['account']['account_id'], 'Retry changed account ID');
$service->unlink('42');
try {
    $service->get('42');
    throw new RuntimeException('Revoked link should not resolve');
} catch (ApiException $exception) {
    check($exception->statusCode === 404, 'Revoked link should be hidden');
}
$relinked = $service->link($input);
check($relinked['account']['account_id'] === $first['account']['account_id'], 'Relink must preserve account ownership');

$other = $service->link(['wordpress_site_id' => 'example.com', 'wordpress_user_id' => '43', 'email_verified' => true]);
$owned = new OwnedSubscriptionRepository($database);
check($owned->publisher($first['account']['account_id']) === null, 'New account unexpectedly has a publisher');
$publisher = $owned->updatePublisher($first['account']['account_id'], ['display_name' => 'Example Knowledge']);
check($publisher['display_name'] === 'Example Knowledge', 'Publisher profile was not created');
$publisher = $owned->updatePublisher($first['account']['account_id'], ['display_name' => 'Example Library']);
check($publisher['display_name'] === 'Example Library', 'Publisher profile was not updated');
$createdSubscription = $owned->create($first['account']['account_id'], [
    'package_id' => 'example.private', 'title' => 'Private library', 'description' => '',
    'language' => 'en', 'visibility' => 'private',
]);
check($createdSubscription['package_id'] === 'example.private', 'Owned subscription was not created');
check(count($owned->list($first['account']['account_id'])) === 1, 'Owner cannot list subscription');
check(count($owned->list($other['account']['account_id'])) === 0, 'Subscription leaked to another account');
try {
    $owned->get($other['account']['account_id'], 'example.private');
    throw new RuntimeException('Other account should not read subscription');
} catch (ApiException $exception) {
    check($exception->statusCode === 404, 'Cross-account read must be concealed as not found');
}
$updatedSubscription = $owned->update($first['account']['account_id'], 'example.private', ['title' => 'Renamed']);
check($updatedSubscription['title'] === 'Renamed', 'Owner update failed');
$versions = new OwnedSubscriptionVersionRepository($database);
$published = $versions->publish($first['account']['account_id'], 'example.private', ['documents' => [
    ['id' => 'intro', 'title' => 'Introduction', 'content' => 'Version one content'],
]]);
check($published['version'] === 1 && $published['document_count'] === 1, 'Version publish failed');
$publishedTwo = $versions->publish($first['account']['account_id'], 'example.private', ['documents' => [
    ['id' => 'intro', 'title' => 'Introduction', 'content' => 'Version two content'],
]]);
check($publishedTwo['version'] === 2, 'Version numbers are not sequential');
check(count($versions->list($first['account']['account_id'], 'example.private')) === 2, 'Version list failed');
check($versions->get($first['account']['account_id'], 'example.private', 1)['documents'][0]['content'] === 'Version one content', 'Published version was mutated');
try {
    $versions->list($other['account']['account_id'], 'example.private');
    throw new RuntimeException('Other account should not list versions');
} catch (ApiException $exception) {
    check($exception->statusCode === 404, 'Cross-account versions must be concealed as not found');
}
try {
    $owned->delete($other['account']['account_id'], 'example.private');
    throw new RuntimeException('Other account should not delete subscription');
} catch (ApiException $exception) {
    check($exception->statusCode === 404, 'Cross-account delete must be concealed as not found');
}
$owned->delete($first['account']['account_id'], 'example.private');
check($owned->list($first['account']['account_id']) === [], 'Owner delete failed');

$adminToken = 'nlat_' . str_repeat('a', 43);
$database->exec("INSERT INTO users (id,email,role,status) VALUES (1,'admin@example.com','SUPERADMIN','ACTIVE')");
$tokenInsert = $database->prepare("INSERT INTO admin_api_tokens (id,user_id,token_hash,token_prefix,label) VALUES (1,1,:hash,'nlat_aaaaaaaaaaa','test')");
$tokenInsert->execute(['hash' => hash('sha256', $adminToken)]);
$adminUserId = (new AdminApiAuthenticator($database))->authenticate(['HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken]);
check($adminUserId === 1, 'Admin bearer authentication failed');
$adminApi = new AdminApiRepository($database, $adminUserId);
$adminApi->updateAccount($first['account']['account_id'], ['status' => 'suspended']);
check($adminApi->account($first['account']['account_id'])['status'] === 'suspended', 'Admin account moderation failed');
check((int) $database->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn() === 1, 'Admin mutation was not audited');

$_ENV['WORDPRESS_CLIENT_ID'] = 'wordpress-test';
$_ENV['WORDPRESS_CLIENT_SECRET'] = 'test-secret-with-sufficient-entropy';
$body = json_encode($input, JSON_THROW_ON_ERROR);
$timestamp = (string) time();
$nonce = '0123456789abcdef0123456789abcdef';
$canonical = "POST\n/api/v1/accounts/link\n{$timestamp}\n{$nonce}\n" . hash('sha256', $body);
$headers = [
    'HTTP_X_NEONLIB_CLIENT_ID' => 'wordpress-test',
    'HTTP_X_NEONLIB_TIMESTAMP' => $timestamp,
    'HTTP_X_NEONLIB_NONCE' => $nonce,
    'HTTP_X_NEONLIB_SIGNATURE' => 'v1=' . hash_hmac('sha256', $canonical, $_ENV['WORDPRESS_CLIENT_SECRET']),
];
$authenticator = new RequestAuthenticator($database);
$authenticator->authenticate('POST', '/api/v1/accounts/link', $body, $headers);
try {
    $authenticator->authenticate('POST', '/api/v1/accounts/link', $body, $headers);
    throw new RuntimeException('Replayed nonce should fail');
} catch (ApiException $exception) {
    check($exception->errorCode === 'replayed_request', 'Wrong replay error');
}


// Moderation regressions: use the real repositories with an isolated in-memory DB.
$account = $other['account']['account_id'];
$owned->updatePublisher($account, ['display_name' => 'Review Publisher']);
$owned->create($account, ['package_id'=>'review.public', 'title'=>'Review', 'description'=>'Original description', 'language'=>'en', 'visibility'=>'PUBLIC']);
$docs = ['documents'=>[['id'=>'one','title'=>'Guide','content'=>'Original approved text']]];
$draft = $versions->publish($account, 'review.public', $docs);
check($draft['status'] === 'draft', 'Submission must be a draft');
check($owned->get($account, 'review.public')['status'] === 'draft', 'Submission must not publish the collection');
$adminApi->updateSubscription('review.public', ['is_featured'=>true]);
$public = new NeonLib\SubscriptionRepository($database);
check(count($public->featured()) === 0, 'Unapproved collection leaked through public search');
function expectReviewError(callable $action, string $code): void {
    try { $action(); } catch (ApiException $e) { check($e->errorCode === $code, 'Unexpected error: '.$e->errorCode); return; }
    throw new RuntimeException('Expected error: '.$code);
}
expectReviewError(fn() => $adminApi->updateSubscription('review.public', ['status'=>'published']), 'review_required');
$review = $adminApi->subscription('review.public')['review'];
check($review['documents'][0]['content'] === 'Original approved text', 'Admin must see the actual documents');
$approve = fn(array $r) => $adminApi->updateSubscription('review.public', ['approve_version'=>(int)$r['version']['version_number'], 'review_token'=>$r['review_token']]);
$approve($review);
check(count($public->featured()) === 1, 'Approved collection missing');
$servedVersion = $public->manifest('review.public')['version'];
$oldReview = $adminApi->subscription('review.public')['review'];
$versions->publish($account, 'review.public', ['documents'=>[['id'=>'one','title'=>'Guide','content'=>'Unapproved changed text']]]);
check($public->manifest('review.public')['version'] === $servedVersion, 'New submission replaced approved content');
expectReviewError(fn() => $approve($oldReview), 'review_changed');
$review = $adminApi->subscription('review.public')['review'];
$owned->update($account, 'review.public', ['description'=>'Changed description']);
check(count($public->featured()) === 0, 'Unapproved metadata leaked');
expectReviewError(fn() => $approve($review), 'review_changed');
$approve($adminApi->subscription('review.public')['review']);
check((int)$public->manifest('review.public')['version'] === 2, 'Approval failed to switch version');
check($adminApi->subscription('review.public')['pending_count'] === 0, 'Approved drafts remain in the queue');
$review = $adminApi->subscription('review.public')['review'];
$owned->updatePublisher($account, ['display_name'=>'Changed publisher']);
check(count($public->featured()) === 0, 'Publisher rename bypassed moderation');
expectReviewError(fn() => $approve($review), 'review_changed');
$approve($adminApi->subscription('review.public')['review']);
$adminApi->updateSubscription('review.public', ['status'=>'archived']);
check(count($public->featured()) === 0, 'Archived collection is public');
expectReviewError(fn() => $versions->publish($account, 'review.public', $docs), 'subscription_archived');
$owned->update($account, 'review.public', ['title'=>'Archived edit']);
check($owned->get($account, 'review.public')['status'] === 'archived', 'Metadata edit reactivated archive');
expectReviewError(fn() => $approve($adminApi->subscription('review.public')['review']), 'subscription_archived');
$adminApi->updateSubscription('review.public', ['status'=>'draft']);
$approve($adminApi->subscription('review.public')['review']);
check($owned->get($account, 'review.public')['status'] === 'published', 'Administrator could not reopen/review archive');
check((int)$database->query("SELECT COUNT(*) FROM admin_audit_log WHERE event_type = 'admin_version_approved'")->fetchColumn() === 4, 'Approval audit entries missing');
// Audit failures must roll back both the approval and published pointer.
$versions->publish($account, 'review.public', $docs);
$review = $adminApi->subscription('review.public')['review'];
$database->exec('DROP TABLE admin_audit_log');
try { $approve($review); throw new RuntimeException('Missing audit table should fail approval'); }
catch (PDOException $e) {}
check((int)$public->manifest('review.public')['version'] === 2, 'Failed approval changed public version');
check(!$database->inTransaction(), 'Approval left transaction open');

echo "All unit tests passed (including moderation regressions).\n";
