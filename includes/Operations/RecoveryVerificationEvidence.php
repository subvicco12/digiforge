<?php

declare(strict_types=1);

namespace DigiForge\Operations;

/**
 * Immutable verification receipts bind read-only staging observations to the
 * durable recovery dispatch identity. They are not recovery-drill PASS evidence.
 */
final class RecoveryVerificationEvidence
{
    private const PREFIX = 'digiforge_recovery_verification_';

    /** @return array<string,mixed>|\WP_Error */
    public static function record(array $verification): array|\WP_Error
    {
        if (($verification['verified'] ?? false) !== true || ($verification['drill_evidence_recorded'] ?? true) !== false) {
            return new \WP_Error('digiforge_recovery_verification_unverified', __('Only a fully verified staging observation can be recorded.', 'digiforge'), ['status' => 409]);
        }
        $operationKey = trim((string) ($verification['operation_key'] ?? ''));
        $plan = RecoveryOrchestrator::snapshot();
        if ($operationKey === '' || ! hash_equals((string) ($plan['operation_key'] ?? ''), $operationKey)
            || ($plan['reconciliation_required'] ?? false) !== true
            || ($plan['target_environment'] ?? '') !== 'staging'
            || ($plan['provider'] ?? '') !== 'hostinger'
            || ! hash_equals((string) ($plan['target_site_url'] ?? ''), (string) ($verification['target_site_url'] ?? ''))
            || ! hash_equals((string) ($plan['database_backup_identifier'] ?? ''), (string) ($verification['database_backup_identifier'] ?? ''))
            || ! hash_equals((string) ($plan['plugin_package_identifier'] ?? ''), (string) ($verification['plugin_package_identifier'] ?? ''))
            || ! hash_equals((string) ($plan['artifact_evidence_hash'] ?? ''), (string) ($verification['artifact_evidence_hash'] ?? ''))
            || ! hash_equals((string) ($plan['provider_operation_reference'] ?? ''), (string) ($verification['provider_operation_reference'] ?? ''))) {
            return new \WP_Error('digiforge_recovery_verification_binding', __('Verification evidence does not match the durable recovery dispatch identity.', 'digiforge'), ['status' => 409, 'reconciliation_required' => true]);
        }

        $record = [
            'operation_key' => $operationKey,
            'target_site_url' => (string) $verification['target_site_url'],
            'database_backup_identifier' => (string) $verification['database_backup_identifier'],
            'plugin_package_identifier' => (string) $verification['plugin_package_identifier'],
            'artifact_evidence_hash' => (string) $verification['artifact_evidence_hash'],
            'provider' => 'hostinger',
            'provider_operation_reference' => (string) $verification['provider_operation_reference'],
            'checks' => is_array($verification['checks'] ?? null) ? $verification['checks'] : [],
            'observed' => is_array($verification['observed'] ?? null) ? $verification['observed'] : [],
            'verified_at' => gmdate('c'),
            'external_actions_performed' => false,
            'commerce_execution_authorized' => false,
            'drill_evidence_recorded' => false,
        ];
        $record['evidence_hash'] = hash('sha256', (string) wp_json_encode($record, JSON_UNESCAPED_SLASHES));
        $name = self::PREFIX . hash('sha256', $operationKey);
        $existing = RecoveryDispatchLedger::read($name);
        if ($existing instanceof \WP_Error) return $existing;
        if ($existing !== []) {
            $same = $existing;
            unset($same['verified_at'], $same['evidence_hash']);
            $candidate = $record;
            unset($candidate['verified_at'], $candidate['evidence_hash']);
            if ($same !== $candidate) {
                return new \WP_Error('digiforge_recovery_verification_conflict', __('Immutable recovery verification evidence already exists with different observations.', 'digiforge'), ['status' => 409]);
            }
            return $existing + ['replayed' => true];
        }
        if (! RecoveryDispatchLedger::insert($name, $record)) {
            return new \WP_Error('digiforge_recovery_verification_persist_failed', __('Recovery verification evidence could not be persisted immutably.', 'digiforge'), ['status' => 500, 'reconciliation_required' => true]);
        }
        return $record + ['replayed' => false];
    }
}
