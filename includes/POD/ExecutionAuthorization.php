<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;

/**
 * Creates short-lived execution authorization evidence. It does not execute an
 * Etsy/fulfillment action; downstream adapters must independently verify it.
 */
final class ExecutionAuthorization
{
    /** @return array<string,mixed>|WP_Error */
    public static function issue(array $approval,string $action,int $authorizerId,string $nonce,int $ttlSeconds=900):array|WP_Error
    {
        if(($approval['state']??'')!=='HUMAN_APPROVED'||($approval['decision']??'')!=='APPROVE')
            return new WP_Error('digiforge_pod_not_human_approved','Human approval is required before execution authorization.',['status'=>409]);
        if(($approval['publishing_enabled']??null)!==false||($approval['order_execution_enabled']??null)!==false)
            return new WP_Error('digiforge_pod_approval_unlocked','Approval evidence must remain execution locked.',['status'=>409]);
        $hash=strtolower(trim((string)($approval['evidence_hash']??'')));
        if(!preg_match('/^[a-f0-9]{64}$/',$hash))return new WP_Error('digiforge_pod_evidence_hash','Valid approval evidence hash required.',['status'=>400]);
        $action=strtoupper(trim($action));
        if(!in_array($action,['ETSY_DRAFT_CREATE','PROVIDER_ORDER_SUBMIT'],true))
            return new WP_Error('digiforge_pod_execution_action','Unsupported execution action.',['status'=>400]);
        if($authorizerId<1)return new WP_Error('digiforge_pod_execution_authorizer','Explicit human authorizer required.',['status'=>403]);
        if(!preg_match('/^[A-Za-z0-9_-]{24,128}$/',$nonce))return new WP_Error('digiforge_pod_execution_nonce','A strong one-time nonce is required.',['status'=>400]);
        if($ttlSeconds<60||$ttlSeconds>900)return new WP_Error('digiforge_pod_execution_ttl','Authorization TTL must be 60-900 seconds.',['status'=>400]);
        $issued=time();$payload=['action'=>$action,'evidence_hash'=>$hash,'authorized_by'=>$authorizerId,'nonce'=>$nonce,'issued_at'=>$issued,'expires_at'=>$issued+$ttlSeconds];
        $canonical=$payload;ksort($canonical);
        return ['state'=>'EXECUTION_AUTHORIZED','authorization'=>$payload,'authorization_hash'=>hash('sha256',(string)wp_json_encode($canonical)),'executed'=>false];
    }
}
