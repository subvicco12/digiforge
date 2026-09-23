<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Pure evidence contract for the boundary immediately after a future adapter
 * has actually attempted an external request.
 *
 * It does not invoke an adapter and does not mutate the ledger. Its output is
 * the minimum evidence a later persistence boundary may require before
 * considering NOT_SENT -> SENT.
 */
final class EtsyExternalAttemptEvidence
{
    /** @return array<string,mixed>|WP_Error */
    public static function validate(array $plan, array $attempt): array|WP_Error
    {
        if (($plan['state'] ?? '') !== 'ETSY_ADAPTER_INVOCATION_PLANNED') {
            return self::error('invalid_plan','A validated Etsy invocation plan is required.');
        }
        $operationId=(int)($plan['operation_id'] ?? 0);
        if ($operationId < 1 || $operationId !== (int)($attempt['operation_id'] ?? 0)) {
            return self::error('operation_mismatch','Attempt evidence must match the planned operation.');
        }
        if (($attempt['adapter_invoked'] ?? null) !== true || ($attempt['external_request_attempted'] ?? null) !== true) {
            return self::error('attempt_not_proven','SENT evidence requires an actual adapter invocation and external request attempt.');
        }
        $attemptId=trim((string)($attempt['attempt_id'] ?? ''));
        $attemptedAt=trim((string)($attempt['attempted_at'] ?? ''));
        if ($attemptId === '' || strlen($attemptId) > 191 || $attemptedAt === '') {
            return self::error('attempt_evidence_invalid','Bounded attempt identity and timestamp are required.');
        }

        return [
            'state'=>'ETSY_EXTERNAL_ATTEMPT_EVIDENCED',
            'operation_id'=>$operationId,
            'attempt_id'=>$attemptId,
            'attempted_at'=>$attemptedAt,
            'sent_transition_eligible'=>true,
            'sent_transition_performed'=>false,
            'ledger_state_change_permitted'=>false,
            'external_execution_performed'=>true,
        ];
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_attempt_evidence_'.$code,$message,['status'=>409]);
    }
}
