<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Pure local plan for a future Etsy adapter invocation.
 *
 * The plan validates the consumed-permit handoff and operation bindings but
 * cannot invoke an adapter or mutate the ledger. SENT may only be recorded by
 * a later execution boundary that has evidence an external request was
 * actually attempted.
 */
final class EtsyAdapterInvocationPlan
{
    /** @return array<string,mixed>|WP_Error */
    public static function build(array $handoff, array $operation): array|WP_Error
    {
        if (($handoff['state'] ?? '') !== 'ETSY_ADAPTER_HANDOFF_VALIDATED') {
            return self::error('invalid_handoff', 'Validated Etsy adapter handoff is required.');
        }
        $operationId=(int)($operation['id'] ?? 0);
        if ($operationId < 1 || $operationId !== (int)($handoff['operation_id'] ?? 0)) {
            return self::error('operation_mismatch', 'Handoff and operation identities must match.');
        }
        if ((string)($operation['state'] ?? '') !== EtsyOperationLifecycle::NOT_SENT) {
            return self::error('operation_not_sendable', 'Invocation planning requires a NOT_SENT operation.');
        }

        foreach (['authorization_hash','evidence_hash'] as $field) {
            $left=strtolower(trim((string)($handoff[$field] ?? '')));
            $right=strtolower(trim((string)($operation[$field] ?? '')));
            if (!preg_match('/^[a-f0-9]{64}$/',$left) || !hash_equals($right,$left)) {
                return self::error('binding_mismatch', 'Handoff does not match persisted operation bindings.');
            }
        }

        return [
            'state'=>'ETSY_ADAPTER_INVOCATION_PLANNED',
            'operation_id'=>$operationId,
            'permit'=>$handoff['permit'] ?? [],
            'payload'=>$handoff['payload'] ?? [],
            'pre_call_ledger_state'=>EtsyOperationLifecycle::NOT_SENT,
            'sent_transition_requires_external_attempt'=>true,
            'ledger_state_change_permitted'=>false,
            'adapter_invoked'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_invocation_plan_'.$code,$message,['status'=>409]);
    }
}
