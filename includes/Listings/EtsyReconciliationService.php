<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Starts reconciliation only for persisted UNKNOWN operations and returns the
 * provider lookup evidence needed by a later read-only reconciliation worker.
 */
final class EtsyReconciliationService
{
    public function __construct(private EtsyOperationRepository $operations) {}

    /** @return array<string,mixed>|WP_Error */
    public function begin(int $operationId): array|WP_Error
    {
        $operation=$this->operations->find($operationId);
        if (!is_array($operation)) return self::error('not_found','Etsy operation was not found.');
        $plan=EtsyReconciliationPlan::build($operation);
        if ($plan instanceof WP_Error) return $plan;

        $transitioned=$this->operations->transition($operationId,EtsyOperationLifecycle::RECONCILIATION);
        if ($transitioned instanceof WP_Error) return $transitioned;

        return [
            'state'=>'ETSY_RECONCILIATION_READY',
            'operation_id'=>$operationId,
            'shop_reference'=>$plan['shop_reference'],
            'idempotency_key'=>$plan['idempotency_key'],
            'request_fingerprint'=>$plan['request_fingerprint'],
            'lookup_reference'=>$plan['lookup_reference'],
            'lookup_identity_available'=>$plan['lookup_identity_available'],
            'operation_type'=>(string)($operation['operation_type']??''),
            'provider_lookup_required'=>true,
            'external_retry_permitted'=>false,
            'automatic_retry_permitted'=>false,
            'network_request_permitted'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_reconciliation_service_'.$code,$message,['status'=>409]);
    }
}
