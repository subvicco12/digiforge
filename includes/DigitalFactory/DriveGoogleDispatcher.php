<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;

/** One guarded attempt. No autonomous stage progression, retry, credential response or activation route. */
final class DriveGoogleDispatcher
{
    public function __construct(private DriveGrantStore $store,private DriveGoogleGate $gate,private DriveGoogleOAuth $oauth,
        private DriveGoogleAuthority $authority,private DriveGoogleHttp $http){}
    public function dispatch(array $plan,#[\SensitiveParameter] array $grant,string $stage,array $completed,
        #[\SensitiveParameter] string $source,string $attempt,int $now):array
    {
        $blocked=['state'=>'BLOCKED','provider_acceptance'=>'UNKNOWN','production_authorized'=>false];
        $ticket=null;$operationKey=null;$operation=null;$journal=new DriveGrantJournal($this->store);
        try{
            $b=DriveGoogleOAuth::binding($plan);
            if($now!==$this->gate->now()||$source==='' ||strlen($source)>DriveTransferRequest::LIMIT||!hash_equals($b['source_sha256'],hash('sha256',$source)))return $blocked;
            $completed=$this->completed($b,$stage,$completed);if($completed===null)return $blocked;
            $request=str_starts_with($stage,'reconcile_')?DriveTransferRequest::reconciliation(substr($stage,10),$b):DriveTransferRequest::build($stage,$b,$completed);
            $wire=DriveHttpsProtocol::encode($request,$b,$completed,$source);
            $connection=$this->authority->current($b,$grant,$stage,$now);if($connection===null)return $blocked;
            $ticket=$journal->reserve($grant,$b,$stage,$attempt,$now);if($ticket===null)return $blocked;
            if(in_array($stage,['original_upload','native_conversion'],true)){
                // Across grants/nonces, process restarts and credential refresh: never blindly repeat a create.
                $operationKey=$this->operationKey($b,'create:'.$stage);
                $operation=['binding'=>$b,'stage'=>$stage,'attempt_id'=>$attempt,'state'=>'PENDING','response_sha256'=>null];
                if(!$this->store->compareAndSwap($operationKey,[],$operation))return $blocked;
            }
            if($this->authority->current($b,$grant,$stage,$now)===null)return $blocked;
            $token=$this->oauth->access($b,$grant['connection_id'],$connection['generation'],$now);
            if($token===null||!$this->gate->allows($b)||$this->authority->current($b,$grant,$stage,$now)===null)return $blocked;
            $args=$wire['args'];$args['headers']['Authorization']='Bearer '.$token;
            $r=$this->http->exchange($b,$wire['url'],$args);unset($args,$token);
            if($this->authority->current($b,$grant,$stage,$now)===null)throw new \RuntimeException('Google authority changed.');
            $decoded=DriveHttpsProtocol::decode($request,$r['status']??0,$r['headers']??[],$r['body']??'');unset($r);
            // A valid wire observation is not semantic artifact acceptance. Existing source/export QA must still run.
            $value=$this->value($b,$stage,$decoded,$completed);
            $state=$value!==null?'OBSERVED':'UNKNOWN';$sha=DriveGoogleOAuth::digest($decoded);
            if($value!==null){
                $target=str_starts_with($stage,'reconcile_')?substr($stage,10):$stage;
                $checkpoint=$this->operationKey($b,'checkpoint:'.$target);$old=$this->store->read($checkpoint);
                $proof=['binding'=>$b,'value'=>$value];
                if($old!==$proof&&!$this->store->compareAndSwap($checkpoint,[],$proof))throw new \RuntimeException('Google checkpoint conflict.');
            }
            if(!$journal->finish($grant,$b,$ticket,$state==='OBSERVED'?'CONFIRMED':'UNKNOWN',$sha,$this->gate->now()))throw new \RuntimeException('Google result persistence unavailable.');
            if($operationKey!==null){$done=$operation;$done['state']=$state;$done['response_sha256']=$sha;if(!$this->store->compareAndSwap($operationKey,$operation,$done))throw new \RuntimeException('Google operation persistence unavailable.');}
            return ['state'=>$state,'observation'=>$decoded,'provider_acceptance'=>'UNKNOWN','production_authorized'=>false];
        }catch(\Throwable){
            if($ticket!==null){$journal->finish($grant,$plan,$ticket,'UNKNOWN',hash('sha256','google-attempt-unknown'),$this->gate->now());}
            if($operationKey!==null&&$operation!==null){$unknown=$operation;$unknown['state']='UNKNOWN';$this->store->compareAndSwap($operationKey,$operation,$unknown);}
            return ['state'=>'UNKNOWN','reconciliation_required'=>true,'provider_acceptance'=>'UNKNOWN','production_authorized'=>false];
        }
    }
    private function operationKey(array $b,string $stage):string
    {
        $identity=$b;unset($identity['id'],$identity['actor_id']);
        return DriveGoogleOAuth::key('operation',DriveGoogleOAuth::digest($identity).':'.$stage);
    }
    private function checkpoint(array $b,string $stage):?array
    {
        $r=$this->store->read($this->operationKey($b,'checkpoint:'.$stage));
        return is_array($r)&&($r['binding']??null)===$b&&is_array($r['value']??null)?$r['value']:null;
    }
    private function completed(array $b,string $stage,array $provided):?array
    {
        $completed=[];
        foreach(['original_upload','native_conversion'] as $role){
            $value=$this->checkpoint($b,$role);if($value!==null)$completed[$role]=$value;
            if(isset($provided[$role])&&($provided[$role]!==$value))return null;
        }
        $needs=match($stage){
            'original_upload'=>['folder'],'native_conversion'=>['original_upload','original_readback'],
            'original_readback'=>['original_upload'],'native_readback'=>['native_conversion'],
            'pdf_export'=>['native_conversion','native_readback'],'native_final'=>['native_conversion','native_readback','pdf_export'],
            default=>[],
        };
        foreach($needs as $required)if($this->checkpoint($b,$required)===null)return null;
        if(str_starts_with($stage,'reconcile_')){
            $r=$this->store->read($this->operationKey($b,'create:'.substr($stage,10)));
            if(!is_array($r)||($r['binding']??null)!==$b)return null;
        }
        return $completed;
    }
    private function value(array $b,string $stage,array $r,array $completed):?array
    {
        if(($r['status']??0)!==200)return null;
        if(str_starts_with($stage,'reconcile_')){
            if(count($r['files']??[])!==1)return null;
            $r=['status'=>200,'metadata'=>$r['files'][0]];$stage=substr($stage,10);
        }
        if($stage==='folder'){
            $m=$r['metadata']??[];
            return ($m['id']??null)===DriveTransferRequest::FOLDER&&($m['mimeType']??null)==='application/vnd.google-apps.folder'
                &&($m['trashed']??null)===false&&($m['capabilities']['canAddChildren']??null)===true?['id'=>DriveTransferRequest::FOLDER]:null;
        }
        if(in_array($stage,['original_readback','pdf_export'],true)){
            $bytes=$r['bytes']??null;if(!is_string($bytes))return null;$sha=hash('sha256',$bytes);
            if($stage==='original_readback'&&!hash_equals($b['source_sha256'],$sha)||$stage==='pdf_export'&&!str_starts_with($bytes,'%PDF-'))return null;
            return ['sha256'=>$sha];
        }
        $m=$r['metadata']??[];$role=$stage==='original_upload'?'original_upload':'native_conversion';
        if(!DriveTransferRequest::validId($m['id']??null)||$m['id']===DriveTransferRequest::FOLDER
            ||($m['mimeType']??null)!==($role==='original_upload'?DriveTransferRequest::DOCX:DriveTransferRequest::DOC)
            ||($m['parents']??null)!==[DriveTransferRequest::FOLDER]||($m['trashed']??null)!==false
            ||($m['capabilities']['canDownload']??null)!==true||!is_string($m['version']??null)
            ||preg_match('/^[1-9][0-9]{0,19}$/D',$m['version'])!==1)return null;
        $p=$m['appProperties']??[];
        if(count($p)!==3||($p['digiforge_transfer']??null)!==$b['id']||($p['digiforge_role']??null)!==$role||($p['source_sha256']??null)!==$b['source_sha256'])return null;
        if($role==='native_conversion'&&$m['id']===($completed['original_upload']['id']??null))return null;
        if(in_array($stage,['native_readback','native_final'],true)&&$m['id']!==($completed['native_conversion']['id']??null))return null;
        if($stage==='native_final'&&$m['version']!==($this->checkpoint($b,'native_readback')['version']??null))return null;
        return ['id'=>$m['id'],'version'=>$m['version']];
    }

}
