<?php

declare(strict_types=1);

namespace DigiForge\Operations;

/** Builds a human-review candidate; never records drill PASS evidence. */
final class RecoveryDrillReviewCandidate
{
    /** @return array<string,mixed>|\WP_Error */
    public static function build(string $operationKey): array|\WP_Error
    {
        if (! RecoveryOrchestrator::safetyLocked()) {
            return new \WP_Error('digiforge_recovery_review_not_locked', __('Recovery drill review requires STOP ALL and the external safety lock.', 'digiforge'), ['status' => 409]);
        }
        $receipt = RecoveryVerificationEvidence::read($operationKey);
        if ($receipt instanceof \WP_Error) return $receipt;
        if ($receipt === [] || ($receipt['drill_evidence_recorded'] ?? true) !== false) {
            return new \WP_Error('digiforge_recovery_review_evidence_missing', __('Immutable verified staging evidence is required before drill review.', 'digiforge'), ['status' => 409]);
        }
        $checks = is_array($receipt['checks'] ?? null) ? $receipt['checks'] : [];
        if ($checks === [] || in_array(false, $checks, true)) {
            return new \WP_Error('digiforge_recovery_review_checks_failed', __('All immutable staging verification checks must pass before drill review.', 'digiforge'), ['status' => 409]);
        }
        return [
            'review_required' => true,
            'operation_key' => $operationKey,
            'verification_evidence_hash' => (string) ($receipt['evidence_hash'] ?? ''),
            'environment' => 'staging',
            'database_backup_identifier' => (string) ($receipt['database_backup_identifier'] ?? ''),
            'plugin_package_identifier' => (string) ($receipt['plugin_package_identifier'] ?? ''),
            'target_site_url' => (string) ($receipt['target_site_url'] ?? ''),
            'verified_at' => (string) ($receipt['verified_at'] ?? ''),
            'proposed_checks' => [
                'restore_verified' => true,
                'schema_verified' => ($checks['schema_current'] ?? false) === true,
                'application_health_verified' => ($checks['health_ok'] ?? false) === true
                    && ($checks['stop_all_active'] ?? false) === true
                    && ($checks['externally_locked'] ?? false) === true,
            ],
            'external_actions_performed' => false,
            'external_execution_authorized' => false,
            'commerce_execution_authorized' => false,
            'drill_evidence_recorded' => false,
            'passed' => false,
        ];
    }
}
