<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/**
 * Adapter for reconciliation over the already-audited Etsy HTTP boundary.
 * It creates no HTTP client and retrieves no credential material itself.
 */
final class EtsyReconciliationLookupExecutor
{
    /** @return array<string,mixed>|WP_Error */
    public function execute(array $plan,array $prepared,array $authorized,EtsyControlledHttpExecutor $executor):array|WP_Error
    {
        if (($plan['state']??'')!=='ETSY_RECONCILIATION_LOOKUP_PLANNED'
            || ($plan['method']??'')!=='GET'
            || ($plan['mutation_permitted']??null)!==false
            || ($plan['external_retry_permitted']??null)!==false
            || ($plan['automatic_retry_permitted']??null)!==false) return self::error('plan','A validated GET-only reconciliation plan is required.');

        $operationId=(int)($plan['operation_id']??0);$integrationId=(int)($plan['integration_id']??0);
        $request=is_array($prepared['request_plan']??null)?$prepared['request_plan']:[];
        if($operationId<1||$integrationId<1
            ||(int)($prepared['operation_id']??0)!==$operationId
            ||(int)($prepared['integration_id']??0)!==$integrationId
            ||($request['method']??'')!=='GET'
            ||!hash_equals((string)($plan['endpoint']??''),(string)($request['endpoint']??''))) return self::error('binding','Lookup plan must exactly match the audited prepared transport.');

        $result=$executor->execute($prepared,$authorized);
        if($result instanceof WP_Error)return $result;
        if(($result['state']??'')!=='ETSY_HTTP_ATTEMPT_COMPLETED')return self::error('attempt','Audited Etsy HTTP attempt evidence is required.');

        $outcome=is_array($result['http_outcome']??null)?$result['http_outcome']:[];
        if(($outcome['state']??'')==='RESPONSE_ACCEPTED') {
            $evidence=EtsyReconciliationLookupResponse::normalize($plan,$result['reconciliation_response']??null);
            if($evidence instanceof WP_Error)return $evidence;
        } else {
            $evidence=['state'=>EtsyOperationLifecycle::UNKNOWN,'failure_category'=>'provider_lookup','failure_code'=>'lookup_inconclusive','external_reference'=>''];
        }
        return ['state'=>'ETSY_RECONCILIATION_LOOKUP_COMPLETED','operation_id'=>$operationId,'attempt'=>$result['attempt']??[],'result'=>$evidence,'mutation_performed'=>false,'external_retry_performed'=>false,'automatic_retry_performed'=>false,'network_request_attempted'=>true,'external_execution_performed'=>true];
    }
    private static function error(string $c,string $m):WP_Error{return new WP_Error('digiforge_etsy_reconciliation_executor_'.$c,$m,['status'=>409]);}
}
