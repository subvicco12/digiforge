<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Persists a provider-lookup reconciliation result without performing the lookup.
 * Conclusive processing is resumable from RECONCILED so an interruption between
 * lifecycle transitions cannot strand an operation.
 */
final class EtsyReconciliationResultService
{
    public function __construct(private EtsyOperationRepository $operations) {}

    /** @return array<string,mixed>|WP_Error */
    public function record(int $operationId, array $lookupResult): array|WP_Error
    {
        if ($operationId < 1) return $this->error('operation_id','A persisted Etsy operation id is required.');
        $operation=$this->operations->find($operationId);
        if (!is_array($operation)) return $this->error('not_found','Etsy operation was not found.');

        $current=(string)($operation['state'] ?? '');
        if (!in_array($current,[EtsyOperationLifecycle::RECONCILIATION,EtsyOperationLifecycle::RECONCILED],true)) {
            return $this->error('operation_not_reconciling','Only a RECONCILIATION or resumable RECONCILED operation may accept a lookup result.');
        }

        $outcome=EtsyAdapterOutcome::normalize($lookupResult);
        if ($outcome instanceof WP_Error) return $outcome;
        $state=(string)$outcome['state'];

        if ($state === EtsyOperationLifecycle::UNKNOWN) {
            if ($current !== EtsyOperationLifecycle::RECONCILIATION) {
                return $this->error('reconciled_requires_conclusive','A RECONCILED operation may only resume with a conclusive result.');
            }
            $transitioned=$this->operations->transition($operationId,EtsyOperationLifecycle::UNKNOWN);
            if ($transitioned instanceof WP_Error) return $transitioned;
            return $this->response($operationId,$transitioned,$outcome,true);
        }

        if ($current === EtsyOperationLifecycle::RECONCILIATION) {
            $reconciled=$this->operations->transition($operationId,EtsyOperationLifecycle::RECONCILED);
            if ($reconciled instanceof WP_Error) return $reconciled;
        }

        $externalReference=$state === EtsyOperationLifecycle::CONFIRMED_SUCCESS
            ? (string)($outcome['external_reference'] ?? '') : '';
        $terminal=$this->operations->transition($operationId,$state,$externalReference);
        if ($terminal instanceof WP_Error) return $terminal;

        return $this->response($operationId,$terminal,$outcome,false);
    }

    /** @return array<string,mixed> */
    private function response(int $operationId,array $operation,array $outcome,bool $reconciliationRequired): array
    {
        return [
            'state'=>'ETSY_RECONCILIATION_RESULT_RECORDED',
            'operation'=>$operation,
            'operation_id'=>$operationId,
            'outcome'=>$outcome,
            'reconciliation_required'=>$reconciliationRequired,
            'external_retry_permitted'=>false,
            'adapter_invoked'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_reconciliation_result_'.$code,$message,['status'=>409]);
    }
}
