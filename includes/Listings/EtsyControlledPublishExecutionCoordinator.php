<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/** Executes exactly one prepared PUBLISH_LISTING operation through certified Etsy transport/lifecycle boundaries. */
final class EtsyControlledPublishExecutionCoordinator
{
    public function __construct(private EtsyOperationRepository $operations,private ?EtsyControlledHttpExecutor $executor=null){}
    /** @return array<string,mixed>|WP_Error */
    public function execute(array $prepared,array $operation,array $tokenMetadata,array $plan,array $headers=[]):array|WP_Error
    {
        if(($plan['state']??'')!=='ETSY_PUBLISH_OPERATION_PLANNED'||($plan['operation']??'')!=='PUBLISH_LISTING'||($plan['publish_permitted']??null)!==true||($plan['network_request_permitted']??null)!==false)
            return self::error('plan','A non-executed governed publish plan is required.');
        if(strtoupper((string)($operation['operation_type']??''))!=='PUBLISH_LISTING')return self::error('binding','Ledger operation must be PUBLISH_LISTING.');
        $shop=(string)($operation['shop_reference']??'');$listing=(string)($operation['resource_reference']??'');
        if(!ctype_digit($shop)||!ctype_digit($listing)||!hash_equals("/application/shops/{$shop}/listings/{$listing}",(string)($plan['endpoint']??'')))return self::error('scope','Publish plan must target the exact persisted Etsy listing identity.');
        $pf=EtsyRequestFingerprint::fromPayload((array)($plan['payload']??[]));$af=EtsyRequestFingerprint::fromPayload((array)($prepared['payload']??[]));
        if($pf instanceof WP_Error)return $pf;if($af instanceof WP_Error)return $af;if(!hash_equals($pf,$af))return self::error('payload','Publish payload differs from authorized payload.');
        $transport=(new EtsyControlledTransportOrchestrator())->prepare($prepared,$operation,$tokenMetadata,'PATCH',(string)$plan['endpoint'],$headers);
        if($transport instanceof WP_Error)return $transport;
        $id=(int)($operation['id']??0);if($id<1)return self::error('claimed','Publish operation is already being executed.');
        $lock=$this->operations->acquireExecutionLock($id);if($lock instanceof WP_Error)return $lock;if($lock!==true)return self::error('claimed','Publish operation is already being executed.');
        try{
            $current=$this->operations->find($id);
if($current instanceof WP_Error)return $current;if(!is_array($current)||(string)($current['state']??'')!==EtsyOperationLifecycle::NOT_SENT)return self::error('stale','Only a current NOT_SENT publish operation may execute.');
            $e=$this->operations->recordReconciliationEvidence($id,(array)$plan['payload']);if($e instanceof WP_Error)return $e;
            $transport['operation_type']='PUBLISH_LISTING';$transport['external_reference']=$listing;
            $authorized=EtsyPublishLiveTransportInterlock::authorize($transport);if($authorized instanceof WP_Error)return $authorized;
            $execution=($this->executor??new EtsyControlledHttpExecutor())->execute($transport,$authorized);if($execution instanceof WP_Error)return $execution;
            $handoff=EtsyConsumedPermitHandoff::validate($prepared);if($handoff instanceof WP_Error)return $handoff;
            $invocation=EtsyAdapterInvocationPlan::build($handoff,$operation);if($invocation instanceof WP_Error)return $invocation;
            $lifecycle=(new EtsyAttemptLifecycleService($this->operations))->record($invocation,$execution);if($lifecycle instanceof WP_Error)return $lifecycle;
            return ['state'=>'ETSY_CONTROLLED_PUBLISH_EXECUTION_RECORDED','operation_id'=>$id,'lifecycle'=>$lifecycle,'reconciliation_required'=>(bool)($lifecycle['reconciliation_required']??false),'automatic_retry_permitted'=>false,'publish_performed'=>true,'external_execution_performed'=>true];
        }finally{$this->operations->releaseExecutionLock($id);}
    }
    private static function error(string $c,string $m):WP_Error{return new WP_Error('digiforge_etsy_controlled_publish_'.$c,$m,['status'=>409]);}
}
