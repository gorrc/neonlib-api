<?php

declare(strict_types=1);

namespace NeonLib;

use PDO;

final class SubscriptionRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function featured(): array
    {
        $statement = $this->database->query(
            "SELECT s.package_id, sv.version_number, s.title, p.name AS publisher_name,
                    s.description, s.language, sv.document_count, sv.content_bytes, sv.published_at
             FROM subscriptions s
             JOIN publishers p ON p.id = s.publisher_id
             JOIN subscription_versions sv ON sv.id = s.published_version_id
             WHERE s.visibility = 'PUBLIC' AND s.status = 'PUBLISHED' AND s.is_featured = TRUE
             ORDER BY s.title"
        );

        return $this->catalogRows($statement->fetchAll());
    }

    public function search(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            throw new ApiException(422, 'invalid_search', 'Search query must contain 2–100 characters.');
        }
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $statement = $this->database->prepare(
            "SELECT s.package_id, sv.version_number, s.title, p.name AS publisher_name,
                    s.description, s.language, sv.document_count, sv.content_bytes, sv.published_at
             FROM subscriptions s
             JOIN publishers p ON p.id = s.publisher_id
             JOIN subscription_versions sv ON sv.id = s.published_version_id
             WHERE s.visibility = 'PUBLIC' AND s.status = 'PUBLISHED'
               AND (s.title LIKE :query_title ESCAPE '\\\\'
                    OR p.name LIKE :query_publisher ESCAPE '\\\\'
                    OR s.description LIKE :query_description ESCAPE '\\\\'
                    OR s.package_id LIKE :query_package ESCAPE '\\\\')
             ORDER BY s.is_featured DESC, s.title
             LIMIT 50"
        );
        $pattern = "%{$escaped}%";
        $statement->execute([
            'query_title' => $pattern,
            'query_publisher' => $pattern,
            'query_description' => $pattern,
            'query_package' => $pattern,
        ]);

        return $this->catalogRows($statement->fetchAll());
    }

    private function catalogRows(array $rows): array
    {

        return array_map(static fn (array $row): array => [
            'packageId' => $row['package_id'],
            'version' => (int) $row['version_number'],
            'title' => $row['title'],
            'publisherName' => $row['publisher_name'],
            'description' => $row['description'],
            'language' => $row['language'],
            'documentCount' => (int) $row['document_count'],
            'sizeBytes' => (int) $row['content_bytes'],
            'publishedAt' => $row['published_at'],
        ], $rows);
    }

    public function manifest(string $packageId): array
    {
        $subscription = $this->publishedSubscription($packageId);
        $contentJson = json_encode(
            $this->content($packageId),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return [
            'packageId' => $subscription['package_id'],
            'version' => (int) $subscription['version_number'],
            'title' => $subscription['title'],
            'publisherId' => $subscription['publisher_slug'],
            'publisherName' => $subscription['publisher_name'],
            'description' => $subscription['description'],
            'language' => $subscription['language'],
            'documentCount' => (int) $subscription['document_count'],
            'sizeBytes' => strlen($contentJson),
            'contentHash' => hash('sha256', $contentJson),
            'contentUrl' => "subscriptions/{$packageId}/content",
            'publishedAt' => $subscription['published_at'],
        ];
    }

    public function content(string $packageId): array
    {
        $subscription = $this->publishedSubscription($packageId);
        $statement = $this->database->prepare(
            'SELECT document_key, title, content, sort_order
             FROM subscription_documents
             WHERE version_id = :version_id
             ORDER BY sort_order, id'
        );
        $statement->execute(['version_id' => $subscription['version_id']]);

        return [
            'packageId' => $subscription['package_id'],
            'version' => (int) $subscription['version_number'],
            'documents' => array_map(static fn (array $row): array => [
                'id' => $row['document_key'],
                'title' => $row['title'],
                'content' => $row['content'],
            ], $statement->fetchAll()),
        ];
    }

    private function publishedSubscription(string $packageId): array
    {
        $statement = $this->database->prepare(
            "SELECT s.package_id, s.title, s.description, s.language,
                    p.slug AS publisher_slug, p.name AS publisher_name,
                    sv.id AS version_id, sv.version_number, sv.document_count,
                    sv.content_bytes, sv.content_sha256, sv.published_at
             FROM subscriptions s
             JOIN publishers p ON p.id = s.publisher_id
             JOIN subscription_versions sv ON sv.id = s.published_version_id
             WHERE s.package_id = :package_id
               AND s.visibility = 'PUBLIC'
               AND s.status = 'PUBLISHED'
             LIMIT 1"
        );
        $statement->execute(['package_id' => $packageId]);
        $result = $statement->fetch();
        if (!$result) {
            throw new ApiException(404, 'subscription_not_found', 'Published subscription not found.');
        }
        return $result;
    }
}
