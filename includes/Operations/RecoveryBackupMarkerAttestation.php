<?php

declare(strict_types=1);

namespace DigiForge\Operations;

/** Read-only database-resident marker attestation for isolated recovery verification. */
final class RecoveryBackupMarkerAttestation
{
    /** @return array<string,mixed>|\WP_Error */
    public static function attest(string $operationKeyHash): array|\WP_Error
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $operationKeyHash)) {
            return new \WP_Error('digiforge_recovery_marker_attestation_key', __('A valid recovery marker key hash is required.', 'digiforge'), ['status'=>400]);
        }
        $record=RecoveryDispatchLedger::read('digiforge_recovery_backup_marker_'.$operationKeyHash);
        if ($record instanceof \WP_Error) return $record;
        if ($record===[]) return new \WP_Error('digiforge_recovery_marker_attestation_missing', __('The requested database recovery marker is not present.', 'digiforge'), ['status'=>404]);
        $markerContents = $record;
        unset($markerContents['marker_hash']);
        $markerJson = wp_json_encode($markerContents, JSON_UNESCAPED_SLASHES);
        if (! is_string($markerJson)
            || ! hash_equals(hash('sha256', $markerJson), (string) ($record['marker_hash'] ?? ''))
            || ! hash_equals($operationKeyHash, (string) ($record['operation_key_hash'] ?? ''))) {
            return new \WP_Error('digiforge_recovery_marker_attestation_integrity', __('Restored backup marker integrity could not be verified.', 'digiforge'), ['status'=>409]);
        }
        return [
            'marker_version'=>(int)($record['marker_version']??0),
            'operation_key_hash'=>(string)($record['operation_key_hash']??''),
            'marker_hash'=>(string)($record['marker_hash']??''),
            'prepared_at'=>(string)($record['prepared_at']??''),
            'backup_certified'=>($record['backup_certified']??false)===true,
            'external_actions_performed'=>false,
            'read_only'=>true,
        ];
    }
}
