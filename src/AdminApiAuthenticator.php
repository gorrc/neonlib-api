<?php
declare(strict_types=1);
namespace NeonLib;

use PDO;

final class AdminApiAuthenticator
{
    public function __construct(private readonly PDO $database) {}

    public function authenticate(array $server): int
    {
        $authorization = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer\s+(nlat_[A-Za-z0-9_-]{43})$/i', trim($authorization), $match)) {
            throw new ApiException(401, 'admin_authentication_failed', 'Valid administrator credentials are required.');
        }
        $query = $this->database->prepare(
            "SELECT t.id AS token_id, u.id AS user_id
             FROM admin_api_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :hash AND t.revoked_at IS NULL
               AND (t.expires_at IS NULL OR t.expires_at > CURRENT_TIMESTAMP)
               AND u.status = 'ACTIVE' AND u.role = 'SUPERADMIN' LIMIT 1"
        );
        $query->execute(['hash' => hash('sha256', $match[1])]);
        $row = $query->fetch();
        if (!$row) throw new ApiException(401, 'admin_authentication_failed', 'Valid administrator credentials are required.');
        $this->database->prepare('UPDATE admin_api_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['id' => $row['token_id']]);
        return (int) $row['user_id'];
    }
}
