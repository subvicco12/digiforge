<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Local persistence boundary for UNKNOWN -> RECONCILIATION.
 * Provider lookup remains a separate future action and retries stay forbidden.
 */
final class EtsyReconciliationTransitionService
{
    public function __construct(private EtsyOperationRepository $operations) {}

    /** @return array<string,mixed>|WP_Error */
    public function begin(int $operationId): array|WP_Error
    {
        if ($operationId < 1) return $this->error('operation_id','A persisted Etsy operation id is required.');

        $operation=$this->operations->find($operationId);
        if (!is_array($operation)) return $this->error('not_found','Etsy operation was not found.');

        $plan=EtsyReconciliationPlan::build($operation);
        if ($plan instanceof WP_Error) return $plan;
        if (($plan['provider_lookup_required'] ?? null) !== true || ($plan['external_retry_permitted'] ?? null) !== false) {
            return $this->error('unsafe_plan','Reconciliation must require provider lookup and forbid external retry.');
        }

        $transitioned=$this->operations->transition($operationId,EtsyOperationLifecycle::RECONCILIATION);
        if ($transitioned instanceof WP_Error) return $transitioned;

        return [
            'state'=>'ETSY_RECONCILIATION_STARTED',
            'operation'=>$transitioned,
            'operation_id'=>$operationId,
            'plan'=>$plan,
            'provider_lookup_required'=>true,
            'external_retry_permitted'=>false,
            'adapter_invoked'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_reconciliation_transition_'.$code,$message,['status'=>409]);
    }
}
