<?php

declare(strict_types=1);

namespace DigiForge\Operations;

use DigiForge\Integrations\RecoveryStagingClient;
use DigiForge\Security\Logger;

/**
 * Terminally supersedes an irrecoverably stale, already-reconciled staging drill.
 * Historical operation claims remain immutable and can never be replayed.
 */
final class RecoveryOperationSupersession
{
    private const CONFIRMATION = 'I_CONFIRM_STALE_RECOVERY_OPERATION_SUPERSESSION';

    /** @return array<string,mixed>|\WP_Error */
    public static function supersede(string $operationKey, string $confirmation, string $performedBy): array|\WP_Error
    {
        $operationKey = trim(sanitize_text_field($operationKey));
        $performedBy = trim(sanitize_text_field($performedBy));
        if ($operationKey === '' || ! hash_equals(self::CONFIRMATION, $confirmation) || $performedBy === '') {
            return new \WP_Error('digiforge_recovery_supersession_confirmation_required', __('Exact operation, explicit supersession confirmation, and reviewer identity are required.', 'digiforge'), ['status'=>400]);
        }
        if (! RecoveryOrchestrator::safetyLocked() || ! RecoveryDispatchLedger::safetyLocked()) {
            return new \WP_Error('digiforge_recovery_supersession_not_locked', __('Recovery supersession requires STOP ALL and the durable safety lock.', 'digiforge'), ['status'=>409]);
        }

        $plan = RecoveryOrchestrator::snapshot();
        if (! hash_equals((string)($plan['operation_key']??''), $operationKey)
            || ($plan['state']??'') !== 'VERIFY_REQUIRED'
            || ($plan['dispatch_state']??'') !== 'MANUAL_RESTORE_RECONCILED'
            || ($plan['manual_restore_reconciled']??false) !== true
            || ($plan['reconciliation_required']??false) !== true
            || ($plan['target_environment']??'') !== 'staging') {
            return new \WP_Error('digiforge_recovery_supersession_ineligible', __('Only an already reconciled staging restore that is stuck at exact-version verification may be superseded.', 'digiforge'), ['status'=>409]);
        }

        $existingVerification = RecoveryVerificationEvidence::read($operationKey);
        if ($existingVerification instanceof \WP_Error) return $existingVerification;
        if ($existingVerification !== []) {
            return new \WP_Error('digiforge_recovery_supersession_verified', __('A recovery operation with immutable verification evidence must proceed through review, not supersession.', 'digiforge'), ['status'=>409]);
        }

        $artifacts = RecoveryEvidence::snapshot();
        $currentPackage = is_array($artifacts['plugin_package']??null) ? $artifacts['plugin_package'] : [];
        if (($artifacts['plugin_package_retrievable']??false)!==true || ($artifacts['checksum_verified']??false)!==true
            || ($artifacts['database_backup_retrievable']??false)!==true
            || trim((string)($currentPackage['version']??'')) === ''
            || hash_equals((string)($plan['plugin_package_identifier']??''), (string)($currentPackage['identifier']??''))) {
            return new \WP_Error('digiforge_recovery_supersession_current_artifacts_required', __('A different current verified recovery artifact set is required before stale-operation supersession.', 'digiforge'), ['status'=>409]);
        }

        $target = trailingslashit((string)($plan['target_site_url']??''));
        $snapshot = (new RecoveryStagingClient())->get($target.'wp-json/digiforge/v1/recovery/verification-snapshot');
        if ($snapshot instanceof \WP_Error) return $snapshot;
        $schema = is_array($snapshot['schema']??null) ? $snapshot['schema'] : [];
        $healthy = ($snapshot['status']??'')==='ok'
            && ($snapshot['stop_all']??false)===true
            && ($snapshot['externally_locked']??false)===true
            && ($snapshot['automation_enabled']??true)===false
            && ($snapshot['activation_not_authorized']??false)===true
            && ($snapshot['automation_unarmed']??false)===true
            && ($snapshot['no_effective_feature_switches']??false)===true
            && isset($schema['current'],$schema['expected']) && (int)$schema['current']===(int)$schema['expected'];
        $plannedVersion = RecoveryOrchestrator::plannedPackageVersion($plan);
        $observedVersion = trim((string)($snapshot['version']??''));
        if (!$healthy || $plannedVersion==='' || $observedVersion==='' || hash_equals($plannedVersion,$observedVersion)
            || !hash_equals((string)($currentPackage['version']??''),$observedVersion)) {
            return new \WP_Error('digiforge_recovery_supersession_stale_not_proven', __('Healthy locked staging must prove a different, current verified plugin version before the stale operation can be superseded.', 'digiforge'), ['status'=>409]);
        }

        $receipt = [
            'state'=>'SUPERSEDED',
            'operation_key'=>$operationKey,
            'target_environment'=>'staging',
            'target_site_url'=>(string)$plan['target_site_url'],
            'database_backup_identifier'=>(string)$plan['database_backup_identifier'],
            'plugin_package_identifier'=>(string)$plan['plugin_package_identifier'],
            'plugin_package_version'=>$plannedVersion,
            'observed_plugin_version'=>$observedVersion,
            'current_plugin_package_identifier'=>(string)($currentPackage['identifier']??''),
            'reason'=>'STALE_EXACT_VERSION_MISMATCH',
            'performed_by'=>$performedBy,
            'superseded_at'=>gmdate('c'),
            'reconciliation_required'=>false,
            'retry_permitted'=>false,
            'external_actions_performed'=>false,
            'external_execution_authorized'=>false,
            'commerce_execution_authorized'=>false,
        ];
        $receipt['evidence_hash']=hash('sha256',(string)wp_json_encode($receipt,JSON_UNESCAPED_SLASHES));
        $archiveName='digiforge_recovery_superseded_'.hash('sha256',$operationKey);
        $existing=RecoveryDispatchLedger::read($archiveName);
        if ($existing instanceof \WP_Error) return $existing;
        if ($existing!==[]) {
            $same = ($existing['state']??'')==='SUPERSEDED'
                && hash_equals($operationKey,(string)($existing['operation_key']??''))
                && hash_equals((string)($plan['database_backup_identifier']??''),(string)($existing['database_backup_identifier']??''))
                && hash_equals((string)($plan['plugin_package_identifier']??''),(string)($existing['plugin_package_identifier']??''))
                && hash_equals($plannedVersion,(string)($existing['plugin_package_version']??''))
                && hash_equals($observedVersion,(string)($existing['observed_plugin_version']??''))
                && hash_equals((string)($currentPackage['identifier']??''),(string)($existing['current_plugin_package_identifier']??''))
                && ($existing['reason']??'')==='STALE_EXACT_VERSION_MISMATCH'
                && ($existing['retry_permitted']??true)===false
                && ($existing['reconciliation_required']??true)===false;
            if (!$same) {
                return new \WP_Error('digiforge_recovery_supersession_conflict', __('Immutable supersession evidence already exists with different facts.', 'digiforge'), ['status'=>409]);
            }
            if (!RecoveryOrchestrator::terminallySupersede($operationKey,$existing)) {
                return new \WP_Error('digiforge_recovery_supersession_handoff_failed', __('Supersession receipt exists, but the global recovery slot could not be terminally handed off. Reconciliation remains required.', 'digiforge'), ['status'=>500,'reconciliation_required'=>true]);
            }
            return $existing+['replayed'=>true];
        }
        if (!Logger::write('recovery_operation_supersession_authorized',[
            'operation_key'=>$operationKey,'planned_version'=>$plannedVersion,'observed_version'=>$observedVersion,
            'external_actions_performed'=>false,'external_execution_authorized'=>false
        ],'human','recovery_evidence')) {
            return new \WP_Error('digiforge_recovery_supersession_audit_failed', __('Supersession cannot proceed without durable human audit evidence.', 'digiforge'), ['status'=>500]);
        }
        if (!RecoveryDispatchLedger::insert($archiveName,$receipt)) {
            return new \WP_Error('digiforge_recovery_supersession_archive_failed', __('Immutable supersession evidence could not be persisted.', 'digiforge'), ['status'=>500]);
        }
        if (!RecoveryOrchestrator::terminallySupersede($operationKey,$receipt)) {
            return new \WP_Error('digiforge_recovery_supersession_handoff_failed', __('Supersession receipt was retained, but the global recovery slot could not be terminally handed off. Reconciliation remains required.', 'digiforge'), ['status'=>500,'reconciliation_required'=>true]);
        }
        return $receipt+['replayed'=>false];
    }
}
