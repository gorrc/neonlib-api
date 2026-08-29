<?php
declare(strict_types=1);
namespace NeonLib;

use PDO;
use PDOException;

final class RequestAuthenticator
{
    public function __construct(private readonly PDO $database) {}

    public function authenticate(string $method, string $path, string $body, array $server): void
    {
        $clientId = (string) ($server['HTTP_X_NEONLIB_CLIENT_ID'] ?? '');
        $timestamp = (string) ($server['HTTP_X_NEONLIB_TIMESTAMP'] ?? '');
        $nonce = (string) ($server['HTTP_X_NEONLIB_NONCE'] ?? '');
        $signature = (string) ($server['HTTP_X_NEONLIB_SIGNATURE'] ?? '');
        $subject = (string) ($server['HTTP_X_NEONLIB_SUBJECT'] ?? '');
        $configuredId = (string) ($_ENV['WORDPRESS_CLIENT_ID'] ?? '');
        $secret = (string) ($_ENV['WORDPRESS_CLIENT_SECRET'] ?? '');

        if ($configuredId === '' || $secret === '') {
            throw new ApiException(503, 'service_unavailable', 'WordPress linking is not configured.');
        }
        if (!preg_match('/^\d{10}$/', $timestamp) || abs(time() - (int) $timestamp) > 300
            || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce)
            || !hash_equals($configuredId, $clientId)) {
            throw new ApiException(401, 'authentication_failed', 'Request authentication failed.');
        }

        $canonical = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
        if ($subject !== '') {
            $canonical .= "\n" . $subject;
        }
        $expected = 'v1=' . hash_hmac('sha256', $canonical, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new ApiException(401, 'authentication_failed', 'Request authentication failed.');
        }

        try {
            $statement = $this->database->prepare(
                'INSERT INTO api_request_nonces (client_id, nonce, expires_at) VALUES (:client, :nonce, :expires)'
            );
            $statement->execute([
                'client' => $clientId,
                'nonce' => $nonce,
                'expires' => gmdate('Y-m-d H:i:s', (int) $timestamp + 300),
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new ApiException(409, 'replayed_request', 'This authenticated request was already used.');
            }
            throw $exception;
        }
    }
}
