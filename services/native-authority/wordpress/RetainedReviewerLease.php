<?php
declare(strict_types=1);
namespace DigiForge\NativeAuthority\WordPress;
use DigiForge\Connector\Printify\NativeSourceTransaction;
use DigiForge\Security\ShopAccessPolicy;

/** Process-local, single-pin proof. No exported JSON/Boolean can reconstruct this capability. */
final class RetainedReviewerLease
{
    private bool $attempted=false;
    private bool $pinned=false;
    private bool $released=false;
    private bool $poisoned=false;
    private bool $committed=false;
    private function __construct(private \wpdb $original,private \wpdb $aux,private \mysqli $originalHandle,private \mysqli $auxHandle,private int $originalThread,private int $auxThread,private string $id,private string $generation,private int $actor,private int $reviewer,private string $shop,private string $cookie,private array $evidence,private string $commitMarker){}
    private function __clone(){}
    public function __serialize():array{throw new \LogicException('Retained authority cannot be serialized');}
    public function __unserialize(array $data):void{throw new \LogicException('Retained authority cannot be restored');}
    public function __wakeup():void{throw new \LogicException('Retained authority cannot be restored');}

    /** Same checked factory as RetainedReviewerAuthority::capture; no caller supplied evidence. */
    public static function capture(string $shop,int $reviewer):self|\WP_Error {
        global $wpdb;
        try{
            if($reviewer<1||!in_array($shop,ShopAccessPolicy::canonicalShops(),true))return RetainedReviewerAuthority::error('scope');
            $original=$wpdb;$backend=Runtime::backend();if(is_wp_error($backend))return $backend;
            if(!Runtime::outsideTransaction($original))return RetainedReviewerAuthority::error('caller_transaction');
            $schema=OfflineSchema::verify($original);if(is_wp_error($schema))return $schema;
            $actor=get_current_user_id();$cookie=Runtime::cookies();if(!Runtime::pureActor($actor,$cookie))return RetainedReviewerAuthority::error('actor');
            $oh=Runtime::handle($original);if($oh===false)return RetainedReviewerAuthority::error('connection');$ot=$oh->thread_id;
            $aux=new \wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$aux->set_prefix($original->prefix);$aux->suppress_errors(true);
            $ah=Runtime::handle($aux);if($ah===false||$ah->thread_id===$ot||!Runtime::outsideTransaction($aux))return RetainedReviewerAuthority::error('auxiliary_connection');$at=$ah->thread_id;
            $before=Runtime::generation($aux);if(is_wp_error($before))return $before;
            $evidence=Runtime::authorize($aux,$actor,$reviewer,$shop,$cookie);if(is_wp_error($evidence))return $evidence;
            $after=Runtime::generation($aux);
            if(is_wp_error($after)||$before!==$after||$evidence['expires_at']<=time()||!Runtime::physical($original,$oh,$ot)||!Runtime::physical($aux,$ah,$at)||!Runtime::pureActor($actor,$cookie))return RetainedReviewerAuthority::error('capture_changed');
            $id=bin2hex(random_bytes(32));
            if($aux->insert(OfflineSchema::leasesTable($aux),['lease_id'=>$id,'generation'=>$before,'actor_id'=>$actor,'reviewer_id'=>$reviewer,'shop'=>$shop,'session_sha256'=>$evidence['session_sha256'],'expires_at'=>$evidence['expires_at'],'state'=>'ACTIVE'])!==1)return RetainedReviewerAuthority::error('lease_write');
            $lease=new self($original,$aux,$oh,$ah,$ot,$at,$id,$before,$actor,$reviewer,$shop,$cookie,$evidence,bin2hex(random_bytes(32)));
            return $lease->check(false)===true?$lease:RetainedReviewerAuthority::error('capture_changed');
        }catch(\Throwable){return RetainedReviewerAuthority::error('capture_unavailable');}
    }
    private function fail(string $reason):\WP_Error{$this->poisoned=true;return RetainedReviewerAuthority::error($reason);}
    private function connected():bool {
        global $wpdb;
        return $wpdb===$this->original&&Runtime::physical($this->original,$this->originalHandle,$this->originalThread)&&Runtime::physical($this->aux,$this->auxHandle,$this->auxThread);
    }
    private function row(string $state):bool {
        $this->aux->last_error='';$row=$this->aux->get_row($this->aux->prepare('SELECT lease_id,generation,actor_id,reviewer_id,shop,session_sha256,expires_at,state,commit_marker FROM `'.OfflineSchema::leasesTable($this->aux).'` WHERE lease_id=%s',$this->id),ARRAY_A);
        return $this->aux->last_error===''&&$row===['lease_id'=>$this->id,'generation'=>$this->generation,'actor_id'=>(string)$this->actor,'reviewer_id'=>(string)$this->reviewer,'shop'=>$this->shop,'session_sha256'=>$this->evidence['session_sha256'],'expires_at'=>(string)$this->evidence['expires_at'],'state'=>$state,'commit_marker'=>$this->released&&$this->committed?$this->commitMarker:null];
    }
    private function check(bool $inTransaction):true|\WP_Error {
        try{
            if($this->poisoned||!$this->connected()||$this->evidence['expires_at']<=time()||!Runtime::pureActor($this->actor,$this->cookie))return $this->fail('invalid_lease');
            if($inTransaction){$tx=NativeSourceTransaction::id();if(is_wp_error($tx)||$tx!==(string)$this->originalThread)return $this->fail('caller_transaction');}
            elseif(!Runtime::outsideTransaction($this->original))return $this->fail('caller_transaction');
            if(!Runtime::outsideTransaction($this->aux))return $this->fail('auxiliary_transaction');
            $schema=OfflineSchema::verify($this->aux);if(is_wp_error($schema))return $this->fail('schema_changed');
            $before=Runtime::generation($this->aux);
            if(is_wp_error($before)||$before!==$this->generation||!$this->row($this->released?'RELEASED':($this->pinned?'PINNED':'ACTIVE')))return $this->fail('generation_changed');
            $auth=Runtime::authorize($this->aux,$this->actor,$this->reviewer,$this->shop,$this->cookie);
            $after=Runtime::generation($this->aux);
            if(is_wp_error($auth)||$auth['session_sha256']!==$this->evidence['session_sha256']||$auth['expires_at']<=time()||is_wp_error($after)||$after!==$before)return $this->fail('authorization_changed');
            // Current lock reads see caller's own authority writes, unlike auxiliary committed reads.
            if($inTransaction){
                $this->original->last_error='';
                $marker=Runtime::privateVar($this->original,$this->original->prepare('SELECT commit_marker FROM `'.OfflineSchema::leasesTable($this->original).'` WHERE lease_id=%s FOR UPDATE',$this->id));
                if($this->original->last_error!==''||$marker!==$this->commitMarker)return $this->fail('transaction_changed');
                // Last SQL: current own writes must be checked AFTER every witness read.
                if(Runtime::generation($this->original,true)!==$this->generation)return $this->fail('generation_changed');
            }
            return !is_wp_error(Runtime::backend())&&$this->connected()&&$this->evidence['expires_at']>time()&&Runtime::pureActor($this->actor,$this->cookie)?true:$this->fail('actor_changed');
        }catch(\Throwable){return $this->fail('validation_unavailable');}
    }
    /** Fresh public authorization before the consumer starts its own transaction. */
    public function validateBeforePin():true|\WP_Error {
        return $this->attempted||$this->pinned||$this->released?$this->fail('replay'):$this->check(false);
    }
    public function pin():true|\WP_Error {
        if($this->attempted||$this->released||$this->poisoned)return $this->fail('replay');$this->attempted=true;
        try{
            if(!$this->connected()||is_wp_error(NativeSourceTransaction::id()))return $this->fail('caller_transaction');
            // One-use marker is durable on the auxiliary connection, never in caller's rollback.
            $changed=$this->aux->query($this->aux->prepare('UPDATE `'.OfflineSchema::leasesTable($this->aux)."` SET state='PINNED' WHERE lease_id=%s AND state='ACTIVE'",$this->id));
            if($changed!==1)return $this->fail('lease_consumed');$this->pinned=true;
            if(Runtime::generation($this->original,true)!==$this->generation)return $this->fail('generation_changed');
            if(Runtime::privateQuery($this->original,$this->original->prepare('UPDATE `'.OfflineSchema::leasesTable($this->original)."` SET commit_marker=%s WHERE lease_id=%s AND state='PINNED' AND commit_marker IS NULL",$this->commitMarker,$this->id))!==1)return $this->fail('transaction_witness');
            return $this->check(true);
        }catch(\Throwable){return $this->fail('pin_unavailable');}
    }
    /** Precommit linearization check; expiry is not frozen by the generation row lock. */
    public function validate():true|\WP_Error {
        return !$this->pinned||$this->released?$this->fail('not_pinned'):$this->check(true);
    }
    public function release(?bool $expectedCommitted=null):true|\WP_Error {
        try{
            if($this->released||!$this->connected()||!Runtime::outsideTransaction($this->original))return $this->fail('release_state');
            $this->aux->last_error='';$marker=$this->aux->get_var($this->aux->prepare('SELECT commit_marker FROM `'.OfflineSchema::leasesTable($this->aux).'` WHERE lease_id=%s',$this->id));
            if($this->aux->last_error!==''||($marker!==null&&$marker!==$this->commitMarker))return $this->fail('release_unknown');
            $this->committed=$marker===$this->commitMarker;$mismatch=$expectedCommitted!==null&&$expectedCommitted!==$this->committed;$this->released=true;
            $changed=$this->aux->query($this->aux->prepare('UPDATE `'.OfflineSchema::leasesTable($this->aux)."` SET state='RELEASED' WHERE lease_id=%s AND state=%s",$this->id,$this->pinned?'PINNED':'ACTIVE'));
            if($changed!==1||!$this->row('RELEASED')||!$this->connected())return $this->fail('release_unknown');
            return $mismatch?$this->fail('commit_outcome_mismatch'):true;
        }catch(\Throwable){return $this->fail('release_unknown');}
    }
    /** Call after caller COMMIT and release. Denial never purports to undo a committed effect. */
    public function finalAdmission():true|\WP_Error {
        return !$this->released||!$this->pinned||!$this->committed?$this->fail('not_released'):$this->check(false);
    }
}
