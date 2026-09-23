<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Composes a completed controlled HTTP attempt into the persistent Etsy
 * operation lifecycle. No network request or credential access occurs here.
 */
final class EtsyAttemptLifecycleService
{
    public function __construct(private EtsyOperationRepository $operations) {}

    /** @return array<string,mixed>|WP_Error */
    public function record(array $invocationPlan,array $execution): array|WP_Error
    {
        if (($execution['state']??'')!=='ETSY_HTTP_ATTEMPT_COMPLETED'
            || ($execution['network_request_attempted']??null)!==true
            || ($execution['external_execution_performed']??null)!==true
            || !is_array($execution['attempt']??null)
            || !is_array($execution['http_outcome']??null)) {
            return self::error('execution','A completed controlled Etsy HTTP attempt is required.');
        }

        $operationId=(int)($execution['operation_id']??0);
        if ($operationId<1 || $operationId!==(int)($invocationPlan['operation_id']??0)) {
            return self::error('operation','Execution and invocation plan must identify the same operation.');
        }

        $evidence=EtsyExternalAttemptEvidence::validate($invocationPlan,$execution['attempt']);
        if ($evidence instanceof WP_Error) return $evidence;

        $sent=(new EtsySentTransitionService($this->operations))->record($evidence);
        if ($sent instanceof WP_Error) return $sent;

        $adapterResult=self::adapterResult($execution['http_outcome'],$execution);
        if ($adapterResult instanceof WP_Error) return $adapterResult;

        $recorded=(new EtsyAdapterOutcomePersistenceService($this->operations))->record($operationId,$adapterResult);
        if ($recorded instanceof WP_Error) return $recorded;

        return [
            'state'=>'ETSY_HTTP_LIFECYCLE_RECORDED',
            'operation_id'=>$operationId,
            'attempt_id'=>(string)($evidence['attempt_id']??''),
            'sent'=>$sent,
            'outcome'=>$recorded,
            'reconciliation_required'=>(bool)($recorded['reconciliation_required']??false),
            'automatic_retry_permitted'=>false,
            'external_execution_performed'=>true,
        ];
    }

    /** @return array<string,mixed>|WP_Error */
    private static function adapterResult(array $http,array $execution): array|WP_Error
    {
        $state=(string)($http['state']??'');
        if ($state==='RESPONSE_ACCEPTED') {
            $reference=trim((string)($execution['external_reference']??''));
            if ($reference==='') return self::error('reference','Accepted Etsy response requires a bounded external reference before success can be confirmed.');
            return ['state'=>'CONFIRMED_SUCCESS','external_reference'=>$reference];
        }
        if ($state==='CONFIRMED_FAILURE') {
            return [
                'state'=>'CONFIRMED_FAILURE',
                'failure_category'=>(string)($http['failure_category']??'HTTP'),
                'failure_code'=>(string)($http['failure_code']??'UNKNOWN'),
            ];
        }
        if ($state==='UNKNOWN') return ['state'=>'UNKNOWN'];
        return self::error('outcome','Unsupported Etsy HTTP outcome state.');
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_attempt_lifecycle_'.$code,$message,['status'=>409]);
    }
}
