<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use NeonLib\Database;

$email = strtolower(trim((string) ($argv[1] ?? '')));
$label = trim((string) ($argv[2] ?? 'CLI token'));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $label === '' || mb_strlen($label) > 120) {
    fwrite(STDERR, "Usage: php scripts/create_admin_token.php admin@example.com \"Token label\"\n"); exit(1);
}
$database = Database::connection();
$query = $database->prepare("SELECT id FROM users WHERE email = :email AND role = 'SUPERADMIN' AND status = 'ACTIVE' LIMIT 1");
$query->execute(['email'=>$email]); $userId=$query->fetchColumn();
if ($userId === false) { fwrite(STDERR, "Active SUPERADMIN not found.\n"); exit(1); }
$token='nlat_'.rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
$insert=$database->prepare('INSERT INTO admin_api_tokens (user_id,token_hash,token_prefix,label) VALUES (:user,:hash,:prefix,:label)');
$insert->execute(['user'=>$userId,'hash'=>hash('sha256',$token),'prefix'=>substr($token,0,16),'label'=>$label]);
fwrite(STDOUT, "Store this token now; it will not be shown again:\n{$token}\n");
