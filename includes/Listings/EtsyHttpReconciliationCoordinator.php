<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * End-to-end local coordinator for uncertain Etsy HTTP outcomes.
 * It starts reconciliation and records separately obtained provider lookup
 * evidence. It never performs HTTP, retries, credential access, or publishing.
 */
final class EtsyHttpReconciliationCoordinator
{
    public function __construct(private EtsyOperationRepository $operations) {}

    /** @return array<string,mixed>|WP_Error */
    public function beginFromAttempt(array $lifecycle): array|WP_Error
    {
        if (($lifecycle['state']??'')!=='ETSY_HTTP_LIFECYCLE_RECORDED'
            || ($lifecycle['reconciliation_required']??null)!==true
            || ($lifecycle['automatic_retry_permitted']??null)!==false) {
            return self::error('attempt','Only a recorded uncertain Etsy HTTP lifecycle may enter reconciliation.');
        }
        $operationId=(int)($lifecycle['operation_id']??0);
        if($operationId<1) return self::error('operation','A persisted Etsy operation id is required.');

        $operation=$this->operations->find($operationId);
        if(!is_array($operation)||(string)($operation['state']??'')!==EtsyOperationLifecycle::UNKNOWN) {
            return self::error('state','The persisted Etsy operation must still be UNKNOWN.');
        }

        $started=(new EtsyReconciliationTransitionService($this->operations))->begin($operationId);
        if($started instanceof WP_Error) return $started;

        return [
            'state'=>'ETSY_HTTP_RECONCILIATION_READY',
            'operation_id'=>$operationId,
            'reconciliation'=>$started,
            'provider_lookup_required'=>true,
            'external_retry_permitted'=>false,
            'automatic_retry_permitted'=>false,
            'network_request_permitted'=>false,
            'external_execution_performed'=>false,
        ];
    }

    /** @return array<string,mixed>|WP_Error */
    public function recordLookup(int $operationId,array $lookupResult): array|WP_Error
    {
        $recorded=(new EtsyReconciliationResultService($this->operations))->record($operationId,$lookupResult);
        if($recorded instanceof WP_Error) return $recorded;
        return [
            'state'=>'ETSY_HTTP_RECONCILIATION_RECORDED',
            'operation_id'=>$operationId,
            'result'=>$recorded,
            'reconciliation_required'=>(bool)($recorded['reconciliation_required']??false),
            'external_retry_permitted'=>false,
            'automatic_retry_permitted'=>false,
            'network_request_permitted'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_http_reconciliation_'.$code,$message,['status'=>409]);
    }
}
