<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use NeonLib\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

[$script, $email, $displayName] = array_pad($argv, 3, null);
if (!$email || !$displayName || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/create_admin.php <email> <display-name>\n");
    exit(1);
}

fwrite(STDOUT, 'Password: ');
$password = trim((string) fgets(STDIN));
if (strlen($password) < 10) {
    fwrite(STDERR, "Password must contain at least 10 characters.\n");
    exit(1);
}

$statement = Database::connection()->prepare(
    "INSERT INTO users (email, password_hash, display_name, role, status)
     VALUES (:email, :hash, :name, 'SUPERADMIN', 'ACTIVE')
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), display_name = VALUES(display_name),
                             role = 'SUPERADMIN', status = 'ACTIVE'"
);
$statement->execute([
    'email' => strtolower($email),
    'hash' => password_hash($password, PASSWORD_DEFAULT),
    'name' => $displayName,
]);

fwrite(STDOUT, "Admin user created or updated.\n");
