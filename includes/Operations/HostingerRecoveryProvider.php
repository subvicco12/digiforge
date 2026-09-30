<?php

declare(strict_types=1);

namespace DigiForge\Operations;

use DigiForge\Database\Tables;
use DigiForge\Integrations\Repository;

/**
 * Non-destructive Hostinger recovery capability adapter.
 *
 * This slice intentionally cannot dispatch an import. It proves that a governed,
 * enabled Hostinger connector is bound to one explicit isolated staging domain.
 */
final class HostingerRecoveryProvider implements RecoveryProviderAdapter
{
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
        $connection = $this->connection();
        if ($connection instanceof \WP_Error) { return $connection; }
        $config = is_array($connection['config'] ?? null) ? $connection['config'] : [];
        $targetHost = strtolower((string) wp_parse_url((string) ($plan['target_site_url'] ?? ''), PHP_URL_HOST));
        $stagingDomain = strtolower(trim((string) ($config['staging_domain'] ?? '')));
        if ($targetHost === '' || $stagingDomain === '' || ! hash_equals($stagingDomain, $targetHost)) {
            return new \WP_Error('digiforge_hostinger_target_mismatch', __('Recovery target does not match the connector-approved isolated staging domain.', 'digiforge'), ['status' => 409]);
        }
        if ($targetHost === strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST))) {
            return new \WP_Error('digiforge_hostinger_current_site_refused', __('The running DigiForge site cannot be used as the Hostinger recovery target.', 'digiforge'), ['status' => 409]);
        }

        return new \WP_Error(
            'digiforge_hostinger_import_not_enabled',
            __('Hostinger recovery capability is configured, but destructive import dispatch is not enabled in this certified slice.', 'digiforge'),
            ['status' => 501, 'state' => 'PROVIDER_REQUIRED']
        );
    }

    public function reconcile(array $plan): array|\WP_Error
    {
        return new \WP_Error('digiforge_hostinger_reconcile_not_enabled', __('Hostinger recovery reconciliation is not enabled in this certified slice.', 'digiforge'), ['status' => 501]);
    }

    /** @return array<string,mixed>|\WP_Error */
    private function connection(): array|\WP_Error
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id,provider,environment,connection_key,display_name,status,enabled,config FROM ' . Tables::integrations() . ' WHERE provider = %s AND status = %s AND enabled = %d ORDER BY id ASC LIMIT 1',
                'hostinger',
                'CONFIGURED',
                1
            ),
            ARRAY_A
        );
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
