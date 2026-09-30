<?php

declare(strict_types=1);

namespace DigiForge\Operations;

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

        $health = self::get($base . 'wp-json/digiforge/v1/health');
        if ($health instanceof \WP_Error) { return $health; }
        $readiness = self::get($base . 'wp-json/digiforge/v1/readiness');
        if ($readiness instanceof \WP_Error) { return $readiness; }

        $artifacts = RecoveryEvidence::snapshot();
        $expectedVersion = (string) ($artifacts['plugin_package']['version'] ?? '');
        $schema = is_array($readiness['schema'] ?? null) ? $readiness['schema'] : [];
        $checks = [
            'health_ok' => ($health['status'] ?? '') === 'ok',
            'stop_all_active' => ($health['stop_all'] ?? false) === true && (($readiness['checks']['stop_all_active'] ?? false) === true),
            'externally_locked' => ($health['externally_locked'] ?? false) === true && ($readiness['externally_locked'] ?? false) === true,
            'schema_current' => isset($schema['current'], $schema['expected']) && (int) $schema['current'] === (int) $schema['expected'],
            'plugin_version_exact' => $expectedVersion !== '' && hash_equals($expectedVersion, (string) ($health['version'] ?? '')),
            'automation_disabled' => ($health['automation_enabled'] ?? true) === false,
            'activation_not_authorized' => ($readiness['checks']['activation_not_authorized'] ?? false) === true,
            'automation_unarmed' => ($readiness['checks']['automation_unarmed'] ?? false) === true,
            'no_effective_feature_switches' => ($readiness['checks']['no_effective_feature_switches'] ?? false) === true,
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
            'observed' => ['version' => (string) ($health['version'] ?? ''), 'schema' => $schema],
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
            || strtolower((string) ($parts['host'] ?? '')) === $homeHost || strtolower((string) ($parts['host'] ?? '')) === 'digiforge.converentis.com') {
            return new \WP_Error('digiforge_recovery_verify_target', __('Verification target must be an exact isolated HTTPS staging root.', 'digiforge'), ['status' => 409]);
        }
        return trailingslashit($url);
    }

    /** @return array<string,mixed>|\WP_Error */
    private static function get(string $url): array|\WP_Error
    {
        $response = wp_remote_get($url, ['timeout' => 12, 'redirection' => 0, 'reject_unsafe_urls' => true, 'sslverify' => true]);
        if (is_wp_error($response)) {
            return new \WP_Error('digiforge_recovery_verify_unreachable', __('The isolated recovery target could not be verified.', 'digiforge'), ['status' => 502]);
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return new \WP_Error('digiforge_recovery_verify_http', __('The isolated recovery target did not return a successful verification response.', 'digiforge'), ['status' => 502]);
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($body)) {
            return new \WP_Error('digiforge_recovery_verify_payload', __('The isolated recovery target returned invalid verification evidence.', 'digiforge'), ['status' => 502]);
        }
        return $body;
    }
}
