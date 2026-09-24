<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/** Sanitizes provider retry/rate-limit metadata without scheduling retries. */
final class EtsyRateLimitMetadata
{
    /** @return array<string,mixed>|WP_Error */
    public static function fromHeaders(array $headers): array|WP_Error
    {
        $retryAfter=self::value($headers,'retry-after');
        $remaining=self::value($headers,'x-rate-limit-remaining');
        $reset=self::value($headers,'x-rate-limit-reset');

        $retrySeconds=null;
        if ($retryAfter!=='') {
            if (ctype_digit($retryAfter)) $retrySeconds=min(86400,(int)$retryAfter);
            else {
                $ts=strtotime($retryAfter);
                if ($ts!==false) $retrySeconds=max(0,min(86400,$ts-time()));
            }
        }
        return [
            'retry_after_seconds'=>$retrySeconds,
            'remaining'=>ctype_digit($remaining)?max(0,(int)$remaining):null,
            'reset'=>$reset!=='' && strlen($reset)<=64?$reset:null,
            'automatic_retry_permitted'=>false,
            'retry_scheduled'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private static function value(array $headers,string $name): string
    {
        foreach ($headers as $key=>$value) {
            if (strtolower((string)$key)!==$name) continue;
            if (is_array($value)) $value=reset($value);
            return trim((string)$value);
        }
        return '';
    }
}
