<?php

declare(strict_types=1);

namespace DigiForge\POD;

use WP_Error;

/**
 * Read-only Printify catalog transport. The caller supplies a credential from
 * DigiForge's encrypted vault. This class never persists or logs credentials
 * and exposes only allowlisted catalog GET operations.
 */
final class PrintifyCatalogClient
{
    private const V1 = 'https://api.printify.com/v1/';
    private const V2 = 'https://api.printify.com/v2/';
    private const MAX_ATTEMPTS = 3;
    private const MAX_RETRY_AFTER_SECONDS = 60;

    /** @var callable */ private $transport;
    /** @var callable */ private $sleep;

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
        if (!in_array($method, ['standard', 'priority', 'express', 'economy'], true)) {
            return new WP_Error('digiforge_printify_shipping_method', 'Unsupported Printify shipping method.', ['status'=>400]);
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
        if ($token === '') return new WP_Error('digiforge_printify_credentials', 'Printify credential is required.', ['status'=>409]);
        if (!$this->approvedEndpoint($url)) return new WP_Error('digiforge_printify_endpoint', 'Printify endpoint is outside the approved catalog boundary.', ['status'=>400]);

        $args = ['timeout'=>20,'redirection'=>0,'reject_unsafe_urls'=>true,'sslverify'=>true,'headers'=>[
            'Authorization'=>'Bearer '.$token,'Accept'=>'application/json','User-Agent'=>'DigiForge/PrintifyCatalogSync'
        ]];
        for ($attempt=1; $attempt<=self::MAX_ATTEMPTS; $attempt++) {
            $response=($this->transport)($url,$args);
            if (is_wp_error($response)) return new WP_Error('digiforge_printify_transport','Printify catalog transport failed.',['status'=>502]);
            $status=(int)wp_remote_retrieve_response_code($response);
            if ($status===429) {
                if ($attempt===self::MAX_ATTEMPTS) return new WP_Error('digiforge_printify_rate_limit','Printify catalog rate limit retry budget exhausted.',['status'=>429]);
                $retry=$this->retryAfterSeconds((string)wp_remote_retrieve_header($response,'retry-after'));
                $delay=$retry!==null ? $retry*1000000 : (2 ** ($attempt-1))*500000;
                ($this->sleep)($delay);
                continue;
            }
            if ($status<200 || $status>=300) return new WP_Error('digiforge_printify_http','Printify catalog request failed.',['status'=>$status ?: 502]);
            $body=(string)wp_remote_retrieve_body($response);
            try { $decoded=json_decode($body,true,512,JSON_THROW_ON_ERROR); }
            catch (\JsonException) { return new WP_Error('digiforge_printify_json','Printify returned invalid JSON.',['status'=>502]); }
            if (!is_array($decoded)) return new WP_Error('digiforge_printify_shape','Printify returned an unsupported response shape.',['status'=>502]);
            return $decoded;
        }
        return new WP_Error('digiforge_printify_rate_limit','Printify catalog rate limit retry budget exhausted.',['status'=>429]);
    }

    private function approvedEndpoint(string $url): bool
    {
        $parts=parse_url($url);
        if (!is_array($parts) || ($parts['scheme']??'')!=='https' || ($parts['host']??'')!=='api.printify.com' || isset($parts['user'],$parts['pass'],$parts['fragment'])) return false;
        $path=(string)($parts['path']??'');
        return preg_match('#^/v1/catalog/blueprints(?:\.json|/[1-9][0-9]*(?:\.json|/print_providers(?:\.json|/[1-9][0-9]*/variants\.json)))$#',$path)===1
            || preg_match('#^/v2/catalog/blueprints/[1-9][0-9]*/print_providers/[1-9][0-9]*/shipping(?:\.json|/(?:standard|priority|express|economy)\.json)$#',$path)===1;
    }

    private function retryAfterSeconds(string $value): ?int
    {
        $value=trim($value);
        if ($value==='') return null;
        if (ctype_digit($value)) return min((int)$value,self::MAX_RETRY_AFTER_SECONDS);
        $at=strtotime($value);
        if ($at===false) return null;
        return min(max(0,$at-time()),self::MAX_RETRY_AFTER_SECONDS);
    }
}
