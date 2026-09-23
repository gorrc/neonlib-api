<?php
declare(strict_types=1);
namespace NeonLib;

use PDO;
use PDOException;
use Throwable;

final class ReportRepository
{
    public function __construct(private readonly PDO $database) {}

    public function submit(array $input, string $address, string $secret): array
    {
        if (strlen($secret) < 32) throw new ApiException(503, 'reports_unavailable', 'Reporting is not configured.');
        $allowed = ['report_id','kind','target_id','target_label','content_version','reason','excerpt','details'];
        if (array_diff(array_keys($input), $allowed)) throw new ApiException(422, 'validation_failed', 'Unknown report fields.');
        $limits = ['report_id'=>36,'kind'=>20,'target_id'=>190,'target_label'=>240,'reason'=>30,'excerpt'=>8000,'details'=>2000];
        $data = [];
        foreach ($limits as $field=>$limit) {
            $value = $input[$field] ?? '';
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit || str_contains($value, "\0")) {
                throw new ApiException(422, 'validation_failed', 'Invalid report field: '.$field);
            }
            $data[$field] = trim($value);
        }
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $data['report_id']) ||
            !in_array($data['kind'], ['AI','SUBSCRIPTION','PUBLISHER'], true) || $data['target_id'] === '' ||
            !in_array($data['reason'], ['DANGEROUS','SEXUAL','HATE','FRAUD','PRIVACY','OTHER'], true)) {
            throw new ApiException(422, 'validation_failed', 'Invalid report identity, kind or reason.');
        }
        // Publisher reports reference the collection through which its publisher was encountered.
        if ($data['kind'] !== 'AI' && !preg_match('/^[a-z0-9][a-z0-9._-]{1,189}$/', $data['target_id'])) {
            throw new ApiException(422, 'validation_failed', 'Invalid collection reference.');
        }
        $version = $input['content_version'] ?? null;
        if ($version !== null && (!is_int($version) || $version < 1)) throw new ApiException(422, 'validation_failed', 'Invalid version.');
        $data['content_version'] = $version;
        $data['payload_hash'] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $existing = $this->receipt($data['report_id'], $data['payload_hash']);
        if ($existing) return $existing;
        $this->database->beginTransaction();
        try {
            $window = intdiv(time(), 60) * 60;
            $key = hash_hmac('sha256', $address, $secret);
            $sql = $this->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? 'INSERT INTO report_rate_limits (bucket_key,window_start,request_count) VALUES (:key,:window,1) ON DUPLICATE KEY UPDATE request_count=request_count+1'
                : 'INSERT INTO report_rate_limits (bucket_key,window_start,request_count) VALUES (:key,:window,1) ON CONFLICT(bucket_key,window_start) DO UPDATE SET request_count=request_count+1';
            $this->database->prepare($sql)->execute(['key'=>$key,'window'=>$window]);
            $query = $this->database->prepare('SELECT request_count FROM report_rate_limits WHERE bucket_key=:key AND window_start=:window');
            $query->execute(['key'=>$key,'window'=>$window]);
            if ((int)$query->fetchColumn() > 10) throw new ApiException(429, 'rate_limited', 'Please wait before submitting another report.');
            $this->database->prepare('DELETE FROM report_rate_limits WHERE window_start < :old')->execute(['old'=>$window-3600]);
            $this->database->prepare("INSERT INTO content_reports
                (report_id,kind,target_id,target_label,content_version,reason,excerpt,details,payload_hash,admin_note)
                VALUES (:report_id,:kind,:target_id,:target_label,:content_version,:reason,:excerpt,:details,:payload_hash,'')")
                ->execute($data);
            $this->database->commit();
            return ['report_id'=>$data['report_id'], 'received'=>true];
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            if ($error instanceof PDOException && str_starts_with((string)$error->getCode(), '23')) {
                $existing = $this->receipt($data['report_id'], $data['payload_hash']);
                if ($existing) return $existing;
            }
            throw $error;
        }
    }

    private function receipt(string $id, string $hash): ?array
    {
        $query=$this->database->prepare('SELECT payload_hash FROM content_reports WHERE report_id=:id');
        $query->execute(['id'=>$id]); $old=$query->fetchColumn();
        if ($old === false) return null;
        if (!hash_equals((string)$old, $hash)) throw new ApiException(409, 'report_conflict', 'Use a new report ID for changed content.');
        return ['report_id'=>$id,'received'=>true];
    }

    public function list(array $filters): array
    {
        $status = (string)($filters['status'] ?? 'NEW');
        if (!in_array($status, ['','NEW','REVIEWING','RESOLVED','DISMISSED'], true)) throw new ApiException(422,'validation_failed','Invalid status.');
        $page = filter_var($filters['page'] ?? 0, FILTER_VALIDATE_INT);
        if ($page === false || $page < 0 || $page > 100000) throw new ApiException(422,'validation_failed','Invalid page.');
        $sql='SELECT * FROM content_reports';
        $params=[];
        if ($status !== '') { $sql.=' WHERE status=:status'; $params['status']=$status; }
        $sql.=' ORDER BY id DESC LIMIT 50 OFFSET '.($page*50);
        $query=$this->database->prepare($sql); $query->execute($params);
        return array_map(static function(array $row): array { unset($row['payload_hash']); return $row; }, $query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function update(string $id, array $input, int $adminId): array
    {
        if (array_diff(array_keys($input), ['status','admin_note','revision']) ||
            !is_string($input['status'] ?? null) || !in_array($input['status'], ['NEW','REVIEWING','RESOLVED','DISMISSED'], true) ||
            !is_string($input['admin_note'] ?? null) || mb_strlen($input['admin_note']) > 2000 ||
            !is_int($input['revision'] ?? null) || $input['revision'] < 1) throw new ApiException(422,'validation_failed','Invalid report update.');
        $this->database->beginTransaction();
        try {
            $query=$this->database->prepare('UPDATE content_reports SET status=:status,admin_note=:note,revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE report_id=:id AND revision=:revision');
            $query->execute(['status'=>$input['status'],'note'=>$input['admin_note'],'revision'=>$input['revision'],'id'=>$id]);
            if ($query->rowCount() !== 1) throw new ApiException(409,'report_changed','Report changed or no longer exists. Refresh the list.');
            $this->database->prepare('INSERT INTO admin_audit_log (user_id,event_type,package_id,ip_address,details) VALUES (:user,:event,NULL,:ip,:details)')
                ->execute(['user'=>$adminId,'event'=>'admin_report_updated','ip'=>$_SERVER['REMOTE_ADDR']??'cli',
                    'details'=>json_encode(['report_id'=>$id,'status'=>$input['status'],'revision'=>$input['revision']+1],JSON_THROW_ON_ERROR)]);
            $this->database->commit();
            return ['report_id'=>$id,'status'=>$input['status'],'revision'=>$input['revision']+1];
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }
}
