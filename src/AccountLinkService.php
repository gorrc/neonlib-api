<?php
declare(strict_types=1);
namespace NeonLib;

use PDO;
use PDOException;

final class AccountLinkService
{
    public function __construct(private readonly PDO $database) {}

    public function link(array $input): array
    {
        $siteId = $input['wordpress_site_id'] ?? null;
        $userId = $input['wordpress_user_id'] ?? null;
        $emailVerified = $input['email_verified'] ?? null;
        $errors = [];
        if (!is_string($siteId) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $siteId)) {
            $errors['wordpress_site_id'] = 'Must be a stable 3-128 character site identifier.';
        }
        if ((!is_int($userId) && !is_string($userId)) || !preg_match('/^[1-9]\d{0,19}$/', (string) $userId)) {
            $errors['wordpress_user_id'] = 'Must be a positive WordPress user ID.';
        }
        if ($emailVerified !== true) {
            $errors['email_verified'] = 'Must be true; WordPress must verify the user first.';
        }
        if ($errors !== []) {
            throw new ApiException(422, 'validation_failed', 'Request validation failed.', ['fields' => $errors]);
        }
        $configuredSiteId = (string) ($_ENV['WORDPRESS_SITE_ID'] ?? '');
        if ($configuredSiteId === '' || !hash_equals($configuredSiteId, (string) $siteId)) {
            throw new ApiException(403, 'site_not_allowed', 'The authenticated WordPress client cannot link this site.');
        }

        $this->database->beginTransaction();
        try {
            $existing = $this->find((string) $siteId, (string) $userId, true);
            if ($existing !== null) {
                $this->database->prepare(
                    "UPDATE external_account_links SET revoked_at = NULL, verified_at = CURRENT_TIMESTAMP
                     WHERE provider = 'wordpress' AND site_id = :site AND external_user_id = :user"
                )->execute(['site' => $siteId, 'user' => (string) $userId]);
                $this->database->commit();
                $existing = $this->find((string) $siteId, (string) $userId);
                return ['created' => false, 'account' => $this->response($existing)];
            }
            $accountId = AccountId::generate();
            $this->database->prepare("INSERT INTO accounts (account_id, status) VALUES (:id, 'ACTIVE')")
                ->execute(['id' => $accountId]);
            $this->database->prepare(
                'INSERT INTO external_account_links (account_id, provider, site_id, external_user_id, verified_at)
                 VALUES (:account, \'wordpress\', :site, :user, CURRENT_TIMESTAMP)'
            )->execute(['account' => $accountId, 'site' => $siteId, 'user' => (string) $userId]);
            $created = $this->find((string) $siteId, (string) $userId);
            $this->database->commit();
            return ['created' => true, 'account' => $this->response($created)];
        } catch (PDOException $exception) {
            $this->database->rollBack();
            if ($exception->getCode() === '23000') {
                $existing = $this->find((string) $siteId, (string) $userId);
                if ($existing !== null) {
                    return ['created' => false, 'account' => $this->response($existing)];
                }
            }
            throw $exception;
        } catch (\Throwable $exception) {
            $this->database->rollBack();
            throw $exception;
        }
    }

    public function get(string $userId): array
    {
        $row = $this->find($this->configuredSiteId(), $this->validUserId($userId));
        if ($row === null) {
            throw new ApiException(404, 'account_link_not_found', 'Account link not found.');
        }
        return $this->response($row);
    }

    public function unlink(string $userId): void
    {
        $siteId = $this->configuredSiteId();
        $userId = $this->validUserId($userId);
        $statement = $this->database->prepare(
            "UPDATE external_account_links SET revoked_at = CURRENT_TIMESTAMP
             WHERE provider = 'wordpress' AND site_id = :site AND external_user_id = :user AND revoked_at IS NULL"
        );
        $statement->execute(['site' => $siteId, 'user' => $userId]);
        if ($statement->rowCount() === 0) {
            throw new ApiException(404, 'account_link_not_found', 'Account link not found.');
        }
    }

    public function refresh(string $userId, array $input): array
    {
        if (($input['email_verified'] ?? null) !== true || array_diff(array_keys($input), ['email_verified'])) {
            throw new ApiException(422, 'validation_failed', 'Only email_verified=true is accepted.');
        }
        $siteId = $this->configuredSiteId();
        $userId = $this->validUserId($userId);
        $statement = $this->database->prepare(
            "UPDATE external_account_links SET verified_at = CURRENT_TIMESTAMP
             WHERE provider = 'wordpress' AND site_id = :site AND external_user_id = :user"
        );
        $statement->execute(['site' => $siteId, 'user' => $userId]);
        return $this->get($userId);
    }

    public function resolveActive(string $userId): string
    {
        $row = $this->find($this->configuredSiteId(), $this->validUserId($userId));
        if ($row === null || $row['status'] !== 'ACTIVE') {
            throw new ApiException(403, 'account_unavailable', 'No active NeonLib account is linked to this user.');
        }
        return $row['account_id'];
    }

    private function configuredSiteId(): string
    {
        $siteId = (string) ($_ENV['WORDPRESS_SITE_ID'] ?? '');
        if ($siteId === '') {
            throw new ApiException(503, 'service_unavailable', 'WordPress linking is not configured.');
        }
        return $siteId;
    }

    private function validUserId(string $userId): string
    {
        if (!preg_match('/^[1-9]\d{0,19}$/', $userId)) {
            throw new ApiException(400, 'invalid_subject', 'X-NeonLib-Subject must be a positive WordPress user ID.');
        }
        return $userId;
    }

    private function find(string $siteId, string $userId, bool $includeRevoked = false): ?array
    {
        $query = $this->database->prepare(
            "SELECT a.account_id, a.status, a.created_at, l.verified_at
             FROM external_account_links l JOIN accounts a ON a.account_id = l.account_id
             WHERE l.provider = 'wordpress' AND l.site_id = :site AND l.external_user_id = :user" .
            ($includeRevoked ? '' : ' AND l.revoked_at IS NULL')
        );
        $query->execute(['site' => $siteId, 'user' => $userId]);
        return $query->fetch() ?: null;
    }

    private function response(?array $row): array
    {
        if ($row === null) {
            throw new \RuntimeException('Account link was not persisted.');
        }
        return [
            'account_id' => $row['account_id'],
            'status' => strtolower($row['status']),
            'created_at' => gmdate(DATE_ATOM, strtotime($row['created_at'] . ' UTC')),
            'linked_at' => gmdate(DATE_ATOM, strtotime($row['verified_at'] . ' UTC')),
        ];
    }
}
