<?php
declare(strict_types=1);
namespace DigiForge\Integrations;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;

/** Explicit, administrator-triggered, read-only provider connectivity checks. */
final class ConnectionTester {
    private const PRINTIFY_SHOPS_URL = 'https://api.printify.com/v1/shops.json';
    private const ETSY_PING_URL = 'https://api.etsy.com/v3/application/openapi-ping';
    private const ETSY_USER_BASE = 'https://api.etsy.com/v3/application/users/';
    private const GELATO_CATALOGS_URL = 'https://product.gelatoapis.com/v3/catalogs';
    private const OPENAI_MODELS_URL = 'https://api.openai.com/v1/models';

    public function test(int $integrationId): array|\WP_Error {
        $repository = new Repository();
        $integration = $repository->find($integrationId);
        if (! $integration) {
            return new \WP_Error('integration_not_found', __('Integration not found.', 'digiforge'), ['status' => 404]);
        }
        return match ((string) ($integration['provider'] ?? '')) {
            'printify' => $this->testPrintify($integrationId),
            'etsy' => $this->testEtsy($integrationId),
            'gelato' => $this->testGelato($integrationId),
            'ai' => $this->testAi($integrationId, $integration),
            default => new \WP_Error('connection_test_not_supported', __('This custom provider does not yet have a safe read-only connection test profile.', 'digiforge'), ['status' => 400]),
        };
    }

    private function testPrintify(int $integrationId): array|\WP_Error {
        $token = $this->firstCredential($integrationId, ['personal_access_token', 'access_token']);
        if (is_wp_error($token)) { return new \WP_Error('printify_credential_missing', __('Store a Printify personal access token before testing the connection.', 'digiforge'), ['status' => 409]); }
        $response = $this->get(self::PRINTIFY_SHOPS_URL, ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']);
        unset($token);
        $checked = $this->checkHttp('printify', $integrationId, $response); if (is_wp_error($checked)) { return $checked; }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true); if (! is_array($decoded)) { return $this->invalidResponse('printify', $integrationId); }
        $shops = [];
        foreach (array_slice($decoded, 0, 50) as $shop) { if (! is_array($shop)) { continue; } $shops[] = ['id' => absint($shop['id'] ?? 0), 'title' => sanitize_text_field((string) ($shop['title'] ?? '')), 'sales_channel' => sanitize_text_field((string) ($shop['sales_channel'] ?? ''))]; }
        $details = ['http_status' => 200, 'shop_count' => count($shops), 'shops' => $shops];
        $this->record($integrationId, true, $details, true);
        Logger::audit('integration_connection_test_succeeded', ['provider' => 'printify', 'shop_count' => count($shops)], 'integration', (string) $integrationId);
        $names = array_values(array_filter(array_map(static fn(array $shop): string => (string) ($shop['title'] ?? ''), $shops)));
        return ['ok' => true, 'provider' => 'printify', 'checked_at' => current_time('mysql', true), 'shops' => $shops, 'message' => sprintf(__('Printify connection verified. %d shop(s) returned%s.', 'digiforge'), count($shops), $names ? ': ' . implode(', ', array_slice($names, 0, 5)) : '')];
    }

    private function testEtsy(int $integrationId): array|\WP_Error {
        $keystring = $this->credential($integrationId, 'keystring'); $shared = $this->credential($integrationId, 'shared_secret');
        if (is_wp_error($keystring) || is_wp_error($shared)) { return new \WP_Error('etsy_app_credentials_missing', __('Store both Etsy keystring and shared_secret before testing the connection.', 'digiforge'), ['status' => 409]); }
        $apiKey = $keystring . ':' . $shared; unset($keystring, $shared);
        $ping = $this->get(self::ETSY_PING_URL, ['x-api-key' => $apiKey, 'Accept' => 'application/json']);
        $checked = $this->checkHttp('etsy', $integrationId, $ping, false); if (is_wp_error($checked)) { return $checked; }
        $access = $this->credential($integrationId, 'access_token');
        if (is_wp_error($access) || $access === '') {
            $details = ['http_status' => 200, 'app_credentials' => 'valid', 'oauth' => 'not_configured'];
            $this->record($integrationId, true, $details, false);
            Logger::audit('integration_connection_test_partial', ['provider' => 'etsy', 'oauth' => 'not_configured'], 'integration', (string) $integrationId);
            return ['ok' => true, 'partial' => true, 'provider' => 'etsy', 'checked_at' => current_time('mysql', true), 'message' => __('Etsy app credentials verified. OAuth shop authorization is still required before this Etsy connector is fully configured.', 'digiforge')];
        }
        $parts = explode('.', $access, 2); $userId = isset($parts[0]) ? absint($parts[0]) : 0;
        if ($userId <= 0) { return new \WP_Error('etsy_access_token_invalid', __('The stored Etsy access token does not contain a valid user identifier.', 'digiforge'), ['status' => 409]); }
        $userResponse = $this->get(self::ETSY_USER_BASE . $userId, ['x-api-key' => $apiKey, 'Authorization' => 'Bearer ' . $access, 'Accept' => 'application/json']); unset($access, $apiKey);
        $checked = $this->checkHttp('etsy', $integrationId, $userResponse); if (is_wp_error($checked)) { return $checked; }
        $user = json_decode((string) wp_remote_retrieve_body($userResponse), true); if (! is_array($user)) { return $this->invalidResponse('etsy', $integrationId); }
        $details = ['http_status' => 200, 'app_credentials' => 'valid', 'oauth' => 'valid', 'user_id' => $userId];
        $this->record($integrationId, true, $details, true);
        Logger::audit('integration_connection_test_succeeded', ['provider' => 'etsy', 'user_id' => $userId], 'integration', (string) $integrationId);
        return ['ok' => true, 'provider' => 'etsy', 'checked_at' => current_time('mysql', true), 'message' => sprintf(__('Etsy connection verified for authenticated user %d.', 'digiforge'), $userId)];
    }

