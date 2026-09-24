<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/**
 * Repository-bound coordinator for read-only Etsy reconciliation.
 * Provider lookup is GET-only and never authorizes retry, publish, or mutation.
 */
final class EtsyReconciliationWorkflow
{
    public function __construct(private EtsyOperationRepository $operations) {}

    /** @return array<string,mixed>|WP_Error */
    public function prepare(int $operationId,int $integrationId):array|WP_Error
    {
        if($operationId<1||$integrationId<1)return self::error('identity','Persisted operation and integration identifiers are required.');
        if(!$this->operations->acquireExecutionLock($operationId))return self::error('locked','Etsy operation reconciliation is already in progress.');
        try{
            $operation=$this->operations->find($operationId);
            if(!is_array($operation)||(string)($operation['state']??'')!==EtsyOperationLifecycle::UNKNOWN)return self::error('state','Persisted Etsy operation must be UNKNOWN.');
            $ready=(new EtsyReconciliationService($this->operations))->begin($operationId);
            if($ready instanceof WP_Error)return $ready;
            $plan=EtsyReconciliationLookupPlan::build($ready,$integrationId);
            if($plan instanceof WP_Error)return $plan;
            return [
                'state'=>'ETSY_RECONCILIATION_WORKFLOW_PREPARED',
                'operation_id'=>$operationId,
                'integration_id'=>$integrationId,
                'operation_type'=>(string)($operation['operation_type']??''),
                'lookup_plan'=>$plan,
                'mutation_permitted'=>false,
                'external_retry_permitted'=>false,
                'automatic_retry_permitted'=>false,
                'network_request_permitted'=>false,
                'external_execution_performed'=>false,
            ];
        }finally{$this->operations->releaseExecutionLock($operationId);}
    }

    /** @return array<string,mixed>|WP_Error */
    public function record(int $operationId,array $lookup):array|WP_Error
    {
        if($operationId<1||($lookup['state']??'')!=='ETSY_RECONCILIATION_LOOKUP_COMPLETED'
            ||(int)($lookup['operation_id']??0)!==$operationId
            ||($lookup['mutation_performed']??null)!==false
            ||($lookup['external_retry_performed']??null)!==false
            ||($lookup['automatic_retry_performed']??null)!==false
            ||!is_array($lookup['result']??null))return self::error('lookup','Bound read-only Etsy lookup evidence is required.');
        if(!$this->operations->acquireExecutionLock($operationId))return self::error('locked','Etsy operation reconciliation is already in progress.');
        try{
            $operation=$this->operations->find($operationId);
            if(!is_array($operation)||(string)($operation['state']??'')!==EtsyOperationLifecycle::RECONCILIATION)return self::error('state','Persisted Etsy operation must be RECONCILIATION.');
            $recorded=(new EtsyReconciliationResultService($this->operations))->record($operationId,$lookup['result']);
            if($recorded instanceof WP_Error)return $recorded;
            return ['state'=>'ETSY_RECONCILIATION_WORKFLOW_RECORDED','operation_id'=>$operationId,'result'=>$recorded,'external_retry_permitted'=>false,'automatic_retry_permitted'=>false,'external_execution_performed'=>false];
        }finally{$this->operations->releaseExecutionLock($operationId);}
    }
    private static function error(string $c,string $m):WP_Error{return new WP_Error('digiforge_etsy_reconciliation_workflow_'.$c,$m,['status'=>409]);}
}
