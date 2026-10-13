<?php
declare(strict_types=1);
namespace NeonLib;

use PDO;

final class PreparedVectorRepository
{
    public function __construct(private readonly PDO $database, private readonly PreparedVectorStore $store) {}

    public function upload(string $package, int $number, array $prepared, int $adminId): array
    {
        $this->database->beginTransaction();
        try {
            $lock = $this->database->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? ' FOR UPDATE' : '';
            $query = $this->database->prepare('SELECT v.id, v.status FROM subscription_versions v JOIN subscriptions s ON s.id=v.subscription_id WHERE s.package_id=:package AND v.version_number=:version' . $lock);
            $query->execute(['package'=>$package, 'version'=>$number]);
            $version = $query->fetch(PDO::FETCH_ASSOC);
            if (!$version) throw new ApiException(404,'version_not_found','Subscription version not found.');
            if ($version['status']!=='DRAFT') throw new ApiException(409,'immutable_version','Prepared vectors can only be attached to a draft version.');
            $documents = $this->database->prepare('SELECT document_key AS id, title, content FROM subscription_documents WHERE version_id=:id ORDER BY sort_order,id');
            $documents->execute(['id'=>$version['id']]);
            $source = ['packageId'=>$package, 'version'=>$number, 'documents'=>$documents->fetchAll(PDO::FETCH_ASSOC)];
            $audit = $this->database->prepare('INSERT INTO admin_audit_log (user_id,event_type,package_id,ip_address,details) VALUES (:user,:event,:package,:ip,:details)');
            $audit->execute(['user'=>$adminId,'event'=>'PREPARED_VECTORS_UPLOAD','package'=>$package,
                'ip'=>(string)($_SERVER['REMOTE_ADDR'] ?? 'cli'),'details'=>json_encode(['version'=>$number],JSON_THROW_ON_ERROR)]);
            $result = $this->store->save($source, $prepared);
            $this->database->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }
}
