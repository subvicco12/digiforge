<?php
declare(strict_types=1);
namespace DigiForge\Queue;

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Orders\{HeldFulfillmentPreparation,PersonalizationReadContext};
use DigiForge\Portal\ScopedCapabilityPolicy;
use DigiForge\Portal\ScopedCapabilityPolicyRepository;
use DigiForge\Security\ShopAccess;
use WP_Error;

/** Explicit current-actor local metadata work. No scheduler registration or provider execution. */
final class Shop2HeldPreparationDispatcher
{
    private const TYPE='shop2_held_preparation';
    public function __construct(private ?PersonalizationReadContext $personalizationContext=null) {}

    /** Queue references to exact currently approved evidence; never arms automation. */
    public function enqueue(string $shop,int $orderId,int $intentId,string $key,array $dependencies=[]):int|WP_Error
    {
        $access=$this->access($shop);if(is_wp_error($access))return $access;
        if($orderId<1||$intentId<1||$key===''||strlen($key)>191)return self::error('input','Exact bounded order, intent and idempotency identities are required.');
        $prepared=(new HeldFulfillmentPreparation($this->personalizationContext))->prepare($shop,$orderId,$intentId);
        if(is_wp_error($prepared))return $prepared;
        if(!$this->held($prepared))return self::error('handler','Only held local preparation can enter this queue.');
        $payload=['schema'=>1,'shop_key'=>$shop,'actor_id'=>get_current_user_id(),'order_id'=>$orderId,
            'intent_id'=>$intentId,'plan_payload_hash'=>$prepared['plan_payload_hash'],'evidence_hash'=>$prepared['current_evidence_hash']];
        $job=(new JobRepository())->enqueue(self::TYPE,$payload,'shop2-held:'.hash('sha256',$key),$dependencies);
        return is_int($job)&&$job>0?$job:(is_wp_error($job)?$job:self::error('enqueue','Local job persistence is unavailable.',503));
    }

    /** One explicit job only; anonymous/background callers and all default settings are denied. */
    public function dispatch(int $jobId):array|WP_Error
    {
        $access=$this->access('personalized_pod');if(is_wp_error($access))return $access;
        $enabled=$this->enabled();if(is_wp_error($enabled))return $enabled;
        $row=$this->read($jobId);if(is_wp_error($row))return $row;
        $payload=$row['decoded'];
        $access=$this->access($payload['shop_key']);if(is_wp_error($access))return $access;
        if($payload['actor_id']!==get_current_user_id())return self::error('actor','The current actor must match the queued actor.',403);
        if(!in_array($row['state'],['BLOCKED','QUEUED'],true))return self::error('state','Only an unstarted local job can be dispatched.');
        $repo=new JobRepository();
        if($row['state']==='BLOCKED'&&!$repo->transition($jobId,'BLOCKED','QUEUED'))return self::error('state','The queued state changed concurrently.');
        $owner='shop2_'.bin2hex(random_bytes(16));
        if(!$repo->acquireLease($jobId,$owner,60))return self::error('lease','Current dependency success and an available lease are required.');
        if(!$repo->start($jobId,$owner)){$repo->releaseLease($jobId,$owner);return self::error('start','The owned local job could not start.');}

        try {
            $current=$this->read($jobId);
            if(is_wp_error($current)||$current['payload']!==$row['payload']||$current['locked_by']!==$owner||$current['state']!=='RUNNING')return self::error('claim_changed','The claimed job changed; lease reconciliation is required.');
            $enabled=$this->enabled();$access=$this->access($payload['shop_key']);
            if(is_wp_error($enabled)||is_wp_error($access))$result=is_wp_error($enabled)?$enabled:$access;
            elseif($payload['actor_id']!==get_current_user_id())$result=self::error('actor','The current actor changed during local preparation.',403);
            else $result=(new HeldFulfillmentPreparation())->prepare($payload['shop_key'],$payload['order_id'],$payload['intent_id']);
            if(!is_wp_error($result)&&(!$this->held($result)||($result['plan_payload_hash']??null)!==$payload['plan_payload_hash']||($result['current_evidence_hash']??null)!==$payload['evidence_hash']))$result=self::error('evidence_changed','Prepare and review the exact current order evidence again.');
            $enabled=$this->enabled();$access=$this->access($payload['shop_key']);
            if(is_wp_error($enabled)||is_wp_error($access))$result=is_wp_error($enabled)?$enabled:$access;
            if($payload['actor_id']!==get_current_user_id())$result=self::error('actor','The current actor changed during local preparation.',403);
        } catch(\Throwable) {
            $result=self::error('unknown','Local preparation outcome is unavailable; human reconciliation is required.',503);
        }

        if(is_wp_error($result)){
            // This handler supplies no safe automatic retry boundary. Reuse the conservative policy.
            $policy=QueueFailurePolicy::evaluate($result->get_error_code(),['retry_permitted'=>false],(int)$row['attempts'],(int)$row['max_attempts'],60);
            if($policy['state']!=='HUMAN_REVIEW'||!$this->finish($row,$owner,'HUMAN_REVIEW',$result->get_error_code()))return self::error('completion_conflict','The job claim changed or final persistence is unavailable; reconcile its lease.',503);
            return $result;
        }
        if(!$this->finish($row,$owner,'SUCCESS'))return self::error('completion_conflict','The job claim changed or final persistence is unavailable; reconcile its lease.',503);
        return ['job_id'=>$jobId,'job_state'=>'SUCCESS','result'=>$result,'release_hold'=>true,
            'retry_permitted'=>false,'external_execution_authorized'=>false,'external_execution_performed'=>false];
    }

