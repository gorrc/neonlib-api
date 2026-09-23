<?php
declare(strict_types=1);
namespace NeonLib;

use PDO;
use Throwable;

/** Reviews immutable document versions together with the current public metadata. */
final class SubscriptionReview
{
    public function __construct(private readonly PDO $database) {}

    public function get(string $packageId, bool $lock = false): ?array
    {
        $suffix = $lock && $this->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $query = $this->database->prepare('SELECT s.id, s.package_id, s.title, s.description, s.language,
            s.visibility, s.status, s.published_version_id, p.name AS publisher_name
            FROM subscriptions s JOIN publishers p ON p.id = s.publisher_id WHERE s.package_id = :package' . $suffix);
        $query->execute(['package' => $packageId]);
        $subscription = $query->fetch(PDO::FETCH_ASSOC);
        if (!$subscription) throw new ApiException(404, 'subscription_not_found', 'Subscription not found.');
        $query = $this->database->prepare('SELECT id, version_number, content_sha256 FROM subscription_versions
            WHERE subscription_id = :id ORDER BY version_number DESC LIMIT 1');
        $query->execute(['id' => $subscription['id']]);
        $version = $query->fetch(PDO::FETCH_ASSOC);
        if (!$version) return null;
        $query = $this->database->prepare('SELECT document_key AS id, title, content FROM subscription_documents
            WHERE version_id = :id ORDER BY sort_order, subscription_documents.id');
        $query->execute(['id' => $version['id']]);
        $documents = $query->fetchAll(PDO::FETCH_ASSOC);
        $snapshot = ['subscription' => $subscription, 'version' => $version, 'documents' => $documents];
        return $snapshot + ['review_token' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
    }

    public function approve(string $packageId, array $input, int $adminId): void
    {
        if (array_diff(array_keys($input), ['approve_version', 'review_token']) ||
            !is_int($input['approve_version'] ?? null) || !is_string($input['review_token'] ?? null)) {
            throw new ApiException(422, 'validation_failed', 'A reviewed version and review token are required.');
        }
        $this->database->beginTransaction();
        try {
            $review = $this->get($packageId, true);
            if (!$review || (int) $review['version']['version_number'] !== $input['approve_version'] ||
                !hash_equals($review['review_token'], $input['review_token'])) {
                throw new ApiException(409, 'review_changed', 'The content or metadata changed. Review the current version again.');
            }
            if ($review['subscription']['status'] === 'ARCHIVED') {
                throw new ApiException(409, 'subscription_archived', 'Move the subscription to Draft and review it before approval.');
            }
            $this->database->prepare("UPDATE subscription_versions SET status = 'PUBLISHED', published_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute(['id' => $review['version']['id']]);
            $this->database->prepare("UPDATE subscriptions SET status = 'PUBLISHED', published_version_id = :version WHERE id = :id")
                ->execute(['version' => $review['version']['id'], 'id' => $review['subscription']['id']]);
            $this->database->prepare('INSERT INTO admin_audit_log (user_id,event_type,package_id,ip_address,details)
                VALUES (:user,:event,:package,:ip,:details)')->execute([
                    'user' => $adminId, 'event' => 'admin_version_approved', 'package' => $packageId,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
                    'details' => json_encode(['version' => $input['approve_version'], 'review_token' => $input['review_token']], JSON_THROW_ON_ERROR),
                ]);
            $this->database->prepare("UPDATE subscription_versions SET status = 'RETIRED' WHERE subscription_id = :subscription AND status = 'DRAFT' AND id <> :version")
                ->execute(['subscription' => $review['subscription']['id'], 'version' => $review['version']['id']]);
            $this->database->commit();
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }
}
