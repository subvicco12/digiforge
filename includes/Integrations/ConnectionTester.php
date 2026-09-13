<?php
declare(strict_types=1);
namespace DigiForge\Integrations;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;

/** Explicit, administrator-triggered, read-only provider connectivity checks. */
final class ConnectionTester {
    private const PRINTIFY_SHOPS_URL = 'https://api.printify.com/v1/shops.json';

    public function test(int $integrationId): array|\WP_Error {
        $repository = new Repository();
        $integration = $repository->find($integrationId);
        if (! $integration) {
            return new \WP_Error('integration_not_found', __('Integration not found.', 'digiforge'), ['status' => 404]);
        }

        if (($integration['provider'] ?? '') !== 'printify') {
            return new \WP_Error(
                'connection_test_not_supported',
                __('A live read-only connection test is not implemented for this provider yet.', 'digiforge'),
                ['status' => 400]
            );
        }

        $token = $this->credential($integrationId, 'personal_access_token');
        if (is_wp_error($token)) {
            $token = $this->credential($integrationId, 'access_token');
        }
        if (is_wp_error($token) || $token === '') {
            return new \WP_Error(
                'printify_credential_missing',
                __('Store a Printify personal access token before testing the connection.', 'digiforge'),
                ['status' => 409]
            );
        }

        $response = wp_remote_get(self::PRINTIFY_SHOPS_URL, [
            'timeout' => 12,
            'redirection' => 0,
            'reject_unsafe_urls' => true,
            'sslverify' => true,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'User-Agent' => 'DigiForge WordPress Integration Tester',
            ],
        ]);
        unset($token);

        if (is_wp_error($response)) {
            $this->record($integrationId, false, ['reason' => 'transport_error']);
            Logger::audit('integration_connection_test_failed', ['provider' => 'printify', 'reason' => 'transport_error'], 'integration', (string) $integrationId);
            return new \WP_Error('printify_transport_error', __('Printify could not be reached from this WordPress server.', 'digiforge'), ['status' => 502]);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 401 || $status === 403) {
            $this->record($integrationId, false, ['http_status' => $status, 'reason' => 'authentication_failed']);
            Logger::audit('integration_connection_test_failed', ['provider' => 'printify', 'http_status' => $status, 'reason' => 'authentication_failed'], 'integration', (string) $integrationId);
            return new \WP_Error('printify_authentication_failed', __('Printify rejected the stored credential. Generate or store a valid token and try again.', 'digiforge'), ['status' => 401]);
        }
        if ($status < 200 || $status >= 300) {
            $this->record($integrationId, false, ['http_status' => $status, 'reason' => 'unexpected_response']);
            Logger::audit('integration_connection_test_failed', ['provider' => 'printify', 'http_status' => $status, 'reason' => 'unexpected_response'], 'integration', (string) $integrationId);
            return new \WP_Error('printify_unexpected_response', sprintf(__('Printify returned HTTP %d during the read-only connection test.', 'digiforge'), $status), ['status' => 502]);
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($decoded)) {
            $this->record($integrationId, false, ['http_status' => $status, 'reason' => 'invalid_json']);
            return new \WP_Error('printify_invalid_response', __('Printify returned an unreadable response.', 'digiforge'), ['status' => 502]);
        }

        $shops = [];
        foreach (array_slice($decoded, 0, 50) as $shop) {
            if (! is_array($shop)) { continue; }
            $shops[] = [
                'id' => absint($shop['id'] ?? 0),
                'title' => sanitize_text_field((string) ($shop['title'] ?? '')),
                'sales_channel' => sanitize_text_field((string) ($shop['sales_channel'] ?? '')),
            ];
        }

        $summary = ['http_status' => $status, 'shop_count' => count($shops), 'shops' => $shops];
        $this->record($integrationId, true, $summary);
        Logger::audit('integration_connection_test_succeeded', ['provider' => 'printify', 'shop_count' => count($shops)], 'integration', (string) $integrationId);

        return [
            'ok' => true,
            'provider' => 'printify',
            'checked_at' => current_time('mysql', true),
            'shops' => $shops,
        ];
    }

    private function credential(int $integrationId, string $name): string|\WP_Error {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT ciphertext FROM ' . Tables::integration_secrets() . ' WHERE integration_id = %d AND secret_name = %s LIMIT 1',
                $integrationId,
                sanitize_key($name)
            ),
            ARRAY_A
        );
        if (! is_array($row) || empty($row['ciphertext'])) {
            return new \WP_Error('credential_not_found', __('Credential not found.', 'digiforge'), ['status' => 404]);
        }
        try {
            return CredentialVault::decrypt((string) $row['ciphertext'], Repository::secretContext($integrationId, $name));
        } catch (\Throwable $e) {
            return new \WP_Error('credential_decryption_failed', __('Stored credential could not be decrypted.', 'digiforge'), ['status' => 500]);
        }
    }

    private function record(int $integrationId, bool $success, array $details): void {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT config FROM ' . Tables::integrations() . ' WHERE id = %d', $integrationId), ARRAY_A);
        if (! is_array($row)) { return; }
        $config = json_decode((string) ($row['config'] ?? '{}'), true);
        if (! is_array($config)) { $config = []; }
        $config['_connection_test'] = [
            'ok' => $success,
            'checked_at' => current_time('mysql', true),
            'details' => $details,
        ];
        $encoded = wp_json_encode($config);
        if (! is_string($encoded)) { return; }
        $wpdb->update(Tables::integrations(), [
            'status' => $success ? 'CONFIGURED' : 'ERROR',
            'config' => $encoded,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $integrationId]);
    }
}