    private function testGelato(int $integrationId): array|\WP_Error {
        $apiKey = $this->credential($integrationId, 'api_key');
        if (is_wp_error($apiKey)) {
            $legacy = $this->credential($integrationId, 'personal_access_token');
            if (! is_wp_error($legacy) && $legacy !== '') {
                $migration = (new Repository())->migrateSecretName($integrationId, 'personal_access_token', 'api_key');
                if (is_wp_error($migration)) { return $migration; }
                $apiKey = $legacy;
                Logger::audit('gelato_legacy_credential_normalized', ['from' => 'personal_access_token', 'to' => 'api_key'], 'integration', (string) $integrationId);
            }
        }
        if (is_wp_error($apiKey) || $apiKey === '') { return new \WP_Error('gelato_credential_missing', __('Store the Gelato api_key before testing the connection.', 'digiforge'), ['status' => 409]); }
        $response = $this->get(self::GELATO_CATALOGS_URL, ['X-API-KEY' => $apiKey, 'Accept' => 'application/json']); unset($apiKey);
        $checked = $this->checkHttp('gelato', $integrationId, $response); if (is_wp_error($checked)) { return $checked; }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true); if (! is_array($decoded)) { return $this->invalidResponse('gelato', $integrationId); }
        $details = ['http_status' => 200, 'catalog_count' => count($decoded)];
        $this->record($integrationId, true, $details, true);
        Logger::audit('integration_connection_test_succeeded', ['provider' => 'gelato', 'catalog_count' => count($decoded)], 'integration', (string) $integrationId);
        return ['ok' => true, 'provider' => 'gelato', 'checked_at' => current_time('mysql', true), 'message' => sprintf(__('Gelato connection verified. %d catalog(s) were returned.', 'digiforge'), count($decoded))];
    }

    private function testAi(int $integrationId, array $integration): array|\WP_Error {
        $config = is_array($integration['config'] ?? null) ? $integration['config'] : []; $vendor = sanitize_key((string) ($config['vendor'] ?? ''));
        if ($vendor === '') { return new \WP_Error('ai_vendor_missing', __('Set the AI connector vendor before testing it. OpenAI is supported by the current tester.', 'digiforge'), ['status' => 409]); }
        if ($vendor !== 'openai') { return new \WP_Error('ai_vendor_not_supported', sprintf(__('No audited read-only connection test is available yet for AI vendor "%s".', 'digiforge'), $vendor), ['status' => 400]); }
        $apiKey = $this->credential($integrationId, 'api_key'); if (is_wp_error($apiKey)) { return new \WP_Error('ai_credential_missing', __('Store the AI provider api_key before testing the connection.', 'digiforge'), ['status' => 409]); }
        $response = $this->get(self::OPENAI_MODELS_URL, ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json']); unset($apiKey);
        $checked = $this->checkHttp('ai', $integrationId, $response); if (is_wp_error($checked)) { return $checked; }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true); if (! is_array($decoded)) { return $this->invalidResponse('ai', $integrationId); }
        $modelCount = is_array($decoded['data'] ?? null) ? count($decoded['data']) : 0; $details = ['http_status' => 200, 'vendor' => 'openai', 'model_count' => $modelCount];
        $this->record($integrationId, true, $details, true);
        Logger::audit('integration_connection_test_succeeded', ['provider' => 'ai', 'vendor' => 'openai', 'model_count' => $modelCount], 'integration', (string) $integrationId);
        return ['ok' => true, 'provider' => 'ai', 'checked_at' => current_time('mysql', true), 'message' => sprintf(__('AI provider connection verified for OpenAI. %d model record(s) were returned.', 'digiforge'), $modelCount)];
    }

    private function get(string $url, array $headers): array|\WP_Error { return wp_remote_get($url, ['timeout' => 12, 'redirection' => 0, 'reject_unsafe_urls' => true, 'sslverify' => true, 'headers' => $headers + ['User-Agent' => 'DigiForge WordPress Integration Tester']]); }

    private function checkHttp(string $provider, int $integrationId, array|\WP_Error $response, bool $recordFailure = true): true|\WP_Error {
        if (is_wp_error($response)) { if ($recordFailure) { $this->record($integrationId, false, ['reason' => 'transport_error'], false); } Logger::audit('integration_connection_test_failed', ['provider' => $provider, 'reason' => 'transport_error'], 'integration', (string) $integrationId); return new \WP_Error($provider . '_transport_error', sprintf(__('%s could not be reached from this WordPress server.', 'digiforge'), ucfirst($provider)), ['status' => 502]); }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 401 || $status === 403) { if ($recordFailure) { $this->record($integrationId, false, ['http_status' => $status, 'reason' => 'authentication_failed'], false); } Logger::audit('integration_connection_test_failed', ['provider' => $provider, 'http_status' => $status, 'reason' => 'authentication_failed'], 'integration', (string) $integrationId); return new \WP_Error($provider . '_authentication_failed', sprintf(__('%s rejected the stored credential or its permissions.', 'digiforge'), ucfirst($provider)), ['status' => 401]); }
        if ($status < 200 || $status >= 300) { if ($recordFailure) { $this->record($integrationId, false, ['http_status' => $status, 'reason' => 'unexpected_response'], false); } Logger::audit('integration_connection_test_failed', ['provider' => $provider, 'http_status' => $status, 'reason' => 'unexpected_response'], 'integration', (string) $integrationId); return new \WP_Error($provider . '_unexpected_response', sprintf(__('%1$s returned HTTP %2$d during the read-only connection test.', 'digiforge'), ucfirst($provider), $status), ['status' => 502]); }
        return true;
    }

    private function invalidResponse(string $provider, int $integrationId): \WP_Error { $this->record($integrationId, false, ['reason' => 'invalid_json'], false); return new \WP_Error($provider . '_invalid_response', sprintf(__('%s returned an unreadable response.', 'digiforge'), ucfirst($provider)), ['status' => 502]); }
    private function firstCredential(int $integrationId, array $names): string|\WP_Error { foreach ($names as $name) { $value = $this->credential($integrationId, (string) $name); if (! is_wp_error($value) && $value !== '') { return $value; } } return new \WP_Error('credential_not_found', __('Credential not found.', 'digiforge'), ['status' => 404]); }
    private function credential(int $integrationId, string $name): string|\WP_Error { global $wpdb; $row = $wpdb->get_row($wpdb->prepare('SELECT ciphertext FROM ' . Tables::integration_secrets() . ' WHERE integration_id = %d AND secret_name = %s LIMIT 1', $integrationId, sanitize_key($name)), ARRAY_A); if (! is_array($row) || empty($row['ciphertext'])) { return new \WP_Error('credential_not_found', __('Credential not found.', 'digiforge'), ['status' => 404]); } try { return CredentialVault::decrypt((string) $row['ciphertext'], Repository::secretContext($integrationId, $name)); } catch (\Throwable $e) { return new \WP_Error('credential_decryption_failed', __('Stored credential could not be decrypted.', 'digiforge'), ['status' => 500]); } }
    private function record(int $integrationId, bool $success, array $details, bool $markConfigured): void { global $wpdb; $row = $wpdb->get_row($wpdb->prepare('SELECT config,status FROM ' . Tables::integrations() . ' WHERE id = %d', $integrationId), ARRAY_A); if (! is_array($row)) { return; } $config = json_decode((string) ($row['config'] ?? '{}'), true); if (! is_array($config)) { $config = []; } $config['_connection_test'] = ['ok' => $success, 'checked_at' => current_time('mysql', true), 'details' => $details]; $encoded = wp_json_encode($config); if (! is_string($encoded)) { return; } $status = (string) ($row['status'] ?? 'DISCONNECTED'); if ($success && $markConfigured) { $status = 'CONFIGURED'; } elseif (! $success) { $status = 'ERROR'; } $wpdb->update(Tables::integrations(), ['status' => $status, 'config' => $encoded, 'updated_at' => current_time('mysql', true)], ['id' => $integrationId]); }
}
