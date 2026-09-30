<?php

declare(strict_types=1);

namespace DigiForge\Operations;

/** Human acceptance of an already verified recovery drill; never executes recovery. */
final class RecoveryDrillAcceptance
{
    /** @return array<string,mixed>|\WP_Error */
    public static function accept(string $operationKey, string $evidenceHash, string $performedBy): array|\WP_Error
    {
        $operationKey=trim(sanitize_text_field($operationKey));
        $evidenceHash=strtolower(trim(sanitize_text_field($evidenceHash)));
        $performedBy=trim(sanitize_text_field($performedBy));
        if ($operationKey==='' || !preg_match('/^[a-f0-9]{64}$/',$evidenceHash) || $performedBy==='') {
            return new \WP_Error('digiforge_recovery_drill_accept_input', __('Exact operation, verification evidence hash, and reviewer identity are required.', 'digiforge'), ['status'=>400]);
        }
        if (!RecoveryOrchestrator::safetyLocked() || !RecoveryDispatchLedger::safetyLocked()) {
            return new \WP_Error('digiforge_recovery_drill_accept_not_locked', __('Recovery drill acceptance requires STOP ALL and the durable safety lock.', 'digiforge'), ['status'=>409]);
        }
        $candidate=RecoveryDrillReviewCandidate::build($operationKey);
        if ($candidate instanceof \WP_Error) return $candidate;
        if (($candidate['acceptance_blocked']??true)!==false || ($candidate['passed']??true)!==false
            || !hash_equals((string)($candidate['verification_evidence_hash']??''),$evidenceHash)) {
            return new \WP_Error('digiforge_recovery_drill_accept_blocked', __('The exact immutable verified recovery candidate is not eligible for human acceptance.', 'digiforge'), ['status'=>409]);
        }
        $checks=is_array($candidate['proposed_checks']??null)?$candidate['proposed_checks']:[];
        if (($checks['restore_verified']??false)!==true || ($checks['database_identity_verified']??false)!==true
            || ($checks['schema_verified']??false)!==true || ($checks['application_health_verified']??false)!==true) {
            return new \WP_Error('digiforge_recovery_drill_accept_checks', __('All provenance, restore, schema, and application checks must be verified.', 'digiforge'), ['status'=>409]);
        }
        $record=[
            'drill_id'=>'recovery-drill-'.substr(hash('sha256',$operationKey.'|'.$evidenceHash),0,24),
            'performed_at'=>(string)($candidate['verified_at']??''),
            'environment'=>'staging',
            'database_backup_identifier'=>(string)($candidate['database_backup_identifier']??''),
            'plugin_package_identifier'=>(string)($candidate['plugin_package_identifier']??''),
            'performed_by'=>$performedBy,
            'restore_verified'=>true,
            'schema_verified'=>true,
            'application_health_verified'=>true,
        ];
        if (!RecoveryDrillEvidence::store($record)) {
            return new \WP_Error('digiforge_recovery_drill_accept_store', __('Verified recovery drill evidence could not be recorded.', 'digiforge'), ['status'=>409]);
        }
        return ['accepted'=>true,'operation_key'=>$operationKey,'verification_evidence_hash'=>$evidenceHash,'drill_id'=>$record['drill_id'],'external_actions_performed'=>false,'external_execution_authorized'=>false,'commerce_execution_authorized'=>false,'recovery_drill'=>RecoveryDrillEvidence::snapshot()];
    }
}
