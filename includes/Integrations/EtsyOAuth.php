<?php

declare(strict_types=1);

namespace DigiForge\Integrations;

use DigiForge\Database\Tables;
use DigiForge\Security\Logger;

/** Etsy OAuth 2.0 Authorization Code + PKCE flow for individual DigiForge Etsy connectors. */
final class EtsyOAuth
{
    private const AUTHORIZE_URL = 'https://www.etsy.com/oauth/connect';
    private const TOKEN_URL = 'https://api.etsy.com/v3/public/oauth/token';
    private const TRANSIENT_PREFIX = 'digiforge_etsy_oauth_';
    private const FLOW_TTL = 600;
    private const SCOPES = 'profile_r shops_r listings_r listings_w transactions_r';

    public function register(): void
    {
        add_action('admin_post_digiforge_etsy_oauth_start', [$this, 'start']);
        add_action('admin_post_digiforge_etsy_oauth_callback', [$this, 'callback']);
        add_action('admin_footer', [$this, 'renderConnectControls']);
    }

    public static function callbackUrl(): string
    {
        return admin_url('admin-post.php?action=digiforge_etsy_oauth_callback');
    }

    public function start(): never
    {
        $this->authorize('digiforge_etsy_oauth_start');
        $integrationId = absint($_GET['integration_id'] ?? 0);
        $repository = new Repository();
        $integration = $repository->find($integrationId);
        if (! $integration || ($integration['provider'] ?? '') !== 'etsy') {
            $this->redirect(__('Etsy connector not found.', 'digiforge'), true);
        }

        $keystring = $this->credential($integrationId, 'keystring');
        $sharedSecret = $this->credential($integrationId, 'shared_secret');
        if (is_wp_error($keystring) || is_wp_error($sharedSecret)) {
            $this->redirect(__('Store the Etsy keystring and shared secret first, then click Connect Etsy.', 'digiforge'), true);
        }
        unset($sharedSecret);

        try {
            $state = $this->base64Url(random_bytes(32));
            $verifier = $this->base64Url(random_bytes(64));
            $challenge = $this->base64Url(hash('sha256', $verifier, true));
            $context = 'etsy-oauth:' . $state;
            $encryptedVerifier = CredentialVault::encrypt($verifier, $context);
        } catch (\Throwable $e) {
            $this->redirect(__('Could not initialize the secure Etsy authorization flow.', 'digiforge'), true);
        }
        unset($verifier);

        set_transient(
            self::TRANSIENT_PREFIX . hash('sha256', $state),
            [
                'integration_id' => $integrationId,
                'user_id' => get_current_user_id(),
                'verifier' => $encryptedVerifier,
                'created_at' => time(),
            ],
            self::FLOW_TTL
        );

        $url = add_query_arg(
            [
                'response_type' => 'code',
                'redirect_uri' => self::callbackUrl(),
                'scope' => self::SCOPES,
                'client_id' => $keystring,
                'state' => $state,
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ],
            self::AUTHORIZE_URL
        );
        unset($keystring, $challenge, $encryptedVerifier);

        Logger::audit('etsy_oauth_started', ['integration_id' => $integrationId], 'integration', (string) $integrationId);
        wp_safe_redirect($url);
        exit;
    }

