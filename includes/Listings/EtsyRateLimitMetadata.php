<?php
declare(strict_types=1);

namespace DigiForge\Listings;

use WP_Error;

/** Sanitizes provider retry/rate-limit metadata without scheduling retries. */
final class EtsyRateLimitMetadata
{
    /** @return array<string,mixed>|WP_Error */
    public static function fromHeaders(mixed $headers): array|WP_Error
    {
        $retryAfter=self::value($headers,'retry-after');
        $perSecond=self::integer(self::value($headers,'x-limit-per-second'));
        $remainingSecond=self::integer(self::value($headers,'x-remaining-this-second'));
        $perDay=self::integer(self::value($headers,'x-limit-per-day'));
        $remainingToday=self::integer(self::value($headers,'x-remaining-today'));

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
            'limit_per_second'=>$perSecond,
            'remaining_this_second'=>$remainingSecond,
            'limit_per_day'=>$perDay,
            'remaining_today'=>$remainingToday,
            'automatic_retry_permitted'=>false,
            'retry_scheduled'=>false,
            'external_execution_performed'=>false,
        ];
    }

    private static function value(mixed $headers,string $name): string
    {
        $value=null;
        if (is_array($headers)) {
            foreach ($headers as $key=>$candidate) if (strtolower((string)$key)===$name) { $value=$candidate; break; }
        } elseif (is_object($headers) && method_exists($headers,'offsetGet')) {
            $value=$headers->offsetGet($name);
        } elseif ($headers instanceof \ArrayAccess) {
            $value=$headers[$name]??null;
        }
        if (is_array($value)) $value=reset($value);
        return trim((string)($value??''));
    }

    private static function integer(string $value): ?int
    {
        return ctype_digit($value)?max(0,(int)$value):null;
    }
}
