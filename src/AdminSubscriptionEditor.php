<?php
declare(strict_types=1);
namespace NeonLib;

use PDO;
use Throwable;

/** Admin edits are immutable draft versions, never edits to a published payload. */
final class AdminSubscriptionEditor
{
    public function __construct(private readonly PDO $db) {}

    public function snapshot(string $packageId, bool $lock = false): array
    {
        $suffix = $lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $query = $this->db->prepare('SELECT id, package_id, title, description, language, visibility, status, is_featured, published_version_id FROM subscriptions WHERE package_id = :package' . $suffix);
        $query->execute(['package' => $packageId]);
        $meta = $query->fetch(PDO::FETCH_ASSOC);
        if (!$meta) throw new ApiException(404, 'subscription_not_found', 'Subscription not found.');
        $review = (new SubscriptionReview($this->db))->get($packageId);
        $snapshot = ['metadata' => $meta, 'review' => $review];
        return $snapshot + ['edit_token' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
    }

    public function save(string $packageId, array $input, int $adminId): void
    {
        $fields = ['edit_token', 'title', 'description', 'language', 'visibility', 'documents'];
        if (count($input) !== count($fields) || array_diff(array_keys($input), $fields)) {
            throw new ApiException(422, 'validation_failed', 'Provide all editor fields.');
        }
        foreach (array_diff($fields, ['documents']) as $field) {
            if (!is_string($input[$field])) throw new ApiException(422, 'validation_failed', 'Invalid editor field.');
        }
        $title = trim($input['title']); $description = trim($input['description']);
        $language = strtolower(trim($input['language'])); $visibility = strtoupper($input['visibility']);
        if (mb_strlen($title) < 1 || mb_strlen($title) > 190 || mb_strlen($description) > 10000 ||
            !preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $language) ||
            !in_array($visibility, ['PUBLIC', 'PRIVATE'], true) || !preg_match('/^[a-f0-9]{64}$/', $input['edit_token'])) {
            throw new ApiException(422, 'validation_failed', 'Invalid title, description, language, visibility or edit token.');
        }
        $documents = (new OwnedSubscriptionVersionRepository($this->db))->validateDocuments($input['documents']);
        $this->db->beginTransaction();
        try {
            $before = $this->snapshot($packageId, true);
            if (!hash_equals($before['edit_token'], $input['edit_token'])) {
                throw new ApiException(409, 'edit_changed', 'This collection changed. Reload the editor before saving.');
            }
            $meta = $before['metadata'];
            $version = (int) ($before['review']['version']['version_number'] ?? 0) + 1;
            $payload = json_encode(['packageId' => $packageId, 'version' => $version, 'documents' => $documents], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $this->db->prepare("INSERT INTO subscription_versions (subscription_id,version_number,document_count,content_bytes,content_sha256,status,published_at) VALUES (:id,:version,:count,:bytes,:hash,'DRAFT',NULL)")
                ->execute(['id' => $meta['id'], 'version' => $version, 'count' => count($documents), 'bytes' => strlen($payload), 'hash' => hash('sha256', $payload)]);
            $versionId = (int) $this->db->lastInsertId();
            $insert = $this->db->prepare('INSERT INTO subscription_documents (version_id,document_key,title,content,sort_order) VALUES (:version,:key,:title,:content,:sort)');
            foreach ($documents as $index => $document) {
                $insert->execute(['version' => $versionId, 'key' => $document['id'], 'title' => $document['title'], 'content' => $document['content'], 'sort' => ($index + 1) * 10]);
            }
            // Metadata changes invalidate approval; archived collections stay archived.
            $this->db->prepare("UPDATE subscriptions SET title=:title,description=:description,language=:language,visibility=:visibility,status=CASE WHEN status='ARCHIVED' THEN 'ARCHIVED' ELSE 'DRAFT' END,updated_at=CURRENT_TIMESTAMP WHERE id=:id")
                ->execute(['title' => $title, 'description' => $description, 'language' => $language, 'visibility' => $visibility, 'id' => $meta['id']]);
            $this->db->prepare('INSERT INTO admin_audit_log (user_id,event_type,package_id,ip_address,details) VALUES (:user,:event,:package,:ip,:details)')
                ->execute(['user' => $adminId, 'event' => 'admin_subscription_edited', 'package' => $packageId, 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
                    'details' => json_encode(['previous_version' => $version - 1, 'new_version' => $version, 'document_count' => count($documents), 'previous_metadata' => $meta], JSON_THROW_ON_ERROR)]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }
}
