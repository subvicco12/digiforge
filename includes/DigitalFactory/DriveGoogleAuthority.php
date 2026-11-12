<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;
/** Trusted server issuer. A caller-supplied local review is never an execution grant. */
final class DriveGoogleAuthority
{
    public function __construct(private DriveGrantStore $store,private DriveGoogleGate $gate,private DriveGoogleOAuth $oauth){}
    public function issue(array $plan,string $connection,array $stages,int $quota,int $now,int $ttl=300):?array
    {
        try{
            $b=DriveGoogleOAuth::binding($plan);if($now!==$this->gate->now()||$ttl<1||$ttl>900||!$this->gate->allows($b))return null;
            $c=$this->oauth->connection($b,$connection,$now);if($c===null||$c['expires_at']<=$now+$ttl+30)return null;
            $g=$b+['issuer'=>'digiforge_google_v1','connection_id'=>$connection,'account_id'=>$c['account_id'],'connection_generation'=>$c['generation'],
                'nonce'=>bin2hex(random_bytes(32)),'issued_at'=>$now,'expires_at'=>$now+$ttl,'remaining_attempts'=>$quota,'stages'=>$stages];
            if(!is_string($stages[0]??null)||!DriveExecutionGrant::validate($g,$b,$stages[0],$now,false,true)||!$this->gate->allows($b)
                ||$this->oauth->connection($b,$connection,$now)!==$c)return null;
            return $this->store->compareAndSwap(DriveGrantJournal::key($g),[],['grant'=>$g,'revoked'=>false,'attempts'=>[]])?$g:null;
        }catch(\Throwable){return null;}
    }
    /** Recheck exact issued record and current account/generation before every credential read and send.
     * @phpstan-impure
     */
    public function current(array $plan,#[\SensitiveParameter] array $grant,string $stage,int $now):?array
    {
        try{
            $now=$this->gate->now();$b=DriveGoogleOAuth::binding($plan);
            if(($grant['issuer']??null)!=='digiforge_google_v1'||!$this->gate->allows($b)||!DriveExecutionGrant::validate($grant,$b,$stage,$now,false,true))return null;
            $row=$this->store->read(DriveGrantJournal::key($grant));
            if(!is_array($row)||($row['grant']??null)!==$grant||($row['revoked']??null)!==false)return null;
            $c=$this->oauth->connection($b,$grant['connection_id'],$now);
            if($c===null||($grant['account_id']??null)!==$c['account_id']||($grant['connection_generation']??null)!==$c['generation'])return null;
            return $c;
        }catch(\Throwable){return null;}
    }
}
