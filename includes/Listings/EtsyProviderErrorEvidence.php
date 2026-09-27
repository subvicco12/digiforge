<?php
declare(strict_types=1);

namespace DigiForge\Listings;

/**
 * Extracts a tiny non-secret diagnostic subset from a confirmed Etsy error body.
 * Raw provider bodies, headers, tokens, request data and arbitrary nested fields
 * are never returned.
 */
final class EtsyProviderErrorEvidence
{
    /** @return array<string,string> */
    public static function extract(string $body): array
    {
        if ($body==='' || strlen($body)>65536) return [];
        $decoded=json_decode($body,true);
        if (!is_array($decoded)) return [];
        $out=[];
        foreach (['error','code','message'] as $key) {
            $value=$decoded[$key]??null;
            if (!is_string($value) && !is_int($value)) continue;
            $value=trim(wp_strip_all_tags((string)$value));
            if ($value==='') continue;
            $out[$key]=substr($value,0,512);
        }
        return $out;
    }
}
