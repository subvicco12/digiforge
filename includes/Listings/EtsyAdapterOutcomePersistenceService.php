<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Persists a normalized adapter outcome only after the external attempt has
 * already been recorded as SENT. No adapter invocation or provider call occurs here.
 */
final class EtsyAdapterOutcomePersistenceService
{
    public function __construct(private EtsyOperationRepository $operations) {}

    /** @return array<string,mixed>|WP_Error */
    public function record(int $operationId, array $adapterResult): array|WP_Error
    {
        if ($operationId < 1) return $this->error('operation_id','A persisted Etsy operation id is required.');

        $operation=$this->operations->find($operationId);
        if (!is_array($operation) || (string)($operation['state'] ?? '') !== EtsyOperationLifecycle::SENT) {
            return $this->error('operation_not_sent','Adapter outcomes may only be recorded for SENT operations.');
        }

        $outcome=EtsyAdapterOutcome::normalize($adapterResult);
        if ($outcome instanceof WP_Error) return $outcome;

        $state=(string)$outcome['state'];
        $externalReference=$state === EtsyOperationLifecycle::CONFIRMED_SUCCESS
            ? (string)($outcome['external_reference'] ?? '') : '';

        $transitioned=$this->operations->transition($operationId,$state,$externalReference);
        if ($transitioned instanceof WP_Error) return $transitioned;

        return [
            'state'=>'ETSY_ADAPTER_OUTCOME_RECORDED',
            'operation'=>$transitioned,
            'operation_id'=>$operationId,
            'outcome'=>$outcome,
            'retry_permitted'=>(bool)($outcome['retry_permitted'] ?? false),
            'reconciliation_required'=>(bool)($outcome['reconciliation_required'] ?? false),
            'adapter_invoked'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_outcome_persistence_'.$code,$message,['status'=>409]);
    }
}
