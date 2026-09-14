<?php

declare(strict_types=1);

namespace DigiForge\Launch;

use DigiForge\Database\Tables;
use DigiForge\Integrations\CredentialVault;
use DigiForge\Integrations\Repository as IntegrationRepository;
use DigiForge\Security\Logger;

/** Executes narrowly-scoped launch-time OpenAI calls using the encrypted DigiForge connector. */
final class OpenAIClient
{
    private const RESPONSES_URL = 'https://api.openai.com/v1/responses';
    private const DEFAULT_MODEL = 'gpt-5.6-luna';

    /** @return array<string,mixed>|\WP_Error */
    public function research(string $brief): array|\WP_Error
    {
        return $this->request($brief, true);
    }

    /** @return array<string,mixed>|\WP_Error */
    public function develop(string $brief): array|\WP_Error
    {
        return $this->request($brief, false);
    }

    /** @return array<string,mixed>|\WP_Error */
    private function request(string $brief, bool $webSearch): array|\WP_Error
    {
        $connector = $this->connector();
        if (is_wp_error($connector)) {
            return $connector;
        }

        $apiKey = $this->secret((int) $connector['id'], 'api_key');
        if (is_wp_error($apiKey)) {
            return $apiKey;
        }

        $body = [
            'model' => self::DEFAULT_MODEL,
            'input' => $brief,
            'max_output_tokens' => 4000,
        ];
        if ($webSearch) {
            $body['tools'] = [['type' => 'web_search']];
        }

        $response = wp_remote_post(self::RESPONSES_URL, [
            'timeout' => 90,
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);
        unset($apiKey);

        if (is_wp_error($response)) {
            Logger::audit('launch_openai_request_failed', ['reason' => 'transport'], 'launch_execution');
            return new \WP_Error('digiforge_launch_ai_transport', __('AI provider request failed.', 'digiforge'), ['status' => 502]);
        }

        $status = wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || ! is_array($decoded)) {
            Logger::audit('launch_openai_request_failed', ['http_status' => $status], 'launch_execution');
            return new \WP_Error('digiforge_launch_ai_provider', __('AI provider rejected the request.', 'digiforge'), ['status' => 502]);
        }

        $text = $this->extractOutputText($decoded);
        if ($text === '') {
            return new \WP_Error('digiforge_launch_ai_empty', __('AI provider returned no usable text.', 'digiforge'), ['status' => 502]);
        }

        $payload = $this->decodeJsonObject($text);
        if (! is_array($payload)) {
            Logger::audit('launch_openai_invalid_json', ['response_id' => sanitize_text_field((string) ($decoded['id'] ?? ''))], 'launch_execution');
            return new \WP_Error('digiforge_launch_ai_invalid_json', __('AI provider output was not valid structured JSON.', 'digiforge'), ['status' => 502]);
        }

        return [
            'payload' => $payload,
            'response_id' => sanitize_text_field((string) ($decoded['id'] ?? '')),
            'model' => sanitize_text_field((string) ($decoded['model'] ?? self::DEFAULT_MODEL)),
            'usage' => is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [],
        ];
    }

    /** @return array<string,mixed>|\WP_Error */
    private function connector(): array|\WP_Error
    {
        global $wpdb;
        $row = $wpdb->get_row(
            "SELECT * FROM " . Tables::integrations() . " WHERE provider='ai' AND environment='production' AND status='CONFIGURED' AND enabled=1 ORDER BY id ASC LIMIT 1",
            ARRAY_A
        );
        if (! is_array($row)) {
            return new \WP_Error('digiforge_launch_ai_connector', __('An enabled production AI connector is required.', 'digiforge'), ['status' => 409]);
        }
        return $row;
    }

    private function secret(int $integrationId, string $name): string|\WP_Error
    {
        global $wpdb;
        $ciphertext = $wpdb->get_var($wpdb->prepare(
            'SELECT ciphertext FROM ' . Tables::integration_secrets() . ' WHERE integration_id=%d AND secret_name=%s LIMIT 1',
            $integrationId,
            $name
        ));
        if (! is_string($ciphertext) || $ciphertext === '') {
            return new \WP_Error('digiforge_launch_ai_secret', __('AI connector credential is missing.', 'digiforge'), ['status' => 409]);
        }
        try {
            return CredentialVault::decrypt($ciphertext, IntegrationRepository::secretContext($integrationId, $name));
        } catch (\Throwable $e) {
            return new \WP_Error('digiforge_launch_ai_secret', __('AI connector credential could not be decrypted.', 'digiforge'), ['status' => 500]);
        }
    }

    /** @param array<string,mixed> $response */
    private function extractOutputText(array $response): string
    {
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return trim($response['output_text']);
        }
        $parts = [];
        foreach ((array) ($response['output'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (is_array($content) && isset($content['text']) && is_string($content['text'])) {
                    $parts[] = $content['text'];
                }
            }
        }
        return trim(implode("\n", $parts));
    }

    /** @return array<string,mixed>|null */
    private function decodeJsonObject(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end >= $start) {
            $text = substr($text, $start, $end - $start + 1);
        }
        $decoded = json_decode($text, true);
        return is_array($decoded) ? $decoded : null;
    }
}
