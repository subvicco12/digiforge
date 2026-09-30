<?php

declare(strict_types=1);

namespace DigiForge\Operations;

use DigiForge\Database\Tables;
use DigiForge\Integrations\Repository;
use DigiForge\Integrations\CredentialVault;

/** Exact isolated-target import transport; acceptance never proves recovery success. */
final class HostingerRecoveryProvider implements RecoveryProviderAdapter
{
    private const API_BASE = 'https://developers.hostinger.com/api/hosting/v1';
    public function providerSlug(): string { return 'hostinger'; }

    public function capability(): array
    {
        $connection = $this->connection();
        if ($connection instanceof \WP_Error) {
            return ['available' => false, 'reason' => $connection->get_error_code()];
        }
        return ['available' => true, 'reason' => 'configured'];
    }

    public function execute(array $plan): array|\WP_Error
    {
        if (! RecoveryOrchestrator::safetyLocked()
            || ! RecoveryOrchestrator::dispatchClaimMatches($plan)) {
            return new \WP_Error('digiforge_hostinger_dispatch_not_claimed', __('An externally locked, durable recovery dispatch claim is required.', 'digiforge'), ['status' => 409]);
        }
        $connection = $this->connection();
        if ($connection instanceof \WP_Error) { return $connection; }
        $config = is_array($connection['config'] ?? null) ? $connection['config'] : [];
        $parts = wp_parse_url((string) ($plan['target_site_url'] ?? ''));
        if (! is_array($parts) || ($plan['target_environment'] ?? '') !== 'staging'
            || ($parts['scheme'] ?? '') !== 'https' || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return new \WP_Error('digiforge_hostinger_target_mismatch', __('Only an exact HTTPS staging website root is permitted.', 'digiforge'), ['status' => 409]);
        }
        $targetHost = strtolower((string) wp_parse_url((string) ($plan['target_site_url'] ?? ''), PHP_URL_HOST));
        $stagingDomain = strtolower(trim((string) ($config['staging_domain'] ?? '')));
        if ($targetHost === '' || $stagingDomain === '' || ! hash_equals($stagingDomain, $targetHost)) {
            return new \WP_Error('digiforge_hostinger_target_mismatch', __('Recovery target does not match the connector-approved isolated staging domain.', 'digiforge'), ['status' => 409]);
        }
        if ($targetHost === 'digiforge.converentis.com' || $targetHost === strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST))) {
            return new \WP_Error('digiforge_hostinger_current_site_refused', __('The running DigiForge site cannot be used as the Hostinger recovery target.', 'digiforge'), ['status' => 409]);
        }

        $archive = trim((string) ($config['archive_path'] ?? ''));
        $database = trim((string) ($config['database_path'] ?? ''));
        if (! self::relativeArtifactPath($archive) || ! self::relativeArtifactPath($database)
            || ($config['database_backup_identifier'] ?? '') !== ($plan['database_backup_identifier'] ?? '')
            || ($config['plugin_package_identifier'] ?? '') !== ($plan['plugin_package_identifier'] ?? '')
            || ($config['artifact_evidence_hash'] ?? '') !== ($plan['artifact_evidence_hash'] ?? '')
            || RecoveryOrchestrator::artifactEvidenceHash(RecoveryEvidence::snapshot()) !== ($plan['artifact_evidence_hash'] ?? '')) {
            return new \WP_Error('digiforge_hostinger_artifact_paths_missing', __('Hostinger import requires connector-bound archive_path and database_path values that already exist on the isolated staging account.', 'digiforge'), ['status' => 409]);
        }
        $token = $this->credential((int) $connection['id'], 'api_token');
        if ($token instanceof \WP_Error) { return $token; }
        $account = rawurlencode(trim((string) $config['hosting_account']));
        $domain = rawurlencode($stagingDomain);
        $url = self::API_BASE . '/accounts/' . $account . '/websites/' . $domain . '/wordpress/import';
        $body = wp_json_encode(['archive_path' => $archive, 'sql_path' => $database]);
        if (! is_string($body)) { unset($token); return new \WP_Error('digiforge_hostinger_request_encode_failed', __('Hostinger import request could not be encoded.', 'digiforge'), ['status' => 500]); }
        // A second atomic tombstone also prevents direct/reentrant adapter calls. Never remove it.
        $dispatchOption = 'digiforge_hostinger_dispatch_' . hash('sha256', (string) $plan['operation_key']);
        $dispatch = ['provider' => 'hostinger', 'integration_id' => (int) $connection['id'], 'hosting_account' => (string) $config['hosting_account'], 'operation_key' => $plan['operation_key'], 'state' => 'UNKNOWN', 'dispatch_state' => 'RECONCILIATION_REQUIRED', 'target_site_url' => $plan['target_site_url'], 'request_binding_hash' => hash('sha256', $url . "\n" . $body), 'database_backup_identifier' => $plan['database_backup_identifier'], 'plugin_package_identifier' => $plan['plugin_package_identifier'], 'artifact_evidence_hash' => $plan['artifact_evidence_hash']];
        if (! RecoveryDispatchLedger::insert($dispatchOption, $dispatch) || RecoveryDispatchLedger::read($dispatchOption) !== $dispatch) {
            unset($token);
            return new \WP_Error('digiforge_hostinger_dispatch_persist_failed', __('Unable to claim Hostinger dispatch. Reconciliation is required.', 'digiforge'), ['status' => 500, 'reconciliation_required' => true]);
        }
        if (! RecoveryOrchestrator::safetyLocked() || ! RecoveryOrchestrator::dispatchClaimMatches($plan)
            || ! RecoveryDispatchLedger::connectorMatches($connection)
            || ! RecoveryDispatchLedger::artifactsMatch((string) $plan['artifact_evidence_hash'])) {
            unset($token);
            return new \WP_Error('digiforge_hostinger_dispatch_not_claimed', __('Recovery interlocks changed before dispatch. Reconciliation is required.', 'digiforge'), ['status' => 409, 'reconciliation_required' => true]);
        }
        $response = wp_remote_post($url, [
            'timeout' => 20, 'redirection' => 0, 'reject_unsafe_urls' => true, 'sslverify' => true,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
            'body' => $body,
        ]);
        unset($token);
        if (is_wp_error($response)) {
            return new \WP_Error('digiforge_hostinger_result_unknown', __('Hostinger import transport returned an ambiguous result. Reconcile before retrying.', 'digiforge'), ['status' => 502, 'reconciliation_required' => true]);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || ! is_array($decoded)) {
            return new \WP_Error('digiforge_hostinger_result_unknown', __('Hostinger import did not return a safely classifiable acceptance result. Reconcile before retrying.', 'digiforge'), ['status' => 502, 'reconciliation_required' => true, 'http_status' => $status]);
        }
        // Official SuccessEmptyResource has only a message: no operation reference is promised.
        // Do not trust arbitrary response fields (which could echo secrets) as restore evidence.
        return new \WP_Error('digiforge_hostinger_reference_missing', __('Hostinger returned acceptance without a documented durable operation reference. Reconcile the isolated site before any further action.', 'digiforge'), ['status' => 502, 'state' => 'UNKNOWN', 'reconciliation_required' => true]);
    }

    public function reconcile(array $plan): array|\WP_Error
    {
        return new \WP_Error('digiforge_hostinger_reconcile_requires_site_verification', __('Provider acceptance is not restore proof. Verify the isolated staging site health, schema, and STOP ALL state before recording drill evidence.', 'digiforge'), ['status' => 409, 'reconciliation_required' => true]);
    }

    private static function relativeArtifactPath(string $path): bool
    {
        return $path !== '' && strlen($path) <= 1024
            && preg_match('/^[A-Za-z0-9_.\/-]+$/D', $path) === 1
            && $path[0] !== '/' && ! in_array('..', explode('/', $path), true);
    }

    private function credential(int $integrationId, string $name): string|\WP_Error
    {
        $ciphertext = RecoveryDispatchLedger::ciphertext($integrationId, sanitize_key($name));
        if ($ciphertext instanceof \WP_Error || $ciphertext === '') { return new \WP_Error('digiforge_hostinger_credential_missing', __('Hostinger API token is unavailable.', 'digiforge'), ['status' => 409]); }
        try {
            $token = CredentialVault::decrypt($ciphertext, Repository::secretContext($integrationId, $name));
            if ($token === '' || preg_match('/[\x00-\x20\x7f]/', $token)) { throw new \RuntimeException('Invalid credential'); }
            return $token;
        }
        catch (\Throwable $e) { return new \WP_Error('digiforge_hostinger_credential_decryption_failed', __('Hostinger credential could not be decrypted.', 'digiforge'), ['status' => 500]); }
    }

    /** @return array<string,mixed>|\WP_Error */
    private function connection(): array|\WP_Error
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id,provider,environment,connection_key,display_name,status,enabled,config FROM ' . Tables::integrations() . ' WHERE provider = %s AND status = %s AND enabled = %d ORDER BY id ASC LIMIT 2',
                'hostinger',
                'CONFIGURED',
                1
            ),
            ARRAY_A
        );
        $row = is_array($rows) && count($rows) === 1 ? $rows[0] : null;
        if (! is_array($row)) {
            return new \WP_Error('digiforge_hostinger_connector_unavailable', __('No enabled CONFIGURED Hostinger infrastructure connector is available.', 'digiforge'), ['status' => 409]);
        }
        $row['config'] = json_decode((string) ($row['config'] ?? '{}'), true) ?: [];
        $config = $row['config'];
        if (trim((string) ($config['hosting_account'] ?? '')) === '' || trim((string) ($config['staging_domain'] ?? '')) === '') {
            return new \WP_Error('digiforge_hostinger_config_incomplete', __('Hostinger recovery requires a hosting account and explicit staging domain.', 'digiforge'), ['status' => 409]);
        }
        $secrets = (new Repository())->secretMetadata((int) $row['id']);
        $names = array_map(static fn(array $secret): string => (string) ($secret['secret_name'] ?? ''), $secrets);
        if (! in_array('api_token', $names, true)) {
            return new \WP_Error('digiforge_hostinger_credential_missing', __('Hostinger API token is not stored in the credential vault.', 'digiforge'), ['status' => 409]);
        }
        return $row;
    }
}