    private function enabled():true|WP_Error
    {
        if(get_current_user_id()<1||!is_user_logged_in())return self::error('actor','An authenticated current actor is required.',403);
        if(!Settings::is_enabled('order_automation'))return self::error('disabled','Order automation is disabled or globally stopped.',403);
        // These evidence-only records can further restrict local work. They never
        // replace global activation, current actor access or the handler's approvals.
        $repository=new ScopedCapabilityPolicyRepository();$shopState=null;$workflowState=null;
        $shop=$repository->latest('personalized_pod','shop','order_automation',$shopState);
        $workflow=$repository->latest('personalized_pod','held_preparation','order_automation',$workflowState);
        if($shopState!=='AVAILABLE'||$workflowState!=='AVAILABLE')return self::error('restriction_unavailable','Current local dispatch restrictions are unavailable or malformed.',503);
        if(!is_array($shop)||!is_array($workflow))return self::error('restriction_missing','Both shop and held-preparation restrictions must be explicit.',403);
        foreach(['shop'=>$shop,'held_preparation'=>$workflow] as $scope=>$record){
            if($record['shop_key']!=='personalized_pod'||$record['workflow_key']!==$scope||$record['capability']!=='order_automation')return self::error('restriction_scope','Local dispatch restrictions must match this exact shop and workflow.',403);
        }
        $policy=ScopedCapabilityPolicy::evaluate('order_automation',['order_automation'=>(int)$shop['enabled']===1],['order_automation'=>(int)$workflow['enabled']===1]);
        return $policy['effective_enabled']===true?true:self::error('restricted','Shop or held-preparation policy disables local dispatch.',403);
    }

    private function access(string $shop):true|WP_Error
    {
        if($shop!=='personalized_pod'||get_current_user_id()<1||!is_user_logged_in())return self::error('scope','The current actor must have explicit Shop 2 access.',403);
        foreach(['manage_digiforge_automation','manage_digiforge_orders','manage_digiforge_pod'] as $cap){
            $access=ShopAccess::authorize($shop,$cap);if(is_wp_error($access))return $access;
        }
        return true;
    }

    private function read(int $id):array|WP_Error
    {
        if($id<1)return self::error('job','One exact persisted local job is required.');
        global $wpdb;$wpdb->last_error='';
        $row=$wpdb->get_row($wpdb->prepare('SELECT id,job_type,state,payload,locked_by,attempts,max_attempts FROM '.Tables::jobs().' WHERE id=%d',$id),ARRAY_A);
        if($wpdb->last_error!==''||!is_array($row))return self::error('job','The local job is unavailable.',503);
        if($row['job_type']!==self::TYPE||!is_string($row['payload'])||strlen($row['payload'])>8192)return self::error('payload','The job is not an exact held-preparation reference.');
        try {$payload=json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);}catch(\Throwable){return self::error('payload','Malformed held-preparation references.');}
        if(!is_array($payload)||array_diff(array_keys($payload),['schema','shop_key','actor_id','order_id','intent_id','plan_payload_hash','evidence_hash','_digiforge_dependencies'])!==[]||($payload['schema']??null)!==1||($payload['shop_key']??null)!=='personalized_pod')return self::error('payload','Unexpected held-preparation references.');
        foreach(['actor_id','order_id','intent_id'] as $key)if(!is_int($payload[$key]??null)||$payload[$key]<1)return self::error('payload','Positive exact stored identities are required.');
        foreach(['plan_payload_hash','evidence_hash'] as $key)if(!is_string($payload[$key]??null)||!preg_match('/^[a-f0-9]{64}$/D',$payload[$key]))return self::error('payload','Exact approved evidence fingerprints are required.');
        $row['decoded']=$payload;return $row;
    }

    private function held(array $result):bool
    {
        return ($result['state']??null)==='HELD_FULFILLMENT_PREPARED'&&($result['release_hold']??null)===true
            &&($result['retry_permitted']??null)===false&&($result['external_execution_authorized']??null)===false
            &&($result['external_execution_performed']??null)===false&&($result['preparation']['adapter_invoked']??null)===false;
    }

    /** Complete only the exact payload still owned under the same live repository lease. */
    private function finish(array $row,string $owner,string $state,string $error=''):bool
    {
        global $wpdb;$wpdb->last_error='';$now=current_time('mysql',true);
        $updated=$wpdb->query($wpdb->prepare('UPDATE '.Tables::jobs()." SET state=%s,last_error=%s,completed_at=CASE WHEN %s='SUCCESS' THEN %s ELSE NULL END,locked_by=NULL,locked_at=NULL,lease_expires_at=NULL,updated_at=%s "
            ."WHERE id=%d AND job_type=%s AND state='RUNNING' AND BINARY locked_by=BINARY %s AND lease_expires_at >= %s AND BINARY payload=BINARY %s",
            $state,sanitize_text_field($error),$state,$now,$now,(int)$row['id'],self::TYPE,$owner,$now,$row['payload']));
        return $updated===1&&$wpdb->last_error==='';
    }

    private static function error(string $code,string $message,int $status=409):WP_Error
    {
        return new WP_Error('shop2_held_dispatch_'.$code,$message,['status'=>$status,'release_hold'=>true,
            'retry_permitted'=>false,'external_execution_authorized'=>false,'external_execution_performed'=>false]);
    }
}
