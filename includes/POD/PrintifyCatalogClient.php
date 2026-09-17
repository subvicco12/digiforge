<?php

declare(strict_types=1);

namespace DigiForge\POD;

use WP_Error;

/**
 * Read-only Printify catalog transport.
 *
 * The caller supplies a token resolved from DigiForge's encrypted integration
 * vault. This class never persists, logs or returns the token and exposes only
 * catalog GETs; it cannot publish products or create orders.
 */
final class PrintifyCatalogClient
{
    private const V1 = 'https://api.printify.com/v1/';
    private const V2 = 'https://api.printify.com/v2/';
    private const MAX_ATTEMPTS = 3;

    /** @var callable */
    private $transport;
    /** @var callable */
    private $sleep;

    public function __construct(?callable $transport = null, ?callable $sleep = null)
    {
        $this->transport = $transport ?? static fn(string $url, array $args): array|WP_Error => wp_remote_get($url, $args);
        $this->sleep = $sleep ?? static function (int $microseconds): void { usleep($microseconds); };
    }

    public function blueprints(string $token): array|WP_Error
    {
        return $this->get($token, self::V1 . 'catalog/blueprints.json');
    }

    public function blueprint(string $token, int $blueprintId): array|WP_Error
    {
        return $this->get($token, self::V1 . 'catalog/blueprints/' . $this->id($blueprintId) . '.json');
    }

    public function providers(string $token, int $blueprintId): array|WP_Error
    {
        return $this->get($token, self::V1 . 'catalog/blueprints/' . $this->id($blueprintId) . '/print_providers.json');
    }

    public function variants(string $token, int $blueprintId, int $providerId): array|WP_Error
    {
        return $this->get($token, self::V1 . 'catalog/blueprints/' . $this->id($blueprintId) . '/print_providers/' . $this->id($providerId) . '/variants.json');
    }

    public function shipping(string $token, int $blueprintId, int $providerId, ?string $method = null): array|WP_Error
    {
        $base = self::V2 . 'catalog/blueprints/' . $this->id($blueprintId) . '/print_providers/' . $this->id($providerId) . '/shipping';
        if ($method === null) return $this->get($token, $base . '.json');
        $method = strtolower(trim($method));
        if (! in_array($method, ['standard', 'priority', 'express', 'economy'], true)) {
            return new WP_Error('digiforge_printify_shipping_method', 'Unsupported Printify shipping method.', ['status' => 400]);
        }
        return $this->get($token, $base . '/' . $method . '.json');
    }

    private function id(int $id): int
    {
        if ($id < 1) throw new \InvalidArgumentException('Printify catalog identifiers must be positive integers.');
        return $id;
    }

    private function get(string $token, string $url): array|WP_Error
    {
        $token = trim($token);
        if ($token === '') return new WP_Error('digiforge_printify_credentials', 'Printify credential is required.', ['status' => 409]);
        if (! str_starts_with($url, self::V1) && ! str_starts_with($url, self::V2)) {
            return new WP_Error('digiforge_printify_endpoint', 'Printify endpoint is outside the approved API origin.', ['status' => 400]);
        }
        $args = [
            'timeout' => 20,
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'User-Agent' => 'DigiForge/PrintifyCatalogSync',
            ],
        ];
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $response = ($this->transport)($url, $args);
            if (is_wp_error($response)) return $response;
            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status === 429 && $attempt < self::MAX_ATTEMPTS) {
                $retry = (int) wp_remote_retrieve_header($response, 'retry-after');
                $delay = $retry > 0 ? min($retry, 60) * 1000000 : (2 ** ($attempt - 1)) * 500000;
                ($this->sleep)($delay);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                return new WP_Error('digiforge_printify_http', 'Printify catalog request failed.', ['status' => $status ?: 502]);
            }
            $body = wp_remote_retrieve_body($response);
            try { $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { return new WP_Error('digiforge_printify_json', 'Printify returned invalid JSON.', ['status' => 502]); }
            if (! is_array($decoded)) return new WP_Error('digiforge_printify_shape', 'Printify returned an unsupported response shape.', ['status' => 502]);
            return $decoded;
        }
        return new WP_Error('digiforge_printify_rate_limit', 'Printify catalog rate limit retry budget exhausted.', ['status' => 429]);
    }
}
