<?php

declare(strict_types=1);

namespace DigiForge\Operations;

/**
 * Immutable marker written into the database before a future backup is captured.
 * A restored copy can later prove that this exact pre-capture marker survived.
 */
final class RecoveryBackupIdentityMarker
{
    private const PREFIX = 'digiforge_recovery_backup_marker_';

    /** @return array<string,mixed>|\WP_Error */
    public static function prepare(string $operationKey): array|\WP_Error
    {
        $operationKey = trim(sanitize_text_field($operationKey));
        if ($operationKey === '') {
            return new \WP_Error('digiforge_recovery_backup_marker_key_required', __('A backup preparation operation key is required.', 'digiforge'), ['status' => 400]);
        }
        if (! RecoveryOrchestrator::safetyLocked() || ! RecoveryDispatchLedger::safetyLocked()) {
            return new \WP_Error('digiforge_recovery_backup_marker_not_locked', __('Backup identity preparation requires STOP ALL and the durable safety lock.', 'digiforge'), ['status' => 409]);
        }
        $name = self::PREFIX . hash('sha256', $operationKey);
        $existing = RecoveryDispatchLedger::read($name);
        if ($existing instanceof \WP_Error) return $existing;
        if ($existing !== []) {
            $attested = RecoveryBackupMarkerAttestation::attest(hash('sha256', $operationKey));
            if ($attested instanceof \WP_Error) return $attested;
            return $existing + ['replayed' => true];
        }

        try { $nonce = bin2hex(random_bytes(32)); }
        catch (\Throwable) {
            return new \WP_Error('digiforge_recovery_backup_marker_entropy', __('Backup identity marker entropy is unavailable.', 'digiforge'), ['status' => 503]);
        }
        $record = [
            'marker_version' => 1,
            'operation_key_hash' => hash('sha256', $operationKey),
            'nonce_sha256' => hash('sha256', $nonce),
            'prepared_at' => gmdate('c'),
            'backup_identifier' => '',
            'backup_certified' => false,
            'external_actions_performed' => false,
            'external_execution_authorized' => false,
        ];
        $record['marker_hash'] = hash('sha256', (string) wp_json_encode($record, JSON_UNESCAPED_SLASHES));
        if (! RecoveryDispatchLedger::insert($name, $record)) {
            return new \WP_Error('digiforge_recovery_backup_marker_persist_failed', __('Backup identity marker could not be persisted immutably.', 'digiforge'), ['status' => 500]);
        }
        return $record + ['replayed' => false];
    }
    /** @return array<string,mixed>|\WP_Error */
    public static function read(string $operationKey): array|\WP_Error
    {
        $operationKey = trim(sanitize_text_field($operationKey));
        if ($operationKey === '') return new \WP_Error('digiforge_recovery_backup_marker_key_required', __('A backup preparation operation key is required.', 'digiforge'), ['status' => 400]);
        $record = RecoveryDispatchLedger::read(self::PREFIX . hash('sha256', $operationKey));
        if ($record instanceof \WP_Error || $record === []) return $record;
        $attested = RecoveryBackupMarkerAttestation::attest(hash('sha256', $operationKey));
        if ($attested instanceof \WP_Error) return $attested;
        return $record;
    }

}
