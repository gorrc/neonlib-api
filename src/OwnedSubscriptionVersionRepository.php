<?php
declare(strict_types=1);
namespace NeonLib;

use PDO;
use Throwable;

final class OwnedSubscriptionVersionRepository
{
    private const MAX_DOCUMENTS = 500;
    private const MAX_CONTENT_BYTES = 8_388_608;
    private const MAX_DOCUMENT_BYTES = 1_048_576;

    public function __construct(private readonly PDO $database) {}

    public function list(string $accountId, string $packageId): array
    {
        $subscriptionId = $this->subscriptionId($accountId, $packageId);
        $query = $this->database->prepare(
            'SELECT version_number, document_count, content_bytes, content_sha256, status, created_at, published_at
             FROM subscription_versions WHERE subscription_id = :subscription ORDER BY version_number DESC'
        );
        $query->execute(['subscription' => $subscriptionId]);
        return array_map([$this, 'versionResponse'], $query->fetchAll());
    }

    public function get(string $accountId, string $packageId, int $version): array
    {
        $subscriptionId = $this->subscriptionId($accountId, $packageId);
        $query = $this->database->prepare(
            'SELECT id, version_number, document_count, content_bytes, content_sha256, status, created_at, published_at
             FROM subscription_versions WHERE subscription_id = :subscription AND version_number = :version LIMIT 1'
        );
        $query->execute(['subscription' => $subscriptionId, 'version' => $version]);
        $row = $query->fetch();
        if (!$row) throw new ApiException(404, 'version_not_found', 'Subscription version not found.');
        $documents = $this->database->prepare(
            'SELECT document_key, title, content FROM subscription_documents WHERE version_id = :version ORDER BY sort_order, id'
        );
        $documents->execute(['version' => $row['id']]);
        $response = $this->versionResponse($row);
        $response['documents'] = array_map(static fn(array $document): array => [
            'id' => $document['document_key'], 'title' => $document['title'], 'content' => $document['content'],
        ], $documents->fetchAll());
        return $response;
    }

    public function publish(string $accountId, string $packageId, array $input): array
    {
        if (count($input) !== 1 || !array_key_exists('documents', $input)) {
            throw new ApiException(422, 'validation_failed', 'Request must contain only the documents field.');
        }
        $documents = $this->validateDocuments($input['documents']);
        $this->database->beginTransaction();
        try {
            $lock = $this->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $subscription = $this->database->prepare(
                'SELECT id, status FROM subscriptions WHERE owner_account_id = :account AND package_id = :package' . $lock
            );
            $subscription->execute(['account' => $accountId, 'package' => $packageId]);
            $row = $subscription->fetch(PDO::FETCH_ASSOC);
            $subscriptionId = $row ? $row['id'] : false;
            if ($subscriptionId === false) throw new ApiException(404, 'subscription_not_found', 'Subscription not found.');

            if ($row['status'] === 'ARCHIVED') throw new ApiException(409, 'subscription_archived', 'An administrator must reopen this subscription before submission.');

            $next = $this->database->prepare(
                'SELECT COALESCE(MAX(version_number), 0) + 1 FROM subscription_versions WHERE subscription_id = :subscription'
            );
            $next->execute(['subscription' => $subscriptionId]);
            $versionNumber = (int) $next->fetchColumn();
            $contentPayload = ['packageId' => $packageId, 'version' => $versionNumber, 'documents' => $documents];
            $contentJson = json_encode($contentPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $versionInsert = $this->database->prepare(
                "INSERT INTO subscription_versions
                 (subscription_id, version_number, document_count, content_bytes, content_sha256, status, published_at)
                 VALUES (:subscription, :number, :count, :bytes, :hash, 'DRAFT', NULL)"
            );
            $versionInsert->execute(['subscription' => $subscriptionId, 'number' => $versionNumber,
                'count' => count($documents), 'bytes' => strlen($contentJson), 'hash' => hash('sha256', $contentJson)]);
            $versionId = (int) $this->database->lastInsertId();
            $documentInsert = $this->database->prepare(
                'INSERT INTO subscription_documents (version_id, document_key, title, content, sort_order)
                 VALUES (:version, :key, :title, :content, :sort)'
            );
            foreach ($documents as $index => $document) {
                $documentInsert->execute(['version' => $versionId, 'key' => $document['id'], 'title' => $document['title'],
                    'content' => $document['content'], 'sort' => ($index + 1) * 10]);
            }
            // Submitting does not change the version served to readers.
            $this->database->prepare('UPDATE subscriptions SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute(['id' => $subscriptionId]);
            $this->database->commit();
            return $this->get($accountId, $packageId, $versionNumber);
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $exception;
        }
    }

    private function subscriptionId(string $accountId, string $packageId): int
    {
        $query = $this->database->prepare(
            'SELECT id, status FROM subscriptions WHERE owner_account_id = :account AND package_id = :package LIMIT 1'
        );
        $query->execute(['account' => $accountId, 'package' => $packageId]);
        $id = $query->fetchColumn();
        if ($id === false) throw new ApiException(404, 'subscription_not_found', 'Subscription not found.');
        return (int) $id;
    }

    private function validateDocuments(mixed $input): array
    {
        if (!is_array($input) || !array_is_list($input) || $input === [] || count($input) > self::MAX_DOCUMENTS) {
            throw new ApiException(422, 'validation_failed', 'Documents must be a non-empty list of at most 500 items.');
        }
        $result = []; $ids = []; $totalBytes = 0;
        foreach ($input as $index => $document) {
            if (!is_array($document) || count($document) !== 3
                || array_diff(array_keys($document), ['id', 'title', 'content'])) {
                throw new ApiException(422, 'validation_failed', "Document {$index} must contain only id, title and content.");
            }
            $id = $document['id']; $title = $document['title']; $content = $document['content'];
            if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,188}$/', $id) || isset($ids[$id])) {
                throw new ApiException(422, 'validation_failed', "Document {$index} has an invalid or duplicate id.");
            }
            if (!is_string($title) || mb_strlen(trim($title)) < 1 || mb_strlen(trim($title)) > 255 || !is_string($content)) {
                throw new ApiException(422, 'validation_failed', "Document {$index} has an invalid title or content.");
            }
            $bytes = strlen($content); $totalBytes += $bytes;
            if ($bytes > self::MAX_DOCUMENT_BYTES || $totalBytes > self::MAX_CONTENT_BYTES) {
                throw new ApiException(413, 'content_too_large', 'Document content exceeds the allowed size.');
            }
            $ids[$id] = true;
            $result[] = ['id' => $id, 'title' => trim($title), 'content' => $content];
        }
        return $result;
    }

    private function versionResponse(array $row): array
    {
        return ['version' => (int) $row['version_number'], 'status' => strtolower($row['status']),
            'document_count' => (int) $row['document_count'], 'content_bytes' => (int) $row['content_bytes'],
            'content_sha256' => $row['content_sha256'],
            'created_at' => gmdate(DATE_ATOM, strtotime($row['created_at'] . ' UTC')),
            'published_at' => $row['published_at'] === null ? null : gmdate(DATE_ATOM, strtotime($row['published_at'] . ' UTC'))];
    }
}
