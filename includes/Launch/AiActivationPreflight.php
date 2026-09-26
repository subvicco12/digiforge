<?php

declare(strict_types=1);

namespace DigiForge\Launch;

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Integrations\CredentialVault;
use DigiForge\Integrations\Repository as IntegrationRepository;

/** Read-only preflight for separately governed AI capability activation. */
final class AiActivationPreflight
{
    /** @return array<string,mixed> */
    public function report(): array
    {
        global $wpdb;
        $connector = $wpdb->get_row(
            "SELECT id FROM " . Tables::integrations()
                . " WHERE provider='ai' AND environment='production' AND status='CONFIGURED' AND enabled=1 ORDER BY id ASC LIMIT 1",
            ARRAY_A
        );
        $connectorReady = is_array($connector) && (int) ($connector['id'] ?? 0) > 0;
        $credentialPresent = false;
        $credentialDecryptable = false;
        if ($connectorReady) {
            $id = (int) $connector['id'];
            $ciphertext = $wpdb->get_var($wpdb->prepare(
                'SELECT ciphertext FROM ' . Tables::integration_secrets() . ' WHERE integration_id=%d AND secret_name=%s LIMIT 1',
                $id,
                'api_key'
            ));
            $credentialPresent = is_string($ciphertext) && $ciphertext !== '';
            if ($credentialPresent) {
                try {
                    $secret = CredentialVault::decryptExisting($ciphertext, IntegrationRepository::secretContext($id, 'api_key'));
                    $credentialDecryptable = is_string($secret) && $secret !== '';
                    unset($secret);
                } catch (\Throwable $e) {
                    $credentialDecryptable = false;
                }
            }
        }
        return self::summarize([
            'stop_all' => Settings::get('stop_all', true) === true,
            'activation_authorized' => Settings::get('activation_authorized', false) === true,
            'automation_armed' => Settings::get('automation_armed', false) === true,
            'research_authorized' => Settings::get('research_activation_authorized', false) === true,
            'research_effective' => Settings::is_enabled('research'),
            'ai_configured' => Settings::get('ai', false) === true,
            'ai_authorized' => Settings::get('ai_activation_authorized', false) === true,
            'ai_effective' => Settings::is_enabled('ai'),
            'connector_ready' => $connectorReady,
            'credential_present' => $credentialPresent,
            'credential_decryptable' => $credentialDecryptable,
        ]);
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public static function summarize(array $evidence): array
    {
        $checks = [
            'stop_all_released' => ($evidence['stop_all'] ?? true) === false,
            'activation_authorized' => ($evidence['activation_authorized'] ?? false) === true,
            'automation_armed' => ($evidence['automation_armed'] ?? false) === true,
            'research_authorized' => ($evidence['research_authorized'] ?? false) === true,
            'research_effective' => ($evidence['research_effective'] ?? false) === true,
            'ai_configured' => ($evidence['ai_configured'] ?? false) === true,
            'ai_not_authorized_yet' => ($evidence['ai_authorized'] ?? true) === false,
            'ai_not_effective_yet' => ($evidence['ai_effective'] ?? true) === false,
            'production_ai_connector_ready' => ($evidence['connector_ready'] ?? false) === true,
            'credential_present' => ($evidence['credential_present'] ?? false) === true,
            'credential_decryptable' => ($evidence['credential_decryptable'] ?? false) === true,
        ];
        $blockers = array_keys(array_filter($checks, static fn(bool $pass): bool => ! $pass));
        return [
            'status' => $blockers === [] ? 'READY_FOR_CONTROLLED_AI_ACTIVATION' : 'BLOCKED',
            'checks' => $checks,
            'blockers' => $blockers,
            'next_action' => $blockers === [] ? 'EXPLICIT_AI_ACTIVATION_AUTHORIZATION_REQUIRED' : 'RESOLVE_AI_PREFLIGHT_BLOCKERS',
            'network_requests_performed' => false,
            'external_actions_performed' => false,
        ];
    }
}
