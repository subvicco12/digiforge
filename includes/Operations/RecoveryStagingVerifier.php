<?php

declare(strict_types=1);

namespace DigiForge\Operations;

use DigiForge\Integrations\RecoveryStagingClient;

/**
 * Read-only verifier for an isolated recovery target.
 * Provider acceptance is never sufficient: the restored DigiForge site must
 * independently prove health, schema, STOP ALL, and the expected plugin version.
 */
final class RecoveryStagingVerifier
{
    /** @return array<string,mixed>|\WP_Error */
    public static function verify(string $operationKey): array|\WP_Error
    {
        $plan = RecoveryOrchestrator::snapshot();
        if ($operationKey === '' || ! hash_equals((string) ($plan['operation_key'] ?? ''), $operationKey)) {
            return new \WP_Error('digiforge_recovery_verify_operation_mismatch', __('Verification must match the active recovery operation.', 'digiforge'), ['status' => 409]);
        }
        if (! RecoveryOrchestrator::safetyLocked() || ($plan['reconciliation_required'] ?? false) !== true) {
            return new \WP_Error('digiforge_recovery_verify_not_locked', __('Recovery verification requires the locked reconciliation state.', 'digiforge'), ['status' => 409]);
        }
        if (($plan['target_environment'] ?? '') !== 'staging' || ($plan['provider'] ?? '') !== 'hostinger') {
            return new \WP_Error('digiforge_recovery_verify_target', __('Only the governed isolated Hostinger staging target can be verified.', 'digiforge'), ['status' => 409]);
        }

        $base = self::targetRoot((string) ($plan['target_site_url'] ?? ''));
        if ($base instanceof \WP_Error) { return $base; }

        $client = new RecoveryStagingClient();
        $snapshot = $client->get($base . 'wp-json/digiforge/v1/recovery/verification-snapshot');
        if ($snapshot instanceof \WP_Error) { return $snapshot; }

        $identityOperationKey = (string)($plan['backup_identity_operation_key'] ?? '');
        $binding = RecoveryBackupIdentityBinding::read($identityOperationKey);
        if ($binding instanceof \WP_Error) { return $binding; }
        $databaseIdentityVerified = false;
        $observedMarker = [];
        if ($binding !== [] && ($binding['database_identity_proof_available'] ?? false) === true) {
            $marker = RecoveryBackupIdentityMarker::read($identityOperationKey);
            if ($marker instanceof \WP_Error) { return $marker; }
            $keyHash = (string)($marker['operation_key_hash'] ?? '');
            if (preg_match('/^[a-f0-9]{64}$/', $keyHash)) {
                $observedMarker = $client->get($base . 'wp-json/digiforge/v1/recovery/evidence/database-backup/marker/' . $keyHash);
                if ($observedMarker instanceof \WP_Error) { return $observedMarker; }
                $databaseIdentityVerified = hash_equals((string)($binding['marker_hash'] ?? ''), (string)($observedMarker['marker_hash'] ?? ''))
                    && hash_equals((string)($marker['marker_hash'] ?? ''), (string)($observedMarker['marker_hash'] ?? ''))
                    && hash_equals((string)($plan['database_backup_identifier'] ?? ''), (string)($binding['backup_identifier'] ?? ''))
                    && hash_equals((string)($plan['backup_identity_binding_hash'] ?? ''), (string)($binding['binding_hash'] ?? ''));
            }
        }

        $expectedVersion = RecoveryOrchestrator::plannedPackageVersion($plan);
        $schema = is_array($snapshot['schema'] ?? null) ? $snapshot['schema'] : [];
        $checks = [
            'health_ok' => ($snapshot['status'] ?? '') === 'ok',
            'stop_all_active' => ($snapshot['stop_all'] ?? false) === true,
            'externally_locked' => ($snapshot['externally_locked'] ?? false) === true,
            'schema_current' => isset($schema['current'], $schema['expected']) && (int) $schema['current'] === (int) $schema['expected'],
            'plugin_version_exact' => $expectedVersion !== '' && hash_equals($expectedVersion, (string) ($snapshot['version'] ?? '')),
            'automation_disabled' => ($snapshot['automation_enabled'] ?? true) === false,
            'activation_not_authorized' => ($snapshot['activation_not_authorized'] ?? false) === true,
            'automation_unarmed' => ($snapshot['automation_unarmed'] ?? false) === true,
            'no_effective_feature_switches' => ($snapshot['no_effective_feature_switches'] ?? false) === true,
            'database_identity_verified' => $databaseIdentityVerified,
        ];
        $verified = ! in_array(false, $checks, true);
        return [
            'verified' => $verified,
            'status' => $verified ? 'VERIFY_REQUIRED' : 'REVIEW_REQUIRED',
            'operation_key' => $operationKey,
            'target_site_url' => $base,
            'database_backup_identifier' => (string) ($plan['database_backup_identifier'] ?? ''),
            'plugin_package_identifier' => (string) ($plan['plugin_package_identifier'] ?? ''),
            'artifact_evidence_hash' => (string) ($plan['artifact_evidence_hash'] ?? ''),
            'provider_operation_reference' => (string) ($plan['provider_operation_reference'] ?? ''),
            'checks' => $checks,
            'observed' => ['version' => (string) ($snapshot['version'] ?? ''), 'schema' => $schema, 'database_marker' => $observedMarker],
            'external_actions_performed' => false,
            'commerce_execution_authorized' => false,
            'drill_evidence_recorded' => false,
        ];
    }

    private static function targetRoot(string $url): string|\WP_Error
    {
        $parts = wp_parse_url($url);
        $homeHost = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || strtolower((string) ($parts['host'] ?? '')) !== 'digiforgestaging.converentis.com'
            || strtolower((string) ($parts['host'] ?? '')) === $homeHost) {
            return new \WP_Error('digiforge_recovery_verify_target', __('Verification target must be an exact isolated HTTPS staging root.', 'digiforge'), ['status' => 409]);
        }
        return trailingslashit($url);
    }

}
