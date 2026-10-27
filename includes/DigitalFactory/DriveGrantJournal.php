<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;

/**
 * Durable consumption of an ALREADY ISSUED Google grant. Not an issuer or
 * authorization gate. No credentials or HTTP. A reservation never authorizes
 * dispatch: current ownership, connection and safety must be checked separately.
 */
final class DriveGrantJournal
{
    public function __construct(private DriveGrantStore $store) {}
    public static function key(#[\SensitiveParameter] array $grant):string
    {
        return 'digiforge_google_grant_'.hash('sha256',(is_string($grant['nonce']??null)?$grant['nonce']:''));
    }
    public function reserve(#[\SensitiveParameter] array $grant,#[\SensitiveParameter] array $plan,string $stage,string $attempt,int $now):?array
    {
        try {
            if(!self::sha($attempt)||!DriveExecutionGrant::validate($grant,$plan,$stage,$now,false,true))return null;
            $key=self::key($grant);$before=$this->store->read($key);
            if(!self::valid($before,$grant)||$before['revoked']||isset($before['attempts'][$attempt])||count($before['attempts']) >= $grant['remaining_attempts'])return null;
            foreach($before['attempts'] as $previous){
                // A crash, rejection or timeout cannot become another create.
                // Reconcile via the explicitly allowed read stage instead.
                if(in_array($stage,['original_upload','native_conversion'],true)&&$previous['stage']===$stage)return null;
            }
            $claim=bin2hex(random_bytes(32));
            $entry=['stage'=>$stage,'state'=>'PENDING','claim'=>$claim,'reserved_at'=>$now,'receipt_sha256'=>null];
            $next=$before;$next['attempts'][$attempt]=$entry;
            if(!$this->store->compareAndSwap($key,$before,$next))return null;
            return ['attempt_id'=>$attempt,'stage'=>$stage,'claim'=>$claim,'execution_authorized'=>false];
        }catch(\Throwable){return null;}
    }
    public function finish(#[\SensitiveParameter] array $grant,#[\SensitiveParameter] array $plan,#[\SensitiveParameter] array $ticket,string $outcome,string $receipt,int $now):bool
    {
        try {
            $stage=$ticket['stage']??null;$id=$ticket['attempt_id']??null;$claim=$ticket['claim']??null;
            if(!is_string($stage)||!self::sha($id)||!self::sha($claim)||!self::sha($receipt)
                ||!in_array($outcome,['CONFIRMED','REJECTED','UNKNOWN'],true)
                ||!DriveExecutionGrant::validate($grant,$plan,$stage,$now,false,true))return false;
            $key=self::key($grant);$before=$this->store->read($key);
            if(!self::valid($before,$grant)||$before['revoked'])return false;
            $entry=$before['attempts'][$id]??null;
            if(!is_array($entry)||$entry['state']!=='PENDING'||$entry['stage']!==$stage||!hash_equals($entry['claim'],$claim)||$now<$entry['reserved_at'])return false;
            $next=$before;$next['attempts'][$id]['state']=$outcome;$next['attempts'][$id]['receipt_sha256']=$receipt;
            return $this->store->compareAndSwap($key,$before,$next);
        }catch(\Throwable){return false;}
    }
    /** Revocation is monotonic, including expired or exhausted grants. */
    public function revoke(#[\SensitiveParameter] array $grant):bool
    {
        try {
            $key=self::key($grant);$before=$this->store->read($key);
            if(!self::valid($before,$grant))return false;
            if($before['revoked'])return true;
            $next=$before;$next['revoked']=true;
            return $this->store->compareAndSwap($key,$before,$next);
        }catch(\Throwable){return false;}
    }
    private static function valid(?array $record,array $grant):bool
    {
        if(!is_array($record)||array_keys($record)!==['grant','revoked','attempts']
            ||($record['grant']??null)!==$grant||!is_bool($record['revoked']??null)
            ||!is_array($record['attempts']??null)||!is_int($grant['remaining_attempts']??null)
            ||$grant['remaining_attempts']<1||$grant['remaining_attempts']>14
            ||count($record['attempts'])>$grant['remaining_attempts'])return false;
        foreach($record['attempts'] as $id=>$entry){
            if(!self::sha($id)||!is_array($entry)||array_keys($entry)!==['stage','state','claim','reserved_at','receipt_sha256']
                ||!is_string($entry['stage']??null)||!in_array($entry['stage'],$grant['stages']??[],true)
                ||!in_array($entry['state']??null,['PENDING','CONFIRMED','REJECTED','UNKNOWN'],true)
                ||!self::sha($entry['claim']??null)||!is_int($entry['reserved_at']??null)
                ||$entry['reserved_at']<($grant['issued_at']??PHP_INT_MAX)||$entry['reserved_at']>=($grant['expires_at']??0)
                ||($entry['state']==='PENDING'?$entry['receipt_sha256']!==null:!self::sha($entry['receipt_sha256'])))return false;
        }
        return true;
    }
    private static function sha(mixed $value):bool { return is_string($value)&&preg_match('/^[a-f0-9]{64}$/D',$value)===1; }
}
