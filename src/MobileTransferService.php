<?php
declare(strict_types=1);
namespace NeonLib;

use PDO;

/** One-use, account-owned upload inbox. Never publishes a subscription. */
final class MobileTransferService
{
    public const MAX_BYTES = 25 * 1024 * 1024;
    public const PAIR_TTL = 900;
    public const DRAFT_TTL = 86400;
    private readonly string $directory;
    private readonly string $secret;

    public function __construct(private readonly PDO $db, ?string $directory = null, ?string $secret = null)
    {
        $this->directory = $directory ?? ($_ENV['MOBILE_TRANSFERS_DIR'] ?? dirname(__DIR__) . '/storage/mobile-transfers');
        $this->secret = $secret ?? (string)($_ENV['WORDPRESS_CLIENT_SECRET'] ?? '');
        if (strlen($this->secret) < 32) throw new ApiException(503, 'transfer_not_configured', 'Transfer signing secret is not configured.');
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) throw new \RuntimeException('Cannot create transfer storage');
    }

    public function create(string $account): array
    {
        $this->active($account);
        $this->rate('create:' . $account, 5, 3600);
        $code = strtoupper(bin2hex(random_bytes(6)));
        $id = substr(hash('sha256', $code), 0, 32);
        $pairing = bin2hex(random_bytes(32));
        $row = ['id'=>$id, 'account'=>$account, 'status'=>'WAITING', 'createdAt'=>time(),
            'pairExpiresAt'=>time()+self::PAIR_TTL, 'expiresAt'=>time()+self::PAIR_TTL,
            'pairHash'=>hash('sha256', $pairing), 'nonceHash'=>null, 'uploadHash'=>null];
        $file = fopen($this->path($id), 'x');
        if (!$file) throw new \RuntimeException('Cannot create transfer');
        try { $this->write($file, $row); } finally { fclose($file); }
        chmod($this->path($id), 0600);
        return $this->summary($row) + ['manualCode'=>implode('-', str_split($code,4)),
            'pairing'=>['sessionId'=>$id, 'pairingToken'=>$pairing], 'limits'=>['maxBytes'=>self::MAX_BYTES, 'maxDocuments'=>500]];
    }

    public function claim(array $input, string $address): array
    {
        $this->rate('claim:' . $address, 10, 60);
        $nonce = $input['clientNonce'] ?? null;
        if (!is_string($nonce) || !preg_match('/^[a-f0-9]{64}$/', $nonce)) throw new ApiException(422,'invalid_pairing','clientNonce must be 32 random bytes encoded as hex.');
        if (isset($input['manualCode']) && is_string($input['manualCode'])) {
            if (array_diff(array_keys($input), ['manualCode','clientNonce'])) throw new ApiException(422,'invalid_pairing','Unexpected pairing fields.');
            $code = strtoupper(str_replace(['-',' '], '', $input['manualCode']));
            if (!preg_match('/^[A-F0-9]{12}$/', $code)) throw new ApiException(401,'invalid_pairing','Pairing code is invalid or unavailable.');
            $id = substr(hash('sha256',$code),0,32);
            $pairing = null;
        } else {
            if (array_diff(array_keys($input), ['sessionId','pairingToken','clientNonce'])) throw new ApiException(422,'invalid_pairing','Unexpected pairing fields.');
            $id = $input['sessionId'] ?? '';
            $pairing = $input['pairingToken'] ?? '';
            if (!is_string($id) || !is_string($pairing) || !preg_match('/^[a-f0-9]{64}$/',$pairing)) throw new ApiException(401,'invalid_pairing','Pairing code is invalid or unavailable.');
        }
        return $this->locked($id, function(array &$row) use ($nonce,$pairing): array {
            if ($pairing !== null && !hash_equals($row['pairHash'],hash('sha256',$pairing))) throw new ApiException(401,'invalid_pairing','Pairing code is invalid or unavailable.');
            if (time() >= $row['pairExpiresAt'] || !in_array($row['status'],['WAITING','PAIRED'],true)) throw new ApiException(409,'pairing_unavailable','Transfer is expired or already used.');
            $this->active($row['account']);
            $nonceHash = hash('sha256',$nonce);
            if ($row['status']==='PAIRED' && !hash_equals($row['nonceHash'],$nonceHash)) throw new ApiException(409,'already_paired','Another device has paired this transfer.');
            // Same nonce recovers a lost claim response without granting another device access.
            $token = 'nlu_' . hash_hmac('sha256','mobile-transfer:' . $row['id'] . ':' . $nonce,$this->secret);
            $row['status']='PAIRED'; $row['nonceHash']=$nonceHash; $row['uploadHash']=hash('sha256',$token);
            $query=$this->db->prepare('SELECT name FROM publishers WHERE owner_account_id = :account LIMIT 1');
            $query->execute(['account'=>$row['account']]);
            return ['sessionId'=>$row['id'],'uploadToken'=>$token,'expiresAt'=>gmdate('c',$row['pairExpiresAt']),
                'recipient'=>['publisherName'=>(string)($query->fetchColumn() ?: 'NeonLib publisher')]];
        });
    }

    public function upload(string $id, string $token, array $payload): array
    {
        return $this->locked($id, function(array &$row) use ($token,$payload): array {
            if (!is_string($row['uploadHash']) || !hash_equals($row['uploadHash'],hash('sha256',$token))) throw new ApiException(401,'invalid_upload_token','Upload authorization failed.');
            if (time() >= $row['pairExpiresAt'] || !in_array($row['status'],['PAIRED','UPLOADED'],true)) throw new ApiException(409,'transfer_unavailable','Transfer is expired or cancelled.');
            $this->active($row['account']);
            $raw=self::encode($payload);
            if (strlen($raw)>self::MAX_BYTES) throw new ApiException(413,'request_too_large','Transfer exceeds 25 MiB.');
            $hash=hash('sha256',$raw);
            if ($row['status']==='UPLOADED') {
                if (!hash_equals($row['payloadHash'],$hash)) throw new ApiException(409,'already_uploaded','This transfer already contains a different collection.');
                return $this->summary($row);
            }
            $validated=$this->validatePayload($payload);
            $path=$this->payloadPath($row['id']);
            $temporary=tempnam($this->directory,'upload-');
            try {
                $saved=self::encode($validated);
                if ($temporary===false || file_put_contents($temporary,$saved,LOCK_EX)!==strlen($saved)) throw new \RuntimeException('Cannot save transfer');
                chmod($temporary,0600);
                if (!rename($temporary,$path)) throw new \RuntimeException('Cannot commit transfer');
            } finally { if ($temporary!==false && is_file($temporary)) unlink($temporary); }
            $row['status']='UPLOADED'; $row['payloadHash']=$hash; $row['expiresAt']=time()+self::DRAFT_TTL;
            $row['title']=$validated['collection']['title']; $row['documentCount']=count($validated['documents']);
            $row['sizeBytes']=strlen($saved); $row['vectorStatus']=isset($validated['prepared']) ? 'UNVERIFIED' : 'ABSENT';
            return $this->summary($row);
        });
    }

    public function owned(string $account, string $id, string $action='status'): array
    {
        $this->active($account);
        return $this->locked($id,function(array &$row) use ($account,$action): array {
            if (!hash_equals($row['account'],$account)) throw new ApiException(404,'transfer_not_found','Transfer not found.');
            if ($row['status']!=='UPLOADED' && time()>=$row['expiresAt']) throw new ApiException(410,'transfer_expired','Transfer has expired.');
            if ($action==='cancel') {
                $row['status']='CANCELLED'; $row['uploadHash']=null;
                if (is_file($this->payloadPath($row['id']))) unlink($this->payloadPath($row['id']));
                return $this->summary($row);
            }
            if ($action==='draft') {
                if ($row['status']!=='UPLOADED') throw new ApiException(409,'draft_unavailable','A completed upload is required.');
                $raw=file_get_contents($this->payloadPath($row['id']),false,null,0,self::MAX_BYTES+1);
                if ($raw===false || strlen($raw)>self::MAX_BYTES) throw new \RuntimeException('Cannot read transfer draft');
                return ['transfer'=>$this->summary($row),'draft'=>json_decode($raw,true,64,JSON_THROW_ON_ERROR)];
            }
            return $this->summary($row);
        });
    }

    /** Durable, account-owned drafts; pairing credentials still expire after fifteen minutes. */
    public function drafts(string $account): array
    {
        $this->active($account);
        $drafts=[];
        foreach (glob($this->directory . '/session-*.json') ?: [] as $path) {
            $id=substr(basename($path),8,32);
            $item=$this->locked($id,function(array &$row) use ($account): array {
                if ($row['status']!=='UPLOADED' || !hash_equals($row['account'],$account)) return [];
                return $this->summary($row) + ['draftStatus'=>'DRAFT','createdAt'=>gmdate('c',$row['createdAt'])];
            });
            if ($item) $drafts[]=$item;
        }
        usort($drafts,static fn(array $a,array $b):int => strcmp($b['createdAt'],$a['createdAt']));
        return $drafts;
    }

    private function validatePayload(array $payload): array
    {
        if (array_diff(array_keys($payload),['collection','documents','prepared']) || !is_array($payload['collection']??null)) throw new ApiException(422,'invalid_transfer','Invalid transfer fields.');
        $collection=$payload['collection'];
        if (array_diff(array_keys($collection),['title','description','language'])) throw new ApiException(422,'invalid_transfer','Invalid collection metadata.');
        foreach (['title'=>255,'description'=>5000,'language'=>35] as $key=>$limit) {
            $value=$collection[$key]??($key==='description'?'':null);
            if (!is_string($value) || mb_strlen($value)>$limit || ($key!=='description' && trim($value)==='')) throw new ApiException(422,'invalid_transfer','Invalid collection ' . $key);
            $collection[$key]=trim($value);
        }
        if (!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/i',$collection['language'])) throw new ApiException(422,'invalid_transfer','Invalid collection language.');
        $documents=(new OwnedSubscriptionVersionRepository($this->db))->validateDocuments($payload['documents']??null);
        $result=['collection'=>$collection,'documents'=>$documents];
        if (isset($payload['prepared'])) {
            if (!is_array($payload['prepared'])) throw new ApiException(422,'invalid_vectors','Invalid prepared vectors.');
            // Format/association validation only: never attach user vectors to public downloads here.
            (new PreparedVectorStore($this->directory . '/unused'))->validateTransfer(['packageId'=>'mobile-transfer','version'=>1,'documents'=>$documents],$payload['prepared']);
            $result['prepared']=$payload['prepared'];
        }
        return $result;
    }

    private function active(string $account): void
    {
        $query=$this->db->prepare("SELECT a.account_id FROM accounts a JOIN external_account_links l ON l.account_id=a.account_id WHERE a.account_id=:account AND a.status='ACTIVE' AND l.provider='wordpress' AND l.revoked_at IS NULL AND l.verified_at IS NOT NULL LIMIT 1");
        $query->execute(['account'=>$account]);
        if (!$query->fetchColumn()) throw new ApiException(403,'account_unavailable','Publisher account is not active.');
    }

    private function summary(array $row): array
    {
        $result=['sessionId'=>$row['id'],'status'=>$row['status'],'expiresAt'=>$row['status']==='UPLOADED' ? null : gmdate('c',$row['expiresAt'])];
        foreach (['title','documentCount','sizeBytes','vectorStatus'] as $key) if (isset($row[$key])) $result[$key]=$row[$key];
        return $result;
    }

    private function path(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/',$id)) throw new ApiException(404,'transfer_not_found','Transfer not found.');
        return $this->directory . '/session-' . $id . '.json';
    }
    private function payloadPath(string $id): string { return $this->directory . '/draft-' . $id . '.json'; }
    private static function encode(array $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    private function write($file,array $value): void
    {
        $raw=self::encode($value); rewind($file);
        if (!ftruncate($file,0) || fwrite($file,$raw)!==strlen($raw) || !fflush($file)) throw new \RuntimeException('Cannot save transfer state');
    }
    private function locked(string $id,callable $operation): array
    {
        $path=$this->path($id);
        $file=is_file($path)?fopen($path,'r+'):false;
        if (!$file) throw new ApiException(404,'transfer_not_found','Transfer not found.');
        try {
            if (!flock($file,LOCK_EX)) throw new \RuntimeException('Cannot lock transfer');
            $row=json_decode(stream_get_contents($file),true,32,JSON_THROW_ON_ERROR);
            $result=$operation($row); $this->write($file,$row); return $result;
        } finally { flock($file,LOCK_UN); fclose($file); }
    }
    private function rate(string $key,int $limit,int $window): void
    {
        $file=fopen($this->directory . '/rate-' . hash_hmac('sha256',$key,$this->secret) . '.json','c+');
        if (!$file) throw new \RuntimeException('Cannot open rate limit');
        try {
            if (!flock($file,LOCK_EX)) throw new \RuntimeException('Cannot lock rate limit');
            $row=json_decode(stream_get_contents($file),true) ?: ['start'=>time(),'count'=>0];
            if (time()-$row['start'] >= $window) $row=['start'=>time(),'count'=>0];
            if ($row['count'] >= $limit) throw new ApiException(429,'rate_limited','Please wait before trying again.');
            $row['count']++; $this->write($file,$row);
        } finally { flock($file,LOCK_UN); fclose($file); }
    }

    /** Run periodically. Tombstones preserve locked filenames for concurrent requests. */
    public function cleanup(): int
    {
        $count=0;
        foreach (glob($this->directory . '/session-*.json') ?: [] as $path) {
            $id=substr(basename($path),8,32);
            $this->locked($id,function(array &$row) use (&$count): array {
                if (time()>=$row['expiresAt'] && !in_array($row['status'],['EXPIRED','UPLOADED'],true)) {
                    if (is_file($this->payloadPath($row['id']))) unlink($this->payloadPath($row['id']));
                    $row=['id'=>$row['id'],'account'=>$row['account'],'status'=>'EXPIRED','expiresAt'=>$row['expiresAt'], 'pairExpiresAt'=>0,'pairHash'=>'','nonceHash'=>null,'uploadHash'=>null];
                    $count++;
                }
                return [];
            });
        }
        return $count;
    }
}
