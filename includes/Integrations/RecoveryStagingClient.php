<?php

declare(strict_types=1);

namespace DigiForge\Integrations;

/** Read-only HTTP boundary for isolated DigiForge recovery verification. */
final class RecoveryStagingClient
{
    /** @return array<string,mixed>|\WP_Error */
    public function get(string $url): array|\WP_Error
    {
        $response = wp_remote_get($url, ['timeout' => 20, 'redirection' => 0, 'reject_unsafe_urls' => true, 'sslverify' => true, 'headers' => ['Accept' => 'application/json', 'User-Agent' => 'DigiForge-Recovery-Verifier/' . (defined('DIGIFORGE_VERSION') ? DIGIFORGE_VERSION : 'unknown')]]);
        if (is_wp_error($response)) {
            return new \WP_Error('digiforge_recovery_verify_unreachable', __('The isolated recovery target could not be verified.', 'digiforge'), ['status' => 502]);
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return new \WP_Error('digiforge_recovery_verify_http', __('The isolated recovery target did not return a successful verification response.', 'digiforge'), ['status' => 502, 'upstream_status' => (int) wp_remote_retrieve_response_code($response)]);
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($body)) {
            return new \WP_Error('digiforge_recovery_verify_payload', __('The isolated recovery target returned invalid verification evidence.', 'digiforge'), ['status' => 502]);
        }
        return $body;
    }
}
