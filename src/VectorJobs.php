<?php
declare(strict_types=1);
namespace NeonLib;
use PDO;

/** Authenticated queue for a desktop worker. All operations share a file lock. */
final class VectorJobs
{
    private string $directory;
    public function __construct(private readonly PDO $db, private readonly PreparedVectorStore $store, ?string $directory=null)
    {
        $this->directory=$directory ?? ($_ENV['VECTOR_JOBS_DIR'] ?? dirname(__DIR__).'/storage/vector-jobs');
    }
    private function locked(callable $operation): mixed
    {
        if (!is_dir($this->directory) && !mkdir($this->directory,0700,true) && !is_dir($this->directory)) throw new \RuntimeException('Cannot create job storage');
        $lock=fopen($this->directory.'/queue.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) throw new \RuntimeException('Cannot lock job storage');
        try {
            $path=$this->directory.'/queue.json';
            $jobs=is_file($path)?json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR):[];
            $result=$operation($jobs);
            $temporary=tempnam($this->directory,'queue-');
            try {
                $data=json_encode($jobs,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                if ($temporary===false || file_put_contents($temporary,$data)!==strlen($data)) throw new \RuntimeException('Cannot save jobs');
                chmod($temporary,0600);
                if (!rename($temporary,$path)) throw new \RuntimeException('Cannot commit jobs');
            } finally {if($temporary!==false && is_file($temporary))unlink($temporary);}
            return $result;
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    private function source(string $package,int $number):array
    {
        $query=$this->db->prepare('SELECT v.id,v.status FROM subscription_versions v JOIN subscriptions s ON s.id=v.subscription_id WHERE s.package_id=:package AND v.version_number=:number');
        $query->execute(['package'=>$package,'number'=>$number]);$version=$query->fetch(PDO::FETCH_ASSOC);
        if(!$version)throw new ApiException(404,'version_not_found','Version not found.');
        if($version['status']!=='DRAFT')throw new ApiException(409,'immutable_version','Create a new draft version before preparing vectors.');
        $query=$this->db->prepare('SELECT document_key AS id,title,content FROM subscription_documents WHERE version_id=:id ORDER BY sort_order,id');
        $query->execute(['id'=>$version['id']]);
        return ['packageId'=>$package,'version'=>$number,'documents'=>$query->fetchAll(PDO::FETCH_ASSOC)];
    }
    private static function publicJob(array $job):array
    {
        unset($job['lease'],$job['leaseUntil']);return $job;
    }
    public function enqueue(string $package,int $number,int $admin):array
    {
        return $this->locked(function(array &$jobs)use($package,$number,$admin){
            $this->source($package,$number);
            foreach(array_reverse($jobs) as $job)if($job['packageId']===$package && $job['version']===$number && in_array($job['status'],['QUEUED','RUNNING'],true))return self::publicJob($job);
            // Keep active jobs and the latest 200 completed jobs.
            $active=array_filter($jobs,fn($j)=>in_array($j['status'],['QUEUED','RUNNING'],true));
            if(count($active)>=100)throw new ApiException(429,'queue_full','Vector queue is full.');
            $finished=array_slice(array_filter($jobs,fn($j)=>!in_array($j['status'],['QUEUED','RUNNING'],true)),-200,null,true);
            $jobs=$active+$finished;
            $job=['id'=>bin2hex(random_bytes(16)),'packageId'=>$package,'version'=>$number,'status'=>'QUEUED','progress'=>0,'total'=>0,'requestedBy'=>$admin,'createdAt'=>gmdate('c'),'updatedAt'=>gmdate('c'),'error'=>''];
            $jobs[$job['id']]=$job;return self::publicJob($job);
        });
    }
    public function status(string $package,int $number):?array
    {
        return $this->locked(function(array &$jobs)use($package,$number){foreach(array_reverse($jobs)as $job)if($job['packageId']===$package && $job['version']===$number)return self::publicJob($job);return null;});
    }
    public function claim():?array
    {
        return $this->locked(function(array &$jobs){
            foreach($jobs as &$job){
                if($job['status']==='RUNNING' && ($job['leaseUntil']??0)<time())$job['status']='QUEUED';
                if($job['status']!=='QUEUED')continue;
                try{$source=$this->source($job['packageId'],$job['version']);}catch(ApiException $e){$job['status']='FAILED';$job['error']=$e->getMessage();continue;}
                $job['status']='RUNNING';$job['lease']=bin2hex(random_bytes(24));$job['leaseUntil']=time()+180;
                $job['updatedAt']=gmdate('c');return self::publicJob($job)+['lease'=>$job['lease'],'source'=>$source];
            }return null;
        });
    }
    public function update(string $id,array $input,int $admin):array
    {
        return $this->locked(function(array &$jobs)use($id,$input,$admin){
            if(!isset($jobs[$id]))throw new ApiException(404,'job_not_found','Job not found.');
            $job=&$jobs[$id];
            if($job['status']!=='RUNNING' || !is_string($input['lease']??null) || !hash_equals($job['lease'],$input['lease']) || $job['leaseUntil']<time())throw new ApiException(409,'lease_expired','Worker lease expired.');
            $state=$input['status']??'RUNNING';
            if(!in_array($state,['RUNNING','DONE','FAILED'],true))throw new ApiException(422,'invalid_job','Invalid job update.');
            if($state==='DONE'){
                if(!is_array($input['prepared']??null))throw new ApiException(422,'invalid_job','Prepared vectors required.');
                (new PreparedVectorRepository($this->db,$this->store))->upload($job['packageId'],$job['version'],$input['prepared'],$admin);
            }
            $job['status']=$state;$job['leaseUntil']=time()+180;$job['updatedAt']=gmdate('c');
            $job['progress']=max(0,(int)($input['progress']??$job['progress']));$job['total']=max(0,(int)($input['total']??$job['total']));
            // Do not accept raw worker exceptions that may contain secrets or document text.
            if($state==='FAILED')$job['error']='Preparation failed. Check the desktop worker and retry.';
            return self::publicJob($job);
        });
    }
}
