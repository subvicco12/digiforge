<?php

declare(strict_types=1);

namespace DigiForge\Operations;

/** Immutable evidence binding a future verified backup to its pre-capture DB marker. */
final class RecoveryBackupIdentityBinding
{
    private const PREFIX = 'digiforge_recovery_backup_binding_';

    /** @return array<string,mixed>|\WP_Error */
    public static function bind(string $operationKey, string $backupIdentifier): array|\WP_Error
    {
        if (! RecoveryOrchestrator::safetyLocked() || ! RecoveryDispatchLedger::safetyLocked()) {
            return new \WP_Error('digiforge_recovery_backup_binding_not_locked', __('Backup identity binding requires STOP ALL and the durable safety lock.', 'digiforge'), ['status' => 409]);
        }
        $marker = RecoveryBackupIdentityMarker::read($operationKey);
        if ($marker instanceof \WP_Error) return $marker;
        if ($marker === [] || ($marker['backup_certified'] ?? true) !== false || (string)($marker['backup_identifier'] ?? '') !== '') {
            return new \WP_Error('digiforge_recovery_backup_marker_invalid', __('An immutable unbound pre-capture marker is required.', 'digiforge'), ['status' => 409]);
        }
        $markerContents = $marker;
        unset($markerContents['marker_hash']);
        $markerJson = wp_json_encode($markerContents, JSON_UNESCAPED_SLASHES);
        if (! is_string($markerJson) || ! hash_equals(hash('sha256', $markerJson), (string) ($marker['marker_hash'] ?? ''))) {
            return new \WP_Error('digiforge_recovery_backup_marker_integrity', __('Backup identity marker integrity could not be verified.', 'digiforge'), ['status' => 409]);
        }
        $backupIdentifier = trim(sanitize_text_field($backupIdentifier));
        $snapshot = RecoveryEvidence::snapshot();
        $backup = is_array($snapshot['database_backup'] ?? null) ? $snapshot['database_backup'] : [];
        if ($backupIdentifier === '' || ! hash_equals((string)($backup['identifier'] ?? ''), $backupIdentifier)
            || ($snapshot['database_backup_retrievable'] ?? false) !== true
            || ($snapshot['database_backup_verification_fresh'] ?? false) !== true
            || ($backup['verification_status'] ?? '') !== 'VERIFIED') {
            return new \WP_Error('digiforge_recovery_backup_binding_evidence', __('Current fresh verified and retrievable backup evidence is required.', 'digiforge'), ['status' => 409]);
        }
        $prepared = strtotime((string)($marker['prepared_at'] ?? ''));
        $captured = strtotime((string)($backup['captured_at'] ?? ''));
        if ($prepared === false || $captured === false || $captured < $prepared) {
            return new \WP_Error('digiforge_recovery_backup_binding_order', __('The verified backup must have been captured after the pre-backup identity marker.', 'digiforge'), ['status' => 409]);
        }
        $record = [
            'marker_hash' => (string)($marker['marker_hash'] ?? ''),
            'backup_identifier' => $backupIdentifier,
            'backup_evidence_hash' => (string)($backup['evidence_hash'] ?? ''),
            'marker_prepared_at' => (string)($marker['prepared_at'] ?? ''),
            'backup_captured_at' => (string)($backup['captured_at'] ?? ''),
            'bound_at' => gmdate('c'),
            'database_identity_proof_available' => true,
            'external_actions_performed' => false,
            'external_execution_authorized' => false,
        ];
        $record['binding_hash'] = hash('sha256', (string)wp_json_encode($record, JSON_UNESCAPED_SLASHES));
        $name = self::PREFIX . hash('sha256', $operationKey);
        $existing = RecoveryDispatchLedger::read($name);
        if ($existing instanceof \WP_Error) return $existing;
        if ($existing !== []) {
            $same=$existing; unset($same['bound_at'],$same['binding_hash']);
            $candidate=$record; unset($candidate['bound_at'],$candidate['binding_hash']);
            if ($same !== $candidate) return new \WP_Error('digiforge_recovery_backup_binding_conflict', __('Immutable backup identity binding already exists with different evidence.', 'digiforge'), ['status'=>409]);
            return $existing + ['replayed'=>true];
        }
        if (! RecoveryDispatchLedger::insert($name,$record)) return new \WP_Error('digiforge_recovery_backup_binding_persist_failed', __('Backup identity binding could not be persisted immutably.', 'digiforge'), ['status'=>500]);
        return $record + ['replayed'=>false];
    }
    /** @return array<string,mixed>|\WP_Error */
    public static function read(string $operationKey): array|\WP_Error
    {
        $operationKey=trim(sanitize_text_field($operationKey));
        if ($operationKey==='') return new \WP_Error('digiforge_recovery_backup_binding_key_required', __('A backup identity operation key is required.', 'digiforge'), ['status'=>400]);
        return RecoveryDispatchLedger::read(self::PREFIX . hash('sha256',$operationKey));
    }

}
