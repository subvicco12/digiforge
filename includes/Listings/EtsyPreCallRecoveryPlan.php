<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/**
 * Fail-closed recovery plan for a consumed authorization when no external
 * request-attempt evidence exists and the ledger is still NOT_SENT.
 *
 * The consumed authorization is never reusable. Recovery requires a fresh
 * authorization before another adapter invocation can be prepared.
 */
final class EtsyPreCallRecoveryPlan
{
    /** @return array<string,mixed>|WP_Error */
    public static function build(array $operation, array $prepared): array|WP_Error
    {
        $id=(int)($operation['id']??0);
        if ($id < 1 || $id !== (int)($prepared['operation_id']??0)) {
            return self::error('operation_mismatch','Recovery requires matching persisted and prepared operation identities.');
        }
        if ((string)($operation['state']??'') !== EtsyOperationLifecycle::NOT_SENT) {
            return self::error('state','Pre-call recovery is only valid while the operation remains NOT_SENT.');
        }
        if (($prepared['state']??'') !== 'ETSY_OPERATION_PREPARED') {
            return self::error('prepared_state','Recovery requires a prepared Etsy operation.');
        }
        $permit=$prepared['permit']??null;
        if (!is_array($permit) || ($permit['nonce_consumed']??null)!==true) {
            return self::error('permit','Recovery requires evidence that the prior authorization nonce was consumed.');
        }
        if (($prepared['adapter_invoked']??null)!==false
            || ($prepared['external_execution_performed']??null)!==false
            || ($permit['external_execution_performed']??null)!==false) {
            return self::error('attempt_uncertain','Recovery cannot claim no-send when adapter or external execution state is uncertain.');
        }

        $priorAuthorization=strtolower(trim((string)($permit['authorization_hash']??'')));
        $persistedAuthorization=strtolower(trim((string)($operation['authorization_hash']??'')));
        if (!preg_match('/^[a-f0-9]{64}$/',$priorAuthorization)
            || !hash_equals($persistedAuthorization,$priorAuthorization)) {
            return self::error('binding','Consumed authorization must match the persisted NOT_SENT operation.');
        }

        return [
            'state'=>'ETSY_PRECALL_RECOVERY_REQUIRED',
            'operation_id'=>$id,
            'ledger_state'=>EtsyOperationLifecycle::NOT_SENT,
            'prior_authorization_hash'=>$priorAuthorization,
            'prior_authorization_consumed'=>true,
            'reuse_prior_authorization'=>false,
            'new_authorization_required'=>true,
            'sent_transition_permitted'=>false,
            'external_attempt_evidence_present'=>false,
            'adapter_invoked'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private static function error(string $code,string $message): WP_Error
    {
        return new WP_Error('digiforge_etsy_precall_recovery_'.$code,$message,['status'=>409]);
    }
}
