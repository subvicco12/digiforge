<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;

/** Callback/queue orchestration; all external attempts remain in the audited dispatcher. */
final class DriveGoogleWorkflow
{
    private const STAGES=['folder','original_upload','original_readback','native_conversion','native_readback','pdf_export','native_final'];
    public function __construct(private DriveGrantStore $store,private DriveGoogleGate $gate,private DriveGoogleOAuth $oauth,
        private DriveGoogleAuthority $authority,private DriveGoogleDispatcher $dispatcher,private DriveQueueJobs $jobs,
        private \Closure $source,private \Closure $export,private \Closure $finalize,private string $callback){}
    private static function key(string $kind,string $id):string{return DriveGoogleOAuth::key('operation','workflow:'.$kind.':'.$id);}
    public function begin(array $plan,#[\SensitiveParameter] string $session):?array
    {
        try{
            $b=DriveGoogleOAuth::binding($plan);if(!$this->gate->allows($b))return null;
            $key=self::key('plan',$b['id']);$row=['binding'=>$b,'state'=>'INITIALIZING','flow_sha256'=>null,'connection'=>null];
            if(!$this->store->compareAndSwap($key,[],$row))return null;
            $start=$this->oauth->begin($b,$session,$this->gate->now());if($start===null)return null;
            parse_str((string)parse_url($start['authorization_url'],PHP_URL_QUERY),$query);
            $next=$row;$next['state']='OAUTH_PENDING';$next['flow_sha256']=hash('sha256',$query['state']);
            return $this->store->compareAndSwap($key,$row,$next)?$start:null;
        }catch(\Throwable){return null;}
    }
    public function callback(#[\SensitiveParameter] array $query,#[\SensitiveParameter] string $session):?array
    {
        try{
            $state=$query['state']??null;$code=$query['code']??null;
            if(!is_string($state)||preg_match('/^[a-f0-9]{64}$/D',$state)!==1||!is_string($code)||isset($query['error']))return null;
            $flow=$this->store->read(DriveGoogleOAuth::key('oauth',$state));$b=DriveGoogleOAuth::binding($flow['binding']??[]);
            if(!$this->gate->allows($b)||($flow['state']??null)!=='PENDING'
                ||!hash_equals($flow['session_sha256']??'',hash('sha256',$session)))return null;
            $key=self::key('plan',$b['id']);$row=$this->store->read($key);
            if(!is_array($row)||($row['binding']??null)!==$b||($row['state']??null)!=='OAUTH_PENDING'
                ||($row['flow_sha256']??null)!==hash('sha256',$state)||($row['connection']??null)!==null)return null;
            $result=$this->oauth->complete($b,$session,$state,$code,$this->callback,$this->gate->now());if($result===null)return null;
            $next=$row;$next['state']='CONNECTED';$next['connection']=$result;
            if(!$this->gate->allows($b)||!$this->store->compareAndSwap($key,$row,$next))return null;
            return ['plan_id'=>$b['id'],'state'=>'CONNECTED','external_execution_authorized'=>false];
        }catch(\Throwable){return null;}
    }
    public function plan(string $id):?array
    {
        try{$r=$this->store->read(self::key('plan',$id));return is_array($r)&&($r['binding']['id']??null)===$id&&$this->gate->allows($r['binding'])?$r:null;}catch(\Throwable){return null;}
    }
    public function submit(string $planId):?array
    {
        try{
            $p=$this->plan($planId);if($p===null||$p['state']!=='CONNECTED')return null;$b=$p['binding'];$key=self::key('transfer',$planId);$row=$this->store->read($key);
            if($row===[]){
                $c=$this->oauth->connection($b,$p['connection']['connection_id'],$this->gate->now());
                if($c===null||$c['account_id']!==$p['connection']['account_id']||$c['generation']!==$p['connection']['generation'])return null;
                $stages=array_merge(self::STAGES,['reconcile_original_upload','reconcile_native_conversion']);
                $g=$this->authority->issue($b,$c['id'],$stages,14,$this->gate->now(),900);if($g===null)return null;
                $next=['binding'=>$b,'grant'=>$g,'state'=>'QUEUED','jobs'=>[],'claims'=>[],'results'=>[]];
                if(!$this->store->compareAndSwap($key,[],$next))return null;$row=$next;
            }
            if(!is_array($row)||($row['binding']??null)!==$b||$this->authority->current($b,$row['grant'],'folder',$this->gate->now())===null)return null;
            foreach($row['claims'] as $claimedStage=>$claim){
                // A successful explicit GET reconciliation supplies the result without rewriting
                // the crashed create claim. Preserve that history, but allow the next stage to resume.
                $reconciled=in_array($claimedStage,['original_upload','native_conversion'],true)
                    &&isset($row['results'][$claimedStage])
                    &&($row['claims']['reconcile_'.$claimedStage]['state']??null)==='OBSERVED';
                if($claim['state']==='PENDING'&&!$reconciled){
                    $job=$this->jobs->read($row['jobs'][$claimedStage]??0);
                    return ['plan_id'=>$planId,'state'=>($job['state']??null)==='RUNNING'?'RUNNING':'UNKNOWN','production_authorized'=>false];
                }
            }
            if(in_array($row['state'],['UNKNOWN','TECHNICAL_QA_COMPLETE'],true))return ['plan_id'=>$planId,'state'=>$row['state'],'production_authorized'=>false];
            $stage='folder';$dep=null;
            foreach(self::STAGES as $candidate){
                if(!isset($row['results'][$candidate])){$stage=$candidate;break;}
                if(($row['claims']['reconcile_'.$candidate]['state']??null)==='OBSERVED'){
                    // The original create may remain HUMAN_REVIEW after a crash. Its successful
                    // reconciliation, not that failed job, is the predecessor of recovered work.
                    $dep=$row['jobs']['reconcile_'.$candidate]??null;if(!is_int($dep))return null;
                }else $dep=$row['jobs'][$candidate]??$dep;
            }
            $id=$this->schedule($planId,$stage,$dep);return $id===null?null:['plan_id'=>$planId,'job_id'=>$id,'state'=>'QUEUED','production_authorized'=>false];
        }catch(\Throwable){return null;}
    }
    /** Refresh custody only; never renew a grant or schedule a job implicitly. */
    public function refresh(string $planId):bool
    {
        try{
            $p=$this->plan($planId);if($p===null||$p['state']!=='CONNECTED')return false;
            $r=$this->store->read(self::key('transfer',$planId));if($r===null)return false;
            foreach($r['jobs']??[] as $id){$job=$this->jobs->read($id);if($job===null||$job['state']==='RUNNING')return false;}
            return $this->oauth->refresh($p['binding'],$p['connection']['connection_id'],$this->gate->now());
        }catch(\Throwable){return false;}
    }
    /** Explicit owner POST only. Never called automatically by a worker/retry. */
    public function renew(string $planId):bool
    {
        try{
            $p=$this->plan($planId);if($p===null||$p['state']!=='CONNECTED')return false;$b=$p['binding'];$key=self::key('transfer',$planId);$row=$this->store->read($key);
            if(!is_array($row)||($row['binding']??null)!==$b||$row['state']==='TECHNICAL_QA_COMPLETE')return false;
            foreach($row['jobs'] as $id){$job=$this->jobs->read($id);if($job===null||$job['state']==='RUNNING')return false;}
            $c=$this->oauth->connection($b,$p['connection']['connection_id'],$this->gate->now());
            if($c===null||$c['account_id']!==$p['connection']['account_id'])return false;
            $journal=new DriveGrantJournal($this->store);if(!$journal->revoke($row['grant']))return false;
            $g=$this->authority->issue($b,$c['id'],array_merge(self::STAGES,['reconcile_original_upload','reconcile_native_conversion']),14,$this->gate->now(),900);
            if($g===null)return false;$next=$row;$next['grant']=$g;
            // Old issued records, attempts, logical create claims, source and queued-job identities survive.
            if(!$this->store->compareAndSwap($key,$row,$next)){$journal->revoke($g);return false;}return true;
        }catch(\Throwable){return false;}
    }
    public function reconcile(string $planId,string $role):?int
    {
        try{
            if(!in_array($role,['original_upload','native_conversion'],true))return null;$p=$this->plan($planId);if($p===null)return null;
            $r=$this->store->read(self::key('transfer',$planId));$stage='reconcile_'.$role;
            if(!is_array($r)||!isset($r['claims'][$role])||isset($r['results'][$role])
                ||$this->authority->current($p['binding'],$r['grant'],$stage,$this->gate->now())===null)return null;
            $job=$this->jobs->read($r['jobs'][$role]??0);
            if($job===null||$job['transfer']!==$planId||$job['stage']!==$role||$job['state']==='RUNNING')return null;
            return $this->schedule($planId,$stage,null);
        }catch(\Throwable){return null;}
    }
    /** Only a persisted job-to-transfer map may select an actor for the trusted cron hook. */
    public function actorForJob(int $id):?int
    {
        try{
            $j=$this->jobs->read($id);if($j===null)return null;$r=$this->store->read(self::key('transfer',$j['transfer']));
            if(!is_array($r)||($r['jobs'][$j['stage']]??null)!==$id||($r['binding']['id']??null)!==$j['transfer'])return null;
            $b=DriveGoogleOAuth::binding($r['binding']);return $b['actor_id'];
        }catch(\Throwable){return null;}
    }
    public function run(int $id):array
    {
        $denied=['state'=>'BLOCKED','production_authorized'=>false];$owner=bin2hex(random_bytes(16));$claimed=false;$record=null;$key=null;$stage=null;
        try{
            $j=$this->jobs->read($id);if($j===null)return $denied;$stage=$j['stage'];$key=self::key('transfer',$j['transfer']);$record=$this->store->read($key);
            if(!is_array($record)||($record['jobs'][$stage]??null)!==$id)return $denied;
            $b=DriveGoogleOAuth::binding($record['binding']);$g=$record['grant'];
            if($this->authority->current($b,$g,$stage,$this->gate->now())===null){$this->jobs->block($id);return $denied;}
            if(!$this->jobs->claim($id,$owner))return $denied;$claimed=true;
            // Expired leases/crashes cannot reuse an execution claim, even under a new worker.
            if(isset($record['claims'][$stage])){$this->jobs->finish($id,$owner,'HUMAN_REVIEW');return ['state'=>'UNKNOWN','production_authorized'=>false];}
            $attempt=bin2hex(random_bytes(32));$next=$record;$next['claims'][$stage]=['attempt'=>$attempt,'state'=>'PENDING'];
            if(!$this->store->compareAndSwap($key,$record,$next))throw new \RuntimeException('Queue claim unavailable.');$record=$next;
            $source=($this->source)($b);if(!is_array($source)||!is_string($source['bytes']??null)||!hash_equals($b['source_sha256'],hash('sha256',$source['bytes'])))throw new \RuntimeException('Source unavailable.');
            $workerCurrent=fn():bool=>$this->jobs->active($id,$owner);
            $r=$this->dispatcher->dispatch($b,$g,$stage,[],$source['bytes'],$attempt,$this->gate->now(),$workerCurrent);
            if(!$workerCurrent())throw new \RuntimeException('Worker lease changed.');
            if(($r['state']??null)!=='OBSERVED')throw new \RuntimeException('Attempt uncertain.');
            $value=$r['observation'];if(isset($value['bytes'])){$value=['sha256'=>hash('sha256',$value['bytes'])];}
            if($stage==='pdf_export'){$value=($this->export)($b,$r['observation']['bytes']);if(!is_array($value))throw new \RuntimeException('Export custody unavailable.');}
            if($stage==='native_final'){
                $qa=($this->finalize)($b,$source,$record['results']['pdf_export']??[]);
                if(!is_array($qa)||($qa['source_sha256']??null)!==$b['source_sha256']||($qa['visual_approved']??null)!==false
                    ||($qa['rights_certified']??null)!==false||($qa['production_authorized']??null)!==false)throw new \RuntimeException('Final source/export QA unavailable.');$value=$qa;
            }
            if($this->authority->current($b,$g,$stage,$this->gate->now())===null||!$workerCurrent())throw new \RuntimeException('Authority or worker changed.');
            $target=str_starts_with($stage,'reconcile_')?substr($stage,10):$stage;
            $next=$record;$next['claims'][$stage]['state']='OBSERVED';$next['results'][$target]=$value;
            $next['state']=$target==='native_final'?'TECHNICAL_QA_COMPLETE':'QUEUED';
            if(!$this->store->compareAndSwap($key,$record,$next))throw new \RuntimeException('Queue checkpoint unavailable.');
            $record=$next;
            if(!$this->jobs->finish($id,$owner,'SUCCESS'))throw new \RuntimeException('Queue completion unavailable.');
            $index=array_search($target,self::STAGES,true);
            if($index!==false&&isset(self::STAGES[$index+1])&&$this->schedule($b['id'],self::STAGES[$index+1],$id)===null)return ['state'=>'BLOCKED','production_authorized'=>false];
            return ['state'=>$next['state'],'provider_acceptance'=>'UNKNOWN','production_authorized'=>false];
        }catch(\Throwable){
            if($claimed){
                $this->jobs->finish($id,$owner,'HUMAN_REVIEW');
                if($key!==null&&$stage!==null&&is_array($record)){
                    $current=$this->store->read($key);
                    if(is_array($current)&&isset($record['claims'][$stage])&&($current['claims'][$stage]??null)===$record['claims'][$stage]){
                        $unknown=$current;$unknown['state']='UNKNOWN';$unknown['claims'][$stage]['state']='UNKNOWN';$this->store->compareAndSwap($key,$current,$unknown);
                    }
                }
            }
            // A persisted PENDING claim is deliberately retained as UNKNOWN/crash evidence.
            return ['state'=>'UNKNOWN','reconciliation_required'=>true,'production_authorized'=>false];
        }
    }
    private function schedule(string $transfer,string $stage,?int $dependency):?int
    {
        $key=self::key('transfer',$transfer);$r=$this->store->read($key);
        if(!is_array($r)||$this->authority->current($r['binding'],$r['grant'],$stage,$this->gate->now())===null)return null;
        $id=$r['jobs'][$stage]??null;
        if($id===null){$id=$this->jobs->create($transfer,$stage,$dependency);if($id===null)return null;$next=$r;$next['jobs'][$stage]=$id;if(!$this->store->compareAndSwap($key,$r,$next))return null;}
        return $this->jobs->wake($id)?$id:null;
    }
}