    public function callback(): never
    {
        if (! current_user_can('manage_digiforge_connections')) {
            wp_die(esc_html__('You are not allowed to manage DigiForge connections.', 'digiforge'));
        }

        $error = sanitize_key((string) ($_GET['error'] ?? ''));
        if ($error !== '') {
            $description = sanitize_text_field((string) ($_GET['error_description'] ?? __('Etsy authorization was not completed.', 'digiforge')));
            $this->redirect($description, true);
        }

        $state = sanitize_text_field((string) ($_GET['state'] ?? ''));
        $code = sanitize_text_field((string) ($_GET['code'] ?? ''));
        if ($state === '' || $code === '') {
            $this->redirect(__('Etsy returned an incomplete OAuth response.', 'digiforge'), true);
        }

        $transientKey = self::TRANSIENT_PREFIX . hash('sha256', $state);
        $flow = get_transient($transientKey);
        delete_transient($transientKey);
        if (! is_array($flow) || empty($flow['integration_id']) || empty($flow['verifier'])) {
            $this->redirect(__('The Etsy authorization request expired or was already used. Please start Connect Etsy again.', 'digiforge'), true);
        }
        if ((int) ($flow['user_id'] ?? 0) !== get_current_user_id()) {
            $this->redirect(__('The Etsy authorization response does not match the administrator who started it.', 'digiforge'), true);
        }

        $integrationId = absint($flow['integration_id']);
        $integration = (new Repository())->find($integrationId);
        if (! $integration || ($integration['provider'] ?? '') !== 'etsy') {
            $this->redirect(__('Etsy connector not found.', 'digiforge'), true);
        }

        try {
            $verifier = CredentialVault::decrypt((string) $flow['verifier'], 'etsy-oauth:' . $state);
        } catch (\Throwable $e) {
            $this->redirect(__('The Etsy PKCE verifier could not be recovered safely.', 'digiforge'), true);
        }

        $keystring = $this->credential($integrationId, 'keystring');
        if (is_wp_error($keystring)) {
            unset($verifier);
            $this->redirect(__('The Etsy keystring is missing from the connector.', 'digiforge'), true);
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
                    'User-Agent' => 'DigiForge Etsy OAuth',
                ],
                'body' => [
                    'grant_type' => 'authorization_code',
                    'client_id' => $keystring,
                    'redirect_uri' => self::callbackUrl(),
                    'code' => $code,
                    'code_verifier' => $verifier,
                ],
            ]
        );
        unset($verifier, $keystring, $code, $state, $flow);

        if (is_wp_error($response)) {
            $this->redirect(__('Etsy token exchange could not reach Etsy.', 'digiforge'), true);
        }
        $httpStatus = (int) wp_remote_retrieve_response_code($response);
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($httpStatus < 200 || $httpStatus >= 300 || ! is_array($payload)) {
            Logger::audit('etsy_oauth_token_exchange_failed', ['http_status' => $httpStatus], 'integration', (string) $integrationId);
            $this->redirect(__('Etsy rejected the authorization-code exchange. Please start Connect Etsy again.', 'digiforge'), true);
        }

        $accessToken = (string) ($payload['access_token'] ?? '');
        $refreshToken = (string) ($payload['refresh_token'] ?? '');
        $expiresIn = max(1, absint($payload['expires_in'] ?? 3600));
        $scope = sanitize_text_field((string) ($payload['scope'] ?? self::SCOPES));
        if ($accessToken === '' || $refreshToken === '') {
            $this->redirect(__('Etsy did not return the required OAuth tokens.', 'digiforge'), true);
        }

        $repository = new Repository();
        $storedAccess = $repository->storeSecret($integrationId, 'access_token', $accessToken);
        $storedRefresh = $repository->storeSecret($integrationId, 'refresh_token', $refreshToken);
        unset($accessToken, $refreshToken, $payload, $response);
        if (is_wp_error($storedAccess) || is_wp_error($storedRefresh)) {
            $this->redirect(__('Etsy authorized successfully, but DigiForge could not store the OAuth tokens securely.', 'digiforge'), true);
        }

        $current = $repository->find($integrationId);
        $config = is_array($current['config'] ?? null) ? $current['config'] : [];
        unset($config['_connection_test']);
        $config['etsy_oauth'] = [
            'authorized_at' => current_time('mysql', true),
            'access_expires_at' => gmdate('Y-m-d H:i:s', time() + $expiresIn),
            'scope' => $scope,
            'refresh_mode' => 'just_in_time',
        ];
        $updated = $repository->update($integrationId, ['config' => $config, 'status' => 'DISCONNECTED', 'enabled' => false]);
        if (is_wp_error($updated)) {
            $this->redirect(__('Etsy tokens were stored, but connector metadata could not be updated.', 'digiforge'), true);
        }

        $test = (new ConnectionTester())->test($integrationId);
        if (is_wp_error($test)) {
            $this->redirect(__('Etsy OAuth authorization completed, but the live verification test failed. Use Test Etsy connection for details.', 'digiforge'), true);
        }

        Logger::audit('etsy_oauth_completed', ['scope' => $scope], 'integration', (string) $integrationId);
        $this->redirect(__('Etsy connected and verified successfully. Automation remains disabled until you explicitly enable it.', 'digiforge'));
    }

    public function renderConnectControls(): void
    {
        if (! current_user_can('manage_digiforge_connections')) { return; }
        if (sanitize_key((string) ($_GET['page'] ?? '')) !== 'digiforge-connections') { return; }

        $items = (new Repository())->all(1, 100)['items'] ?? [];
        $controls = [];
        foreach ($items as $item) {
            if (($item['provider'] ?? '') !== 'etsy') { continue; }
            $secretNames = array_column(is_array($item['secrets'] ?? null) ? $item['secrets'] : [], 'secret_name');
            $hasAppCredentials = in_array('keystring', $secretNames, true) && in_array('shared_secret', $secretNames, true);
            $authorized = in_array('access_token', $secretNames, true) && in_array('refresh_token', $secretNames, true);
            $controls[] = [
                'connection_key' => (string) $item['connection_key'],
                'has_app_credentials' => $hasAppCredentials,
                'authorized' => $authorized,
                'url' => $hasAppCredentials ? add_query_arg(
                    [
                        'action' => 'digiforge_etsy_oauth_start',
                        'integration_id' => (int) $item['id'],
                        '_wpnonce' => wp_create_nonce('digiforge_etsy_oauth_start'),
                    ],
                    admin_url('admin-post.php')
                ) : '',
            ];
        }
        if ($controls === []) { return; }
        ?>
        <script>
        (() => {
            const controls = <?php echo wp_json_encode($controls, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            for (const control of controls) {
                const card = [...document.querySelectorAll('.df-card')].find((node) => {
                    const meta = node.querySelector('.df-meta');
                    return meta && meta.textContent.includes(control.connection_key);
                });
                if (!card || card.querySelector('.df-etsy-oauth-control')) continue;
                const actions = card.querySelector('.df-actions');
                if (!actions) continue;
                const wrapper = document.createElement('span');
                wrapper.className = 'df-etsy-oauth-control';
                if (!control.has_app_credentials) {
                    const disabled = document.createElement('button');
                    disabled.type = 'button';
                    disabled.className = 'button';
                    disabled.disabled = true;
                    disabled.textContent = 'Connect Etsy';
                    disabled.title = 'Save Etsy keystring and shared secret first';
                    wrapper.appendChild(disabled);
                } else {
                    const link = document.createElement('a');
                    link.className = 'button button-primary';
                    link.href = control.url;
                    link.textContent = control.authorized ? 'Reconnect Etsy' : 'Connect Etsy';
                    wrapper.appendChild(link);
                }
                actions.prepend(wrapper);
            }
        })();
        </script>
        <?php
    }

    private function authorize(string $nonceAction): void
    {
        if (! current_user_can('manage_digiforge_connections')) {
            wp_die(esc_html__('You are not allowed to manage DigiForge connections.', 'digiforge'));
        }
        check_admin_referer($nonceAction);
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

    private function redirect(string $notice, bool $error = false): never
    {
        wp_safe_redirect(
            add_query_arg(
                ['page' => 'digiforge-connections', 'df_notice' => $notice, 'df_error' => $error ? '1' : '0'],
                admin_url('admin.php')
            )
        );
        exit;
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
