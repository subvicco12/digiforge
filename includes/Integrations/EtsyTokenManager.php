<?php

declare(strict_types=1);

namespace DigiForge\Integrations;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;

/** Handles Etsy OAuth access-token reuse and just-in-time refresh without enabling automation. */
final class EtsyTokenManager
{
    private const TOKEN_URL = 'https://api.etsy.com/v3/public/oauth/token';
    private const REFRESH_SKEW_SECONDS = 120;

    public function accessToken(int $integrationId, bool $forceRefresh = false): string|\WP_Error
    {
        $repository = new Repository();
        $integration = $repository->find($integrationId);
        if (! $integration || ($integration['provider'] ?? '') !== 'etsy') {
            return new \WP_Error('etsy_integration_not_found', __('Etsy connector not found.', 'digiforge'), ['status' => 404]);
        }

        $access = $this->credential($integrationId, 'access_token');
        if (is_wp_error($access)) {
            return new \WP_Error('etsy_access_token_missing', __('Etsy access token is missing. Reconnect Etsy to authorize this shop.', 'digiforge'), ['status' => 409]);
        }

        $config = is_array($integration['config'] ?? null) ? $integration['config'] : [];
        $oauth = is_array($config['etsy_oauth'] ?? null) ? $config['etsy_oauth'] : [];
        $expiresAt = strtotime((string) ($oauth['access_expires_at'] ?? ''));
        $shouldRefresh = $forceRefresh || ($expiresAt !== false && $expiresAt <= (time() + self::REFRESH_SKEW_SECONDS));

        if (! $shouldRefresh) {
            return $access;
        }

        unset($access);
        return $this->refresh($integrationId, $integration, $config);
    }

    private function refresh(int $integrationId, array $integration, array $config): string|\WP_Error
    {
        $keystring = $this->credential($integrationId, 'keystring');
        $refreshToken = $this->credential($integrationId, 'refresh_token');
        if (is_wp_error($keystring) || is_wp_error($refreshToken)) {
            unset($keystring, $refreshToken);
            return new \WP_Error('etsy_refresh_credentials_missing', __('Etsy refresh credentials are missing. Reconnect Etsy to authorize this shop again.', 'digiforge'), ['status' => 409]);
        }

        $response = wp_remote_post(
            self::TOKEN_URL,
            [
                'timeout' => 15,
                'redirection' => 0,
                'reject_unsafe_urls' => true,
                'sslverify' => true,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'User-Agent' => 'DigiForge Etsy OAuth Refresh',
                ],
                'body' => [
                    'grant_type' => 'refresh_token',
                    'client_id' => $keystring,
                    'refresh_token' => $refreshToken,
                ],
            ]
        );
        unset($keystring);

        if (is_wp_error($response)) {
            unset($refreshToken);
            Logger::audit('etsy_oauth_refresh_failed', ['reason' => 'transport_error'], 'integration', (string) $integrationId);
            return new \WP_Error('etsy_refresh_transport_error', __('Etsy token refresh could not reach Etsy.', 'digiforge'), ['status' => 502]);
        }

        $httpStatus = (int) wp_remote_retrieve_response_code($response);
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($httpStatus < 200 || $httpStatus >= 300 || ! is_array($payload)) {
            unset($refreshToken, $payload, $response);
            Logger::audit('etsy_oauth_refresh_failed', ['http_status' => $httpStatus], 'integration', (string) $integrationId);
            return new \WP_Error('etsy_refresh_rejected', __('Etsy rejected the stored refresh token. Reconnect Etsy to authorize this shop again.', 'digiforge'), ['status' => 401]);
        }

        $newAccess = (string) ($payload['access_token'] ?? '');
        $newRefresh = (string) ($payload['refresh_token'] ?? '');
        $expiresIn = max(1, absint($payload['expires_in'] ?? 3600));
        $scope = sanitize_text_field((string) ($payload['scope'] ?? ''));
        if ($newAccess === '') {
            unset($refreshToken, $payload, $response);
            Logger::audit('etsy_oauth_refresh_failed', ['reason' => 'missing_access_token'], 'integration', (string) $integrationId);
            return new \WP_Error('etsy_refresh_invalid_response', __('Etsy refreshed authorization but did not return a usable access token.', 'digiforge'), ['status' => 502]);
        }
        if ($newRefresh === '') {
            $newRefresh = $refreshToken;
        }
        unset($refreshToken, $payload, $response);

        $repository = new Repository();
        $storedRefresh = $repository->storeSecret($integrationId, 'refresh_token', $newRefresh);
        if (is_wp_error($storedRefresh)) {
            unset($newAccess, $newRefresh);
            return new \WP_Error('etsy_refresh_store_failed', __('DigiForge could not securely store the refreshed Etsy authorization.', 'digiforge'), ['status' => 500]);
        }
        unset($newRefresh);

        $storedAccess = $repository->storeSecret($integrationId, 'access_token', $newAccess);
        if (is_wp_error($storedAccess)) {
            unset($newAccess);
            return new \WP_Error('etsy_access_store_failed', __('DigiForge could not securely store the refreshed Etsy access token.', 'digiforge'), ['status' => 500]);
        }

        $oauth = is_array($config['etsy_oauth'] ?? null) ? $config['etsy_oauth'] : [];
        $oauth['access_expires_at'] = gmdate('Y-m-d H:i:s', time() + $expiresIn);
        $oauth['last_refreshed_at'] = current_time('mysql', true);
        $oauth['refresh_mode'] = 'just_in_time';
        if ($scope !== '') {
            $oauth['scope'] = $scope;
        }
        $config['etsy_oauth'] = $oauth;

        $updated = $repository->update($integrationId, ['config' => $config]);
        if (is_wp_error($updated)) {
            unset($newAccess);
            return new \WP_Error('etsy_refresh_metadata_failed', __('Etsy token refresh succeeded, but DigiForge could not update the connector metadata.', 'digiforge'), ['status' => 500]);
        }

        Logger::audit(
            'etsy_oauth_refreshed',
            [
                'expires_in' => $expiresIn,
                'connector_enabled' => ! empty($integration['enabled']),
                'automation_changed' => false,
            ],
            'integration',
            (string) $integrationId
        );

        return $newAccess;
    }

    private function credential(int $integrationId, string $name): string|\WP_Error
    {
        global $wpdb;
        $name = sanitize_key($name);
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT ciphertext FROM ' . Tables::integration_secrets() . ' WHERE integration_id = %d AND secret_name = %s LIMIT 1',
                $integrationId,
                $name
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
}
