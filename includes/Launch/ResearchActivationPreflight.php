<?php

declare(strict_types=1);

namespace DigiForge\Launch;

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Integrations\Repository as IntegrationRepository;
use DigiForge\Operations\Readiness;
use DigiForge\Integrations\CredentialVault;

/**
 * Read-only production preflight for staged Research activation.
 *
 * This class never changes activation gates, feature switches or external systems.
 */
final class ResearchActivationPreflight
{
    /** @return array<string,mixed> */
    public function report(): array
    {
        global $wpdb;

        $readiness = (new Readiness())->report();
        $connector = $wpdb->get_row(
            "SELECT id, provider, environment, status, enabled FROM " . Tables::integrations()
                . " WHERE provider='ai' AND environment='production' AND status='CONFIGURED' AND enabled=1 ORDER BY id ASC LIMIT 1",
            ARRAY_A
        );

        $connectorReady = is_array($connector) && (int) ($connector['id'] ?? 0) > 0;
        $credentialPresent = false;
        $credentialDecryptable = false;

        if ($connectorReady) {
            $integrationId = (int) $connector['id'];
            $ciphertext = $wpdb->get_var($wpdb->prepare(
                'SELECT ciphertext FROM ' . Tables::integration_secrets() . ' WHERE integration_id=%d AND secret_name=%s LIMIT 1',
                $integrationId,
                'api_key'
            ));
            $credentialPresent = is_string($ciphertext) && $ciphertext !== '';
            if ($credentialPresent) {
                try {
                    $secret = CredentialVault::decryptExisting(
                        $ciphertext,
                        IntegrationRepository::secretContext($integrationId, 'api_key')
                    );
                    $credentialDecryptable = is_string($secret) && $secret !== '';
                    unset($secret);
                } catch (\Throwable $e) {
                    $credentialDecryptable = false;
                }
            }
        }

        return self::summarize([
            'readiness_status' => (string) ($readiness['status'] ?? ''),
            'externally_locked' => (bool) ($readiness['externally_locked'] ?? Settings::safety_locked()),
            'recovery_status' => (string) ($readiness['recovery']['status'] ?? ''),
            'stop_all' => Settings::get('stop_all', true) === true,
            'activation_authorized' => Settings::get('activation_authorized', false) === true,
            'automation_armed' => Settings::get('automation_armed', false) === true,
            'research_configured' => Settings::get('research', false) === true,
            'ai_configured' => Settings::get('ai', false) === true,
            'research_effective' => Settings::is_enabled('research'),
            'ai_effective' => Settings::is_enabled('ai'),
            'connector_ready' => $connectorReady,
            'credential_present' => $credentialPresent,
            'credential_decryptable' => $credentialDecryptable,
        ]);
    }

    /**
     * Pure fail-closed projection used by runtime and tests.
     *
     * @param array<string,mixed> $evidence
     * @return array<string,mixed>
     */
    public static function summarize(array $evidence): array
    {
        $checks = [
            'readiness_ready_locked' => ($evidence['readiness_status'] ?? '') === 'READY_LOCKED',
            'externally_locked' => ($evidence['externally_locked'] ?? false) === true,
            'recovery_pass' => ($evidence['recovery_status'] ?? '') === 'PASS',
            'stop_all_active' => ($evidence['stop_all'] ?? false) === true,
            'activation_not_authorized' => ($evidence['activation_authorized'] ?? true) === false,
            'automation_unarmed' => ($evidence['automation_armed'] ?? true) === false,
            'production_ai_connector_ready' => ($evidence['connector_ready'] ?? false) === true,
            'credential_present' => ($evidence['credential_present'] ?? false) === true,
            'credential_decryptable' => ($evidence['credential_decryptable'] ?? false) === true,
            'research_not_effective_yet' => ($evidence['research_effective'] ?? true) === false,
            'ai_not_effective_yet' => ($evidence['ai_effective'] ?? true) === false,
        ];

        $blockers = [];
        foreach ($checks as $name => $pass) {
            if (! $pass) {
                $blockers[] = $name;
            }
        }

        $ready = $blockers === [];

        return [
            'status' => $ready ? 'READY_FOR_CONTROLLED_RESEARCH_ACTIVATION' : 'BLOCKED',
            'checks' => $checks,
            'blockers' => $blockers,
            'configured_switches' => [
                'research' => ($evidence['research_configured'] ?? false) === true,
                'ai' => ($evidence['ai_configured'] ?? false) === true,
            ],
            'research_live_execution_dependency' => 'Live research requires both research and AI effective switches. Batch 1 must not execute live research before the separately governed AI activation stage.',
            'next_action' => $ready ? 'EXPLICIT_ACTIVATION_AUTHORIZATION_REQUIRED' : 'RESOLVE_PREFLIGHT_BLOCKERS',
            'network_requests_performed' => false,
            'external_actions_performed' => false,
        ];
    }
}
